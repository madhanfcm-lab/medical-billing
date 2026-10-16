<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\AppointmentService;
use App\Support\CurrentBranch;
use App\Support\Whatsapp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

/** Booking, confirming, moving, cancelling and checking in appointments. */
class AppointmentTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private const COLS = ['when', 'patient', 'doctor', 'reason', 'status', 'actions'];

    private User $admin;

    private User $rita;

    private User $doctorUser;

    private Patient $patient;

    private Doctor $rao;

    private Doctor $iyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->rita = User::factory()->create(['name' => 'Rita'])->assignRole('receptionist');
        $this->doctorUser = User::factory()->create()->assignRole('doctor');
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $this->rao = Doctor::factory()->create(['name' => 'Dr. Rao', 'user_id' => $this->doctorUser->id]);
        $this->iyer = Doctor::factory()->create(['name' => 'Dr. Iyer']);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id,
            'appointment_date' => today()->addDays(2)->toDateString(), 'appointment_time' => '10:30', 'duration_minutes' => 15, 'reason' => 'Check-up',
        ], $over);
    }

    private function book(array $over = []): Appointment
    {
        $this->actingAs($this->rita)->post('/appointments', $this->payload($over))->assertSessionHasNoErrors();

        return Appointment::latest('id')->firstOrFail();
    }

    // ═══ booking ═══

    public function test_reception_books_an_appointment_that_waits_for_confirmation(): void
    {
        $a = $this->book();

        $this->assertSame('APT-'.now()->year.'-00001', $a->booking_number);
        $this->assertSame(AppointmentStatus::Scheduled, $a->status);
        $this->assertSame(Branch::main()->id, $a->branch_id);
        $this->assertSame($this->rita->id, $a->created_by);
        $this->assertNull($a->confirmed_at);
        $this->actingAs($this->rita)->get("/appointments/{$a->id}")->assertOk()->assertSee('Awaiting confirmation')->assertSee('Patient confirmed')->assertSee('Asha Verma');
    }

    public function test_booking_rules(): void
    {
        $this->actingAs($this->rita);
        $this->post('/appointments', $this->payload(['appointment_date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('appointment_date');
        $this->post('/appointments', $this->payload(['doctor_id' => '']))->assertSessionHasErrors('doctor_id');
        $this->post('/appointments', $this->payload(['patient_id' => '']))->assertSessionHasErrors('patient_id');
        $this->post('/appointments', $this->payload(['appointment_time' => '']))->assertSessionHasErrors('appointment_time');
        $this->post('/appointments', $this->payload(['duration_minutes' => 7]))->assertSessionHasErrors('duration_minutes');
        $this->assertSame(0, Appointment::count());

        $inactive = Doctor::factory()->create(['is_active' => false]);
        $this->post('/appointments', $this->payload(['doctor_id' => $inactive->id]))->assertSessionHasErrors('doctor_id');
    }

    public function test_a_time_that_already_passed_today_is_refused(): void
    {
        $this->travelTo(today()->setTime(15, 0));
        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_date' => today()->toDateString(), 'appointment_time' => '09:00']))->assertSessionHasErrors('appointment_time');
        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_date' => today()->toDateString(), 'appointment_time' => '16:00']))->assertSessionHasNoErrors();
    }

    public function test_the_doctor_cannot_be_double_booked_unless_the_desk_says_so(): void
    {
        $this->book(['appointment_time' => '10:30', 'duration_minutes' => 30]);

        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_time' => '10:45']))
            ->assertSessionHasErrors('appointment_time');                                  // inside the first slot
        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_time' => '11:00']))->assertSessionHasNoErrors();   // right after it: fine
        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_time' => '10:30', 'doctor_id' => $this->iyer->id]))->assertSessionHasNoErrors();   // another doctor

        $this->actingAs($this->rita)->post('/appointments', $this->payload(['appointment_time' => '10:40', 'allow_overlap' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(4, Appointment::count());

        $page = $this->actingAs($this->rita)->from('/appointments/create')->followingRedirects()->post('/appointments', $this->payload(['appointment_time' => '10:35']));
        $page->assertSee('Book anyway')->assertSee('Asha Verma');
    }

    public function test_a_cancelled_appointment_frees_the_slot(): void
    {
        $a = $this->book();
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/cancel", ['reason' => 'Patient asked'])->assertSessionHasNoErrors();

        $this->actingAs($this->rita)->post('/appointments', $this->payload())->assertSessionHasNoErrors();
    }

    // ═══ confirming ═══

    public function test_confirming_records_how_when_and_by_whom(): void
    {
        $a = $this->book();

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'whatsapp', 'note' => 'Replied yes'])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame('whatsapp', $a->confirmed_via->value);
        $this->assertSame($this->rita->id, $a->confirmed_by);
        $this->assertNotNull($a->confirmed_at);
        $this->assertSame('Replied yes', $a->confirmation_note);

        $this->actingAs($this->rita)->get("/appointments/{$a->id}")->assertSee('Confirmed by the patient')->assertSee('WhatsApp')->assertSee('Replied yes');
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'appointment', 'subject_id' => $a->id, 'event' => 'confirmed']);
    }

    public function test_a_confirmation_needs_a_valid_method(): void
    {
        $a = $this->book();

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", [])->assertSessionHasErrors('via');
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'telepathy'])->assertSessionHasErrors('via');

        // asking again is fine (it just refreshes who confirmed and how), but a closed appointment cannot be confirmed
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'phone'])->assertSessionHasNoErrors();
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'sms'])->assertSessionHasNoErrors();
        $this->assertSame('sms', $a->fresh()->confirmed_via->value);
    }

    public function test_moving_a_confirmed_appointment_asks_for_confirmation_again(): void
    {
        $a = $this->book();
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'phone']);

        $this->actingAs($this->rita)->put("/appointments/{$a->id}", $this->payload(['appointment_time' => '15:00']) + [])->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $a->refresh();
        $this->assertSame(AppointmentStatus::Scheduled, $a->status);
        $this->assertNull($a->confirmed_at);
        $this->assertNull($a->confirmed_via);
        $this->assertSame('15:00', substr($a->appointment_time, 0, 5));
    }

    public function test_a_change_that_keeps_the_slot_keeps_the_confirmation(): void
    {
        $a = $this->book();
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'phone']);

        $this->actingAs($this->rita)->put("/appointments/{$a->id}", $this->payload(['reason' => 'Check-up and scaling']))->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertSame('Check-up and scaling', $a->fresh()->reason);
    }

    public function test_the_person_on_an_appointment_cannot_be_swapped(): void
    {
        $a = $this->book();
        $other = Patient::factory()->create();

        $this->actingAs($this->rita)->put("/appointments/{$a->id}", $this->payload(['patient_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->patient->id, $a->fresh()->patient_id);
    }

    // ═══ cancelling, no-show, arriving ═══

    public function test_cancelling_needs_a_reason(): void
    {
        $a = $this->book();

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/cancel", ['reason' => 'Travelling'])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $a->status);
        $this->assertSame('Travelling', $a->cancel_reason);
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'phone'])->assertForbidden();   // closed
    }

    public function test_check_in_opens_the_visit_and_lands_on_the_patient_page(): void
    {
        $a = $this->book(['appointment_date' => today()->toDateString(), 'appointment_time' => '23:30', 'reason' => 'Tooth pain']);

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/check-in")->assertRedirect(route('patients.show', $this->patient->id))->assertSessionHas('success');

        $a->refresh();
        $this->assertSame(AppointmentStatus::CheckedIn, $a->status);
        $visit = Visit::findOrFail($a->visit_id);
        $this->assertSame($this->patient->id, $visit->patient_id);
        $this->assertSame($this->rao->id, $visit->doctor_id);
        $this->assertSame('Tooth pain', $visit->chief_complaint);
        $this->assertSame(today()->toDateString(), $visit->visit_date->toDateString());

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/check-in")->assertForbidden();   // already arrived
        $this->assertSame(1, Visit::count());
    }

    public function test_check_in_is_only_for_the_day_of_the_appointment(): void
    {
        $a = $this->book();   // two days away

        $this->actingAs($this->rita)->post("/appointments/{$a->id}/check-in")->assertSessionHasErrors('status');
        $this->assertSame(AppointmentStatus::Scheduled, $a->fresh()->status);
        $this->assertSame(0, Visit::count());
    }

    public function test_no_show_only_after_the_day_has_passed(): void
    {
        $a = $this->book();
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/no-show")->assertSessionHasErrors('status');

        $this->travelTo(today()->addDays(3)->setTime(9, 0));
        $this->actingAs($this->rita)->get("/appointments/{$a->id}")->assertOk()->assertSee('did not arrive');
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/no-show")->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::NoShow, $a->fresh()->status);
    }

    // ═══ who can do what ═══

    public function test_doctors_only_look_at_their_own_appointments(): void
    {
        $mine = $this->book(['doctor_id' => $this->rao->id]);
        $theirs = $this->book(['doctor_id' => $this->iyer->id]);

        $this->actingAs($this->doctorUser)->get("/appointments/{$mine->id}")->assertOk();
        $this->actingAs($this->doctorUser)->get("/appointments/{$theirs->id}")->assertForbidden();
        $this->actingAs($this->doctorUser)->get('/appointments?tab=all')->assertOk()->assertDontSee('Book appointment');
        $this->dt('/appointments?tab=all', self::COLS, '', ['tab' => 'all'], ['when', 'doctor', 'status', 'actions'])->assertJsonPath('recordsTotal', 1);

        $this->actingAs($this->doctorUser)->post("/appointments/{$mine->id}/confirm", ['via' => 'phone'])->assertForbidden();
        $this->actingAs($this->doctorUser)->get('/appointments/create')->assertForbidden();
        $this->actingAs($this->doctorUser)->post('/appointments', $this->payload())->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/appointments')->assertRedirect('/login');
    }

    // ═══ lists, patient page, dashboard, branches ═══

    public function test_the_tabs_list_the_right_appointments(): void
    {
        $today = $this->book(['appointment_date' => today()->toDateString(), 'appointment_time' => '23:00']);
        $later = $this->book(['appointment_time' => '09:00']);
        $this->actingAs($this->rita)->post("/appointments/{$later->id}/confirm", ['via' => 'phone']);
        $unconfirmed = $this->book(['appointment_time' => '09:30', 'doctor_id' => $this->iyer->id]);

        $count = fn (string $tab) => $this->dt("/appointments?tab={$tab}", self::COLS, '', ['tab' => $tab], ['when', 'doctor', 'status', 'actions'])->json('recordsTotal');
        $this->assertSame(1, $count('today'));
        $this->assertSame(2, $count('upcoming'));
        $this->assertSame(2, $count('confirm'));    // today's one and the later unconfirmed one: not the confirmed one
        $this->assertSame(3, $count('all'));

        $this->actingAs($this->rita)->get('/appointments')->assertOk()->assertSee('Needs confirmation');
        $this->assertNotNull($unconfirmed);
        $this->assertNotNull($today);
    }

    public function test_a_search_finds_the_patient(): void
    {
        $this->book();
        $found = $this->dt('/appointments?tab=all', self::COLS, 'Asha', ['tab' => 'all'], ['when', 'doctor', 'status', 'actions']);
        $this->assertSame(1, $found->json('recordsFiltered'));
        $none = $this->dt('/appointments?tab=all', self::COLS, 'Zzzz', ['tab' => 'all'], ['when', 'doctor', 'status', 'actions']);
        $this->assertSame(0, $none->json('recordsFiltered'));
    }

    public function test_the_patient_page_shows_and_lets_you_book_and_confirm(): void
    {
        $a = $this->book(['appointment_date' => today()->toDateString(), 'appointment_time' => '23:00']);

        $page = $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk();
        $page->assertSee('Book appointment')->assertSee('Awaiting confirmation')->assertSee(route('appointments.confirm', $a))->assertSee(route('appointments.check-in', $a))
            ->assertSee('Appointment 11:00 PM');
        $this->actingAs($this->rita)->get('/appointments/create?patient='.$this->patient->id)->assertOk()->assertSee('Asha Verma');
    }

    public function test_the_dashboard_lists_todays_appointments(): void
    {
        $this->book(['appointment_date' => today()->toDateString(), 'appointment_time' => '23:00']);

        $this->actingAs($this->rita)->get('/dashboard')->assertOk()->assertSee("Today's appointments", false)->assertSee('Asha Verma')->assertSee('1 booked · 1 to confirm')->assertDontSee('@if', false);
    }

    public function test_appointments_belong_to_a_branch(): void
    {
        $north = Branch::create(['name' => 'North Branch', 'code' => 'NOR', 'is_active' => true]);
        $this->admin->branches()->syncWithoutDetaching([$north->id]);

        $a = $this->book();
        $inNorth = app(CurrentBranch::class);
        $inNorth->set($north, $north);
        $northPatient = Patient::factory()->create();
        $b = app(AppointmentService::class)->book($this->payload(['appointment_time' => '12:00', 'patient_id' => $northPatient->id]));
        $inNorth->clear();
        $this->assertSame($north->id, $b->branch_id);

        $this->actingAs($this->admin)->post('/branch/switch', ['branch' => $north->id]);
        $this->actingAs($this->admin)->get("/appointments/{$b->id}")->assertOk();
        $this->actingAs($this->admin)->get("/appointments/{$a->id}")->assertNotFound();
        $this->dt('/appointments?tab=all', self::COLS, '', ['tab' => 'all'], ['when', 'doctor', 'status', 'actions'])->assertJsonPath('recordsTotal', 1);
    }

    public function test_a_doctor_cannot_be_booked_twice_at_once_even_across_branches(): void
    {
        // A doctor who visits both branches is only in one place at a time, so this IS a clash across branches too.
        $north = Branch::create(['name' => 'North Branch', 'code' => 'NOR', 'is_active' => true]);
        $this->book(['appointment_time' => '10:00']);

        $current = app(CurrentBranch::class);
        $current->set($north, $north);
        try {
            $this->expectException(ValidationException::class);
            app(AppointmentService::class)->book($this->payload(['appointment_time' => '10:00']));
        } finally {
            $current->clear();
        }
    }

    // ═══ slip and messages ═══

    public function test_the_slip_prints_the_details_and_the_whatsapp_text_changes_once_confirmed(): void
    {
        $a = $this->book(['appointment_time' => '10:30']);

        $this->actingAs($this->rita)->get("/appointments/{$a->id}/slip")->assertOk()->assertSee('APPOINTMENT SLIP')->assertSee($a->booking_number)->assertSee('10:30 AM')->assertSee('Dr. Rao');

        $ask = Whatsapp::appointment($a->load(['patient', 'doctor', 'branch']));
        $this->assertStringContainsString('reply YES to confirm', $ask);
        $this->actingAs($this->rita)->post("/appointments/{$a->id}/confirm", ['via' => 'phone']);
        $this->assertStringContainsString('is confirmed', Whatsapp::appointment($a->fresh()->load(['patient', 'doctor', 'branch'])));
    }
}

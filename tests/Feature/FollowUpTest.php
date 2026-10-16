<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\DashboardService;
use App\Services\FollowUpService;
use App\Support\Whatsapp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private Patient $patient;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $this->doctor = Doctor::factory()->create(['name' => 'Dr. Rao']);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'follow_up_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Wire adjustment',
        ], $over);
    }

    private function make(array $over = []): FollowUp
    {
        return FollowUp::factory()->create(array_merge(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id], $over));
    }

    // --- creating ------------------------------------------------------------------

    public function test_reception_schedules_a_follow_up(): void
    {
        $this->as('receptionist')->post('/follow-ups', $this->payload(['follow_up_time' => '10:30', 'notes' => 'Bring X-ray']))->assertSessionHasNoErrors();

        $f = FollowUp::firstOrFail();
        $this->assertSame(FollowUpStatus::Scheduled, $f->status);
        $this->assertSame('10:30 AM', $f->time_label);
        $this->assertSame(today()->addDays(7)->toDateString(), $f->follow_up_date->toDateString());
        $this->assertNotNull($f->created_by);
    }

    public function test_time_is_optional(): void
    {
        $this->as('receptionist')->post('/follow-ups', $this->payload(['follow_up_time' => '']))->assertSessionHasNoErrors();

        $this->assertNull(FollowUp::firstOrFail()->time_label);
    }

    public function test_validation(): void
    {
        $r = $this->as('receptionist');
        $bad = fn (array $over, $errors) => $r->post('/follow-ups', $this->payload($over))->assertSessionHasErrors($errors);

        $bad(['follow_up_date' => today()->subDay()->toDateString()], 'follow_up_date');   // must be today or later
        $bad(['follow_up_date' => ''], 'follow_up_date');
        $bad(['follow_up_date' => '15/11/2026'], 'follow_up_date');
        $bad(['follow_up_time' => '25:99'], 'follow_up_time');
        $bad(['reason' => ''], 'reason');
        $bad(['reason' => str_repeat('x', 201)], 'reason');
        $bad(['patient_id' => ''], 'patient_id');
        $bad(['doctor_id' => ''], 'doctor_id');
        $bad(['doctor_id' => Doctor::factory()->create(['is_active' => false])->id], 'doctor_id');
        $bad(['visit_id' => Visit::factory()->create()->id], 'visit_id');                   // someone else's visit

        $this->assertSame(0, FollowUp::count());
        $r->post('/follow-ups', $this->payload(['follow_up_date' => today()->toDateString()]))->assertSessionHasNoErrors();   // today is fine
    }

    public function test_can_be_set_from_a_visit_and_from_an_emi_plan(): void
    {
        $visit = Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);
        $plan = $this->emiPlan();
        $r = $this->as('receptionist');

        $r->get('/follow-ups/create?visit='.$visit->id)->assertOk()->assertSee('name="visit_id" value="'.$visit->id.'"', false);
        $r->get('/follow-ups/create?emi_plan='.$plan->id)->assertOk()->assertSee('EMI payment follow-up', false);

        $r->post('/follow-ups', $this->payload(['visit_id' => $visit->id]))->assertSessionHasNoErrors();
        $r->post('/follow-ups', $this->payload(['emi_plan_id' => $plan->id, 'reason' => 'EMI reminder']))->assertSessionHasNoErrors();
        $this->assertSame($visit->id, FollowUp::first()->visit_id);
        $this->assertSame($plan->id, FollowUp::latest('id')->first()->emi_plan_id);
    }

    private function emiPlan(): EmiPlan
    {
        $this->as('receptionist')->post('/emi-plans', [
            'patient_id' => $this->patient->id, 'treatment_name' => 'Braces', 'total_amount' => '30000', 'down_payment' => 0,
            'calc_by' => 'monthly', 'monthly_amount' => '3000', 'start_date' => today()->toDateString(), 'due_day' => 15,
        ])->assertSessionHasNoErrors();

        return EmiPlan::firstOrFail();
    }

    // --- status changes -----------------------------------------------------------------

    public function test_complete(): void
    {
        $f = $this->make();
        $this->as('receptionist')->post("/follow-ups/{$f->id}/complete")->assertSessionHasNoErrors();

        $f->refresh();
        $this->assertSame(FollowUpStatus::Completed, $f->status);
        $this->assertNotNull($f->completed_at);
    }

    public function test_reschedule_keeps_the_old_record_and_links_the_new_one(): void
    {
        $f = $this->make(['reason' => 'Suture removal', 'follow_up_time' => '09:00']);
        $newDate = today()->addDays(14)->toDateString();

        $this->as('receptionist')->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => $newDate, 'follow_up_time' => '11:15', 'notes' => 'Patient travelling'])
            ->assertSessionHasNoErrors();

        $f->refresh();
        $new = FollowUp::where('rescheduled_from_id', $f->id)->firstOrFail();
        $this->assertSame(FollowUpStatus::Rescheduled, $f->status);                 // old record kept
        $this->assertSame(FollowUpStatus::Scheduled, $new->status);
        $this->assertSame($newDate, $new->follow_up_date->toDateString());
        $this->assertSame('11:15 AM', $new->time_label);
        $this->assertSame('Suture removal', $new->reason);                           // carried over
        $this->assertSame($this->doctor->id, $new->doctor_id);
        $this->assertSame($new->id, $f->rescheduledTo->id);
        $this->assertSame($f->id, $new->rescheduledFrom->id);
        $this->assertSame(2, FollowUp::count());
    }

    public function test_reschedule_can_change_doctor_and_needs_a_valid_date(): void
    {
        $f = $this->make();
        $other = Doctor::factory()->create();
        $r = $this->as('receptionist');

        $r->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => today()->subDay()->toDateString()])->assertSessionHasErrors('follow_up_date');
        $r->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => ''])->assertSessionHasErrors('follow_up_date');
        $this->assertSame(FollowUpStatus::Scheduled, $f->fresh()->status);

        $r->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => today()->addDay()->toDateString(), 'doctor_id' => $other->id]);
        $this->assertSame($other->id, FollowUp::where('rescheduled_from_id', $f->id)->first()->doctor_id);
    }

    public function test_cancel_with_optional_reason(): void
    {
        $f = $this->make();
        $this->as('receptionist')->post("/follow-ups/{$f->id}/cancel", ['reason' => 'Patient recovered'])->assertSessionHasNoErrors();

        $this->assertSame(FollowUpStatus::Cancelled, $f->fresh()->status);
        $this->assertSame('Patient recovered', $f->fresh()->cancel_reason);

        $g = $this->make();
        $this->as('receptionist')->post("/follow-ups/{$g->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame(FollowUpStatus::Cancelled, $g->fresh()->status);
    }

    public function test_closed_follow_ups_cannot_be_changed_again(): void
    {
        $r = $this->as('receptionist');

        foreach ([FollowUpStatus::Completed, FollowUpStatus::Cancelled, FollowUpStatus::Rescheduled] as $status) {
            $f = $this->make(['status' => $status]);
            $r->post("/follow-ups/{$f->id}/complete")->assertForbidden();
            $r->post("/follow-ups/{$f->id}/cancel")->assertForbidden();
            $r->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => today()->addDay()->toDateString()])->assertForbidden();
            $this->assertSame($status, $f->fresh()->status);
        }
        $this->assertSame(3, FollowUp::count());
    }

    public function test_service_rechecks_status_under_lock(): void
    {
        $f = $this->make();
        $stale = FollowUp::find($f->id);
        $svc = app(FollowUpService::class);

        $svc->complete($f);

        $this->expectException(ValidationException::class);
        $svc->reschedule($stale, ['follow_up_date' => today()->addDay()->toDateString()]);
    }

    public function test_a_missed_follow_up_can_still_be_completed_or_rescheduled(): void
    {
        $r = $this->as('receptionist');
        $a = $this->make(['status' => FollowUpStatus::Missed, 'follow_up_date' => today()->subDays(3)->toDateString()]);
        $b = $this->make(['status' => FollowUpStatus::Missed, 'follow_up_date' => today()->subDays(3)->toDateString()]);

        $r->post("/follow-ups/{$a->id}/complete")->assertSessionHasNoErrors();
        $r->post("/follow-ups/{$b->id}/reschedule", ['follow_up_date' => today()->addDay()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame(FollowUpStatus::Completed, $a->fresh()->status);
        $this->assertSame(FollowUpStatus::Rescheduled, $b->fresh()->status);
    }

    // --- complete + open a visit -----------------------------------------------------------

    public function test_complete_and_record_visit_links_them(): void
    {
        $f = $this->make(['reason' => 'Wire adjustment']);
        $r = $this->as('receptionist');

        $r->get("/visits/create?patient={$this->patient->id}&follow_up={$f->id}")->assertOk()
            ->assertSee('name="follow_up_id" value="'.$f->id.'"', false)->assertSee('Follow-up: Wire adjustment');

        $r->post('/visits', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(),
            'chief_complaint' => 'Follow-up: Wire adjustment', 'follow_up_id' => $f->id,
        ])->assertSessionHasNoErrors();

        $f->refresh();
        $visit = Visit::firstOrFail();
        $this->assertSame(FollowUpStatus::Completed, $f->status);
        $this->assertSame($visit->id, $f->completed_visit_id);
        $this->assertNotNull($f->completed_at);
    }

    public function test_visit_cannot_link_another_patients_follow_up(): void
    {
        $other = FollowUp::factory()->create();
        $this->as('receptionist')->post('/visits', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(),
            'chief_complaint' => 'x', 'follow_up_id' => $other->id,
        ])->assertSessionHasErrors('follow_up_id');

        $this->assertSame(FollowUpStatus::Scheduled, $other->fresh()->status);
        $this->assertSame(0, Visit::count());
    }

    public function test_visit_from_an_already_completed_follow_up_just_links(): void
    {
        $f = $this->make(['status' => FollowUpStatus::Completed, 'completed_at' => now()]);
        $visit = Visit::factory()->create(['patient_id' => $this->patient->id]);

        app(FollowUpService::class)->completeWithVisit($f, $visit);

        $this->assertSame($visit->id, $f->fresh()->completed_visit_id);
        $this->assertSame(FollowUpStatus::Completed, $f->fresh()->status);
    }

    // --- scheduler ----------------------------------------------------------------------------

    public function test_daily_command_marks_only_past_unactioned_follow_ups_as_missed(): void
    {
        $past = $this->make(['follow_up_date' => today()->subDay()->toDateString()]);
        $old = $this->make(['follow_up_date' => today()->subDays(30)->toDateString()]);
        $today = $this->make(['follow_up_date' => today()->toDateString()]);
        $future = $this->make();
        $done = $this->make(['follow_up_date' => today()->subDay()->toDateString(), 'status' => FollowUpStatus::Completed]);
        $cancelled = $this->make(['follow_up_date' => today()->subDay()->toDateString(), 'status' => FollowUpStatus::Cancelled]);
        $moved = $this->make(['follow_up_date' => today()->subDay()->toDateString(), 'status' => FollowUpStatus::Rescheduled]);

        $this->artisan('followups:mark-missed')->expectsOutputToContain('2 follow-up(s)')->assertSuccessful();

        $this->assertSame(FollowUpStatus::Missed, $past->fresh()->status);
        $this->assertSame(FollowUpStatus::Missed, $old->fresh()->status);
        $this->assertSame(FollowUpStatus::Scheduled, $today->fresh()->status);   // today is not over yet
        $this->assertSame(FollowUpStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(FollowUpStatus::Completed, $done->fresh()->status);
        $this->assertSame(FollowUpStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(FollowUpStatus::Rescheduled, $moved->fresh()->status);

        $this->artisan('followups:mark-missed')->expectsOutputToContain('0 follow-up(s)')->assertSuccessful();   // idempotent
    }

    public function test_commands_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->map->command->implode("\n");

        $this->assertStringContainsString('followups:mark-missed', $events);
        $this->assertStringContainsString('emi:mark-overdue', $events);
    }

    // --- pages & filters ----------------------------------------------------------------------

    private function feed(string $query, ?User $as = null)
    {
        $c = $as ? $this->actingAs($as) : $this->as('receptionist');

        parse_str($query, $params);
        $search = $params['search']['value'] ?? '';
        unset($params['search']);

        return $c->dt('/follow-ups', ['follow_up_date', 'patient', 'doctor', 'reason', 'status', 'actions'], $search, $params,
            notSearchable: ['follow_up_date', 'doctor', 'status', 'actions'])->assertOk();
    }

    public function test_tabs_show_the_right_follow_ups(): void
    {
        $this->make(['reason' => 'today-one', 'follow_up_date' => today()->toDateString()]);
        $this->make(['reason' => 'today-done', 'follow_up_date' => today()->toDateString(), 'status' => FollowUpStatus::Completed]);
        $this->make(['reason' => 'today-cancelled', 'follow_up_date' => today()->toDateString(), 'status' => FollowUpStatus::Cancelled]);
        $this->make(['reason' => 'tomorrow', 'follow_up_date' => today()->addDay()->toDateString()]);
        $this->make(['reason' => 'late-missed', 'follow_up_date' => today()->subDays(2)->toDateString(), 'status' => FollowUpStatus::Missed]);
        $this->make(['reason' => 'late-unflagged', 'follow_up_date' => today()->subDay()->toDateString()]);   // job has not run yet

        $reasons = fn (string $tab) => collect($this->feed("tab=$tab&length=50")->json('data'))->map(fn ($r) => strip_tags($r['reason']))->sort()->values()->all();

        $this->assertSame(['today-done', 'today-one'], $reasons('today'));
        $this->assertSame(['tomorrow'], $reasons('upcoming'));
        $this->assertSame(['late-missed', 'late-unflagged'], $reasons('missed'));
        $this->assertCount(6, $reasons('all'));
    }

    public function test_filters_by_doctor_date_range_and_status(): void
    {
        $other = Doctor::factory()->create();
        $this->make(['doctor_id' => $other->id, 'follow_up_date' => today()->addDays(3)->toDateString()]);
        $this->make(['follow_up_date' => today()->addDays(10)->toDateString()]);
        $this->make(['follow_up_date' => today()->addDays(20)->toDateString(), 'status' => FollowUpStatus::Cancelled]);

        $this->assertSame(1, $this->feed("tab=upcoming&doctor_id={$other->id}")->json('recordsFiltered'));
        $this->assertSame(1, $this->feed('tab=upcoming&from='.today()->addDays(8)->toDateString().'&to='.today()->addDays(12)->toDateString())->json('recordsFiltered'));
        $this->assertSame(1, $this->feed('tab=all&status=cancelled')->json('recordsFiltered'));
        $this->assertSame(3, $this->feed('tab=all')->json('recordsFiltered'));
    }

    public function test_search_and_row_content(): void
    {
        $this->make(['follow_up_date' => today()->toDateString(), 'follow_up_time' => '14:30', 'reason' => 'Crown fitting']);

        $res = $this->feed('tab=today&search[value]=Asha');
        $this->assertSame(1, $res->json('recordsFiltered'));
        $row = $res->json('data.0');
        $this->assertStringContainsString('02:30 PM', $row['follow_up_date']);
        $this->assertStringContainsString('https://wa.me/919876543210?text=', $row['actions']);
        $this->assertStringContainsString('Crown%20fitting', $row['actions']);

        $this->assertSame(0, $this->feed('tab=today&search[value]=Nobody')->json('recordsFiltered'));
        $this->assertSame(1, $this->feed('tab=today&search[value]=Crown')->json('recordsFiltered'));
    }

    public function test_overdue_row_shows_missed_even_before_the_job_runs(): void
    {
        $this->make(['follow_up_date' => today()->subDay()->toDateString()]);

        $this->assertStringContainsString('Missed', $this->feed('tab=missed')->json('data.0.status'));
    }

    public function test_screens_render(): void
    {
        $f = $this->make(['follow_up_time' => '09:00']);
        $r = $this->as('admin');

        $r->get('/follow-ups')->assertOk()->assertSee('Overdue / Missed');
        $r->get('/follow-ups?tab=missed')->assertOk();
        $r->get('/follow-ups/create')->assertOk();
        $r->get('/follow-ups/create?patient='.$this->patient->id)->assertOk()->assertSee('Asha Verma');
        $r->get("/follow-ups/{$f->id}")->assertOk()->assertSee('Review after treatment')->assertSee('09:00 AM')
            ->assertSee('Reschedule')->assertSee('wa.me/919876543210', false);
        $r->get("/patients/{$this->patient->id}")->assertOk()->assertSee('Review after treatment')->assertSee('Set follow-up');
        $r->get('/visits/'.Visit::factory()->create(['patient_id' => $this->patient->id])->id)->assertOk()->assertSee('Set follow-up');
    }

    public function test_reschedule_chain_is_visible_on_both_records(): void
    {
        $f = $this->make();
        $this->as('receptionist')->post("/follow-ups/{$f->id}/reschedule", ['follow_up_date' => today()->addDays(2)->toDateString()]);
        $new = FollowUp::where('rescheduled_from_id', $f->id)->first();
        $r = $this->as('receptionist');

        $r->get("/follow-ups/{$f->id}")->assertOk()->assertSee('Moved to')->assertDontSee('Complete &amp; record visit', false);
        $r->get("/follow-ups/{$new->id}")->assertOk()->assertSee('Moved from');
    }

    public function test_whatsapp_messages(): void
    {
        $f = $this->make(['follow_up_date' => today()->addDays(2)->toDateString(), 'follow_up_time' => '16:00', 'reason' => 'Wire adjustment'])->load(['patient', 'doctor']);
        $msg = Whatsapp::followUpReminder($f);

        $this->assertStringContainsString('Asha Verma', $msg);
        $this->assertStringContainsString(format_date($f->follow_up_date).' at 04:00 PM', $msg);
        $this->assertStringContainsString('Dr. Rao', $msg);
        $this->assertStringContainsString('reminder', $msg);

        $late = $this->make(['follow_up_date' => today()->subDays(2)->toDateString()])->load(['patient', 'doctor']);
        $this->assertStringContainsString('we missed you', Whatsapp::followUpReminder($late));
    }

    // --- dashboard ------------------------------------------------------------------------------

    public function test_dashboard_lists_todays_follow_ups(): void
    {
        $this->make(['follow_up_date' => today()->toDateString(), 'follow_up_time' => '09:30', 'reason' => 'Check-up']);
        $this->make(['follow_up_date' => today()->toDateString(), 'status' => FollowUpStatus::Completed]);
        $this->make();   // next week

        $d = app(DashboardService::class)->summary();
        $this->assertSame(1, $d['cards']['today_followups']);
        $this->assertCount(1, $d['todays_followups']);

        $this->as('admin')->get('/dashboard')->assertOk()->assertSee('Asha Verma')->assertSee('09:30 AM');
    }

    // --- permissions ----------------------------------------------------------------------------------

    public function test_doctor_sets_follow_ups_under_own_profile_for_own_patients_only(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $d = $this->actingAs($login);

        $d->post('/follow-ups', $this->payload())->assertSessionHasErrors('patient_id');          // not their patient yet
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);

        $d->post('/follow-ups', $this->payload(['doctor_id' => $this->doctor->id]))->assertSessionHasNoErrors();
        $this->assertSame($me->id, FollowUp::firstOrFail()->doctor_id);                            // forced to own profile
    }

    public function test_doctor_only_sees_follow_ups_of_own_patients(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);

        $mine = $this->make(['follow_up_date' => today()->toDateString()]);
        $theirs = FollowUp::factory()->create(['follow_up_date' => today()->toDateString()]);

        $this->assertSame(1, $this->feed('tab=today', $login)->json('recordsTotal'));
        $d = $this->actingAs($login);
        $d->get("/follow-ups/{$mine->id}")->assertOk();
        $d->get("/follow-ups/{$theirs->id}")->assertForbidden();
        $d->post("/follow-ups/{$theirs->id}/complete")->assertForbidden();
        $d->post("/follow-ups/{$mine->id}/complete")->assertSessionHasNoErrors();
    }
}

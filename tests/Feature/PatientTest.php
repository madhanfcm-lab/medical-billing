<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Services\NumberGenerator;
use App\Services\PatientService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Asha Verma', 'gender' => 'female', 'date_of_birth' => '1990-05-20', 'phone' => '9876543210'], $over);
    }

    public function test_uhid_is_sequential_and_formatted(): void
    {
        $svc = app(PatientService::class);
        $a = $svc->create($this->payload());
        $b = $svc->create($this->payload(['phone' => '9876543211']));

        $this->assertSame('PAT-'.now()->year.'-00001', $a->uhid);
        $this->assertSame('PAT-'.now()->year.'-00002', $b->uhid);
    }

    public function test_uhid_uses_configured_prefix_and_restarts_each_year(): void
    {
        Setting::setMany(['patient_prefix' => 'CLN']);
        $this->assertSame('CLN-2026-00001', app(NumberGenerator::class)->next('patient', 'CLN', 2026));
        $this->assertSame('CLN-2027-00001', app(NumberGenerator::class)->next('patient', 'CLN', 2027));
        $this->assertSame('CLN-2026-00002', app(NumberGenerator::class)->next('patient', 'CLN', 2026));
    }

    public function test_numbers_are_unique_across_many_calls(): void
    {
        $gen = app(NumberGenerator::class);
        $nums = collect(range(1, 50))->map(fn () => $gen->next('invoice', 'INV', 2026));

        $this->assertCount(50, $nums->unique());
    }

    public function test_reception_registers_patient(): void
    {
        $this->as('receptionist')->post('/patients', $this->payload())->assertSessionHasNoErrors();

        $p = Patient::firstOrFail();
        $this->assertNotNull($p->uhid);
        $this->assertNotNull($p->created_by);
    }

    public function test_phone_is_normalised(): void
    {
        $this->as('receptionist')->post('/patients', $this->payload(['phone' => '+91 98765-43210']))->assertSessionHasNoErrors();
        $this->assertSame('9876543210', Patient::first()->phone);
    }

    public function test_phone_rules(): void
    {
        $r = $this->as('receptionist');
        $r->post('/patients', $this->payload(['phone' => '1234567890']))->assertSessionHasErrors('phone');
        $r->post('/patients', $this->payload(['phone' => '98765']))->assertSessionHasErrors('phone');
        $r->post('/patients', $this->payload(['phone' => '']))->assertSessionHasErrors('phone');

        Patient::factory()->create(['phone' => '9876543210']);
        $r->post('/patients', $this->payload())->assertSessionHasErrors('phone');
    }

    public function test_dob_cannot_be_future_and_gender_required(): void
    {
        $this->as('receptionist')->post('/patients', $this->payload([
            'date_of_birth' => now()->addDay()->format('Y-m-d'), 'gender' => '',
        ]))->assertSessionHasErrors(['date_of_birth', 'gender']);
    }

    public function test_update_may_keep_own_phone(): void
    {
        $p = Patient::factory()->create(['phone' => '9876543210']);

        $this->as('receptionist')->put("/patients/{$p->id}", $this->payload(['name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $p->fresh()->name);
        $this->assertSame($p->uhid, $p->fresh()->uhid);
    }

    public function test_age_label(): void
    {
        $this->assertSame('34 yrs', (new Patient(['date_of_birth' => now()->subYears(34)->subDays(3)]))->age_label);
        $this->assertSame('8 mths', (new Patient(['date_of_birth' => now()->subMonths(8)->subDay()]))->age_label);
        $this->assertSame('—', (new Patient)->age_label);
    }

    public function test_search_by_name_phone_and_uhid(): void
    {
        $p = Patient::factory()->create(['name' => 'Ramesh Kumar', 'phone' => '9811122233', 'uhid' => 'PAT-2026-00042']);
        Patient::factory()->create(['name' => 'Someone Else']);

        foreach (['Ramesh', '98111', '00042'] as $q) {
            $this->as('receptionist')->getJson('/patients/search?q='.$q)
                ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $p->id);
        }
    }

    public function test_doctor_sees_only_own_patients_and_cannot_register(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $other = Doctor::factory()->create();

        $mine = Patient::factory()->create(['name' => 'My Patient']);
        $theirs = Patient::factory()->create(['name' => 'Other Patient']);
        Visit::factory()->create(['patient_id' => $mine->id, 'doctor_id' => $me->id]);
        Visit::factory()->create(['patient_id' => $theirs->id, 'doctor_id' => $other->id]);

        $doctor = $this->actingAs($login);
        $doctor->get('/patients')->assertOk();
        $doctor->getJson('/patients', ['X-Requested-With' => 'XMLHttpRequest'])->assertJsonPath('recordsTotal', 1);
        $doctor->getJson('/patients/search?q=Patient')->assertJsonCount(1, 'results');
        $doctor->get("/patients/{$mine->id}")->assertOk();
        $doctor->get("/patients/{$theirs->id}")->assertForbidden();
        $doctor->get('/patients/create')->assertForbidden();
        $doctor->post('/patients', $this->payload())->assertForbidden();
    }

    public function test_profile_shows_tabs_and_totals(): void
    {
        $p = Patient::factory()->create(['name' => 'Tab Tester']);

        $this->as('receptionist')->get("/patients/{$p->id}")->assertOk()
            ->assertSee('Tab Tester')->assertSee('Total billed')->assertSee('₹0.00')
            ->assertSee('EMI Plans')->assertSee('Follow-ups');
    }

    public function test_create_and_edit_forms_render(): void
    {
        $p = Patient::factory()->create(['allergies' => 'Penicillin']);
        $r = $this->as('receptionist');

        $r->get('/patients/create')->assertOk()->assertSee('Register patient');
        $r->get("/patients/{$p->id}/edit")->assertOk()->assertSee($p->uhid)->assertSee('Penicillin');
    }

    public function test_uhid_cannot_be_changed_through_update(): void
    {
        $p = Patient::factory()->create();
        $original = $p->uhid;

        $this->as('receptionist')->put("/patients/{$p->id}", $this->payload(['phone' => $p->phone, 'uhid' => 'HACK-1']));
        $this->assertSame($original, $p->fresh()->uhid);
    }

    public function test_datatable_feed(): void
    {
        Patient::factory()->count(3)->create();

        $this->as('receptionist')->getJson('/patients', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('recordsTotal', 3)->assertJsonStructure(['data' => [['uhid', 'name', 'age', 'actions']]]);
    }
}

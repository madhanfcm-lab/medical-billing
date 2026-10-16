<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitTest extends TestCase
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
        return array_merge([
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => Doctor::factory()->create()->id,
            'visit_date' => today()->toDateString(),
            'chief_complaint' => 'Toothache',
            'diagnosis' => 'Cavity',
        ], $over);
    }

    public function test_reception_records_a_visit(): void
    {
        $this->as('receptionist')->post('/visits', $this->payload())->assertSessionHasNoErrors();

        $v = Visit::firstOrFail();
        $this->assertSame('Cavity', $v->diagnosis);
        $this->assertNotNull($v->created_by);
    }

    public function test_visit_validation(): void
    {
        $this->as('receptionist')->post('/visits', $this->payload([
            'patient_id' => '', 'doctor_id' => '', 'chief_complaint' => '', 'visit_date' => today()->addDay()->toDateString(),
        ]))->assertSessionHasErrors(['patient_id', 'doctor_id', 'chief_complaint', 'visit_date']);

        $inactive = Doctor::factory()->create(['is_active' => false]);
        $this->as('receptionist')->post('/visits', $this->payload(['doctor_id' => $inactive->id]))->assertSessionHasErrors('doctor_id');
    }

    public function test_doctor_records_visit_under_own_profile_only(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $other = Doctor::factory()->create();

        $this->actingAs($login)->post('/visits', $this->payload(['doctor_id' => $other->id]))->assertSessionHasNoErrors();

        $this->assertSame($me->id, Visit::firstOrFail()->doctor_id);   // forced to own profile
    }

    public function test_doctor_cannot_open_or_edit_another_doctors_visit(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        Doctor::factory()->create(['user_id' => $login->id]);
        $visit = Visit::factory()->create();

        $d = $this->actingAs($login);
        $d->get("/visits/{$visit->id}")->assertForbidden();
        $d->get("/visits/{$visit->id}/edit")->assertForbidden();
        $d->put("/visits/{$visit->id}", $this->payload())->assertForbidden();
    }

    public function test_doctor_can_edit_own_visit_notes(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $visit = Visit::factory()->create(['doctor_id' => $me->id]);

        $this->actingAs($login)->put("/visits/{$visit->id}", [
            'patient_id' => $visit->patient_id, 'visit_date' => today()->toDateString(),
            'chief_complaint' => 'Pain', 'diagnosis' => 'Pulpitis', 'treatment_given' => 'RCT started',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Pulpitis', $visit->fresh()->diagnosis);
    }

    public function test_screens_render_and_show_create_bill_button(): void
    {
        $visit = Visit::factory()->create();
        $r = $this->as('receptionist');

        $r->get('/visits')->assertOk();
        $r->get('/visits/create?patient='.$visit->patient_id)->assertOk()->assertSee($visit->patient->name);
        $r->get("/visits/{$visit->id}")->assertOk()->assertSee('Create bill');
        $r->get("/visits/{$visit->id}/edit")->assertOk();
        $r->getJson('/visits', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('recordsTotal', 1);
    }

    public function test_invoice_form_from_visit_prefills_patient_and_doctor(): void
    {
        $visit = Visit::factory()->create();

        $this->as('receptionist')->get('/invoices/create?visit='.$visit->id)->assertOk()
            ->assertSee($visit->patient->uhid)->assertSee('name="visit_id" value="'.$visit->id.'"', false);
    }

    public function test_invoice_visit_must_belong_to_patient(): void
    {
        $visit = Visit::factory()->create();
        $svc = Service::factory()->create();

        $this->as('receptionist')->post('/invoices', [
            'patient_id' => Patient::factory()->create()->id, 'visit_id' => $visit->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasErrors('visit_id');
    }
}

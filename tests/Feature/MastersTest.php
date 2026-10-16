<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersTest extends TestCase
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

    public function test_only_admin_reaches_masters(): void
    {
        foreach (['departments', 'doctors', 'services'] as $m) {
            $this->as('receptionist')->get("/$m")->assertForbidden();
            $this->as('doctor')->get("/$m/create")->assertForbidden();
            $this->as('admin')->get("/$m")->assertOk();
            $this->as('admin')->get("/$m/create")->assertOk();
        }
    }

    public function test_department_crud_and_unique_name(): void
    {
        $admin = $this->as('admin');
        $admin->post('/departments', ['name' => 'Dental', 'is_active' => 1])->assertRedirect(route('departments.index'));
        $admin->post('/departments', ['name' => 'Dental'])->assertSessionHasErrors('name');

        $d = Department::firstOrFail();
        $admin->put("/departments/{$d->id}", ['name' => 'Dental', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($d->fresh()->is_active);

        $admin->delete("/departments/{$d->id}");
        $this->assertSoftDeleted($d);
    }

    public function test_doctor_validation_and_creation(): void
    {
        $dept = Department::factory()->create();
        $admin = $this->as('admin');

        $admin->post('/doctors', ['name' => '', 'phone' => '123', 'consultation_fee' => -5])
            ->assertSessionHasErrors(['name', 'phone', 'consultation_fee']);
        $admin->post('/doctors', ['name' => 'Dr. A', 'consultation_fee' => '10.555'])->assertSessionHasErrors('consultation_fee');

        $admin->post('/doctors', ['name' => 'Dr. Rao', 'department_id' => $dept->id, 'consultation_fee' => '350.50', 'phone' => '9988776655', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('350.50', Doctor::firstOrFail()->consultation_fee);
    }

    public function test_doctor_login_can_only_link_once(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        Doctor::factory()->create(['user_id' => $login->id]);

        $this->as('admin')->post('/doctors', ['name' => 'Dr. B', 'consultation_fee' => 0, 'user_id' => $login->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_service_validation_defaults_and_uniqueness(): void
    {
        $admin = $this->as('admin');
        $admin->post('/services', ['name' => 'Braces', 'category' => 'procedure', 'default_price' => '30000'])->assertSessionHasNoErrors();

        $s = Service::firstOrFail();
        $this->assertSame('0.00', $s->gst_percent);   // GST defaults to 0
        $this->assertSame('30000.00', $s->default_price);

        $admin->post('/services', ['name' => 'Braces', 'category' => 'procedure', 'default_price' => 1])->assertSessionHasErrors('name');
        $admin->post('/services', ['name' => 'Braces', 'category' => 'lab', 'default_price' => 1])->assertSessionHasNoErrors(); // other category ok
        $admin->post('/services', ['name' => 'X', 'category' => 'nope', 'default_price' => 'abc', 'gst_percent' => 150])
            ->assertSessionHasErrors(['category', 'default_price', 'gst_percent']);
    }

    public function test_master_lists_feed_datatables(): void
    {
        Department::factory()->create();
        Doctor::factory()->create();
        Service::factory()->create();

        foreach (['departments', 'doctors', 'services'] as $m) {
            $this->as('admin')->getJson("/$m", ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('recordsTotal', 1);
        }
    }
}

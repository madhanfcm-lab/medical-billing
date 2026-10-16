<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(Role $role, array $attrs = []): User
    {
        return tap(User::factory()->create($attrs))->assignRole($role->value);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
    }

    public function test_every_role_can_see_the_dashboard(): void
    {
        foreach (Role::cases() as $role) {
            $this->actingAs($this->userWithRole($role))->get('/dashboard')->assertOk()->assertSee('Today');
        }
    }

    public function test_only_admin_can_open_settings_and_users(): void
    {
        foreach ([Role::Receptionist, Role::Doctor] as $role) {
            $u = $this->userWithRole($role);
            $this->actingAs($u)->get('/settings')->assertForbidden();
            $this->actingAs($u)->get('/users')->assertForbidden();
        }

        $admin = $this->userWithRole(Role::Admin);
        $this->actingAs($admin)->get('/settings')->assertOk();
        $this->actingAs($admin)->get('/users')->assertOk();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->userWithRole(Role::Receptionist, ['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_deactivated_mid_session_is_logged_out(): void
    {
        $user = $this->userWithRole(Role::Receptionist);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_doctor_has_no_payment_permissions(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);

        $this->assertFalse($doctor->can('payments.collect'));
        $this->assertFalse($doctor->can('invoices.create'));
        $this->assertTrue($doctor->can('visits.manage'));
    }
}

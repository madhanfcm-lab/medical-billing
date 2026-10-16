<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsAndUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function validSettings(array $over = []): array
    {
        return array_merge([
            'hospital_name' => 'Smile Dental Clinic',
            'invoice_prefix' => 'inv',
            'receipt_prefix' => 'rcp',
            'patient_prefix' => 'pat',
            'hospital_gstin' => '',
        ], $over);
    }

    public function test_settings_are_saved_and_prefixes_uppercased(): void
    {
        $this->actingAs($this->admin)->put('/settings', $this->validSettings())->assertSessionHasNoErrors();

        $this->assertSame('Smile Dental Clinic', setting('hospital_name'));
        $this->assertSame('INV', setting('invoice_prefix'));
    }

    public function test_settings_validation_errors(): void
    {
        $this->actingAs($this->admin)->put('/settings', $this->validSettings([
            'hospital_name' => '', 'receipt_prefix' => 'INV', 'hospital_gstin' => 'BAD',
        ]))->assertSessionHasErrors(['hospital_name', 'receipt_prefix', 'hospital_gstin']);
    }

    public function test_logo_upload_replaces_old_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->put('/settings', $this->validSettings(['hospital_logo' => UploadedFile::fake()->image('a.png')]));
        $first = setting('hospital_logo');
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin)->put('/settings', $this->validSettings(['hospital_logo' => UploadedFile::fake()->image('b.png')]));
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists(setting('hospital_logo'));
    }

    public function test_setting_defaults_apply_when_unset(): void
    {
        $this->assertSame('INV', Setting::get('invoice_prefix'));
    }

    public function test_admin_creates_user_with_role(): void
    {
        $this->actingAs($this->admin)->post('/users', [
            'name' => 'Rita Desk', 'email' => 'rita@clinic.test', 'phone' => '9876543210',
            'role' => 'receptionist', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'is_active' => 1,
            'branches' => [Branch::main()->id],
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'rita@clinic.test')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::Receptionist->value));
        $this->assertNotSame('secret123', $user->password);
    }

    public function test_user_validation(): void
    {
        $this->actingAs($this->admin)->post('/users', [
            'name' => '', 'email' => $this->admin->email, 'phone' => '1234567890',
            'role' => 'janitor', 'password' => 'short', 'password_confirmation' => 'x',
        ])->assertSessionHasErrors(['name', 'email', 'phone', 'role', 'password']);
    }

    public function test_admin_cannot_deactivate_or_demote_self(): void
    {
        $this->actingAs($this->admin)->patch("/users/{$this->admin->id}/toggle")->assertForbidden();

        $this->actingAs($this->admin)->put("/users/{$this->admin->id}", [
            'name' => 'Me', 'email' => $this->admin->email, 'role' => 'doctor', 'is_active' => 0,
        ]);
        $this->admin->refresh();
        $this->assertTrue($this->admin->is_active);
        $this->assertTrue($this->admin->hasRole('admin'));
    }

    public function test_toggle_other_user_and_datatable_feed(): void
    {
        $staff = User::factory()->create()->assignRole('receptionist');

        $this->actingAs($this->admin)->patch("/users/{$staff->id}/toggle");
        $this->assertFalse($staff->fresh()->is_active);

        $this->actingAs($this->admin)->getJson('/users', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonStructure(['data' => [['name', 'role', 'actions']]]);
    }

    public function test_money_helper_uses_indian_grouping(): void
    {
        $this->assertSame('₹1,23,456.50', money(123456.5));
        $this->assertSame('₹30,000.00', money(30000));
        $this->assertSame('₹0.00', money(null));
        $this->assertSame('15-10-2026', format_date('2026-10-15'));
    }
}

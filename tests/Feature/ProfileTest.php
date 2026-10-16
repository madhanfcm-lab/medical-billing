<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole('receptionist');
    }

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($this->staff())->get('/profile')->assertOk();
    }

    public function test_profile_can_be_updated(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->patch('/profile', ['name' => 'New Name', 'email' => 'new@clinic.test'])
            ->assertRedirect('/profile')->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_email_must_be_unique(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->staff())->patch('/profile', ['name' => 'X', 'email' => $other->email])
            ->assertSessionHasErrors('email');
    }
}

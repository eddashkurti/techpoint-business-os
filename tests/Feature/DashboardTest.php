<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->seed(BusinessSeeder::class);
        $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_role_home_routes_to_a_permitted_business_screen(): void
    {
        $this->seed(BusinessSeeder::class);
        foreach (['warehouse' => '/products', 'accountant' => '/bills', 'sales' => '/sales'] as $name => $path) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('name', $name)->firstOrFail());
            $this->actingAs($user)->get('/dashboard')->assertRedirect($path);
            $this->get($path)->assertOk();
        }
    }
}

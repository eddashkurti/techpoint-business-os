<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_is_granted_review_but_not_posting_or_user_administration(): void
    {
        $this->seed(BusinessSeeder::class);
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', 'accountant')->firstOrFail());
        $this->assertTrue(Gate::forUser($u)->allows('accounting.view'));
        foreach (['accounting.post', 'users.manage', 'inventory.manage', 'sales.refund', 'integrations.manage'] as $ability) {
            $this->assertFalse(Gate::forUser($u)->allows($ability));
        }
    }

    public function test_inactive_owner_has_no_permissions(): void
    {
        $this->seed(BusinessSeeder::class);
        $u = User::factory()->create(['active' => false]);
        $u->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->assertFalse(Gate::forUser($u)->allows('users.manage'));
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }
}

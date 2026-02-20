<?php

namespace Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class UserControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function test_index_lists_company_users(): void
    {
        User::factory()->forCompany($this->company)->count(3)->create();

        $this->actingAs($this->user)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/index')
                ->has('users')
            );
    }

    public function test_create_shows_form(): void
    {
        $this->actingAs($this->user)
            ->get('/users/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/create')
            );
    }

    public function test_store_creates_user(): void
    {
        $this->actingAs($this->user)
            ->post('/users', [
                'name' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => 'user',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@test.com',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->user)
            ->post('/users', [])
            ->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_edit_shows_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->get("/users/{$target->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/edit')
                ->has('user')
            );
    }

    public function test_update_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->put("/users/{$target->id}", [
                'name' => 'Updated Name',
                'email' => $target->email,
                'role' => 'user',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_destroy_deletes_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->delete("/users/{$target->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_cannot_manage_users_of_other_company(): void
    {
        [$otherCompany, $otherUser] = $this->createOtherTenant();

        $this->actingAs($this->user)
            ->get("/users/{$otherUser->id}/edit")
            ->assertForbidden();
    }
}

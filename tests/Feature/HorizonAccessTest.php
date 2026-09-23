<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_forbidden_in_local_environment(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/horizon');

        $response->assertForbidden();
    }

    public function test_guest_is_forbidden(): void
    {
        $response = $this->get('/horizon');

        $response->assertForbidden();
    }

    public function test_authenticated_user_sees_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/horizon');

        $response->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Login is rate-limited: 5 failed attempts per email + address a minute, and a
 * per-address ceiling across emails. A success clears the email's count.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['email' => 'ana@example.org', 'password' => Hash::make('right'), 'is_active' => true]);
    }

    private function login(string $password, string $email = 'ana@example.org', string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    public function test_five_failures_then_a_wait_even_with_the_right_password(): void
    {
        foreach (range(1, 5) as $_) {
            $this->login('wrong')->assertStatus(401);
        }

        $this->login('right')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('success', false);
    }

    public function test_the_owner_is_not_locked_out_from_another_address(): void
    {
        foreach (range(1, 5) as $_) {
            $this->login('wrong', ip: '10.0.0.66');
        }

        $this->login('right', ip: '10.0.0.1')->assertOk();
    }

    public function test_a_successful_login_clears_the_count(): void
    {
        foreach (range(1, 4) as $_) {
            $this->login('wrong');
        }
        $this->login('right')->assertOk();

        foreach (range(1, 4) as $_) {
            $this->login('wrong')->assertStatus(401);
        }
    }

    public function test_one_address_cannot_sweep_many_emails(): void
    {
        foreach (range(1, 20) as $i) {
            $this->login('wrong', "user{$i}@example.org")->assertStatus(401);
        }

        $this->login('wrong', 'user21@example.org')->assertStatus(429);
    }
}

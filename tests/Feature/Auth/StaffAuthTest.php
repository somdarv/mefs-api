<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('staff-login:mef@mefs.local|127.0.0.1');
    }

    public function test_staff_can_sign_in_with_email(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'mef@mefs.local',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/v1/staff/login', [
            'login' => 'mef@mefs.local',
            'password' => 'correct-horse',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['token', 'user' => ['permissions']]]);
    }

    public function test_staff_can_sign_in_with_phone(): void
    {
        User::factory()->admin()->create([
            'phone' => '0244000111',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/v1/staff/login', [
            'login' => '0244000111',
            'password' => 'correct-horse',
        ])->assertOk();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create([
            'email' => 'mef@mefs.local',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/v1/staff/login', [
            'login' => 'mef@mefs.local',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['login']]);
    }

    /**
     * The response for "no such account" must be identical to "wrong password", or the
     * endpoint becomes a way to enumerate which staff accounts exist.
     */
    public function test_an_unknown_login_is_indistinguishable_from_a_wrong_password(): void
    {
        User::factory()->admin()->create([
            'email' => 'mef@mefs.local',
            'password' => Hash::make('correct-horse'),
        ]);

        $wrongPassword = $this->postJson('/api/v1/staff/login', [
            'login' => 'mef@mefs.local', 'password' => 'wrong',
        ]);

        $noSuchUser = $this->postJson('/api/v1/staff/login', [
            'login' => 'nobody@mefs.local', 'password' => 'wrong',
        ]);

        $this->assertSame($wrongPassword->status(), $noSuchUser->status());
        $this->assertSame(
            $wrongPassword->json('errors.login'),
            $noSuchUser->json('errors.login'),
        );
    }

    /**
     * ⚠️ BRIEF LAW 3. A customer must not obtain a staff token even if they somehow hold a
     * password — the softer login path must never be a way onto the staff surface.
     */
    public function test_a_customer_cannot_use_the_staff_login(): void
    {
        User::factory()->create([
            'email' => 'buyer@example.com',
            'password' => Hash::make('correct-horse'),
        ])->assignRole('customer');

        $this->postJson('/api/v1/staff/login', [
            'login' => 'buyer@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(422);
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->admin()->create([
            'email' => 'mef@mefs.local',
            'password' => Hash::make('correct-horse'),
        ]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/staff/login', [
                'login' => 'mef@mefs.local', 'password' => 'wrong',
            ])->assertStatus(422);
        }

        // The sixth is refused even with the CORRECT password — otherwise throttling only
        // slows a guesser down rather than stopping them.
        //
        // Asserted on the wording rather than the exact seconds: the countdown ticks during
        // the test, so pinning "60" makes this fail whenever the run crosses a second
        // boundary. A test that fails on timing teaches people to re-run rather than read.
        $response = $this->postJson('/api/v1/staff/login', [
            'login' => 'mef@mefs.local', 'password' => 'correct-horse',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'Too many attempts.',
            $response->json('errors.login.0'),
        );
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->admin()->create();

        $phone = $user->createToken('phone', ['staff'])->plainTextToken;
        $laptop = $user->createToken('laptop', ['staff'])->plainTextToken;

        $this->withToken($laptop)->postJson('/api/v1/staff/logout')->assertOk();

        // Signing out on the laptop must not sign her out on the phone in her hand.
        $this->forgetAuth()->withToken($phone)->getJson('/api/v1/staff/me')->assertOk();
        $this->forgetAuth()->withToken($laptop)->getJson('/api/v1/staff/me')->assertUnauthorized();
    }
}

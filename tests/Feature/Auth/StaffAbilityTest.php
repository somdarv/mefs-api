<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brief Law 3 and trap §10.1 — the wall between the two login paths, and a middleware that
 * fails CLOSED.
 */
final class StaffAbilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * ⚠️ THE GATE (brief Law 3).
     *
     * One `users` table means a staff member who also orders lunch is one row. A token
     * minted by the customer path satisfies `auth:sanctum` perfectly well — it must not
     * satisfy the staff middleware. Without the ability check, the softer login path is a
     * privilege escalation.
     */
    public function test_a_customer_token_is_rejected_by_a_staff_route(): void
    {
        $user = User::factory()->customer()->create();

        // A customer OTP token: authenticated, no `staff` ability.
        $token = $user->createToken('customer', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/staff/me')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action requires a staff account.');
    }

    /**
     * The harder version of the same test: a user who genuinely IS staff, but whose token
     * came from the customer path. The role is right; the token is not. It must still fail,
     * because otherwise the ability check is decorative for exactly the people it protects.
     */
    public function test_a_staff_user_holding_a_customer_token_is_still_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $token = $user->createToken('customer-path', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/staff/me')->assertForbidden();
    }

    /**
     * ⚠️ THE DANGEROUS DEFAULT.
     *
     * `createToken($name)` with no abilities argument yields `['*']`, and Sanctum's
     * `$token->can()` treats `*` as every ability — so the obvious implementation of this
     * middleware hands staff access to any token minted without thinking. The check is an
     * explicit membership test for that reason.
     */
    public function test_a_wildcard_token_does_not_count_as_staff(): void
    {
        $user = User::factory()->admin()->create();

        // No abilities argument — this is what a careless call site produces.
        $token = $user->createToken('careless')->plainTextToken;

        $this->assertSame(['*'], $user->tokens()->first()->abilities);

        $this->withToken($token)
            ->getJson('/api/v1/staff/me')
            ->assertForbidden();
    }

    public function test_a_staff_token_is_accepted(): void
    {
        $user = User::factory()->admin()->create();

        $this->withToken($user->createToken('staff', ['staff'])->plainTextToken)
            ->getJson('/api/v1/staff/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/staff/me')->assertUnauthorized();
    }

    /**
     * A role revoked after a token was minted takes effect immediately, not at expiry.
     * The token still says `staff`; the durable fact is the role, and it no longer agrees.
     */
    public function test_revoking_the_role_invalidates_an_existing_staff_token(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('staff', ['staff'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/staff/me')->assertOk();

        $user->syncRoles(['customer']);

        // Without forgetAuth() the guard hands back the user it resolved a moment ago and
        // this assertion passes for the wrong reason — see Tests\TestCase::forgetAuth().
        $this->forgetAuth()
            ->withToken($token)
            ->getJson('/api/v1/staff/me')
            ->assertForbidden();
    }
}

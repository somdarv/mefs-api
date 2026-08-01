<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Banner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront's promotional strip.
 *
 * ⚠️ THE TWO THAT MATTER ARE THE SCHEDULING ONE AND THE LINK ONE. Everything else here is
 * CRUD.
 */
final class BannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));
    }

    private function asStaff(): static
    {
        $user = User::query()->where('email', 'mef@mefs.local')->firstOrFail();

        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    private function banner(array $attributes = []): Banner
    {
        return Banner::query()->create(array_merge([
            'title' => 'Jollof base is here',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * ⚠️ A SCHEDULED BANNER IS NOT PUBLIC BEFORE IT STARTS.
     *
     * Filtering by `is_active` alone and letting the client hide the rest would put
     * "launching Friday" in a Tuesday network tab — which is exactly the kind of leak that
     * looks like nothing until it is somebody's announcement.
     */
    public function test_a_banner_scheduled_for_later_is_not_readable_yet(): void
    {
        $this->banner(['title' => 'Live now']);
        $this->banner(['title' => 'Launching Friday', 'starts_at' => '2026-08-07T00:00:00Z']);
        $this->banner(['title' => 'Finished', 'ends_at' => '2026-08-01T00:00:00Z']);
        $this->banner(['title' => 'Switched off', 'is_active' => false]);

        $titles = collect($this->getJson('/api/v1/banners')->assertOk()->json('data.banners'))
            ->pluck('title')
            ->all();

        $this->assertSame(['Live now'], $titles);
    }

    /** The editor sees everything, or a scheduled banner vanishes the moment it is saved. */
    public function test_the_editor_sees_banners_that_are_not_showing(): void
    {
        $this->banner(['title' => 'Launching Friday', 'starts_at' => '2026-08-07T00:00:00Z']);

        $response = $this->asStaff()->getJson('/api/v1/admin/banners')->assertOk();

        $this->assertCount(1, $response->json('data.banners'));
        $this->assertFalse($response->json('data.banners.0.is_live'));
        $this->assertTrue($response->json('data.banners.0.is_active'));
    }

    /**
     * ⚠️ RELATIVE LINKS ONLY.
     *
     * An absolute URL here is an open redirect aimed at customers, authored by someone
     * holding a content permission rather than a security one.
     */
    public function test_an_offsite_link_is_refused(): void
    {
        $this->asStaff()
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Free money',
                'link_url' => 'https://example.test/phish',
                'link_label' => 'Claim',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_url');

        $this->asStaff()
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Pantry',
                'link_url' => '/menu/jollof-base',
                'link_label' => 'Shop',
            ])
            ->assertCreated();
    }

    /** Ties break on id, so the strip cannot shuffle itself between requests. */
    public function test_banners_come_back_in_a_total_order(): void
    {
        $second = $this->banner(['title' => 'B', 'position' => 0]);
        $first = $this->banner(['title' => 'A', 'position' => 0]);
        $first->update(['position' => -1]);

        $titles = collect($this->getJson('/api/v1/banners')->json('data.banners'))->pluck('title')->all();

        $this->assertSame(['A', 'B'], $titles);
        $this->assertSame($second->id, Banner::query()->orderByDesc('position')->value('id'));
    }

    public function test_a_banner_is_deleted_outright(): void
    {
        $banner = $this->banner();

        $this->asStaff()->deleteJson("/api/v1/admin/banners/{$banner->id}")->assertOk();

        $this->assertNull($banner->fresh());
    }

    /** The public endpoint is unauthenticated; the editor is not. */
    public function test_the_editor_needs_a_staff_token(): void
    {
        $this->getJson('/api/v1/banners')->assertOk();
        $this->getJson('/api/v1/admin/banners')->assertUnauthorized();
    }
}

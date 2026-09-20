<?php

namespace Tests\Feature;

use App\Jobs\GenerateSocialDraft;
use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\SocialSetting;
use App\Models\User;
use App\Support\Social\SocialCard;
use App\Support\Social\SocialGexSource;
use App\Support\Social\SocialText;
use App\Support\Social\SocialWorkflow;
use App\Support\Social\XPublisher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SocialWorkflowTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.social_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'social.admin_ids' => [3], 'social.publishing_enabled' => false, 'social.schedule_enabled' => false]);
        DB::purge('social_test');
        DB::setDefaultConnection('social_test');
        (require database_path('migrations/2026_09_20_100000_create_social_posts_tables.php'))->up();
        Storage::fake('local');
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 08:45', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge('social_test');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function snapshot(): array
    {
        return ['symbol' => 'SPY', 'data_date' => '2026-09-18', 'timeframe' => '14d', 'hvl' => 700,
            'social_quality' => ['publishable' => true, 'missing_input_rows' => 0],
            'expiration_dates' => ['2026-09-21', '2026-09-25'],
            'strike_data' => [['strike' => 690, 'net_gex' => -30e9], ['strike' => 700, 'net_gex' => 10e9], ['strike' => 710, 'net_gex' => 50e9]]];
    }

    private function workflow(?XPublisher $x = null): SocialWorkflow
    {
        $source = Mockery::mock(SocialGexSource::class);
        $source->shouldReceive('capture')->andReturn($this->snapshot());

        return new SocialWorkflow($source, new SocialCard, $x ?? new XPublisher);
    }

    public function test_summary_uses_all_strikes_and_preserves_missing_sides(): void
    {
        $peaks = SocialCard::peaks($this->snapshot());
        $this->assertEquals(30e9, $peaks['total']);
        $this->assertEquals(50e9, $peaks['positive_value']);
        $this->assertEquals(-30e9, $peaks['negative_value']);
        $this->assertSame('+30B', SocialCard::exposure($peaks['total']));
        $this->assertSame('0', SocialCard::exposure(0));
        $this->assertSame('Unavailable', SocialCard::exposure(null));
        $negative = SocialCard::peaks(['strike_data' => [['strike' => 690, 'net_gex' => -30e9]]]);
        $this->assertNull($negative['positive_value']);
        $this->assertNull($negative['positive']);
    }

    public function test_missing_quality_result_cannot_be_approved(): void
    {
        $workflow = $this->workflow();
        $post = $workflow->generate('2026-09-21', 'primary');
        $snapshot = $post->snapshot;
        unset($snapshot['social_quality']);
        $post->update(['snapshot' => $snapshot]);
        $this->expectException(DomainException::class);
        $workflow->approve($post, 3);
    }

    public function test_previous_completed_session_uses_weekends_and_holidays(): void
    {
        $this->assertSame('2026-09-18', SocialGexSource::expectedDate('2026-09-21'));
        $this->assertSame('2026-09-04', SocialGexSource::expectedDate('2026-09-08'));
        $this->assertSame('2026-04-02', SocialGexSource::expectedDate('2026-04-06'));
    }

    public function test_draft_has_real_png_immutable_source_and_no_network_writes(): void
    {
        $post = $this->workflow()->generate('2026-09-21', 'primary');
        $this->assertSame('draft', $post->status);
        $this->assertEquals($this->snapshot(), $post->snapshot);
        $bytes = base64_decode($post->image_base64, true);
        $this->assertArrayNotHasKey('image_base64', $post->toArray());
        $this->assertSame([1600, 1000], array_slice(getimagesizefromstring($bytes), 0, 2));
        $this->assertSame(hash('sha256', $bytes), $post->image_sha256);
        $this->assertLessThanOrEqual(280, SocialText::length($post->body));
        $this->assertStringContainsString('Positive peak: 710', $post->body);
        Http::assertNothingSent();
    }

    public function test_missing_source_blocks_and_does_not_invent_an_image(): void
    {
        $source = Mockery::mock(SocialGexSource::class);
        $source->shouldReceive('capture')->andThrow(new DomainException('Missing snapshot.'));
        $post = (new SocialWorkflow($source, new SocialCard, new XPublisher))->generate('2026-09-21', 'primary');
        $this->assertSame('blocked', $post->status);
        $this->assertNull($post->image_path);
        $this->assertNull($post->body);
    }

    public function test_approval_is_removed_by_edits_and_regeneration_cannot_replace_approved_content(): void
    {
        $workflow = $this->workflow();
        $post = $workflow->generate('2026-09-21', 'primary');
        $workflow->approve($post, 3);
        $hash = $post->refresh()->image_sha256;
        $this->assertSame($hash, $workflow->generate('2026-09-21', 'primary')->image_sha256);
        $workflow->edit($post, 'Updated $SPY reading #GEX', 'Updated description');
        $this->assertSame('draft', $post->refresh()->status);
        $this->assertNull($post->approved_at);
        $this->assertSame(1, SocialPost::count());
    }

    public function test_publishing_is_disabled_by_default_even_after_approval(): void
    {
        $workflow = $this->workflow();
        $post = $workflow->generate('2026-09-21', 'primary');
        $workflow->approve($post, 3);
        $this->expectException(DomainException::class);
        try {
            $workflow->publish($post->refresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_successful_publish_is_not_repeated(): void
    {
        config(['social.publishing_enabled' => true]);
        $x = Mockery::mock(XPublisher::class);
        $x->shouldReceive('verifyAccount')->once()->andReturn('GexOptions');
        $x->shouldReceive('upload')->once()->andReturn('123');
        $x->shouldReceive('publish')->once()->andReturn('456');
        $workflow = $this->workflow($x);
        $post = $workflow->generate('2026-09-21', 'primary');
        $workflow->approve($post, 3);
        $workflow->publish($post->refresh());
        $workflow->publish($post->refresh());
        $this->assertSame('published', $post->refresh()->status);
        $this->assertSame('456', $post->x_post_id);
    }

    public function test_ambiguous_submission_is_held_for_manual_review_and_never_retried(): void
    {
        config(['social.publishing_enabled' => true]);
        $x = Mockery::mock(XPublisher::class);
        $x->shouldReceive('verifyAccount')->once()->andReturn('GexOptions');
        $x->shouldReceive('upload')->once()->andReturn('123');
        $x->shouldReceive('publish')->once()->andThrow(new \RuntimeException('timeout'));
        $workflow = $this->workflow($x);
        $post = $workflow->generate('2026-09-21', 'primary');
        $workflow->approve($post, 3);
        $workflow->publish($post->refresh());
        $workflow->publish($post->refresh());
        $this->assertSame('needs_review', $post->refresh()->status);
        $this->assertSame('needs_review', $workflow->generate('2026-09-21', 'primary')->status);
    }

    public function test_stale_session_and_late_jobs_cannot_publish(): void
    {
        config(['social.publishing_enabled' => true]);
        $workflow = $this->workflow();
        $post = $workflow->generate('2026-09-21', 'primary');
        $workflow->approve($post, 3);
        foreach (['2026-09-21 09:30', '2026-09-22 08:45', '2026-09-21 08:44'] as $time) {
            try {
                $workflow->publish($post->refresh(), CarbonImmutable::parse($time, 'America/New_York'));
                $this->fail('Should reject time');
            } catch (DomainException) {
                $this->assertSame('approved', $post->refresh()->status);
            }
        }
        Http::assertNothingSent();
    }

    public function test_tampered_image_cannot_be_approved(): void
    {
        $workflow = $this->workflow();
        $post = $workflow->generate('2026-09-21', 'primary');
        $post->update(['image_base64' => base64_encode('changed')]);
        $this->expectException(DomainException::class);
        $workflow->approve($post, 3);
    }

    public function test_scheduler_holidays_pausing_and_default_draft_only_mode(): void
    {
        Queue::fake();
        config(['social.schedule_enabled' => true]);
        $this->artisan('social:tick')->assertSuccessful();
        Queue::assertPushed(GenerateSocialDraft::class, 2);
        Queue::assertNotPushed(PublishSocialPost::class);
        Queue::fake();
        SocialSetting::current()->update(['paused' => true]);
        $this->artisan('social:tick')->assertSuccessful();
        Queue::assertNothingPushed();
        SocialSetting::current()->update(['paused' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 08:45', 'America/New_York'));
        $this->artisan('social:tick')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_subscriber_cannot_access_any_social_endpoint(): void
    {
        $user = new User;
        $user->id = 99;
        $user->setRelation('subscriptions', collect());
        $this->actingAs($user);
        $this->workflow()->generate('2026-09-21', 'primary');
        foreach (['/admin/social', '/admin/social/1/image', '/admin/social/1/snapshot'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/admin/social/generate', '/admin/social/verify', '/admin/social/1/approve', '/admin/social/1/publish'] as $url) {
            $this->post($url)->assertForbidden();
        }
        $this->put('/admin/social/settings')->assertForbidden();
        $this->put('/admin/social/1')->assertForbidden();
    }

    public function test_oauth_client_checks_account_and_attaches_uploaded_media(): void
    {
        config(['social.x.consumer_key' => 'key', 'social.x.consumer_secret' => 'secret', 'social.x.access_token' => 'token', 'social.x.access_secret' => 'token-secret']);
        Http::fake([
            'api.x.com/2/users/me' => Http::response(['data' => ['username' => 'GexOptions']]),
            'upload.twitter.com/1.1/media/upload.json' => Http::response(['media_id_string' => '123']),
            'upload.twitter.com/1.1/media/metadata/create.json' => Http::response([], 204),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '456']], 201),
        ]);
        $x = new XPublisher;
        $this->assertSame('GexOptions', $x->verifyAccount());
        $this->assertSame('123', $x->upload('png', 'Chart description'));
        $this->assertSame('456', $x->publish('Draft text', '123'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media']['media_ids'] === ['123'] && str_starts_with($r->header('Authorization')[0], 'OAuth '));
        Http::assertSentCount(4);
    }

    public function test_partial_input_preview_is_downloadable_but_not_approvable(): void
    {
        $source = Mockery::mock(SocialGexSource::class);
        $source->shouldReceive('capture')->andReturn([...$this->snapshot(), 'social_quality' => ['publishable' => false, 'missing_input_rows' => 2]]);
        $workflow = new SocialWorkflow($source, new SocialCard, new XPublisher);
        $post = $workflow->generate('2026-09-21', 'primary');
        $this->assertSame('blocked', $post->status);
        $this->assertNotNull($post->image_path);
        $this->assertStringContainsString('review only', $post->alt_text);
        $this->expectException(DomainException::class);
        $workflow->approve($post, 3);
    }

    public function test_source_rejects_mixed_expiry_dates_before_calculating(): void
    {
        $universe = Mockery::mock(\App\Support\GexExpirationUniverse::class);
        $universe->shouldReceive('resolve')->andReturn(['expiration_ids' => [1, 2], 'timeframe_expirations' => ['14d' => ['2026-09-21', '2026-09-25']]]);
        $selector = Mockery::mock(\App\Support\EodSnapshotSelector::class);
        $selector->shouldReceive('selectedDateRows')->andReturn(collect([(object) ['expiration_id' => 1, 'max_date' => '2026-09-18'], (object) ['expiration_id' => 2, 'max_date' => '2026-09-17']]));
        $this->app->instance(\App\Support\GexExpirationUniverse::class, $universe);
        $this->app->instance(\App\Support\EodSnapshotSelector::class, $selector);
        $this->expectException(DomainException::class);
        (new SocialGexSource)->capture('SPY', '2026-09-21');
    }

    public function test_admin_can_preview_download_and_approve_but_disabled_publish_is_rejected(): void
    {
        $post = $this->workflow()->generate('2026-09-21', 'primary');
        $user = new User(['name' => 'Review Admin', 'email' => 'admin@example.test']);
        $user->id = 3;
        $user->setRelation('subscriptions', collect());
        $this->actingAs($user);
        $this->get('/admin/social')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/social/'.$post->id.'/image?download=1')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/admin/social/'.$post->id.'/snapshot')->assertOk()->assertJsonPath('symbol', 'SPY');
        $this->post('/admin/social/'.$post->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $post->refresh()->status);
        $this->post('/admin/social/'.$post->id.'/publish')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_changing_second_symbol_revokes_existing_approval(): void
    {
        $post = $this->workflow()->generate('2026-09-21', 'secondary');
        $this->workflow()->approve($post, 3);
        $user = new User;
        $user->id = 3;
        $user->setRelation('subscriptions', collect());
        $this->actingAs($user)->put('/admin/social/settings', ['second_symbol' => 'TSLA', 'paused' => false])->assertRedirect();
        $this->assertSame('TSLA', SocialSetting::current()->second_symbol);
        $this->assertSame('blocked', $post->refresh()->status);
        $this->assertNull($post->approved_at);
    }
}

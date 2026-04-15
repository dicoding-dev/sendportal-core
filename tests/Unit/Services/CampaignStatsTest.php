<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStat;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Pipelines\Campaigns\CreateMessages;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;
use Sendportal\Base\Services\Messages\MarkAsSent;
use Sendportal\Base\Services\Webhooks\EmailWebhookService;
use Tests\TestCase;

class CampaignStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function create_messages_pipeline_creates_stat_row_and_increments_total_pending()
    {
        $campaign = $this->createSendToAllCampaign();
        $subscribers = Subscriber::factory()->count(3)->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
        ]);

        $pipeline = app(CreateMessages::class);
        $pipeline->handle($campaign, fn ($c) => $c);

        $stat = CampaignStat::find($campaign->id);
        static::assertNotNull($stat);
        static::assertEquals(3, $stat->total);
        static::assertEquals(3, $stat->pending);
        static::assertEquals(0, $stat->sent);
    }

    /** @test */
    public function create_messages_does_not_double_count_on_redispatch()
    {
        $campaign = $this->createSendToAllCampaign();
        Subscriber::factory()->count(2)->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
        ]);

        $pipeline = app(CreateMessages::class);
        $pipeline->handle($campaign, fn ($c) => $c);

        // Run again (simulating re-dispatch)
        $pipeline2 = app(CreateMessages::class);
        $pipeline2->handle($campaign, fn ($c) => $c);

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(2, $stat->total);
        static::assertEquals(2, $stat->pending);
    }

    /** @test */
    public function mark_as_sent_increments_sent_and_decrements_pending()
    {
        $campaign = Campaign::factory()->withContent()->sending()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        CampaignStat::create([
            'campaign_id' => $campaign->id,
            'total' => 1,
            'pending' => 1,
        ]);

        $message = Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'message_id' => null,
            'sent_at' => null,
        ]);

        app(MarkAsSent::class)->handle($message, 'test-message-id-123');

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(1, $stat->sent);
        static::assertEquals(0, $stat->pending);
    }

    /** @test */
    public function mark_as_sent_does_not_update_stats_for_non_campaign_messages()
    {
        $campaign = Campaign::factory()->withContent()->sending()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        CampaignStat::create([
            'campaign_id' => $campaign->id,
            'total' => 1,
            'pending' => 1,
        ]);

        $message = Message::factory()->create([
            'source_type' => 'SomeOtherType',
            'source_id' => $campaign->id,
            'message_id' => null,
            'sent_at' => null,
        ]);

        app(MarkAsSent::class)->handle($message, 'test-message-id-456');

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(0, $stat->sent);
        static::assertEquals(1, $stat->pending);
    }

    /** @test */
    public function delete_draft_messages_decrements_total_and_pending()
    {
        $emailService = EmailService::factory()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
        ]);

        $campaign = Campaign::factory()->withContent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => $emailService->id,
            'status_id' => CampaignStatus::STATUS_SENDING,
            'save_as_draft' => true,
        ]);

        Message::factory()->count(3)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => null,
        ]);

        Message::factory()->count(2)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
        ]);

        CampaignStat::create([
            'campaign_id' => $campaign->id,
            'total' => 5,
            'sent' => 2,
            'pending' => 3,
        ]);

        $repo = app(CampaignTenantRepositoryInterface::class);
        $repo->cancelCampaign($campaign);

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(2, $stat->total);
        static::assertEquals(0, $stat->pending);
        static::assertEquals(2, $stat->sent);
    }

    /** @test */
    public function backfill_command_populates_stats_from_messages()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        Message::factory()->count(3)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
            'opened_at' => now(),
        ]);

        Message::factory()->count(2)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
            'bounced_at' => now(),
        ]);

        Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => null,
        ]);

        $this->artisan('sp:stats:backfill')
            ->assertSuccessful();

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(6, $stat->total);
        static::assertEquals(5, $stat->sent);
        static::assertEquals(3, $stat->opened);
        static::assertEquals(2, $stat->bounced);
        static::assertEquals(1, $stat->pending);
    }

    /** @test */
    public function backfill_command_is_idempotent()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        Message::factory()->count(2)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
        ]);

        $this->artisan('sp:stats:backfill')->assertSuccessful();
        $this->artisan('sp:stats:backfill')->assertSuccessful();

        static::assertEquals(1, CampaignStat::count());
        static::assertEquals(2, CampaignStat::find($campaign->id)->sent);
    }

    /** @test */
    public function digest_command_recalculates_stats_for_dirty_campaigns()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        CampaignStat::create(['campaign_id' => $campaign->id, 'total' => 3, 'sent' => 3]);

        Message::factory()->count(3)->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
            'opened_at' => now(),
        ]);

        // Simulate dirty set populated by webhook handlers
        Redis::shouldReceive('rename')
            ->once()
            ->with('sp:stats:dirty', 'sp:stats:dirty:processing')
            ->andReturnTrue();
        Redis::shouldReceive('smembers')
            ->once()
            ->with('sp:stats:dirty:processing')
            ->andReturn([(string) $campaign->id]);
        Redis::shouldReceive('del')
            ->once()
            ->with('sp:stats:dirty:processing')
            ->andReturn(1);

        $this->artisan('sp:stats:digest')
            ->assertSuccessful();

        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(3, $stat->opened);
    }

    /** @test */
    public function digest_command_freezes_old_campaigns()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
            'updated_at' => now()->subDays(60),
        ]);

        CampaignStat::create(['campaign_id' => $campaign->id, 'total' => 1, 'sent' => 1]);

        // No dirty campaigns
        Redis::shouldReceive('rename')->once()->andThrow(new \Exception('no key'));

        $this->artisan('sp:stats:digest', ['--freeze-after' => 45])
            ->assertSuccessful();

        $stat = CampaignStat::find($campaign->id);
        static::assertNotNull($stat->stats_frozen_at);
    }

    /** @test */
    public function digest_command_skips_frozen_campaigns()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        CampaignStat::create([
            'campaign_id' => $campaign->id,
            'total' => 1,
            'sent' => 1,
            'opened' => 0,
            'stats_frozen_at' => now()->subDay(),
        ]);

        Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
            'opened_at' => now(),
        ]);

        // Dirty set contains the frozen campaign
        Redis::shouldReceive('rename')->once()->andReturnTrue();
        Redis::shouldReceive('smembers')->once()->andReturn([(string) $campaign->id]);
        Redis::shouldReceive('del')->once()->andReturn(1);

        $this->artisan('sp:stats:digest')->assertSuccessful();

        // opened should remain 0 because campaign is frozen
        $stat = CampaignStat::find($campaign->id);
        static::assertEquals(0, $stat->opened);
    }

    /** @test */
    public function webhook_open_adds_campaign_to_redis_dirty_set()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        $message = Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'message_id' => 'test-msg-open',
            'sent_at' => now(),
        ]);

        $service = app(EmailWebhookService::class);
        $service->handleOpen('test-msg-open', now(), '127.0.0.1');

        Redis::assertCalled('sadd', function ($command, $params) use ($campaign) {
            return $params[0] === 'sp:stats:dirty' && in_array($campaign->id, array_slice($params, 1));
        });
    }

    /** @test */
    public function webhook_click_adds_campaign_to_redis_dirty_set()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        $message = Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'message_id' => 'test-msg-click',
            'sent_at' => now(),
        ]);

        $service = app(EmailWebhookService::class);
        $service->handleClick('test-msg-click', now(), 'https://example.com');

        Redis::assertCalled('sadd', function ($command, $params) use ($campaign) {
            return $params[0] === 'sp:stats:dirty' && in_array($campaign->id, array_slice($params, 1));
        });
    }

    /** @test */
    public function webhook_bounce_adds_campaign_to_redis_dirty_set()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        $message = Message::factory()->create([
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'message_id' => 'test-msg-bounce',
            'sent_at' => now(),
        ]);

        $service = app(EmailWebhookService::class);
        $service->handlePermanentBounce('test-msg-bounce', now());

        Redis::assertCalled('sadd', function ($command, $params) use ($campaign) {
            return $params[0] === 'sp:stats:dirty' && in_array($campaign->id, array_slice($params, 1));
        });
    }

    /** @test */
    public function get_counts_returns_zero_defaults_for_campaigns_without_stats()
    {
        $campaign = Campaign::factory()->withContent()->sent()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
        ]);

        $repo = app(CampaignTenantRepositoryInterface::class);
        $counts = $repo->getCounts(collect($campaign->id), Sendportal::currentWorkspaceId());

        static::assertEquals(0, $counts[$campaign->id]->total);
        static::assertEquals(0, $counts[$campaign->id]->sent);
        static::assertEquals(0, $counts[$campaign->id]->opened);
        static::assertEquals(0, $counts[$campaign->id]->clicked);
        static::assertEquals(0, $counts[$campaign->id]->bounced);
        static::assertEquals(0, $counts[$campaign->id]->pending);
    }

    private function createSendToAllCampaign(): Campaign
    {
        return Campaign::factory()->withContent()->sending()->create([
            'workspace_id' => Sendportal::currentWorkspaceId(),
            'email_service_id' => EmailService::factory(),
            'send_to_all' => true,
            'save_as_draft' => false,
        ]);
    }
}

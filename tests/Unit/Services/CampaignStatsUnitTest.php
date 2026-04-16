<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Services\Webhooks\EmailWebhookService;

/**
 * Pure unit tests for webhook message-mutation logic.
 * No database, no Redis connection.
 *
 * Uses a testable subclass that overrides query/persistence methods,
 * and a simple FakeMessage to avoid Eloquent/DB dependencies.
 */
class CampaignStatsUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        // Bind a mock so Redis facade resolves, then swap to absorb sadd calls
        $redisMock = Mockery::mock();
        $redisMock->shouldReceive('sadd')->byDefault();
        Redis::swap($redisMock);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Mockery::close();
        Container::setInstance(null);
        parent::tearDown();
    }

    // -- handleOpen --

    #[Test]
    public function open_sets_opened_at_and_ip_when_null(): void
    {
        $ts = Carbon::parse('2026-04-15 10:00:00');
        $msg = new FakeMessage();

        $this->service($msg)->handleOpen('x', $ts, '1.2.3.4');

        static::assertEquals($ts, $msg->opened_at);
        static::assertEquals('1.2.3.4', $msg->ip);
    }

    #[Test]
    public function open_preserves_existing_opened_at_and_ip(): void
    {
        $original = Carbon::parse('2026-01-01 12:00:00');
        $msg = new FakeMessage(opened_at: $original);
        $msg->ip = '9.9.9.9';

        $this->service($msg)->handleOpen('x', Carbon::now(), '1.2.3.4');

        static::assertEquals($original, $msg->opened_at);
        static::assertEquals('9.9.9.9', $msg->ip);
    }

    #[Test]
    public function open_always_increments_open_count(): void
    {
        $msg = new FakeMessage(opened_at: Carbon::now());
        $msg->open_count = 5;

        $this->service($msg)->handleOpen('x', Carbon::now(), '1.2.3.4');

        static::assertEquals(6, $msg->open_count);
    }

    #[Test]
    public function open_returns_early_when_message_not_found(): void
    {
        $this->service(null)->handleOpen('x', Carbon::now(), '1.2.3.4');

        $this->expectNotToPerformAssertions();
    }

    // -- handleClick --

    #[Test]
    public function click_sets_clicked_at_when_null(): void
    {
        $ts = Carbon::parse('2026-04-15 11:00:00');
        $msg = new FakeMessage();

        $this->service($msg)->handleClick('x', $ts, 'https://example.com');

        static::assertEquals($ts, $msg->clicked_at);
    }

    #[Test]
    public function click_preserves_existing_clicked_at(): void
    {
        $original = Carbon::parse('2026-01-01');
        $msg = new FakeMessage(clicked_at: $original, opened_at: Carbon::now());

        $this->service($msg)->handleClick('x', Carbon::now(), 'https://example.com');

        static::assertEquals($original, $msg->clicked_at);
    }

    #[Test]
    public function click_implies_open_when_not_opened(): void
    {
        $ts = Carbon::parse('2026-04-15 11:00:00');
        $msg = new FakeMessage();

        $this->service($msg)->handleClick('x', $ts, 'https://example.com');

        static::assertEquals($ts, $msg->opened_at);
        static::assertEquals(1, $msg->open_count);
    }

    #[Test]
    public function click_does_not_set_open_when_already_opened(): void
    {
        $originalOpen = Carbon::parse('2026-01-01');
        $msg = new FakeMessage(opened_at: $originalOpen);
        $msg->open_count = 3;

        $this->service($msg)->handleClick('x', Carbon::now(), 'https://example.com');

        static::assertEquals($originalOpen, $msg->opened_at);
        static::assertEquals(3, $msg->open_count);
    }

    #[Test]
    public function click_always_increments_click_count(): void
    {
        $msg = new FakeMessage(opened_at: Carbon::now(), clicked_at: Carbon::now());
        $msg->click_count = 7;

        $this->service($msg)->handleClick('x', Carbon::now(), 'https://example.com');

        static::assertEquals(8, $msg->click_count);
    }

    #[Test]
    public function click_skips_unsubscribe_urls(): void
    {
        $msg = new FakeMessage();

        $this->service($msg)->handleClick('x', Carbon::now(), 'https://app.test/subscriptions/unsubscribe/abc');

        static::assertNull($msg->clicked_at);
        static::assertFalse($msg->saved);
    }

    // -- handlePermanentBounce --

    #[Test]
    public function bounce_sets_bounced_at_when_null(): void
    {
        $ts = Carbon::parse('2026-04-15 12:00:00');
        $msg = new FakeMessage();

        $this->service($msg)->handlePermanentBounce('x', $ts);

        static::assertEquals($ts, $msg->bounced_at);
    }

    #[Test]
    public function bounce_preserves_existing_bounced_at(): void
    {
        $original = Carbon::parse('2026-01-01');
        $msg = new FakeMessage(bounced_at: $original);

        $this->service($msg)->handlePermanentBounce('x', Carbon::now());

        static::assertEquals($original, $msg->bounced_at);
        static::assertFalse($msg->saved);
    }

    // ---------------------------------------------------------------

    private function service(?FakeMessage $message): TestableEmailWebhookService
    {
        return new TestableEmailWebhookService($message);
    }
}

/**
 * Minimal stand-in for Message that supports property access
 * and tracks save() calls without touching Eloquent.
 */
class FakeMessage extends Message
{
    public bool $saved = false;

    // Bypass Eloquent constructor
    public function __construct(
        ?Carbon $opened_at = null,
        ?Carbon $clicked_at = null,
        ?Carbon $bounced_at = null,
    ) {
        // Don't call parent — avoids Model boot/DB dependency
        $this->attributes['source_type'] = Campaign::class;
        $this->attributes['source_id'] = 1;
        $this->attributes['opened_at'] = $opened_at;
        $this->attributes['clicked_at'] = $clicked_at;
        $this->attributes['bounced_at'] = $bounced_at;
        $this->attributes['open_count'] = 0;
        $this->attributes['click_count'] = 0;
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;
        return true;
    }

    public function isCampaign(): bool
    {
        return $this->source_type === Campaign::class;
    }

    public function isAutomation(): bool
    {
        return false;
    }
}

/**
 * Overrides query/persistence methods so the real mutation logic
 * can be tested without any external dependencies.
 */
class TestableEmailWebhookService extends EmailWebhookService
{
    public function __construct(private ?FakeMessage $testMessage)
    {
    }

    protected function resolveMessage(string $messageId): ?Message
    {
        return $this->testMessage;
    }

    public function resolveSourceId(string $messageId): ?int
    {
        return $this->testMessage?->source_id;
    }

    protected function unsubscribe(string $messageId, int $typeId): void
    {
    }

    protected function recordClickUrl(Message $message, string $url): void
    {
    }
}

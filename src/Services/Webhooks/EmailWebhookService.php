<?php

declare(strict_types=1);

namespace Sendportal\Base\Services\Webhooks;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Sendportal\Base\Facades\Helper;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\MessageFailure;
use Sendportal\Base\Models\MessageUrl;
use Sendportal\Base\Models\MessageLookup;
use Sendportal\Base\Models\UnsubscribeEventType;
use Sendportal\Pro\Models\AutomationSchedule;

class EmailWebhookService
{
    /**
     * Resolve source_id from the lookup table for partition-pruned queries.
     * Returns null if no lookup row exists (e.g. messages older than retention).
     */
    public function resolveSourceId(string $messageId): ?int
    {
        return MessageLookup::where('message_id', $messageId)->value('source_id');
    }

    public function handleDelivery(string $messageId, Carbon $timestamp): void
    {
        $query = DB::table('sendportal_messages')->where('message_id', $messageId)->whereNull('delivered_at');

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        $query->update(['delivered_at' => $timestamp]);
    }

    /**
     * @throws Exception
     */
    public function handleOpen(string $messageId, Carbon $timestamp, ?string $ipAddress): void
    {
        $query = Message::where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        /** @var Message $message */
        $message = $query->first();

        if (! $message) {
            return;
        }

        if (! $message->opened_at) {
            $message->opened_at = $timestamp;
            $message->ip = $ipAddress;
        }

        ++$message->open_count;
        $message->save();

        if ($message->isCampaign()) {
            Redis::sadd('sp:stats:dirty', $message->source_id);
        }

        // @todo not sure that this give much value? We can just derive the count from the messages table.
        if ($message->isAutomation()) {
            $automationStep = $this->resolveAutomationStepFromMessage($message);
            DB::table('sendportal_automation_steps')->where('id', $automationStep->id)->increment('open_count');
        }
    }

    /**
     * @throws Exception
     */
    public function handleClick(string $messageId, Carbon $timestamp, ?string $url): void
    {
        $query = Message::where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        /* @var Message $message */
        $message = $query->first();

        if (! $message) {
            return;
        }

        // Don't track unsubscribe clicks.
        if (Str::contains($url, '/subscriptions/unsubscribe')) {
            return;
        }

        if (! $message->clicked_at) {
            $message->clicked_at = $timestamp;
        }

        // Since you have to open a campaign to click a link inside it, we'll consider those clicks as opens
        // even if the tracking image didn't load.
        if (! $message->opened_at) {
            ++$message->open_count;
            $message->opened_at = $timestamp;
        }

        ++$message->click_count;
        $message->save();

        if ($message->isCampaign()) {
            Redis::sadd('sp:stats:dirty', $message->source_id);
        }

        // @todo not sure that this give much value? We can just derive the count/ from the messages table.
        if ($message->isAutomation()) {
            $automationStep = $this->resolveAutomationStepFromMessage($message);
            DB::table('sendportal_automation_steps')->where('id', $automationStep->id)->increment('click_count');
        }

        $messageUrlHash = $this->generateMessageUrlHash($message, $url);

        if ($messageUrl = MessageUrl::where('hash', $messageUrlHash)->first()) {
            $messageUrl->update([
                'click_count' => $messageUrl->click_count + 1,
            ]);
        } else {
            MessageUrl::create([
                'hash' => $messageUrlHash,
                'source_type' => $message->source_type,
                'source_id' => $message->source_id,
                'url' => $url,
                'click_count' => 1,
            ]);
        }
    }

    public function handleComplaint(string $messageId, Carbon $timestamp): void
    {
        $query = Message::where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        /* @var Message $message */
        $message = $query->first();

        if (! $message) {
            return;
        }

        if (! $message->complained_at) {
            $message->unsubscribed_at = $timestamp;
            $message->save();
        }

        $this->unsubscribe($messageId, UnsubscribeEventType::COMPLAINT);
    }

    public function handlePermanentBounce($messageId, $timestamp): void
    {
        $query = Message::where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        /* @var Message $message */
        $message = $query->first();

        if (! $message) {
            return;
        }

        if (! $message->bounced_at) {
            $message->bounced_at = $timestamp;
            $message->save();
        }

        if ($message->isCampaign()) {
            Redis::sadd('sp:stats:dirty', $message->source_id);
        }

        $this->unsubscribe($messageId, UnsubscribeEventType::BOUNCE);
    }

    public function handleFailure($messageId, $severity, $description, $timestamp): void
    {
        $query = Message::where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        /* @var Message $message */
        $message = $query->first();

        if (! $message) {
            return;
        }

        $failure = new MessageFailure([
            'severity' => $severity,
            'description' => $description,
            'failed_at' => $timestamp,
        ]);

        $message->failures()->save($failure);
    }

    /**
     * Unsubscribe a subscriber.
     */
    protected function unsubscribe(string $messageId, int $typeId): void
    {
        $query = DB::table('sendportal_messages')->where('message_id', $messageId);

        if ($sourceId = $this->resolveSourceId($messageId)) {
            $query->where('source_id', $sourceId);
        }

        $subscriberId = $query->value('subscriber_id');

        if (! $subscriberId) {
            return;
        }

        DB::table('sendportal_subscribers')->where('id', $subscriberId)->update([
            'unsubscribed_at' => now(),
            'unsubscribe_event_id' => $typeId,
            'updated_at' => now()
        ]);
    }

    /**
     * Resolve the automation step from a message
     *
     * @param Message $message
     * @return mixed
     */
    protected function resolveAutomationStepFromMessage(Message $message)
    {
        if (Helper::isPro() && $message->source_type !== AutomationSchedule::class) {
            throw new RuntimeException('Unable to resolve source for message id=' . $message->id);
        }

        $automationSchedule = DB::table('sendportal_automation_schedules')->where('id', $message->source_id)->first();

        if (! $automationSchedule) {
            throw new RuntimeException('Unable to find schedule matching message source id=' . $message->source_id);
        }

        return DB::table('sendportal_automation_steps')->where('id', $automationSchedule->automation_step_id)->first();
    }

    protected function generateMessageUrlHash(Message $message, string $url): string
    {
        return md5($message->source_type . '_' . $message->source_id . '_' . $url);
    }
}

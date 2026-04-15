<?php

namespace Sendportal\Base\Pipelines\Campaigns;

use Illuminate\Support\Facades\DB;
use Sendportal\Base\Events\MessageDispatchEvent;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStat;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Models\Tag;

class CreateMessages
{
    /**
     * Stores unique subscribers for this campaign
     *
     * @var array
     */
    protected $sentItems = [];

    /**
     * CreateMessages handler
     *
     * @param Campaign $campaign
     * @param $next
     * @return Campaign
     * @throws \Exception
     */
    public function handle(Campaign $campaign, $next)
    {
        CampaignStat::firstOrCreate(['campaign_id' => $campaign->id]);

        if ($campaign->send_to_all) {
            $this->handleAllSubscribers($campaign);
        } else {
            $this->handleTags($campaign);
        }

        return $next($campaign);
    }

    /**
     * Handle a campaign where all subscribers have been selected
     *
     * @param Campaign $campaign
     * @throws \Exception
     */
    protected function handleAllSubscribers(Campaign $campaign)
    {
        Subscriber::where('workspace_id', $campaign->workspace_id)
            ->whereNull('unsubscribed_at')
            ->chunkById(1000, function ($subscribers) use ($campaign) {
                $this->dispatchToSubscriber($campaign, $subscribers);
            }, 'id');
    }

    /**
     * Loop through each tag
     *
     * @param Campaign $campaign
     */
    protected function handleTags(Campaign $campaign)
    {
        foreach ($campaign->tags as $tag) {
            $this->handleTag($campaign, $tag);
        }
    }

    /**
     * Handle each tag
     *
     * @param Campaign $campaign
     * @param Tag $tag
     *
     * @return void
     */
    protected function handleTag(Campaign $campaign, Tag $tag): void
    {
        \Log::info('- Handling Campaign Tag id='.$tag->id);

        $tag->subscribers()->whereNull('unsubscribed_at')->chunkById(1000, function ($subscribers) use ($campaign) {
            $this->dispatchToSubscriber($campaign, $subscribers);
        }, 'sendportal_subscribers.id');
    }

    /**
     * Dispatch the campaign to a given subscriber
     *
     * @param Campaign $campaign
     * @param $subscribers
     */
    protected function dispatchToSubscriber(Campaign $campaign, $subscribers)
    {
        \Log::info('- Number of subscribers in this chunk: ' . count($subscribers));

        $created = 0;

        foreach ($subscribers as $subscriber) {
            if (! $this->canSendToSubscriber($campaign->id, $subscriber->id)) {
                continue;
            }

            if ($this->dispatch($campaign, $subscriber)) {
                $created++;
            }
        }

        if ($created > 0) {
            CampaignStat::where('campaign_id', $campaign->id)
                ->update([
                    'total' => DB::raw("total + {$created}"),
                    'pending' => DB::raw("pending + {$created}"),
                ]);
        }
    }

    /**
     * Check if we can send to this subscriber
     * @todo check how this would impact on memory with 200k subscribers?
     *
     * @param int $campaignId
     * @param int $subscriberId
     *
     * @return bool
     */
    protected function canSendToSubscriber($campaignId, $subscriberId): bool
    {
        $key = $campaignId . '-' . $subscriberId;

        if (in_array($key, $this->sentItems, true)) {
            \Log::info('- Subscriber has already been sent a message campaign_id=' . $campaignId . ' subscriber_id=' . $subscriberId);

            return false;
        }

        $this->appendSentItem($key);

        return true;
    }

    /**
     * Append a value to the sentItems
     *
     * @param string $value
     * @return void
     */
    protected function appendSentItem(string $value): void
    {
        $this->sentItems[] = $value;
    }

    /**
     * Dispatch the message
     *
     * @param Campaign $campaign
     * @param Subscriber $subscriber
     * @return bool Whether a new message was created
     */
    protected function dispatch(Campaign $campaign, Subscriber $subscriber): bool
    {
        if ($campaign->save_as_draft) {
            return $this->saveAsDraft($campaign, $subscriber);
        }

        return $this->dispatchNow($campaign, $subscriber);
    }

    /**
     * Dispatch a message now
     *
     * @param Campaign $campaign
     * @param Subscriber $subscriber
     * @return bool Whether a new message was created
     */
    protected function dispatchNow(Campaign $campaign, Subscriber $subscriber): bool
    {
        // If a message already exists, then we're going to assume that
        // it has already been dispatched. This makes the dispatch fault-tolerant
        // and prevent dispatching the same message to the same subscriber
        // more than once
        if ($this->findMessage($campaign, $subscriber)) {
            \Log::info('Message has previously been created campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);

            return false;
        }

        // the message doesn't exist, so we'll create and dispatch
        \Log::info('Saving empty email message campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);
        $attributes = [
            'workspace_id' => $campaign->workspace_id,
            'subscriber_id' => $subscriber->id,
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'recipient_email' => $subscriber->email,
            'subject' => $campaign->subject,
            'from_name' => $campaign->from_name,
            'from_email' => $campaign->from_email,
            'queued_at' => null,
            'sent_at' => null,
        ];

        $message = new Message($attributes);
        $message->save();

        event(new MessageDispatchEvent($message));

        return true;
    }

    /**
     * @param Campaign $campaign
     * @param Subscriber $subscriber
     * @return bool Whether a new message was created
     */
    protected function saveAsDraft(Campaign $campaign, Subscriber $subscriber): bool
    {
        \Log::info('Saving message as draft campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);

        $message = Message::firstOrCreate(
            [
                'workspace_id' => $campaign->workspace_id,
                'subscriber_id' => $subscriber->id,
                'source_type' => Campaign::class,
                'source_id' => $campaign->id,
            ],
            [
                'recipient_email' => $subscriber->email,
                'subject' => $campaign->subject,
                'from_name' => $campaign->from_name,
                'from_email' => $campaign->from_email,
                'queued_at' => now(),
                'sent_at' => null,
            ]
        );

        return $message->wasRecentlyCreated;
    }

    protected function findMessage(Campaign $campaign, Subscriber $subscriber): ?Message
    {
        return Message::where('workspace_id', $campaign->workspace_id)
            ->where('subscriber_id', $subscriber->id)
            ->where('source_type', Campaign::class)
            ->where('source_id', $campaign->id)
            ->first();
    }
}

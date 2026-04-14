<?php

namespace Sendportal\Base\Services\Messages;

use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\MessageLookup;

class MarkAsSent
{
    /**
     * Save the external message_id to the messages table
     */
    public function handle(Message $message, string $messageId): Message
    {
        $message->message_id = $messageId;
        $message->sent_at = now();

        tap($message)->save();

        MessageLookup::create([
            'message_id' => $messageId,
            'source_id' => $message->source_id,
            'hash' => $message->hash,
        ]);

        return $message;
    }
}

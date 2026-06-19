<?php

declare(strict_types=1);

namespace Sendportal\Base\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Services\Messages\DispatchMessage;
use Sendportal\Base\Services\Messages\MessageOptions;

class DispatchBulkEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    protected $workspaceId;

    /** @var EmailService */
    protected $emailService;

    /** @var string */
    protected $recipientEmail;

    /** @var string */
    protected $subject;

    /** @var string */
    protected $fromName;

    /** @var string */
    protected $fromEmail;

    /** @var string */
    protected $content;

    public function __construct(
        int $workspaceId,
        EmailService $emailService,
        string $recipientEmail,
        string $subject,
        string $fromName,
        string $fromEmail,
        string $content
    ) {
        $this->workspaceId = $workspaceId;
        $this->emailService = $emailService;
        $this->recipientEmail = $recipientEmail;
        $this->subject = $subject;
        $this->fromName = $fromName;
        $this->fromEmail = $fromEmail;
        $this->content = $content;

        $this->onQueue('sendportal-message-dispatch');
    }

    /**
     * @throws Exception
     */
    public function handle(DispatchMessage $dispatchMessage): void
    {
        $messageOptions = (new MessageOptions())
            ->setTo($this->recipientEmail)
            ->setFromEmail($this->fromEmail)
            ->setFromName($this->fromName)
            ->setSubject($this->subject)
            ->setBody($this->content);

        try {
            $messageId = $dispatchMessage->handleWithoutCampaign(
                $this->workspaceId,
                $this->emailService,
                $messageOptions
            );

            Log::info('Bulk email has been dispatched.', [
                'recipient_email' => $this->recipientEmail,
                'message_id' => $messageId,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to dispatch bulk email.', [
                'recipient_email' => $this->recipientEmail,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

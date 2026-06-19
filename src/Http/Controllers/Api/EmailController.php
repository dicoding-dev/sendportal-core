<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\SendBulkEmailRequest;
use Sendportal\Base\Http\Requests\Api\SendEmailRequest;
use Sendportal\Base\Jobs\DispatchBulkEmailJob;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Services\Messages\DispatchMessage;
use Sendportal\Base\Services\Messages\MessageOptions;
use Symfony\Component\HttpFoundation\Response;

class EmailController extends Controller
{
    /**
     * Send a one-off email directly to the provider, without a campaign.
     *
     * @throws Exception
     */
    public function send(DispatchMessage $dispatchMessage, SendEmailRequest $request): JsonResponse
    {
        $emailService = EmailService::query()->find($request->get('email_service_id'));

        $messageOptions = (new MessageOptions())
            ->setTo($request->get('recipient_email'))
            ->setFromEmail($request->get('from_email'))
            ->setFromName($request->get('from_name'))
            ->setSubject($request->get('subject'))
            ->setBody($request->get('content'));

        $messageId = $dispatchMessage->handleWithoutCampaign(
            Sendportal::currentWorkspaceId(),
            $emailService,
            $messageOptions
        );

        if (! $messageId) {
            return response()->json(['message' => __('Failed to dispatch email.')], 500);
        }

        return response()->json([
            'message' => __('The email has been dispatched.'),
        ]);
    }

    /**
     * Queue a batch of one-off emails for async dispatch, without a campaign.
     */
    public function sendBulk(SendBulkEmailRequest $request): JsonResponse
    {
        $emailService = EmailService::query()->find($request->get('email_service_id'));

        $workspaceId = Sendportal::currentWorkspaceId();
        $fromName = $request->get('from_name');
        $fromEmail = $request->get('from_email');
        $emails = $request->get('emails');

        foreach ($emails as $email) {
            DispatchBulkEmailJob::dispatch(
                $workspaceId,
                $emailService,
                $email['recipient_email'],
                $email['subject'],
                $fromName,
                $fromEmail,
                $email['content']
            );
        }

        $count = count($emails);

        return response()->json([
            'message' => __(':count emails queued.', ['count' => $count]),
            'count' => $count,
        ], Response::HTTP_ACCEPTED);
    }
}

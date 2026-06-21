<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\SendBulkEmailRequest;
use Sendportal\Base\Http\Requests\Api\SendEmailRequest;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Services\Messages\DispatchMessage;
use Sendportal\Base\Services\Messages\MessageOptions;

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
     * Send a batch of personalized one-off emails, without a campaign.
     *
     * Each email is dispatched synchronously so that a per-item delivery
     * status can be reported. The opaque `row` correlation id supplied by
     * the client is echoed back verbatim on the matching response item.
     */
    public function sendBulk(DispatchMessage $dispatchMessage, SendBulkEmailRequest $request): JsonResponse
    {
        $emailService = EmailService::query()->find($request->get('email_service_id'));

        $workspaceId = Sendportal::currentWorkspaceId();
        $fromName = $request->get('from_name');
        $fromEmail = $request->get('from_email');

        $results = [];

        foreach ($request->get('emails') as $email) {
            $result = [
                'row' => $email['row'],
                'recipient_email' => $email['recipient_email'],
            ];

            try {
                $messageOptions = (new MessageOptions())
                    ->setTo($email['recipient_email'])
                    ->setFromEmail($fromEmail)
                    ->setFromName($fromName)
                    ->setSubject($email['subject'])
                    ->setBody($email['content']);

                $messageId = $dispatchMessage->handleWithoutCampaign(
                    $workspaceId,
                    $emailService,
                    $messageOptions
                );

                if ($messageId) {
                    $result['status'] = 'sent';
                } else {
                    $result['status'] = 'failed';
                    $result['error'] = __('The email service did not accept the message.');
                }
            } catch (Exception $e) {
                $result['status'] = 'failed';
                $result['error'] = $e->getMessage();
            }

            $results[] = $result;
        }

        return response()->json($results);
    }
}

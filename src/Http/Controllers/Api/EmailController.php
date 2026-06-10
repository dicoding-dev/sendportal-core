<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
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
            'data' => ['message_id' => $messageId],
        ]);
    }
}

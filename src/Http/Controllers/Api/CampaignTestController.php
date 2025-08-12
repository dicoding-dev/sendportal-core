<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\CampaignTestRequest;
use Sendportal\Base\Services\Messages\DispatchTestMessage;
use Sendportal\Base\Services\Messages\MessageOptions;

class CampaignTestController extends Controller
{
    /**
     * @throws Exception
     */
    public function send(DispatchTestMessage $dispatchTestMessage, CampaignTestRequest $request): JsonResponse
    {
        $messageOptions = (new MessageOptions())
            ->setTo($request->get('recipient_email'))
            ->setFromEmail($request->get('from_email'))
            ->setFromName($request->get('from_name'))
            ->setSubject($request->get('subject'))
            ->setBody($request->get('content'));

        $messageId = $dispatchTestMessage->handleWithoutCampaign(
            Sendportal::currentWorkspaceId(),
            $request->get('email_service_id'),
            $request->get('template_id'),
            $messageOptions
        );

        if (! $messageId) {
            return response()->json(['error', __('Failed to dispatch test email.')], 500);
        }

        return response()->json(['message' => __('The test email has been dispatched.')]);
    }
}

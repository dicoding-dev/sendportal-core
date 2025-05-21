<?php

declare(strict_types=1);

namespace Sendportal\Base\Adapters;

use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Arr;
use Sendportal\Base\Services\Messages\MessageTrackingOptions;
use Sendportal\Base\Traits\ThrottlesSending;

class SesMailAdapter extends BaseMailAdapter
{
    use ThrottlesSending;

    /** @var SesV2Client */
    protected $client;

    /**
     * @throws BindingResolutionException
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, MessageTrackingOptions $trackingOptions, string $content): string
    {
        // TODO(david): It isn't clear whether it is possible to set per-message tracking for SES.

        $result = $this->throttleSending(function () use ($fromEmail, $fromName, $toEmail, $subject, $trackingOptions, $content) {
            $headers = [];
            $listUnsubscribe = $this->getListUnsubscribe();

            if ($listUnsubscribe) {
                $headers[] = [
                    'Name' => 'List-Unsubscribe',
                    'Value' => $listUnsubscribe,
                ];
            }

            return $this->resolveClient()->sendEmail([
                'FromEmailAddress' => $fromName . ' <' . $fromEmail . '>',

                'Destination' => [
                    'ToAddresses' => [$toEmail],
                ],

                'Content' => [
                    'Simple' => [
                        'Subject' => [
                            'Data' => $subject,
                        ],
                        'Body' => [
                            'Html' => [
                                'Data' => $content,
                            ],
                        ],
                        'Headers' => $headers,
                    ],
                ],

                'ConfigurationSetName' => Arr::get($this->config, 'configuration_set_name'),
            ]);
        });

        return $this->resolveMessageId($result);
    }

    /**
     * @throws BindingResolutionException
     */
    protected function resolveClient(): SesV2Client
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = app()->make('aws')->createClient('sesv2', [
            'region' => Arr::get($this->config, 'region'),
            'credentials' => [
                'key' => Arr::get($this->config, 'key'),
                'secret' => Arr::get($this->config, 'secret'),
            ]
        ]);

        return $this->client;
    }

    protected function resolveMessageId(Result $result): string
    {
        return Arr::get($result->toArray(), 'MessageId');
    }

    /**
     * https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetAccount.html
     *
     * @throws BindingResolutionException
     */
    public function getSendQuota(): array
    {
        return $this->resolveClient()->getAccount()->get('SendQuota')->toArray();
    }
}

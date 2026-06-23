<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Sendportal\Base\Services\Messages\RelayMessage;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SendBulkEmailControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /** @test */
    public function a_batch_of_emails_is_sent_and_returns_a_status_per_item()
    {
        $emailService = $this->createEmailService();

        $payload = [
            'email_service_id' => $emailService->id,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => [
                [
                    'row' => 5,
                    'recipient_email' => 'first@example.com',
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hello there</p>',
                ],
                [
                    'row' => 5,
                    'recipient_email' => 'alt@example.com',
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hello there</p>',
                ],
                [
                    'row' => 6,
                    'recipient_email' => 'second@example.com',
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hello again</p>',
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertOk()
            ->assertJsonCount(3)
            ->assertExactJson([
                ['row' => 5, 'recipient_email' => 'first@example.com', 'status' => 'sent'],
                ['row' => 5, 'recipient_email' => 'alt@example.com', 'status' => 'sent'],
                ['row' => 6, 'recipient_email' => 'second@example.com', 'status' => 'sent'],
            ]);
    }

    /** @test */
    public function a_failed_item_reports_failed_status_with_an_error_and_echoes_its_row()
    {
        $this->mockRelayMessageThrows('invalid recipient');

        $emailService = $this->createEmailService();

        $payload = [
            'email_service_id' => $emailService->id,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => [
                [
                    'row' => 42,
                    'recipient_email' => 'fails@example.com',
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hi</p>',
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertOk()
            ->assertExactJson([
                [
                    'row' => 42,
                    'recipient_email' => 'fails@example.com',
                    'status' => 'failed',
                    'error' => 'invalid recipient',
                ],
            ]);
    }

    /** @test */
    public function it_does_not_allow_more_than_100_emails_per_request()
    {
        $emailService = $this->createEmailService();

        $emails = [];
        for ($i = 0; $i < 101; $i++) {
            $emails[] = [
                'row' => $i,
                'recipient_email' => $this->faker->safeEmail(),
                'subject' => $this->faker->sentence(),
                'content' => '<p>Hi</p>',
            ];
        }

        $payload = [
            'email_service_id' => $emailService->id,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => $emails,
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['emails']);
    }

    /** @test */
    public function it_requires_top_level_and_per_item_fields()
    {
        $payload = [
            'emails' => [
                [
                    'subject' => $this->faker->sentence(),
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors([
                'email_service_id',
                'from_name',
                'from_email',
                'emails.0.row',
                'emails.0.recipient_email',
                'emails.0.content',
            ]);
    }

    /** @test */
    public function the_email_service_must_exist()
    {
        $payload = [
            'email_service_id' => 99999,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => [
                [
                    'row' => 1,
                    'recipient_email' => $this->faker->safeEmail(),
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hi</p>',
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['email_service_id']);
    }

    /**
     * Replace the relay service with one that always throws, so we can
     * exercise the per-item failure branch.
     */
    protected function mockRelayMessageThrows(string $message): void
    {
        $service = $this->getMockBuilder(RelayMessage::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service->method('handle')->willThrowException(new Exception($message));

        app()->instance(RelayMessage::class, $service);
    }
}

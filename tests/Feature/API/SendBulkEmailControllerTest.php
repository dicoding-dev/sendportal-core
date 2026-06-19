<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Queue;
use Sendportal\Base\Jobs\DispatchBulkEmailJob;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SendBulkEmailControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /** @test */
    public function a_batch_of_emails_can_be_queued_for_dispatch()
    {
        Queue::fake();

        $emailService = $this->createEmailService();

        $payload = [
            'email_service_id' => $emailService->id,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => [
                [
                    'recipient_email' => $this->faker->safeEmail(),
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hello there</p>',
                ],
                [
                    'recipient_email' => $this->faker->safeEmail(),
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hello again</p>',
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJson(['count' => 2])
            ->assertJsonStructure(['message', 'count']);

        Queue::assertPushed(DispatchBulkEmailJob::class, 2);

        Queue::assertPushed(DispatchBulkEmailJob::class, function (DispatchBulkEmailJob $job) {
            return $job->queue === 'sendportal-message-dispatch';
        });
    }

    /** @test */
    public function it_does_not_allow_more_than_500_emails_per_request()
    {
        Queue::fake();

        $emailService = $this->createEmailService();

        $emails = [];
        for ($i = 0; $i < 501; $i++) {
            $emails[] = [
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

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_requires_top_level_and_per_item_fields()
    {
        Queue::fake();

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
                'emails.0.recipient_email',
                'emails.0.content',
            ]);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function the_email_service_must_exist()
    {
        Queue::fake();

        $payload = [
            'email_service_id' => 99999,
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'emails' => [
                [
                    'recipient_email' => $this->faker->safeEmail(),
                    'subject' => $this->faker->sentence(),
                    'content' => '<p>Hi</p>',
                ],
            ],
        ];

        $this->postJson(route('sendportal.api.emails.send-bulk'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['email_service_id']);

        Queue::assertNothingPushed();
    }
}

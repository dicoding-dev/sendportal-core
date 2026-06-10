<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SendEmailControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /** @test */
    public function an_email_can_be_sent_directly_to_the_provider()
    {
        $emailService = $this->createEmailService();

        $payload = [
            'recipient_email' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(),
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'email_service_id' => $emailService->id,
            'content' => '<p>Hello there</p>',
        ];

        $this->postJson(route('sendportal.api.emails.send'), $payload)
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['message_id'],
            ]);
    }

    /** @test */
    public function it_requires_a_recipient_subject_sender_email_service_and_content()
    {
        $this->postJson(route('sendportal.api.emails.send'), [])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors([
                'recipient_email',
                'subject',
                'from_name',
                'from_email',
                'email_service_id',
                'content',
            ]);
    }

    /** @test */
    public function the_recipient_and_sender_must_be_valid_email_addresses()
    {
        $emailService = $this->createEmailService();

        $payload = [
            'recipient_email' => 'not-an-email',
            'subject' => $this->faker->sentence(),
            'from_name' => $this->faker->name(),
            'from_email' => 'also-not-an-email',
            'email_service_id' => $emailService->id,
            'content' => 'hi',
        ];

        $this->postJson(route('sendportal.api.emails.send'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['recipient_email', 'from_email']);
    }

    /** @test */
    public function the_email_service_must_exist()
    {
        $payload = [
            'recipient_email' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(),
            'from_name' => $this->faker->name(),
            'from_email' => $this->faker->safeEmail(),
            'email_service_id' => 99999,
            'content' => 'hi',
        ];

        $this->postJson(route('sendportal.api.emails.send'), $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['email_service_id']);
    }
}

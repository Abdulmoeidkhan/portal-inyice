<?php

namespace Tests\Feature;

use App\Mail\SupportRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SupportRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['support.email' => 'info@inyice.com', 'support.mailer' => 'smtp']);
    }

    public function test_support_is_public_and_connection_pages_link_to_it(): void
    {
        $this->get('/support')->assertOk()->assertSee('Send request')->assertSee('info@inyice.com')
            ->assertSee('name="_token"', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/connect/ai')->assertOk()->assertSee(route('support.show'));
    }

    public function test_request_is_sent_only_to_support_with_the_sender_as_reply_to(): void
    {
        Mail::fake();
        $this->post('/support', $this->payload())->assertRedirect(route('support.show'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'SUP-'));
        Mail::assertSent(SupportRequest::class, function (SupportRequest $mail) {
            return $mail->hasTo('info@inyice.com') && $mail->hasReplyTo('requester@example.com')
                && $mail->requestBody === 'My ChatGPT connection fails before login.'
                && $mail->mailer === 'smtp';
        });
        Mail::assertSentCount(1);
    }

    public function test_invalid_requests_and_honeypot_do_not_send_email(): void
    {
        Mail::fake();
        foreach ([['email' => 'invalid'], ['topic' => 'Injected subject'], ['body' => 'short'], ['website' => 'spam.example']] as $invalid) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.random_int(1, 250)])
                ->post('/support', array_replace($this->payload(), $invalid))
                ->assertSessionHasErrors(array_keys($invalid));
        }
        Mail::assertNothingSent();
    }

    public function test_support_rate_limit_prevents_a_fourth_email(): void
    {
        Mail::fake();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/support', $this->payload())->assertRedirect();
        }
        $this->post('/support', $this->payload())->assertStatus(429);
        Mail::assertSentCount(3);
    }

    public function test_delivery_failure_preserves_form_without_reporting_success_or_logging_contents(): void
    {
        Log::spy();
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('info@inyice.com')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Private transport details'));
        $this->post('/support', $this->payload())->assertRedirect(route('support.show'))
            ->assertSessionHasErrors('delivery')->assertSessionMissing('status')
            ->assertSessionHas('_old_input.body', $this->payload()['body']);
        Log::shouldHaveReceived('warning')->once()->with('Support email delivery failed.', \Mockery::on(
            fn (array $context) => array_keys($context) === ['reference', 'exception_type']
                && $context['exception_type'] === RuntimeException::class
        ));
    }

    public function test_non_delivering_mailers_are_rejected(): void
    {
        Mail::fake();
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['support.mailer' => $mailer]);
            $this->post('/support', $this->payload())->assertSessionHasErrors('delivery')->assertSessionMissing('status');
        }
        Mail::assertNothingSent();
    }

    public function test_email_escapes_untrusted_content(): void
    {
        $mail = new SupportRequest('SUP-TEST', 'User', 'requester@example.com', 'Other', '<script>alert(1)</script>');
        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('has not been verified', $html);
    }

    private function payload(): array
    {
        return ['name' => 'Test requester', 'email' => 'requester@example.com', 'topic' => 'Connection issue',
            'body' => 'My ChatGPT connection fails before login.', 'website' => ''];
    }
}

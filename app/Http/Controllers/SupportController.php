<?php

namespace App\Http\Controllers;

use App\Mail\SupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SupportController extends Controller
{
    public function show(): Response
    {
        return response()->view('support', ['email' => config('support.email')])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'topic' => ['required', Rule::in(['Connection issue', 'Account or billing', 'Privacy or deletion', 'Other'])],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $reference = 'SUP-'.Str::ulid();
        $mailer = config('support.mailer');
        $transport = config("mail.mailers.{$mailer}.transport");

        // A logging or failover-to-log transport could expose a private support message
        // and report success without delivering it to the support inbox.
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            return $this->deliveryFailed($request);
        }

        try {
            Mail::mailer($mailer)->to(config('support.email'))->send(new SupportRequest(
                $reference, $data['name'], $data['email'], $data['topic'], $data['body'],
            ));
        } catch (Throwable $exception) {
            // Transport errors can contain addresses or message content. Log only
            // the reference and exception type so the user's message stays private.
            Log::warning('Support email delivery failed.', [
                'reference' => $reference,
                'exception_type' => $exception::class,
            ]);

            return $this->deliveryFailed($request);
        }

        return redirect()->route('support.show')
            ->with('status', "Your request has been sent to support. Reference: {$reference}. We will reply by email.");
    }

    private function deliveryFailed(Request $request): RedirectResponse
    {
        return redirect()->route('support.show')
            ->withErrors(['delivery' => 'We could not send your request. Please try again or use the email link below.'])
            ->withInput($request->only('name', 'email', 'topic', 'body'));
    }
}

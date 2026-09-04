<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

class WelcomeVerifyEmail extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(Lang::get('Welcome to InYice OS - verify your email'))
            ->greeting(Lang::get('Welcome to InYice OS!'))
            ->line(Lang::get('Your account has been created. Verify your email address to activate sign-in access.'))
            ->action(Lang::get('Verify Email Address'), $url)
            ->line(Lang::get('This secure verification link will expire soon.'))
            ->line(Lang::get('If you did not expect this account, no further action is required.'));
    }
}

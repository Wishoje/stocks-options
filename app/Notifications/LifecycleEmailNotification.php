<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LifecycleEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $template,
        public array $context = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->templateData($notifiable);

        $mail = (new MailMessage)
            ->subject($data['subject'])
            ->greeting($data['greeting']);

        foreach ($data['lines'] as $line) {
            $mail->line($line);
        }

        if (!empty($data['action_text']) && !empty($data['action_url'])) {
            $mail->action($data['action_text'], $data['action_url']);
        }

        if (!empty($data['footer'])) {
            $mail->line($data['footer']);
        }

        return $mail;
    }

    private function templateData(object $notifiable): array
    {
        $firstName = $this->firstName($notifiable->name ?? '');
        $trialEndsAt = data_get($this->context, 'trial_ends_at');
        $trialEndsText = $trialEndsAt
            ? Carbon::parse($trialEndsAt)->setTimezone(config('app.timezone'))->format('M j, Y g:i A T')
            : null;

        return match ($this->template) {
            'welcome_signup' => [
                'subject' => 'Welcome to GexOptions - your terminal is ready',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'Your account is ready.',
                    'Start with one workflow: scan watchlist -> validate levels and DEX -> execute with risk context.',
                    'Your 7-day trial begins as soon as checkout is complete.',
                ],
                'action_text' => 'Pick your plan',
                'action_url' => $this->appUrl('/pricing?utm_source=email&utm_medium=lifecycle&utm_campaign=welcome_signup'),
                'footer' => 'Need help? Reply to this email and we will help you get set up.',
            ],
            'checkout_reminder_1' => [
                'subject' => 'Finish setup to start your 7-day trial',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'Your account is ready.',
                    'Complete checkout to unlock full terminal access and start your trial.',
                    'It takes about a minute and you can cancel anytime.',
                ],
                'action_text' => 'Finish checkout',
                'action_url' => $this->appUrl('/checkout?plan=earlybird&billing=monthly&utm_source=email&utm_medium=lifecycle&utm_campaign=finish_checkout_1h'),
                'footer' => null,
            ],
            'checkout_reminder_2' => [
                'subject' => 'Still deciding? Your trial is waiting',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'You get full access during the trial.',
                    'Intraday snapshots, Net GEX levels, DEX context, scanner, and calculator are all included.',
                ],
                'action_text' => 'Start trial now',
                'action_url' => $this->appUrl('/checkout?plan=earlybird&billing=monthly&utm_source=email&utm_medium=lifecycle&utm_campaign=finish_checkout_24h'),
                'footer' => null,
            ],
            'trial_started' => [
                'subject' => 'Your 7-day trial is live',
                'greeting' => "Hi {$firstName},",
                'lines' => array_filter([
                    'Your trial is active.',
                    $trialEndsText ? "Trial end: {$trialEndsText}." : null,
                    'Fast start: Core workflow tools -> Scanner -> one-symbol execution plan.',
                ]),
                'action_text' => 'Open dashboard',
                'action_url' => $this->appUrl('/dashboard?utm_source=email&utm_medium=lifecycle&utm_campaign=trial_started'),
                'footer' => null,
            ],
            'payment_failed_1' => [
                'subject' => 'Action needed: update your card',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'We could not process your latest payment.',
                    'Update your billing details to keep access uninterrupted.',
                ],
                'action_text' => 'Update billing',
                'action_url' => $this->appUrl('/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=payment_failed_1'),
                'footer' => null,
            ],
            'payment_failed_2' => [
                'subject' => 'Reminder: billing issue still needs attention',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'Your billing issue is still unresolved.',
                    'Please update your card details to keep your subscription active.',
                ],
                'action_text' => 'Update billing',
                'action_url' => $this->appUrl('/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=payment_failed_2'),
                'footer' => null,
            ],
            'payment_failed_3' => [
                'subject' => 'Final reminder: update billing to avoid cancellation',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'This is your final billing reminder.',
                    'Update your payment method now to avoid service interruption.',
                ],
                'action_text' => 'Update billing',
                'action_url' => $this->appUrl('/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=payment_failed_3'),
                'footer' => null,
            ],
            'cancellation_confirmation' => [
                'subject' => 'Your plan is set to cancel at period end',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'Your subscription is canceled and will end at the end of the current billing period.',
                    'If this was accidental, you can resume instantly from Billing.',
                ],
                'action_text' => 'Resume subscription',
                'action_url' => $this->appUrl('/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=cancellation_confirmation'),
                'footer' => null,
            ],
            'cancellation_winback' => [
                'subject' => 'Want to continue where you left off?',
                'greeting' => "Hi {$firstName},",
                'lines' => [
                    'You can reactivate anytime.',
                    'Your workflow is easiest when levels, positioning, and flow stay in one place.',
                ],
                'action_text' => 'Reactivate access',
                'action_url' => $this->appUrl('/pricing?utm_source=email&utm_medium=lifecycle&utm_campaign=winback_7d'),
                'footer' => null,
            ],
            default => [
                'subject' => 'GexOptions update',
                'greeting' => "Hi {$firstName},",
                'lines' => ['We have an update for your account.'],
                'action_text' => 'Open GexOptions',
                'action_url' => $this->appUrl('/dashboard'),
                'footer' => null,
            ],
        };
    }

    private function appUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function firstName(string $fullName): string
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return 'there';
        }

        $parts = preg_split('/\s+/', $fullName);

        return $parts[0] ?: 'there';
    }
}

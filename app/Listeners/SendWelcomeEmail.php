<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use App\Notifications\WelcomeEmail;

class SendWelcomeEmail
{
    public function handle(Registered $event): void
    {
        $event->user->notify(new WelcomeEmail());
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\AccountSubscriptionData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController;

class ProfileController extends UserProfileController
{
    public function show(Request $request)
    {
        $this->validateTwoFactorAuthenticationState($request);

        return Inertia::render('Profile/Show', [
            'sessions' => $this->sessions($request)->all(),
            'sessionsSupported' => config('session.driver') === 'database',
            'confirmsTwoFactorAuthentication' => Features::optionEnabled(
                Features::twoFactorAuthentication(),
                'confirm',
            ),
            'subscription' => AccountSubscriptionData::for($request->user()),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Jetstream\Jetstream;

class LegalController extends Controller
{
    public function terms(): Response
    {
        $path = Jetstream::localizedMarkdownPath('terms.md');

        return Inertia::render('TermsOfService', [
            'terms' => Str::markdown(file_get_contents($path)),
        ]);
    }

    public function policy(): Response
    {
        $path = Jetstream::localizedMarkdownPath('policy.md');

        return Inertia::render('PrivacyPolicy', [
            'policy' => Str::markdown(file_get_contents($path)),
        ]);
    }
}

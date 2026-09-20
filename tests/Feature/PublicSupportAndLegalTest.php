<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicSupportAndLegalTest extends TestCase
{
    public function test_pricing_uses_the_configured_public_offer_and_normalized_session_intent(): void
    {
        $this->get('/pricing?plan=unknown&billing=yearly')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Pricing')
                ->where('offer.plan', 'earlybird')
                ->where('offer.label', 'Early Bird')
                ->where('offer.trial_days', 7)
                ->where('offer.display.monthly.amount_minor', 2999)
                ->where('offer.display.yearly.amount_minor', 29900)
                ->where('billing.intent.plan', 'earlybird')
                ->where('billing.intent.billing', 'yearly'));

        $this->assertSame([
            'plan' => 'earlybird',
            'billing' => 'yearly',
        ], session('billing.intent'));
    }

    public function test_contact_submission_validates_and_returns_a_shared_success_status(): void
    {
        Mail::fake();

        $this->from('/contact')->post('/contact', [
            'name' => '',
            'email' => 'not-an-email',
            'message' => '',
        ])->assertSessionHasErrors(['name', 'email', 'message']);

        $this->from('/contact')->post('/contact', [
            'name' => 'Test User',
            'email' => 'trader@example.com',
            'message' => 'I need help understanding a dashboard state.',
        ])
            ->assertRedirect('/contact')
            ->assertSessionHas('status', 'contact-sent');
    }

    public function test_explicit_legal_routes_render_the_existing_markdown_without_enabling_terms_acceptance(): void
    {
        $termsSource = file_get_contents(resource_path('markdown/terms.md'));
        $policySource = file_get_contents(resource_path('markdown/policy.md'));

        $this->get(route('terms.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TermsOfService')
                ->where('terms', Str::markdown($termsSource)));

        $this->get(route('policy.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PrivacyPolicy')
                ->where('policy', Str::markdown($policySource)));

        $this->assertSame($termsSource, file_get_contents(resource_path('markdown/terms.md')));
        $this->assertSame($policySource, file_get_contents(resource_path('markdown/policy.md')));
        $this->assertFalse(\Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature());
    }
}

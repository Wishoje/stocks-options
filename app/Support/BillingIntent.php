<?php

namespace App\Support;

use Illuminate\Http\Request;

final class BillingIntent
{
    public const SESSION_KEY = 'billing.intent';

    public const RETURN_KEY = 'billing.return_to';

    public const ACTIVATION_KEY = 'billing.activation_pending';

    public const CHECKOUT_ATTEMPT_KEY = 'billing.checkout_attempt';

    /**
     * @return array{plan: string, billing: string}
     */
    public static function normalize(?string $plan, ?string $billing): array
    {
        $plans = (array) config('plans.plans', []);
        $defaultPlan = array_key_exists('earlybird', $plans)
            ? 'earlybird'
            : (string) (array_key_first($plans) ?: 'earlybird');

        $normalizedPlan = is_string($plan) && array_key_exists($plan, $plans)
            ? $plan
            : $defaultPlan;

        $configuredBilling = array_keys((array) data_get($plans, "$normalizedPlan.prices", []));

        $normalizedBilling = is_string($billing) && in_array($billing, $configuredBilling, true)
            ? $billing
            : (in_array('monthly', $configuredBilling, true) ? 'monthly' : (string) ($configuredBilling[0] ?? 'monthly'));

        return [
            'plan' => $normalizedPlan,
            'billing' => $normalizedBilling,
        ];
    }

    /**
     * Capture only an explicit plan or billing query. Requests without either
     * value keep the most recent normalized selection in the session.
     *
     * @return array{plan: string, billing: string}
     */
    public static function capture(Request $request): array
    {
        $stored = (array) $request->session()->get(self::SESSION_KEY, []);
        $hasExplicitSelection = $request->query->has('plan') || $request->query->has('billing');

        $intent = self::normalize(
            $hasExplicitSelection ? $request->query('plan') : ($stored['plan'] ?? null),
            $hasExplicitSelection ? $request->query('billing') : ($stored['billing'] ?? null),
        );

        $request->session()->put(self::SESSION_KEY, $intent);

        return $intent;
    }

    /**
     * @return array{plan: string, billing: string}
     */
    public static function current(Request $request): array
    {
        $stored = (array) $request->session()->get(self::SESSION_KEY, []);

        return self::normalize($stored['plan'] ?? null, $stored['billing'] ?? null);
    }

    public static function rememberReturnTo(Request $request, ?string $candidate): void
    {
        if ($safe = self::safeReturnTo($candidate)) {
            $request->session()->put(self::RETURN_KEY, $safe);
        }
    }

    public static function returnTo(Request $request): string
    {
        return self::safeReturnTo($request->session()->get(self::RETURN_KEY))
            ?: route('dashboard', absolute: false);
    }

    public static function intendedCheckoutUrl(Request $request): string
    {
        return route('billing.checkout', self::current($request), absolute: false);
    }

    public static function checkoutAttemptToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function rememberCheckoutAttempt(
        Request $request,
        string $token,
        string $checkoutSessionId,
        string $checkoutUrl,
        array $intent,
        ?int $checkoutExpiresAt = null,
    ): void {
        $request->session()->put(self::CHECKOUT_ATTEMPT_KEY, [
            ...self::normalize($intent['plan'] ?? null, $intent['billing'] ?? null),
            'user_id' => (string) $request->user()->getAuthIdentifier(),
            'token_hash' => hash('sha256', $token),
            'checkout_session_hash' => hash('sha256', $checkoutSessionId),
            'checkout_url' => $checkoutUrl,
            'expires_at' => $checkoutExpiresAt && $checkoutExpiresAt > now()->timestamp
                ? $checkoutExpiresAt
                : now()->addMinutes(30)->timestamp,
        ]);
    }

    /**
     * @param  array{plan?: string, billing?: string}  $intent
     * @return array{plan: string, billing: string, checkout_url: string, matches_intent: bool}|null
     */
    public static function activeCheckoutAttempt(Request $request, array $intent): ?array
    {
        $attempt = (array) $request->session()->get(self::CHECKOUT_ATTEMPT_KEY, []);
        $checkoutUrl = (string) ($attempt['checkout_url'] ?? '');
        $host = parse_url($checkoutUrl, PHP_URL_HOST);
        $valid = (int) ($attempt['expires_at'] ?? 0) >= now()->timestamp
            && hash_equals((string) ($attempt['user_id'] ?? ''), (string) $request->user()->getAuthIdentifier())
            && is_string($host)
            && strtolower($host) === 'checkout.stripe.com'
            && str_starts_with($checkoutUrl, 'https://');

        if (! $valid) {
            if ($attempt) {
                $request->session()->forget(self::CHECKOUT_ATTEMPT_KEY);
            }

            return null;
        }

        $attemptIntent = self::normalize($attempt['plan'] ?? null, $attempt['billing'] ?? null);
        $currentIntent = self::normalize($intent['plan'] ?? null, $intent['billing'] ?? null);

        return [
            ...$attemptIntent,
            'checkout_url' => $checkoutUrl,
            'matches_intent' => $attemptIntent === $currentIntent,
        ];
    }

    public static function clearCheckoutAttempt(Request $request): void
    {
        $request->session()->forget(self::CHECKOUT_ATTEMPT_KEY);
    }

    /**
     * @return array{plan: string, billing: string}|null
     */
    public static function consumeCheckoutAttempt(
        Request $request,
        ?string $token,
        ?string $checkoutSessionId,
    ): ?array {
        $attempt = (array) $request->session()->get(self::CHECKOUT_ATTEMPT_KEY, []);
        $expired = (int) ($attempt['expires_at'] ?? 0) < now()->timestamp;
        if (
            ! is_string($token)
            || $token === ''
            || ! is_string($checkoutSessionId)
            || $checkoutSessionId === ''
            || $expired
            || ! hash_equals((string) ($attempt['user_id'] ?? ''), (string) $request->user()->getAuthIdentifier())
            || ! hash_equals((string) ($attempt['token_hash'] ?? ''), hash('sha256', $token))
            || ! hash_equals((string) ($attempt['checkout_session_hash'] ?? ''), hash('sha256', $checkoutSessionId))
        ) {
            if ($expired) {
                $request->session()->forget(self::CHECKOUT_ATTEMPT_KEY);
            }

            return null;
        }

        $request->session()->forget(self::CHECKOUT_ATTEMPT_KEY);
        $intent = self::normalize($attempt['plan'] ?? null, $attempt['billing'] ?? null);
        $request->session()->put(self::SESSION_KEY, $intent);

        return $intent;
    }

    public static function markActivationPending(Request $request, array $intent): void
    {
        $request->session()->put(self::ACTIVATION_KEY, [
            ...self::normalize($intent['plan'] ?? null, $intent['billing'] ?? null),
            'started_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);
    }

    /**
     * @return array{pending: bool, plan?: string, billing?: string, started_at?: int}
     */
    public static function activation(Request $request): array
    {
        $pending = (array) $request->session()->get(self::ACTIVATION_KEY, []);
        if (! isset($pending['expires_at']) || (int) $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget(self::ACTIVATION_KEY);

            return ['pending' => false];
        }

        return [
            'pending' => true,
            ...self::normalize($pending['plan'] ?? null, $pending['billing'] ?? null),
            'started_at' => (int) ($pending['started_at'] ?? now()->timestamp),
        ];
    }

    public static function clearActivation(Request $request): void
    {
        $request->session()->forget(self::ACTIVATION_KEY);
    }

    public static function safeReturnTo(?string $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || str_starts_with($candidate, '//')) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        if (! in_array($path, ['/dashboard', '/scanner', '/options-calculator', '/ai-export', '/eod-health'], true)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $allowed = [];
        foreach (['symbol', 'mode', 'tab', 'timeframe'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && preg_match('/\A[A-Za-z0-9.^_-]{1,40}\z/', $value)) {
                $allowed[$key] = $value;
            }
        }

        return $path.($allowed ? '?'.http_build_query($allowed) : '');
    }
}

<?php

namespace App\Console\Commands;

use App\Support\StripePlanConfigurationVerifier;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;

class VerifyBillingPlanConfiguration extends Command
{
    protected $signature = 'billing:verify-plan-config
                            {--mode= : Required Stripe mode: test or live}
                            {--json : Output JSON only}';

    protected $description = 'Read Stripe Prices and verify that public billing display values match checkout configuration.';

    public function handle(): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        if (! in_array($mode, ['test', 'live'], true)) {
            $this->error('Provide --mode=test or --mode=live.');

            return self::FAILURE;
        }

        if (! is_string(config('cashier.secret')) || config('cashier.secret') === '') {
            $this->error('STRIPE_SECRET is not configured.');

            return self::FAILURE;
        }

        $plans = (array) config('plans.plans', []);
        $rows = [];
        $failed = false;
        if ($plans === []) {
            $rows[] = [
                'plan' => 'none', 'billing' => 'none', 'mode' => $mode,
                'trial_days' => 0, 'status' => 'failed',
                'findings' => ['No billing plans are configured.'],
            ];
            $failed = true;
        }

        foreach ($plans as $planKey => $plan) {
            $cadenceFindings = StripePlanConfigurationVerifier::cadenceFindings((array) $plan);
            if ($cadenceFindings !== []) {
                $rows[] = [
                    'plan' => (string) $planKey,
                    'billing' => 'configuration',
                    'mode' => $mode,
                    'trial_days' => (int) data_get($plan, 'trial_days', 0),
                    'status' => 'failed',
                    'findings' => $cadenceFindings,
                ];
                $failed = true;
            }

            foreach ((array) data_get($plan, 'prices', []) as $billing => $priceId) {
                $findings = [];
                if (! is_string($priceId) || $priceId === '') {
                    $findings[] = 'Stripe Price ID is not configured.';
                } else {
                    try {
                        $price = Cashier::stripe()->prices->retrieve($priceId, [])->toArray();
                        $findings = StripePlanConfigurationVerifier::findings(
                            (array) $plan,
                            (string) $billing,
                            $price,
                            $mode === 'live',
                        );
                    } catch (\Throwable $exception) {
                        $findings[] = 'Stripe Price could not be retrieved: '.$exception->getMessage();
                    }
                }

                $failed = $failed || $findings !== [];
                $rows[] = [
                    'plan' => (string) $planKey,
                    'billing' => (string) $billing,
                    'mode' => $mode,
                    'trial_days' => (int) data_get($plan, 'trial_days', 0),
                    'status' => $findings === [] ? 'ok' : 'failed',
                    'findings' => $findings,
                ];
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['passed' => ! $failed, 'offers' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Plan', 'Billing', 'Mode', 'Trial days', 'Status', 'Findings'],
                array_map(fn (array $row) => [
                    $row['plan'],
                    $row['billing'],
                    $row['mode'],
                    $row['trial_days'],
                    $row['status'],
                    implode(' ', $row['findings']),
                ], $rows),
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

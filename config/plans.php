<?php

return [
    'default_subscription_name' => 'default',

    'plans' => [
        'earlybird' => [
            'label' => 'Early Bird',
            'trial_days' => 7,
            'prices' => [
                'monthly' => env('STRIPE_PRICE_EARLYBIRD_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_EARLYBIRD_YEARLY'),
            ],
            // Public display values. Stripe price IDs above remain the checkout authority.
            'display' => [
                'currency' => 'USD',
                'monthly' => [
                    'amount_minor' => 2999,
                    'interval' => 'month',
                ],
                'yearly' => [
                    'amount_minor' => 29900,
                    'interval' => 'year',
                ],
            ],
            // Future-proof: gate by features (optional now)
            'features' => [
                'app.access',
                'scanner.access',
                'calculator.access',
                'intraday.access',
            ],
        ],

        // Example future plan
        // 'pro' => [
        //   'label' => 'Pro',
        //   'prices' => ['monthly' => env('STRIPE_PRICE_PRO_MONTHLY'), 'yearly' => env('STRIPE_PRICE_PRO_YEARLY')],
        //   'features' => ['...'],
        // ],
    ],
];

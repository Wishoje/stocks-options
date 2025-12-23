<?php

return [
    'default_subscription_name' => 'default',

    'plans' => [
        'earlybird' => [
            'label' => 'Early Bird',
            'prices' => [
                'monthly' => env('STRIPE_PRICE_EARLYBIRD_MONTHLY'),
                'yearly'  => env('STRIPE_PRICE_EARLYBIRD_YEARLY'),
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

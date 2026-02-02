<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment driver that will be used when
    | no driver is explicitly specified. You may set this to any of the
    | drivers defined in the "drivers" configuration array below.
    |
    */
    'default' => env('PAYMENT_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency to use for all payment operations when no currency
    | is explicitly specified. Use ISO 4217 currency codes.
    |
    */
    'currency' => env('PAYMENT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Payment Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the payment drivers for your application. Each
    | driver can have its own configuration options specific to that provider.
    |
    */
    'drivers' => [
        'stripe' => [
            'driver' => 'stripe',
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'webhook_tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
            'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        ],

        'paypal' => [
            'driver' => 'paypal',
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox or live
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],

        'crypto' => [
            'driver' => 'crypto',
            'provider' => env('CRYPTO_PROVIDER', 'coinbase'), // coinbase, btcpay, custom
            'api_key' => env('CRYPTO_API_KEY'),
            'api_secret' => env('CRYPTO_API_SECRET'),
            'webhook_secret' => env('CRYPTO_WEBHOOK_SECRET'),
            'supported_currencies' => ['BTC', 'ETH', 'USDT', 'USDC'],
        ],

        'bank_transfer' => [
            'driver' => 'bank_transfer',
            'account_name' => env('BANK_ACCOUNT_NAME'),
            'account_number' => env('BANK_ACCOUNT_NUMBER'),
            'bank_name' => env('BANK_NAME'),
            'swift_code' => env('BANK_SWIFT_CODE'),
            'iban' => env('BANK_IBAN'),
            'instructions' => env('BANK_TRANSFER_INSTRUCTIONS'),
            'expiry_days' => env('BANK_TRANSFER_EXPIRY_DAYS', 7),
        ],

        'cash_on_delivery' => [
            'driver' => 'cash_on_delivery',
            'enabled_countries' => [], // Empty = all countries allowed
            'max_amount' => env('COD_MAX_AMOUNT', 50000), // 500.00 in cents
            'fee' => env('COD_FEE', 0),
            'fee_type' => env('COD_FEE_TYPE', 'fixed'), // fixed or percentage
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | Configure subscription and plan settings.
    |
    */
    'subscriptions' => [
        // Default trial period in days (can be overridden per plan)
        'default_trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 14),

        // Available trial period options (for admin UI)
        'trial_options' => [
            0 => 'No trial',
            7 => '7 days',
            14 => '14 days',
            30 => '30 days (1 month)',
            60 => '60 days (2 months)',
        ],

        // Allow free plans (amount = 0)
        'allow_free_plans' => env('SUBSCRIPTION_ALLOW_FREE', true),

        // Grace period after subscription ends (in days)
        'grace_period_days' => env('SUBSCRIPTION_GRACE_DAYS', 0),

        // Auto-cancel after failed payment attempts
        'cancel_after_failed_attempts' => env('SUBSCRIPTION_CANCEL_AFTER_FAILURES', 3),

        // Proration behavior: 'create_prorations', 'none', 'always_invoice'
        'proration_behavior' => env('SUBSCRIPTION_PRORATION', 'create_prorations'),

        // Billing cycle anchor: 'plan_start' or day of month (1-28)
        'billing_anchor' => env('SUBSCRIPTION_BILLING_ANCHOR', 'plan_start'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Configure webhook handling for payment providers.
    |
    */
    'webhooks' => [
        'path' => 'webhooks/payment',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | The names of the database tables used by the payment gateway.
    |
    */
    'tables' => [
        'transactions' => 'payment_transactions',
        'subscriptions' => 'payment_subscriptions',
        'plans' => 'payment_plans',
        'payment_methods' => 'payment_methods',
        'refunds' => 'payment_refunds',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | You may specify custom model classes to use instead of the built-in
    | models. This allows you to extend the base functionality.
    |
    */
    'models' => [
        'transaction' => Nejcc\PaymentGateway\Models\Transaction::class,
        'subscription' => Nejcc\PaymentGateway\Models\Subscription::class,
        'plan' => Nejcc\PaymentGateway\Models\Plan::class,
        'payment_method' => Nejcc\PaymentGateway\Models\PaymentMethod::class,
        'refund' => Nejcc\PaymentGateway\Models\Refund::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billable Model
    |--------------------------------------------------------------------------
    |
    | This is the model that will be used for customers/billing. Typically
    | this would be your User model.
    |
    */
    'billable_model' => App\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix for payment-related routes.
    |
    */
    'route_prefix' => 'payment',

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure email notifications for payment events.
    |
    */
    'notifications' => [
        // Enable/disable specific notification types
        'payment_receipt' => env('PAYMENT_NOTIFY_RECEIPT', true),
        'payment_failed' => env('PAYMENT_NOTIFY_FAILED', true),
        'subscription_created' => env('PAYMENT_NOTIFY_SUBSCRIPTION_CREATED', true),
        'subscription_canceled' => env('PAYMENT_NOTIFY_SUBSCRIPTION_CANCELED', true),
        'refund_processed' => env('PAYMENT_NOTIFY_REFUND', true),
        'trial_ending' => env('PAYMENT_NOTIFY_TRIAL_ENDING', true),

        // Days before trial ends to send reminder
        'trial_reminder_days' => [3, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | Configure invoice generation and display settings.
    |
    */
    'invoice' => [
        // Invoice number prefix
        'prefix' => env('INVOICE_PREFIX', 'INV'),

        // Company details for invoice header
        'company_name' => env('INVOICE_COMPANY_NAME', config('app.name')),
        'company_address' => env('INVOICE_COMPANY_ADDRESS', ''),
        'company_email' => env('INVOICE_COMPANY_EMAIL'),
        'company_phone' => env('INVOICE_COMPANY_PHONE', ''),
        'company_vat' => env('INVOICE_COMPANY_VAT', ''),
        'logo_url' => env('INVOICE_LOGO_URL', ''),

        // Invoice footer text
        'footer' => env('INVOICE_FOOTER', 'Thank you for your business!'),

        // Default payment terms
        'payment_terms' => env('INVOICE_PAYMENT_TERMS', 'Due upon receipt'),
        'due_days' => env('INVOICE_DUE_DAYS', 30),

        // Storage path for generated PDFs
        'storage_path' => env('INVOICE_STORAGE_PATH', 'invoices'),
        'storage_disk' => env('INVOICE_STORAGE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Panel
    |--------------------------------------------------------------------------
    |
    | Configure the admin panel for managing payments, subscriptions, and plans.
    |
    */
    'admin' => [
        // Enable or disable the admin panel routes
        'enabled' => env('PAYMENT_ADMIN_ENABLED', true),

        // Route prefix for admin panel
        'prefix' => env('PAYMENT_ADMIN_PREFIX', 'admin/payments'),

        // Middleware to apply to admin routes
        'middleware' => ['web', 'auth', 'role:super-admin,admin'],

        // Dashboard settings
        'dashboard' => [
            // Number of days for revenue chart
            'chart_days' => 30,

            // Recent items to show
            'recent_transactions' => 5,
            'recent_subscriptions' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for payment operations.
    |
    */
    'logging' => [
        'enabled' => env('PAYMENT_LOGGING', true),
        'channel' => env('PAYMENT_LOG_CHANNEL', 'stack'),
    ],
];

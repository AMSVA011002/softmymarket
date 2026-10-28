<?php
/**
 * YourMarket — example configuration.
 *
 * Copy this file to config/config.php and fill in the values for the host.
 * The installer (install/) can generate it for you and will never overwrite an
 * existing file.
 *
 * config/config.php is blocked from HTTP access by config/.htaccess and is
 * listed in .gitignore — never commit real credentials to the repository.
 */

return [
    'app' => [
        'name'            => 'YourMarket',
        'tagline'         => 'Sri Lanka\'s trusted online marketplace',
        'url'             => 'https://yourmarket.example',  // no trailing slash
        'env'             => 'production',                    // production | local
        'debug'           => false,                            // never true in production
        'timezone'        => 'Asia/Colombo',
        'locale'          => 'en_LK',
        'currency'        => 'LKR',
        'currency_symbol' => 'Rs',
        // 32-byte secret used for signed tokens (password resets, tracking links).
        // Generate with: php -r "echo bin2hex(random_bytes(32));"
        'key'             => 'CHANGE-ME-TO-A-64-CHARACTER-RANDOM-HEX-STRING-0000000000000000',
        'support_email'   => 'hello@yourmarket.example',
        'support_phone'   => '+94 11 234 5678',
        'orders_per_page' => 20,
        'products_per_page' => 24,
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'yourmarket',
        'user'    => 'yourmarket',
        'pass'    => '',
        'charset' => 'utf8mb4',
        // Reserved. Table names are quoted directly in the SQL, so keep this
        // empty — a prefix is not applied to the bundled queries.
        'prefix'  => '',
    ],

    'session' => [
        'name'     => 'yourmarket_session',
        'lifetime' => 60 * 60 * 24 * 14,   // remember-me window (seconds)
        'idle'     => 60 * 60 * 2,         // idle timeout (seconds)
        'secure'   => true,                // set true once HTTPS is live
        'samesite' => 'Lax',
        'domain'   => '',
    ],

    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
        'max_order_qty'      => 10,
        'session_regenerate' => 900,       // seconds between session id rotations
        // Trust X-Forwarded-For only when requests come from these proxies.
        'trusted_proxies'    => [],
    ],

    'mail' => [
        // 'mail' uses the host's sendmail binary (common on shared PHP hosting).
        // 'smtp' uses the socket client in includes/Mailer.php (SMTPS port 465
        // or STARTTLS port 587).
        'transport'   => 'smtp',
        'from_email'  => 'no-reply@yourmarket.example',
        'from_name'   => '',
        'reply_to'    => 'support@yourmarket.example',
        'smtp' => [
            'host'       => '',
            'port'       => 465,
            'encryption' => 'ssl', // ssl | tls (mandatory)
            'username'   => '',
            'timeout'    => 15,
        ],
        // When true every outgoing message is written to storage/logs/mail.log
        'log' => true,
    ],

    'payments' => [
        // Cash on delivery
        'cod' => [
            'enabled' => true,
            'fee'     => 0,
            'max_total' => 150000,
        ],

        // Manual bank transfer — proof uploaded by the customer
        'bank_transfer' => [
            'enabled' => true,
            'accounts' => [
                ['bank' => 'Seylan Bank', 'branch' => 'Colombo 03', 'name' => 'YourMarket (Pvt) Ltd', 'number' => '0152 1234 5678', 'swift' => 'SEYBLKLX'],
                ['bank' => 'Commercial Bank', 'branch' => 'Nugegoda', 'name' => 'YourMarket (Pvt) Ltd', 'number' => '8001 2345 6789', 'swift' => 'CCEYLKLX'],
            ],
            'instructions' => 'Transfer the exact order total, then upload the deposit slip or enter the reference number. Orders are released as soon as the finance team verifies the payment.',
        ],

        // Seylan Bank payment gateway — hosted card checkout.
        // The bank supplies merchant id, API key and the live endpoints.
        'seylan' => [
            'enabled'        => false,
            'mode'           => 'sandbox',                 // sandbox | live
            'merchant_id'    => '',
            'api_key'        => '',
            'secret'         => '',                        // HMAC signing secret
            'currency'       => 'LKR',
            'sandbox_url'    => 'https://sandbox.seylanbank.lk/pg/payment',
            'live_url'       => 'https://pay.seylanbank.lk/pg/payment',
            'sandbox_query'  => 'https://sandbox.seylanbank.lk/pg/order-status',
            'live_query'     => 'https://pay.seylanbank.lk/pg/order-status',
            // Simulation lets the site take demo orders when credentials are
            // absent. Set to false the moment real credentials are added.
            'simulate'       => true,
        ],

        // PayHere hosted checkout. Sandbox mode is for development and uses
        // PayHere sandbox credentials/endpoint. Switch to Live only after
        // testing, and only with live merchant credentials. Local simulation
        // is permitted only in sandbox mode when credentials are missing.
        'payhere' => [
            'enabled'         => false,
            'mode'            => 'sandbox',
            'merchant_id'     => '',
            'merchant_secret' => '',
            'currency'        => 'LKR',
            'min_total'       => 0,
            'max_total'       => 0,
            'simulate'        => true,
        ],

        // KOKO — Sri Lankan buy-now-pay-later (hosted redirect + HMAC callback)
        'koko' => [
            'enabled'     => false,
            'mode'        => 'sandbox',
            'merchant_id' => '',
            'api_key'     => '',
            'secret'      => '',
            'sandbox_url' => 'https://sandbox.paykoko.com/checkout',
            'live_url'    => 'https://paykoko.com/checkout',
            'min_total'   => 1000,
            'max_total'   => 400000,
            'simulate'    => true,
        ],

        // Payzy wallet
        'payzy' => [
            'enabled'     => false,
            'mode'        => 'sandbox',
            'merchant_id' => '',
            'api_key'     => '',
            'secret'      => '',
            'sandbox_url' => 'https://sandbox.payzy.lk/checkout',
            'live_url'    => 'https://payzy.lk/checkout',
            'simulate'    => true,
        ],

        // Mintpay — buy now pay later
        'mintpay' => [
            'enabled'     => false,
            'mode'        => 'sandbox',
            'merchant_id' => '',
            'api_key'     => '',
            'secret'      => '',
            'sandbox_url' => 'https://sandbox.mintpay.lk/checkout',
            'live_url'    => 'https://mintpay.lk/checkout',
            'min_total'   => 500,
            'max_total'   => 500000,
            'simulate'    => true,
        ],
    ],

    'images' => [
        // Hosts the image proxy is allowed to fetch product pictures from.
        // Google Drive links are accepted in any of the usual formats.
        'allowed_hosts' => [
            'drive.google.com',
            'docs.google.com',
            'lh3.googleusercontent.com',
            'images.unsplash.com',
            'res.cloudinary.com',
            'i.imgur.com',
            'cdn.shopify.com',
        ],
        // Product images come from Google Drive share links *and* from
        // manufacturer / product-page URLs. Any public host is proxied (private
        // and reserved IPs are always refused), so hotlink protection and
        // http-only CDNs cannot break the storefront.
        'allow_public_hosts' => true,
        // Serve non-Drive images through /image.php as well (recommended: gives
        // you caching and hides the visitor's IP from third-party hosts).
        'proxy_remote' => true,
        'cache_ttl'   => 60 * 60 * 24 * 7, // seconds a remote image stays cached
        'max_bytes'   => 5 * 1024 * 1024,
        'cache_dir'   => 'storage/cache/images',
        'placeholder' => 'assets/img/placeholder.svg',
    ],

    'uploads' => [
        'max_bytes' => 5 * 1024 * 1024,
        'types'     => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'],
        'dir'       => 'storage/uploads',
    ],
];

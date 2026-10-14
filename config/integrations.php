<?php

return [
    // Default pause makes an imported development environment incapable of live outbound sync/issuance.
    'outbound_paused' => env('INTEGRATIONS_OUTBOUND_PAUSED', true),
    'woocommerce' => [
        'max_pages' => (int) env('WOO_MAX_PAGES', 500),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',',
            strtolower((string) env('WOO_ALLOWED_HOSTS', 'techpoint-ks.com,www.techpoint-ks.com')))))),
    ],
];

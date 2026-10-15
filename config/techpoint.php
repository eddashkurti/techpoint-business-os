<?php

return [
    'timezone' => env('BUSINESS_TIMEZONE', 'Europe/Belgrade'),
    'currency' => 'EUR',
    'fiscal_exemption_verified' => env('FISCAL_EXEMPTION_VERIFIED', false),
    'outbound_paused' => env('OUTBOUND_OPERATIONS_PAUSED', false),
    'permissions' => ['dashboard.view', 'inventory.view', 'inventory.manage', 'inventory.adjust', 'purchasing.view', 'purchasing.manage', 'sales.view', 'sales.manage', 'sales.discount', 'sales.refund', 'finance.view', 'finance.manage', 'finance.reconcile', 'accounting.view', 'accounting.post', 'accounting.close', 'reports.view', 'reports.export', 'integrations.view', 'integrations.manage', 'users.manage', 'settings.manage', 'audit.view'],
    'api_abilities' => ['catalog.read', 'sales.read', 'reports.read'],
    'roles' => [
        'owner' => ['label' => 'Pronari / Administratori', 'permissions' => ['*']],
        'accountant' => ['label' => 'Kontabilisti', 'permissions' => ['finance.view', 'finance.reconcile', 'accounting.view', 'reports.view', 'reports.export', 'purchasing.view']],
        'warehouse' => ['label' => 'Depoja', 'permissions' => ['inventory.view', 'inventory.manage', 'purchasing.view', 'purchasing.manage']],
        'sales' => ['label' => 'Shitjet / POS', 'permissions' => ['sales.view', 'sales.manage', 'inventory.view']],
    ],
];

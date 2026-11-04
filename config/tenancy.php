<?php

return [
    // Set this to the public platform domain (without scheme or leading dot).
    'base_domain' => env('TENANT_BASE_DOMAIN', 'nexasms.com'),

    // Local-only wildcard host support; 127.0.0.1 and localhost remain valid.
    'local_domain' => env('TENANT_LOCAL_DOMAIN', 'localhost'),
];

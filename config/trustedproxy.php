<?php

// Proxy yang dipercaya untuk header X-Forwarded-*. Bawaan hanya lokal (tailscale serve di host).
// Docker: TRUSTED_PROXIES=127.0.0.1,172.16.0.0/12 (nginx kontainer berada di jaringan Docker).
return [
    'proxies' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];

<?php

declare(strict_types=1);

return [
    'admin_kpi_cache_key' => 'dashboard.admin.kpi',
    'admin_completion_rate_cache_key' => 'dashboard.admin.completion_rate',
    'admin_cache_ttl' => (int) env('ADMIN_DASHBOARD_CACHE_TTL', 300),
];

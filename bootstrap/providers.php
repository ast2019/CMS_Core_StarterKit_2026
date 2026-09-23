<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\CmsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    CmsServiceProvider::class,
    RateLimitServiceProvider::class,
    AdminPanelProvider::class,
];

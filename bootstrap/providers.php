<?php

use App\Providers\AppServiceProvider;
use App\Providers\CmsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    CmsServiceProvider::class,
    AdminPanelProvider::class,
];

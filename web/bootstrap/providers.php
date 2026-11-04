<?php

use App\Providers\AppServiceProvider;
use App\Providers\ErrorServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    ErrorServiceProvider::class,
    FortifyServiceProvider::class,
];

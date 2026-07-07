<?php

use Digit\Scan\Http\Actions\ScanCheckInAction;
use Digit\Scan\Http\Middleware\AuthenticateScanDevice;
use Digit\Scan\Http\Middleware\FailOpenThrottle;
use Illuminate\Routing\Router;

/** @var Router $router */
$router = app()->get('router');

$router->prefix('/digit/scan')
    ->middleware([AuthenticateScanDevice::class, FailOpenThrottle::class])
    ->group(function (Router $router): void {
        $router->post('/check-in-lists/{check_in_list_short_id}/check-ins', ScanCheckInAction::class);
    });

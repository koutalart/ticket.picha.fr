<?php

use Digit\Scan\Http\Actions\ScanCheckInAction;
use Illuminate\Routing\Router;

/** @var Router $router */
$router = app()->get('router');

$router->prefix('/digit/scan')->group(function (Router $router): void {
    $router->post('/check-in-lists/{check_in_list_short_id}/check-ins', ScanCheckInAction::class);
});

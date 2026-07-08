<?php

use Digit\Bracelets\Http\Actions\AssociateBraceletAction;
use Digit\Bracelets\Http\Actions\GetBraceletAction;
use Digit\Bracelets\Http\Actions\RevokeBraceletAction;
use Illuminate\Routing\Router;

/**
 * Guichet/admin endpoints - protected by Hi.Events' existing native
 * `auth:api` + organizer-account authorization (isActionAuthorized() inside
 * each action), exactly like every other event-scoped native route. No new
 * Access module, no bespoke auth for these 3 routes.
 *
 * NOT the scanner entry-door endpoint - that will live under /digit/scan
 * (unchanged) once Phase 2 wires BraceletLookupService into
 * ScanCoordinatorService.
 */
/** @var Router $router */
$router = app()->get('router');

$router->prefix('/digit/bracelets')
    ->middleware(['auth:api'])
    ->group(function (Router $router): void {
        $router->get('/{code}', GetBraceletAction::class);
        $router->post('/{code}/associate', AssociateBraceletAction::class);
        $router->post('/{code}/revoke', RevokeBraceletAction::class);
    });

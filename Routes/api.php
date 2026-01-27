<?php

declare(strict_types=1);

/**
 * Tenant Module API Routes
 *
 * REST API for workspace management.
 * Supports both session auth and API key auth.
 */

use Core\Mod\Api\Controllers\WorkspaceController;
use Core\Mod\Tenant\Controllers\Api\EntitlementWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workspaces API (Auth Required)
|--------------------------------------------------------------------------
|
| Workspace CRUD and switching.
| Session-based authentication.
|
*/

Route::middleware('auth')->prefix('workspaces')->name('api.workspaces.')->group(function () {
    Route::get('/', [WorkspaceController::class, 'index'])
        ->name('index');
    Route::get('/current', [WorkspaceController::class, 'current'])
        ->name('current');
    Route::post('/', [WorkspaceController::class, 'store'])
        ->name('store');
    Route::get('/{workspace}', [WorkspaceController::class, 'show'])
        ->name('show');
    Route::put('/{workspace}', [WorkspaceController::class, 'update'])
        ->name('update');
    Route::delete('/{workspace}', [WorkspaceController::class, 'destroy'])
        ->name('destroy');
    Route::post('/{workspace}/switch', [WorkspaceController::class, 'switch'])
        ->name('switch');
});

/*
|--------------------------------------------------------------------------
| Workspaces API (API Key Auth)
|--------------------------------------------------------------------------
|
| Read-only workspace access via API key.
| Use Authorization: Bearer hk_xxx header.
|
*/

Route::middleware(['api.auth', 'api.scope.enforce'])->prefix('workspaces')->name('api.key.workspaces.')->group(function () {
    // Scope enforcement: GET=read (all routes here are read-only)
    Route::get('/', [WorkspaceController::class, 'index'])->name('index');
    Route::get('/current', [WorkspaceController::class, 'current'])->name('current');
    Route::get('/{workspace}', [WorkspaceController::class, 'show'])->name('show');
});

/*
|--------------------------------------------------------------------------
| Entitlement Webhooks API (Auth Required)
|--------------------------------------------------------------------------
|
| Webhook management for entitlement events.
| Session-based authentication.
|
*/

Route::middleware('auth')->prefix('entitlement-webhooks')->name('api.entitlement-webhooks.')->group(function () {
    Route::get('/', [EntitlementWebhookController::class, 'index'])->name('index');
    Route::get('/events', [EntitlementWebhookController::class, 'events'])->name('events');
    Route::post('/', [EntitlementWebhookController::class, 'store'])->name('store');
    Route::get('/{webhook}', [EntitlementWebhookController::class, 'show'])->name('show');
    Route::put('/{webhook}', [EntitlementWebhookController::class, 'update'])->name('update');
    Route::delete('/{webhook}', [EntitlementWebhookController::class, 'destroy'])->name('destroy');
    Route::post('/{webhook}/test', [EntitlementWebhookController::class, 'test'])->name('test');
    Route::post('/{webhook}/regenerate-secret', [EntitlementWebhookController::class, 'regenerateSecret'])->name('regenerate-secret');
    Route::post('/{webhook}/reset-circuit-breaker', [EntitlementWebhookController::class, 'resetCircuitBreaker'])->name('reset-circuit-breaker');
    Route::get('/{webhook}/deliveries', [EntitlementWebhookController::class, 'deliveries'])->name('deliveries');
    Route::post('/deliveries/{delivery}/retry', [EntitlementWebhookController::class, 'retryDelivery'])->name('retry-delivery');
});

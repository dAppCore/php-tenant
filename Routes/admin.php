<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Admin Routes
|--------------------------------------------------------------------------
|
| Routes for workspace team and member management in the admin panel.
|
*/

Route::middleware(['web', 'auth', 'admin.domain'])->prefix('admin/tenant')->name('hub.admin.tenant.')->group(function () {
    // Team Manager
    Route::get('/teams', \Core\Mod\Tenant\View\Modal\Admin\TeamManager::class)
        ->name('teams');

    // Member Manager
    Route::get('/members', \Core\Mod\Tenant\View\Modal\Admin\MemberManager::class)
        ->name('members');
});

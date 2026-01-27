<?php

declare(strict_types=1);

/**
 * Tenant Module Web Routes
 *
 * Account management and workspace routes.
 */

use Core\Mod\Tenant\View\Modal\Web\CancelDeletion;
use Core\Mod\Tenant\View\Modal\Web\ConfirmDeletion;
use Core\Mod\Tenant\View\Modal\Web\WorkspaceHome;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Account Deletion Routes (No Auth Required)
|--------------------------------------------------------------------------
|
| Token-based account deletion confirmation and cancellation.
| Users receive these links via email - no login required.
|
*/

Route::prefix('account')->name('account.')->group(function () {
    Route::get('/delete/{token}', ConfirmDeletion::class)
        ->name('delete.confirm');

    Route::get('/delete/{token}/cancel', CancelDeletion::class)
        ->name('delete.cancel');
});

/*
|--------------------------------------------------------------------------
| Workspace Invitation Routes
|--------------------------------------------------------------------------
|
| Token-based workspace invitation acceptance.
| Users receive these links via email to join a workspace.
|
*/

Route::get('/workspace/invitation/{token}', \Core\Mod\Tenant\Controllers\WorkspaceInvitationController::class)
    ->name('workspace.invitation.accept');

/*
|--------------------------------------------------------------------------
| Workspace Public Routes
|--------------------------------------------------------------------------
|
| Workspace home page, typically accessed via subdomain.
| The workspace slug is resolved from subdomain middleware or route param.
|
*/

Route::get('/workspace/{workspace?}', WorkspaceHome::class)
    ->name('workspace.home')
    ->where('workspace', '[a-z0-9\-]+');

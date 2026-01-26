<?php

namespace Core\Mod\Tenant\View\Modal\Web;

use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.minimal')]
class ConfirmDeletion extends Component
{
    public string $token = '';

    public string $password = '';

    public string $step = 'verify'; // verify, confirm, deleting, goodbye, invalid

    public string $error = '';

    public ?AccountDeletionRequest $deletionRequest = null;

    public ?string $userName = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->deletionRequest = AccountDeletionRequest::findValidByToken($token);

        if (! $this->deletionRequest) {
            $this->step = 'invalid';

            return;
        }

        $this->userName = $this->deletionRequest->user->name;

        // Even if logged in, require re-authentication for security
        $this->step = 'verify';
    }

    public function verifyPassword(): void
    {
        $this->error = '';

        if (! $this->deletionRequest || ! $this->deletionRequest->isActive()) {
            $this->step = 'invalid';

            return;
        }

        $user = $this->deletionRequest->user;

        if (! Hash::check($this->password, $user->password)) {
            $this->error = 'The password you entered is incorrect.';

            return;
        }

        // Log the user in for this session
        Auth::login($user);
        $this->step = 'confirm';
    }

    public function confirmDeletion(): void
    {
        if (! $this->deletionRequest || ! $this->deletionRequest->isActive()) {
            $this->step = 'invalid';

            return;
        }

        $this->step = 'deleting';

        // Process deletion in background after animation starts
        $this->dispatch('start-deletion');
    }

    public function executeDelete(): void
    {
        if (! $this->deletionRequest || ! $this->deletionRequest->isActive()) {
            return;
        }

        $user = $this->deletionRequest->user;

        DB::transaction(function () use ($user) {
            // Mark request as confirmed and completed
            $this->deletionRequest->confirm();
            $this->deletionRequest->complete();

            // Delete all workspaces owned by the user
            if (method_exists($user, 'ownedWorkspaces')) {
                $user->ownedWorkspaces()->each(function ($workspace) {
                    $workspace->delete();
                });
            }

            // Hard delete user account
            $user->forceDelete();
        });

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->step = 'goodbye';
    }

    public function render()
    {
        return view('tenant::web.account.confirm-deletion');
    }
}

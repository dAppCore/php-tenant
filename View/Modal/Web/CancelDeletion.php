<?php

namespace Core\Mod\Tenant\View\Modal\Web;

use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.minimal')]
class CancelDeletion extends Component
{
    public string $token = '';

    public string $status = 'processing'; // processing, success, invalid

    public function mount(string $token): void
    {
        $this->token = $token;
        $deletionRequest = AccountDeletionRequest::findValidByToken($token);

        if (! $deletionRequest) {
            $this->status = 'invalid';

            return;
        }

        // Cancel the deletion request
        $deletionRequest->cancel();
        $this->status = 'success';
    }

    public function render()
    {
        return view('tenant::web.account.cancel-deletion');
    }
}

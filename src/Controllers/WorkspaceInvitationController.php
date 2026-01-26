<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Controllers;

use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Handles workspace invitation acceptance.
 *
 * Users receive an email with a unique token link. When clicked:
 * - If authenticated: Accept invitation and redirect to workspace
 * - If not authenticated: Redirect to login with return URL
 */
class WorkspaceInvitationController extends Controller
{
    /**
     * Handle invitation acceptance.
     */
    public function __invoke(Request $request, string $token): RedirectResponse|View
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        // Invalid token
        if (! $invitation) {
            return redirect()->route('login')
                ->with('error', 'This invitation link is invalid.');
        }

        // Already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('login')
                ->with('info', 'This invitation has already been accepted.');
        }

        // Expired
        if ($invitation->isExpired()) {
            return redirect()->route('login')
                ->with('error', 'This invitation has expired. Please ask the workspace owner to send a new invitation.');
        }

        // User not authenticated - redirect to login with intended return URL
        if (! $request->user()) {
            return redirect()->route('login', [
                'email' => $invitation->email,
            ])->with('invitation_token', $token)
                ->with('info', "You've been invited to join {$invitation->workspace->name}. Please log in or register to accept.");
        }

        // Accept the invitation
        $accepted = Workspace::acceptInvitation($token, $request->user());

        if (! $accepted) {
            return redirect()->route('dashboard')
                ->with('error', 'Unable to accept this invitation. It may have expired or already been used.');
        }

        // Redirect to the workspace
        return redirect()->route('workspace.home', ['workspace' => $invitation->workspace->slug])
            ->with('success', "You've joined {$invitation->workspace->name}.");
    }
}

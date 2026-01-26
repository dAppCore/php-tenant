<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Models\WorkspaceInvitation;
use Core\Mod\Tenant\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_can_invite_user_by_email(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => 'owner']);

        $invitation = $workspace->invite('newuser@example.com', 'member', $owner);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'newuser@example.com',
            'role' => 'member',
            'invited_by' => $owner->id,
        ]);

        $this->assertNotNull($invitation->token);
        $this->assertTrue($invitation->isPending());
        $this->assertFalse($invitation->isExpired());
        $this->assertFalse($invitation->isAccepted());

        Notification::assertSentTo($invitation, WorkspaceInvitationNotification::class);
    }

    public function test_invitation_expires_after_set_days(): void
    {
        $workspace = Workspace::factory()->create();
        $invitation = $workspace->invite('test@example.com', 'member', null, 3);

        $this->assertTrue($invitation->expires_at->isBetween(
            now()->addDays(2)->addHours(23),
            now()->addDays(3)->addHours(1)
        ));
    }

    public function test_user_can_accept_invitation(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['email' => 'invited@example.com']);

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'invited@example.com',
            'role' => 'admin',
        ]);

        $result = $invitation->accept($user);

        $this->assertTrue($result);
        $this->assertTrue($invitation->fresh()->isAccepted());
        $this->assertTrue($workspace->users()->where('user_id', $user->id)->exists());
        $this->assertEquals('admin', $workspace->users()->find($user->id)->pivot->role);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $invitation = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $workspace->id,
        ]);

        $result = $invitation->accept($user);

        $this->assertFalse($result);
        $this->assertFalse($workspace->users()->where('user_id', $user->id)->exists());
    }

    public function test_already_accepted_invitation_cannot_be_reused(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $invitation = WorkspaceInvitation::factory()->accepted()->create([
            'workspace_id' => $workspace->id,
        ]);

        $result = $invitation->accept($user);

        $this->assertFalse($result);
    }

    public function test_resending_invitation_updates_existing(): void
    {
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $owner = User::factory()->create();

        // First invitation as member
        $first = $workspace->invite('test@example.com', 'member', $owner);
        $firstToken = $first->token;

        // Second invitation as admin - should update existing
        $second = $workspace->invite('test@example.com', 'admin', $owner);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals($firstToken, $second->token); // Token unchanged
        $this->assertEquals('admin', $second->role);

        // Should only have one invitation
        $this->assertEquals(1, $workspace->invitations()->count());
    }

    public function test_static_accept_invitation_method(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $result = Workspace::acceptInvitation($invitation->token, $user);

        $this->assertTrue($result);
        $this->assertTrue($workspace->users()->where('user_id', $user->id)->exists());
    }

    public function test_static_accept_with_invalid_token_returns_false(): void
    {
        $user = User::factory()->create();

        $result = Workspace::acceptInvitation('invalid-token', $user);

        $this->assertFalse($result);
    }

    public function test_user_already_in_workspace_still_accepts(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        // User already in workspace
        $workspace->users()->attach($user->id, ['role' => 'member']);

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => $user->email,
            'role' => 'admin',
        ]);

        $result = $invitation->accept($user);

        $this->assertTrue($result);
        $this->assertTrue($invitation->fresh()->isAccepted());
        // Role should remain as original (member), not updated to admin
        $this->assertEquals('member', $workspace->users()->find($user->id)->pivot->role);
    }

    public function test_invitation_scopes(): void
    {
        $workspace = Workspace::factory()->create();

        $pending = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $expired = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $workspace->id,
        ]);

        $accepted = WorkspaceInvitation::factory()->accepted()->create([
            'workspace_id' => $workspace->id,
        ]);

        $this->assertEquals(1, WorkspaceInvitation::pending()->count());
        $this->assertEquals(1, WorkspaceInvitation::expired()->count());
        $this->assertEquals(1, WorkspaceInvitation::accepted()->count());
    }
}

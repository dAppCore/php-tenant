<?php

declare(strict_types=1);

namespace Core\Tenant\Tests\Feature;

use Core\Tenant\Database\Factories\WorkspaceInvitationFactory;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspaceInvitation;
use Core\Tenant\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        // Token should be hashed (starts with $2y$)
        $this->assertNotNull($invitation->token);
        $this->assertTrue(str_starts_with($invitation->token, '$2y$'));
        $this->assertTrue($invitation->isPending());
        $this->assertFalse($invitation->isExpired());
        $this->assertFalse($invitation->isAccepted());

        Notification::assertSentTo($invitation, WorkspaceInvitationNotification::class);
    }

    public function test_invitation_token_is_hashed(): void
    {
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $invitation = $workspace->invite('test@example.com', 'member');

        // Token should be hashed (bcrypt format)
        $this->assertTrue(str_starts_with($invitation->token, '$2y$'));
        $this->assertEquals(60, strlen($invitation->token));
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
        // Token should change when re-inviting (new token generated and hashed)
        $this->assertNotEquals($firstToken, $second->fresh()->token);
        $this->assertEquals('admin', $second->role);

        // Should only have one invitation
        $this->assertEquals(1, $workspace->invitations()->count());
    }

    public function test_static_accept_invitation_method(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        // Use a known plaintext token
        $plaintextToken = 'test-plaintext-token-for-acceptance-testing-1234567890';

        $invitation = WorkspaceInvitation::factory()
            ->withPlaintextToken($plaintextToken)
            ->create([
                'workspace_id' => $workspace->id,
                'role' => 'member',
            ]);

        // Accept using the plaintext token (model stores hashed version)
        $result = Workspace::acceptInvitation($plaintextToken, $user);

        $this->assertTrue($result);
        $this->assertTrue($workspace->users()->where('user_id', $user->id)->exists());
    }

    public function test_find_by_token_uses_hash_check(): void
    {
        $workspace = Workspace::factory()->create();

        $plaintextToken = 'my-secret-plaintext-token-for-testing-hash-lookup';

        $invitation = WorkspaceInvitation::factory()
            ->withPlaintextToken($plaintextToken)
            ->create([
                'workspace_id' => $workspace->id,
            ]);

        // findByToken should find the invitation using the plaintext token
        $found = WorkspaceInvitation::findByToken($plaintextToken);

        $this->assertNotNull($found);
        $this->assertEquals($invitation->id, $found->id);

        // Token in database should be hashed
        $this->assertTrue(str_starts_with($found->token, '$2y$'));

        // Hash::check should verify the plaintext against the stored hash
        $this->assertTrue(Hash::check($plaintextToken, $found->token));
    }

    public function test_verify_token_method(): void
    {
        $workspace = Workspace::factory()->create();
        $plaintextToken = 'plaintext-token-for-verify-method-test';

        $invitation = WorkspaceInvitation::factory()
            ->withPlaintextToken($plaintextToken)
            ->create([
                'workspace_id' => $workspace->id,
            ]);

        $this->assertTrue($invitation->verifyToken($plaintextToken));
        $this->assertFalse($invitation->verifyToken('wrong-token'));
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

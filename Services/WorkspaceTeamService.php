<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Models\WorkspaceMember;
use Core\Mod\Tenant\Models\WorkspaceTeam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workspace Team Service - manages workspace teams and member permissions.
 */
class WorkspaceTeamService
{
    protected ?Workspace $workspace = null;

    public function __construct(?Workspace $workspace = null)
    {
        $this->workspace = $workspace;
    }

    /**
     * Set the workspace context.
     */
    public function forWorkspace(Workspace $workspace): self
    {
        $this->workspace = $workspace;

        return $this;
    }

    /**
     * Get the current workspace, resolving from context if needed.
     */
    protected function getWorkspace(): ?Workspace
    {
        if ($this->workspace) {
            return $this->workspace;
        }

        // Try authenticated user's default workspace first
        $this->workspace = auth()->user()?->defaultHostWorkspace();

        // Fall back to session workspace if set
        if (! $this->workspace) {
            $sessionWorkspaceId = session('workspace_id');
            if ($sessionWorkspaceId) {
                $this->workspace = Workspace::find($sessionWorkspaceId);
            }
        }

        return $this->workspace;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Team Management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all teams for the workspace.
     */
    public function getTeams(): Collection
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return new Collection;
        }

        return WorkspaceTeam::where('workspace_id', $workspace->id)
            ->ordered()
            ->get();
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(int $teamId): ?WorkspaceTeam
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return null;
        }

        return WorkspaceTeam::where('workspace_id', $workspace->id)
            ->where('id', $teamId)
            ->first();
    }

    /**
     * Get a specific team by slug.
     */
    public function getTeamBySlug(string $slug): ?WorkspaceTeam
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return null;
        }

        return WorkspaceTeam::where('workspace_id', $workspace->id)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Get the default team for new members.
     */
    public function getDefaultTeam(): ?WorkspaceTeam
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return null;
        }

        return WorkspaceTeam::where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Create a new team.
     */
    public function createTeam(array $data): WorkspaceTeam
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            throw new \RuntimeException('No workspace context available.');
        }

        $team = WorkspaceTeam::create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'] ?? [],
            'is_default' => $data['is_default'] ?? false,
            'is_system' => $data['is_system'] ?? false,
            'colour' => $data['colour'] ?? 'zinc',
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        // If this is the new default, unset other defaults
        if ($team->is_default) {
            WorkspaceTeam::where('workspace_id', $workspace->id)
                ->where('id', '!=', $team->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        Log::info('Workspace team created', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'workspace_id' => $workspace->id,
        ]);

        return $team;
    }

    /**
     * Update an existing team.
     */
    public function updateTeam(WorkspaceTeam $team, array $data): WorkspaceTeam
    {
        $workspace = $this->getWorkspace();

        // Don't allow updating system teams' slug
        if ($team->is_system && isset($data['slug'])) {
            unset($data['slug']);
        }

        $team->update($data);

        // If this is the new default, unset other defaults
        if (($data['is_default'] ?? false) && $workspace) {
            WorkspaceTeam::where('workspace_id', $workspace->id)
                ->where('id', '!=', $team->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        Log::info('Workspace team updated', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'workspace_id' => $team->workspace_id,
        ]);

        return $team;
    }

    /**
     * Delete a team (only non-system teams).
     */
    public function deleteTeam(WorkspaceTeam $team): bool
    {
        if ($team->is_system) {
            throw new \RuntimeException('Cannot delete system teams.');
        }

        // Check if team has any members assigned
        $memberCount = WorkspaceMember::where('team_id', $team->id)->count();
        if ($memberCount > 0) {
            throw new \RuntimeException(
                "Cannot delete team with {$memberCount} assigned members. Remove members first."
            );
        }

        $teamId = $team->id;
        $teamName = $team->name;
        $workspaceId = $team->workspace_id;

        $team->delete();

        Log::info('Workspace team deleted', [
            'team_id' => $teamId,
            'team_name' => $teamName,
            'workspace_id' => $workspaceId,
        ]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Member Management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a member record for a user in the workspace.
     */
    public function getMember(User|int $user): ?WorkspaceMember
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return null;
        }

        $userId = $user instanceof User ? $user->id : $user;

        return WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get all members in the workspace.
     */
    public function getMembers(): Collection
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return new Collection;
        }

        return WorkspaceMember::where('workspace_id', $workspace->id)
            ->with(['user', 'team', 'inviter'])
            ->get();
    }

    /**
     * Get all members in a specific team.
     */
    public function getTeamMembers(WorkspaceTeam|int $team): Collection
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return new Collection;
        }

        $teamId = $team instanceof WorkspaceTeam ? $team->id : $team;

        return WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('team_id', $teamId)
            ->with(['user', 'team', 'inviter'])
            ->get();
    }

    /**
     * Add a member to a team.
     */
    public function addMemberToTeam(User|int $user, WorkspaceTeam|int $team): WorkspaceMember
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            throw new \RuntimeException('No workspace context available.');
        }

        $userId = $user instanceof User ? $user->id : $user;
        $teamId = $team instanceof WorkspaceTeam ? $team->id : $team;

        // Verify team belongs to workspace
        $teamModel = WorkspaceTeam::where('workspace_id', $workspace->id)
            ->where('id', $teamId)
            ->first();

        if (! $teamModel) {
            throw new \RuntimeException('Team does not belong to the current workspace.');
        }

        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if (! $member) {
            throw new \RuntimeException('User is not a member of this workspace.');
        }

        $member->update(['team_id' => $teamId]);

        Log::info('Member added to team', [
            'user_id' => $userId,
            'team_id' => $teamId,
            'team_name' => $teamModel->name,
            'workspace_id' => $workspace->id,
        ]);

        return $member->fresh();
    }

    /**
     * Remove a member from their team.
     */
    public function removeMemberFromTeam(User|int $user): WorkspaceMember
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            throw new \RuntimeException('No workspace context available.');
        }

        $userId = $user instanceof User ? $user->id : $user;

        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if (! $member) {
            throw new \RuntimeException('User is not a member of this workspace.');
        }

        $oldTeamId = $member->team_id;
        $member->update(['team_id' => null]);

        Log::info('Member removed from team', [
            'user_id' => $userId,
            'old_team_id' => $oldTeamId,
            'workspace_id' => $workspace->id,
        ]);

        return $member->fresh();
    }

    /**
     * Set custom permissions for a member.
     */
    public function setMemberCustomPermissions(User|int $user, array $customPermissions): WorkspaceMember
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            throw new \RuntimeException('No workspace context available.');
        }

        $userId = $user instanceof User ? $user->id : $user;

        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if (! $member) {
            throw new \RuntimeException('User is not a member of this workspace.');
        }

        $member->update(['custom_permissions' => $customPermissions]);

        Log::info('Member custom permissions updated', [
            'user_id' => $userId,
            'workspace_id' => $workspace->id,
            'custom_permissions' => $customPermissions,
        ]);

        return $member->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Permission Checks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all effective permissions for a user in the workspace.
     */
    public function getMemberPermissions(User|int $user): array
    {
        $member = $this->getMember($user);

        if (! $member) {
            return [];
        }

        return $member->getEffectivePermissions();
    }

    /**
     * Check if a user has a specific permission in the workspace.
     */
    public function hasPermission(User|int $user, string $permission): bool
    {
        $member = $this->getMember($user);

        if (! $member) {
            return false;
        }

        return $member->hasPermission($permission);
    }

    /**
     * Check if a user has any of the given permissions.
     */
    public function hasAnyPermission(User|int $user, array $permissions): bool
    {
        $member = $this->getMember($user);

        if (! $member) {
            return false;
        }

        return $member->hasAnyPermission($permissions);
    }

    /**
     * Check if a user has all of the given permissions.
     */
    public function hasAllPermissions(User|int $user, array $permissions): bool
    {
        $member = $this->getMember($user);

        if (! $member) {
            return false;
        }

        return $member->hasAllPermissions($permissions);
    }

    /**
     * Check if a user is the workspace owner.
     */
    public function isOwner(User|int $user): bool
    {
        $member = $this->getMember($user);

        return $member?->isOwner() ?? false;
    }

    /**
     * Check if a user is a workspace admin.
     */
    public function isAdmin(User|int $user): bool
    {
        $member = $this->getMember($user);

        return $member?->isAdmin() ?? false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Member Queries
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get members with a specific permission.
     */
    public function getMembersWithPermission(string $permission): Collection
    {
        $members = $this->getMembers();

        return $members->filter(fn ($member) => $member->hasPermission($permission));
    }

    /**
     * Count members in the workspace.
     */
    public function countMembers(): int
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return 0;
        }

        return WorkspaceMember::where('workspace_id', $workspace->id)->count();
    }

    /**
     * Count members in a specific team.
     */
    public function countTeamMembers(WorkspaceTeam|int $team): int
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return 0;
        }

        $teamId = $team instanceof WorkspaceTeam ? $team->id : $team;

        return WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('team_id', $teamId)
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seeding
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Seed default teams for a workspace.
     */
    public function seedDefaultTeams(?Workspace $workspace = null): Collection
    {
        $workspace = $workspace ?? $this->getWorkspace();
        if (! $workspace) {
            throw new \RuntimeException('No workspace context available for seeding.');
        }

        $teams = new Collection;

        foreach (WorkspaceTeam::getDefaultTeamDefinitions() as $definition) {
            // Check if team already exists
            $existing = WorkspaceTeam::where('workspace_id', $workspace->id)
                ->where('slug', $definition['slug'])
                ->first();

            if ($existing) {
                $teams->push($existing);

                continue;
            }

            $team = WorkspaceTeam::create([
                'workspace_id' => $workspace->id,
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['description'],
                'permissions' => $definition['permissions'],
                'is_default' => $definition['is_default'] ?? false,
                'is_system' => $definition['is_system'] ?? false,
                'colour' => $definition['colour'] ?? 'zinc',
                'sort_order' => $definition['sort_order'] ?? 0,
            ]);

            $teams->push($team);
        }

        Log::info('Default workspace teams seeded', [
            'workspace_id' => $workspace->id,
            'teams_count' => $teams->count(),
        ]);

        return $teams;
    }

    /**
     * Ensure default teams exist for the workspace, creating them if needed.
     */
    public function ensureDefaultTeams(): Collection
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return new Collection;
        }

        // Check if any teams exist
        $existingCount = WorkspaceTeam::where('workspace_id', $workspace->id)->count();

        if ($existingCount === 0) {
            return $this->seedDefaultTeams($workspace);
        }

        return $this->getTeams();
    }

    /**
     * Migrate existing members to appropriate teams based on their role.
     */
    public function migrateExistingMembers(): int
    {
        $workspace = $this->getWorkspace();
        if (! $workspace) {
            return 0;
        }

        // Ensure teams exist
        $this->ensureDefaultTeams();

        $ownerTeam = $this->getTeamBySlug(WorkspaceTeam::TEAM_OWNER);
        $adminTeam = $this->getTeamBySlug(WorkspaceTeam::TEAM_ADMIN);
        $memberTeam = $this->getTeamBySlug(WorkspaceTeam::TEAM_MEMBER);

        $migrated = 0;

        DB::transaction(function () use ($workspace, $ownerTeam, $adminTeam, $memberTeam, &$migrated) {
            // Get members without team assignments
            $members = WorkspaceMember::where('workspace_id', $workspace->id)
                ->whereNull('team_id')
                ->get();

            foreach ($members as $member) {
                $teamId = match ($member->role) {
                    WorkspaceMember::ROLE_OWNER => $ownerTeam?->id,
                    WorkspaceMember::ROLE_ADMIN => $adminTeam?->id,
                    default => $memberTeam?->id,
                };

                if ($teamId) {
                    $member->update([
                        'team_id' => $teamId,
                        'joined_at' => $member->joined_at ?? $member->created_at,
                    ]);
                    $migrated++;
                }
            }
        });

        Log::info('Workspace members migrated to teams', [
            'workspace_id' => $workspace->id,
            'migrated_count' => $migrated,
        ]);

        return $migrated;
    }
}

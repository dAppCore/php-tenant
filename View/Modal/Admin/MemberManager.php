<?php

declare(strict_types=1);

namespace Core\Tenant\View\Modal\Admin;

use Core\Core\Tenant\Models\Workspace;
use Core\Core\Tenant\Models\WorkspaceMember;
use Core\Core\Tenant\Models\WorkspaceTeam;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class MemberManager extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public ?int $workspaceFilter = null;

    public ?int $teamFilter = null;

    // Assign to team modal
    public bool $showAssignModal = false;

    public ?int $assignMemberId = null;

    public ?int $assignTeamId = null;

    // Custom permissions modal
    public bool $showPermissionsModal = false;

    public ?int $permissionsMemberId = null;

    public array $grantedPermissions = [];

    public array $revokedPermissions = [];

    // Bulk selection
    public array $selected = [];

    public bool $selectAll = false;

    // Bulk assign modal
    public bool $showBulkAssignModal = false;

    public ?int $bulkTeamId = null;

    public function mount(?int $workspaceFilter = null, ?int $teamFilter = null): void
    {
        $this->checkHadesAccess();
        $this->workspaceFilter = $workspaceFilter;
        $this->teamFilter = $teamFilter;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingWorkspaceFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->teamFilter = null;
    }

    public function updatingTeamFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->members->pluck('id')->map(fn ($id) => (string) $id)->toArray()
            : [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Team Assignment
    // ─────────────────────────────────────────────────────────────────────────

    public function openAssignModal(int $memberId): void
    {
        $member = WorkspaceMember::findOrFail($memberId);

        $this->assignMemberId = $memberId;
        $this->assignTeamId = $member->team_id;
        $this->showAssignModal = true;
    }

    public function saveAssignment(): void
    {
        $member = WorkspaceMember::findOrFail($this->assignMemberId);

        // Validate team belongs to same workspace
        if ($this->assignTeamId) {
            $team = WorkspaceTeam::where('id', $this->assignTeamId)
                ->where('workspace_id', $member->workspace_id)
                ->first();

            if (! $team) {
                session()->flash('error', __('tenant::tenant.admin.member_manager.messages.invalid_team'));

                return;
            }
        }

        $member->update(['team_id' => $this->assignTeamId]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.team_assigned'));
        $this->closeAssignModal();
    }

    public function removeFromTeam(int $memberId): void
    {
        $member = WorkspaceMember::findOrFail($memberId);
        $member->update(['team_id' => null]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.removed_from_team'));
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->assignMemberId = null;
        $this->assignTeamId = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Custom Permissions
    // ─────────────────────────────────────────────────────────────────────────

    public function openPermissionsModal(int $memberId): void
    {
        $member = WorkspaceMember::findOrFail($memberId);

        $this->permissionsMemberId = $memberId;
        $this->grantedPermissions = [];
        $this->revokedPermissions = [];

        // Parse existing custom permissions
        foreach ($member->custom_permissions ?? [] as $permission) {
            if (str_starts_with($permission, '-')) {
                $this->revokedPermissions[] = substr($permission, 1);
            } elseif (str_starts_with($permission, '+')) {
                $this->grantedPermissions[] = substr($permission, 1);
            } else {
                $this->grantedPermissions[] = $permission;
            }
        }

        $this->showPermissionsModal = true;
    }

    public function savePermissions(): void
    {
        $member = WorkspaceMember::findOrFail($this->permissionsMemberId);

        // Build custom permissions array
        $customPermissions = [];

        foreach ($this->grantedPermissions as $permission) {
            $customPermissions[] = '+'.$permission;
        }

        foreach ($this->revokedPermissions as $permission) {
            $customPermissions[] = '-'.$permission;
        }

        $member->update([
            'custom_permissions' => ! empty($customPermissions) ? $customPermissions : null,
        ]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.permissions_updated'));
        $this->closePermissionsModal();
    }

    public function clearPermissions(int $memberId): void
    {
        $member = WorkspaceMember::findOrFail($memberId);
        $member->update(['custom_permissions' => null]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.permissions_cleared'));
    }

    public function closePermissionsModal(): void
    {
        $this->showPermissionsModal = false;
        $this->permissionsMemberId = null;
        $this->grantedPermissions = [];
        $this->revokedPermissions = [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bulk Operations
    // ─────────────────────────────────────────────────────────────────────────

    public function openBulkAssignModal(): void
    {
        $this->bulkTeamId = null;
        $this->showBulkAssignModal = true;
    }

    public function closeBulkAssignModal(): void
    {
        $this->showBulkAssignModal = false;
        $this->bulkTeamId = null;
    }

    public function bulkAssignTeam(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('tenant::tenant.admin.member_manager.messages.no_members_selected'));

            return;
        }

        // Validate team exists
        if ($this->bulkTeamId) {
            $team = WorkspaceTeam::find($this->bulkTeamId);
            if (! $team) {
                session()->flash('error', __('tenant::tenant.admin.member_manager.messages.invalid_team'));

                return;
            }

            // Update only members from same workspace as the team
            $updated = WorkspaceMember::whereIn('id', $this->selected)
                ->where('workspace_id', $team->workspace_id)
                ->update(['team_id' => $this->bulkTeamId]);
        } else {
            // Remove from teams
            $updated = WorkspaceMember::whereIn('id', $this->selected)
                ->update(['team_id' => null]);
        }

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.bulk_team_assigned', ['count' => $updated]));
        $this->closeBulkAssignModal();
        $this->clearSelection();
    }

    public function bulkRemoveFromTeam(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('tenant::tenant.admin.member_manager.messages.no_members_selected'));

            return;
        }

        $updated = WorkspaceMember::whereIn('id', $this->selected)
            ->update(['team_id' => null]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.bulk_removed_from_team', ['count' => $updated]));
        $this->clearSelection();
    }

    public function bulkClearPermissions(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('tenant::tenant.admin.member_manager.messages.no_members_selected'));

            return;
        }

        $updated = WorkspaceMember::whereIn('id', $this->selected)
            ->update(['custom_permissions' => null]);

        session()->flash('message', __('tenant::tenant.admin.member_manager.messages.bulk_permissions_cleared', ['count' => $updated]));
        $this->clearSelection();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Computed Properties
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function members(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return WorkspaceMember::query()
            ->with(['user', 'workspace', 'team', 'inviter'])
            ->when($this->search, function ($query) {
                $query->whereHas('user', function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->workspaceFilter, function ($query) {
                $query->where('workspace_id', $this->workspaceFilter);
            })
            ->when($this->teamFilter, function ($query) {
                $query->where('team_id', $this->teamFilter);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    #[Computed]
    public function workspaces(): \Illuminate\Database\Eloquent\Collection
    {
        return Workspace::orderBy('name')->get();
    }

    #[Computed]
    public function teamsForFilter(): \Illuminate\Database\Eloquent\Collection
    {
        $query = WorkspaceTeam::query();

        if ($this->workspaceFilter) {
            $query->where('workspace_id', $this->workspaceFilter);
        }

        return $query->ordered()->get();
    }

    #[Computed]
    public function teamsForAssignment(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->assignMemberId) {
            $member = WorkspaceMember::find($this->assignMemberId);
            if ($member) {
                return WorkspaceTeam::where('workspace_id', $member->workspace_id)
                    ->ordered()
                    ->get();
            }
        }

        return new \Illuminate\Database\Eloquent\Collection;
    }

    #[Computed]
    public function teamsForBulkAssignment(): \Illuminate\Database\Eloquent\Collection
    {
        // Only show teams from the current workspace filter
        if ($this->workspaceFilter) {
            return WorkspaceTeam::where('workspace_id', $this->workspaceFilter)
                ->ordered()
                ->get();
        }

        return new \Illuminate\Database\Eloquent\Collection;
    }

    #[Computed]
    public function permissionGroups(): array
    {
        return WorkspaceTeam::getAvailablePermissions();
    }

    #[Computed]
    public function memberForPermissions(): ?WorkspaceMember
    {
        if ($this->permissionsMemberId) {
            return WorkspaceMember::with(['team'])->find($this->permissionsMemberId);
        }

        return null;
    }

    #[Computed]
    public function stats(): array
    {
        $query = WorkspaceMember::query();

        if ($this->workspaceFilter) {
            $query->where('workspace_id', $this->workspaceFilter);
        }

        if ($this->teamFilter) {
            $query->where('team_id', $this->teamFilter);
        }

        return [
            'total_members' => (clone $query)->count(),
            'with_team' => (clone $query)->whereNotNull('team_id')->count(),
            'with_custom_permissions' => (clone $query)->whereNotNull('custom_permissions')->count(),
        ];
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, __('tenant::tenant.errors.hades_required'));
        }
    }

    public function render(): View
    {
        return view('tenant::admin.member-manager')
            ->layout('hub::admin.layouts.app', ['title' => __('tenant::tenant.admin.member_manager.title')]);
    }
}

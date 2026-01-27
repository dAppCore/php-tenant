<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\View\Modal\Admin;

use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Models\WorkspaceMember;
use Core\Mod\Tenant\Models\WorkspaceTeam;
use Core\Mod\Tenant\Services\WorkspaceTeamService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class TeamManager extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public ?int $workspaceFilter = null;

    // Team modal
    public bool $showTeamModal = false;

    public ?int $editingTeamId = null;

    // Team form fields
    public string $teamName = '';

    public string $teamSlug = '';

    public string $teamDescription = '';

    public array $teamPermissions = [];

    public bool $teamIsDefault = false;

    public string $teamColour = 'zinc';

    public ?int $teamWorkspaceId = null;

    // Bulk selection
    public array $selected = [];

    public bool $selectAll = false;

    public function mount(): void
    {
        $this->checkHadesAccess();
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
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->teams->pluck('id')->map(fn ($id) => (string) $id)->toArray()
            : [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Team CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function openCreateTeam(): void
    {
        $this->resetTeamForm();
        $this->showTeamModal = true;
    }

    public function openEditTeam(int $id): void
    {
        $team = WorkspaceTeam::findOrFail($id);

        $this->editingTeamId = $id;
        $this->teamName = $team->name;
        $this->teamSlug = $team->slug ?? '';
        $this->teamDescription = $team->description ?? '';
        $this->teamPermissions = $team->permissions ?? [];
        $this->teamIsDefault = $team->is_default;
        $this->teamColour = $team->colour ?? 'zinc';
        $this->teamWorkspaceId = $team->workspace_id;

        $this->showTeamModal = true;
    }

    public function saveTeam(): void
    {
        $this->validate([
            'teamName' => ['required', 'string', 'max:255'],
            'teamSlug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'teamDescription' => ['nullable', 'string', 'max:1000'],
            'teamPermissions' => ['array'],
            'teamIsDefault' => ['boolean'],
            'teamColour' => ['required', 'string', 'max:32'],
            'teamWorkspaceId' => ['required', 'exists:workspaces,id'],
        ]);

        $data = [
            'name' => $this->teamName,
            'description' => $this->teamDescription ?: null,
            'permissions' => $this->teamPermissions,
            'is_default' => $this->teamIsDefault,
            'colour' => $this->teamColour,
            'workspace_id' => $this->teamWorkspaceId,
        ];

        // Only set slug for new teams or if explicitly provided
        if (! $this->editingTeamId && $this->teamSlug) {
            $data['slug'] = $this->teamSlug;
        }

        // If setting as default, unset other defaults for this workspace
        if ($this->teamIsDefault) {
            WorkspaceTeam::where('workspace_id', $this->teamWorkspaceId)
                ->where('is_default', true)
                ->when($this->editingTeamId, fn ($q) => $q->where('id', '!=', $this->editingTeamId))
                ->update(['is_default' => false]);
        }

        if ($this->editingTeamId) {
            $team = WorkspaceTeam::findOrFail($this->editingTeamId);

            // Don't allow editing system team slug
            if ($team->is_system) {
                unset($data['slug']);
            }

            $team->update($data);
            session()->flash('message', __('tenant::tenant.admin.team_manager.messages.team_updated'));
        } else {
            WorkspaceTeam::create($data);
            session()->flash('message', __('tenant::tenant.admin.team_manager.messages.team_created'));
        }

        $this->closeTeamModal();
    }

    public function deleteTeam(int $id): void
    {
        $team = WorkspaceTeam::findOrFail($id);

        if ($team->is_system) {
            session()->flash('error', __('tenant::tenant.admin.team_manager.messages.cannot_delete_system'));

            return;
        }

        $memberCount = WorkspaceMember::where('team_id', $team->id)->count();
        if ($memberCount > 0) {
            session()->flash('error', __('tenant::tenant.admin.team_manager.messages.cannot_delete_has_members', ['count' => $memberCount]));

            return;
        }

        $team->delete();
        session()->flash('message', __('tenant::tenant.admin.team_manager.messages.team_deleted'));
    }

    public function closeTeamModal(): void
    {
        $this->showTeamModal = false;
        $this->resetTeamForm();
    }

    protected function resetTeamForm(): void
    {
        $this->editingTeamId = null;
        $this->teamName = '';
        $this->teamSlug = '';
        $this->teamDescription = '';
        $this->teamPermissions = [];
        $this->teamIsDefault = false;
        $this->teamColour = 'zinc';
        $this->teamWorkspaceId = null;
    }

    public function seedDefaultTeams(int $workspaceId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);

        $teamService = app(WorkspaceTeamService::class)->forWorkspace($workspace);
        $teamService->seedDefaultTeams();

        session()->flash('message', __('tenant::tenant.admin.team_manager.messages.defaults_seeded'));
    }

    public function migrateMembers(int $workspaceId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);

        $teamService = app(WorkspaceTeamService::class)->forWorkspace($workspace);
        $migrated = $teamService->migrateExistingMembers();

        session()->flash('message', __('tenant::tenant.admin.team_manager.messages.members_migrated', ['count' => $migrated]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Computed Properties
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function teams(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return WorkspaceTeam::query()
            ->with(['workspace'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%");
                });
            })
            ->when($this->workspaceFilter, function ($query) {
                $query->where('workspace_id', $this->workspaceFilter);
            })
            ->withCount('members')
            ->orderBy('workspace_id')
            ->ordered()
            ->paginate(20);
    }

    #[Computed]
    public function workspaces(): \Illuminate\Database\Eloquent\Collection
    {
        return Workspace::orderBy('name')->get();
    }

    #[Computed]
    public function permissionGroups(): array
    {
        return WorkspaceTeam::getAvailablePermissions();
    }

    #[Computed]
    public function colourOptions(): array
    {
        return WorkspaceTeam::getColourOptions();
    }

    #[Computed]
    public function stats(): array
    {
        $teamQuery = WorkspaceTeam::query();
        $memberQuery = WorkspaceMember::query();

        if ($this->workspaceFilter) {
            $teamQuery->where('workspace_id', $this->workspaceFilter);
            $memberQuery->where('workspace_id', $this->workspaceFilter);
        }

        return [
            'total_teams' => $teamQuery->count(),
            'total_members' => $memberQuery->count(),
            'members_with_team' => (clone $memberQuery)->whereNotNull('team_id')->count(),
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
        return view('tenant::admin.team-manager')
            ->layout('hub::admin.layouts.app', ['title' => __('tenant::tenant.admin.team_manager.title')]);
    }
}

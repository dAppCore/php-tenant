<?php

namespace Core\Mod\Tenant\View\Modal\Admin;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class WorkspaceManager extends Component
{
    use WithPagination;

    // Search and filtering
    public string $search = '';

    // Edit modal state
    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public bool $isActive = true;

    // Transfer modal state
    public bool $showTransferModal = false;

    public ?int $sourceWorkspaceId = null;

    public ?int $targetWorkspaceId = null;

    public array $selectedResourceTypes = [];

    // Change owner modal state
    public bool $showOwnerModal = false;

    public ?int $ownerWorkspaceId = null;

    public ?int $newOwnerId = null;

    // Resource viewer modal state
    public bool $showResourcesModal = false;

    public ?int $resourcesWorkspaceId = null;

    public ?string $resourcesType = null;

    public array $selectedResources = [];

    public ?int $resourcesTargetWorkspaceId = null;

    // Provision modal state
    public bool $showProvisionModal = false;

    public ?int $provisionWorkspaceId = null;

    public ?string $provisionType = null;

    public string $provisionName = '';

    public string $provisionUrl = '';

    public string $provisionSlug = '';

    // Action messages
    public string $actionMessage = '';

    public string $actionType = '';

    protected $queryString = [
        'search' => ['except' => ''],
    ];

    protected array $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|alpha_dash',
        'isActive' => 'boolean',
    ];

    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for workspace administration.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function workspaces()
    {
        return Workspace::query()
            ->withCount($this->getAvailableRelations())
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('slug', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(20);
    }

    /**
     * Get relations that are available for counting.
     * Filters out relations whose models don't exist yet or have incompatible schemas.
     */
    protected function getAvailableRelations(): array
    {
        $relations = [];

        // Check each relation's model exists and has workspace_id column
        $checks = [
            'bioPages' => ['model' => \Core\Mod\Web\Models\Page::class, 'table' => 'pages'],
            'bioProjects' => ['model' => \Core\Mod\Web\Models\Project::class, 'table' => 'page_projects'],
            'socialAccounts' => ['model' => \Core\Mod\Social\Models\Account::class, 'table' => 'social_accounts'],
            'analyticsSites' => ['model' => \Core\Mod\Analytics\Models\Website::class, 'table' => 'analytics_websites'],
            'trustWidgets' => ['model' => \Core\Mod\Trust\Models\Campaign::class, 'table' => 'trust_campaigns'],
            'notificationSites' => ['model' => \Core\Mod\Notify\Models\PushWebsite::class, 'table' => 'push_websites'],
        ];

        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();

        foreach ($checks as $relation => $info) {
            if (class_exists($info['model'])) {
                // Verify the table has workspace_id column
                try {
                    if ($schema->hasColumn($info['table'], 'workspace_id')) {
                        $relations[] = $relation;
                    }
                } catch (\Exception $e) {
                    // Table might not exist yet, skip
                }
            }
        }

        return $relations;
    }

    #[Computed]
    public function allWorkspaces()
    {
        return Workspace::orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function resourceTypes(): array
    {
        $types = [];
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();

        // Only include resource types for models that exist and have valid relations
        $checks = [
            'bio_pages' => ['model' => \Core\Mod\Web\Models\Page::class, 'table' => 'pages', 'label' => 'Bio Pages', 'relation' => 'bioPages', 'icon' => 'link'],
            'bio_projects' => ['model' => \Core\Mod\Web\Models\Project::class, 'table' => 'page_projects', 'label' => 'Bio Projects', 'relation' => 'bioProjects', 'icon' => 'folder'],
            'social_accounts' => ['model' => \Core\Mod\Social\Models\Account::class, 'table' => 'social_accounts', 'label' => 'Social Accounts', 'relation' => 'socialAccounts', 'icon' => 'share-nodes'],
            'analytics_sites' => ['model' => \Core\Mod\Analytics\Models\Website::class, 'table' => 'analytics_websites', 'label' => 'Analytics Sites', 'relation' => 'analyticsSites', 'icon' => 'chart-line'],
            'trust_widgets' => ['model' => \Core\Mod\Trust\Models\Campaign::class, 'table' => 'trust_campaigns', 'label' => 'Trust Campaigns', 'relation' => 'trustWidgets', 'icon' => 'shield-check'],
            'notification_sites' => ['model' => \Core\Mod\Notify\Models\PushWebsite::class, 'table' => 'push_websites', 'label' => 'Notification Sites', 'relation' => 'notificationSites', 'icon' => 'bell'],
        ];

        foreach ($checks as $key => $info) {
            if (class_exists($info['model'])) {
                try {
                    if ($schema->hasColumn($info['table'], 'workspace_id')) {
                        $types[$key] = [
                            'label' => $info['label'],
                            'relation' => $info['relation'],
                            'icon' => $info['icon'],
                        ];
                    }
                } catch (\Exception $e) {
                    // Table might not exist yet, skip
                }
            }
        }

        return $types;
    }

    public function openEdit(int $id): void
    {
        $workspace = Workspace::findOrFail($id);
        $this->editingId = $id;
        $this->name = $workspace->name;
        $this->slug = $workspace->slug;
        $this->isActive = $workspace->is_active;
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
        $this->reset(['name', 'slug', 'isActive']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate();

        $workspace = Workspace::findOrFail($this->editingId);

        // Check if slug is unique (excluding current workspace)
        $slugExists = Workspace::where('slug', $this->slug)
            ->where('id', '!=', $this->editingId)
            ->exists();

        if ($slugExists) {
            $this->addError('slug', 'This slug is already in use.');

            return;
        }

        $workspace->update([
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->isActive,
        ]);

        $this->closeEdit();
        $this->actionMessage = "Workspace '{$workspace->name}' updated successfully.";
        $this->actionType = 'success';
        unset($this->workspaces);
    }

    public function delete(int $id): void
    {
        $workspace = Workspace::withCount($this->getAvailableRelations())->findOrFail($id);

        // Check for resources (safely get counts that might not exist)
        $totalResources = ($workspace->bio_pages_count ?? 0)
            + ($workspace->bio_projects_count ?? 0)
            + ($workspace->social_accounts_count ?? 0)
            + ($workspace->analytics_sites_count ?? 0)
            + ($workspace->trust_widgets_count ?? 0)
            + ($workspace->notification_sites_count ?? 0)
            + ($workspace->orders_count ?? 0);

        if ($totalResources > 0) {
            $this->actionMessage = "Cannot delete workspace '{$workspace->name}'. It has {$totalResources} resources. Transfer or delete them first.";
            $this->actionType = 'error';

            return;
        }

        // Check for users
        if ($workspace->users()->count() > 0) {
            $this->actionMessage = "Cannot delete workspace '{$workspace->name}'. It still has users assigned.";
            $this->actionType = 'error';

            return;
        }

        $workspaceName = $workspace->name;
        $workspace->delete();

        $this->actionMessage = "Workspace '{$workspaceName}' deleted successfully.";
        $this->actionType = 'success';
        unset($this->workspaces);
    }

    public function openTransfer(int $workspaceId): void
    {
        $this->sourceWorkspaceId = $workspaceId;
        $this->targetWorkspaceId = null;
        $this->selectedResourceTypes = [];
        $this->showTransferModal = true;
    }

    public function closeTransfer(): void
    {
        $this->showTransferModal = false;
        $this->reset(['sourceWorkspaceId', 'targetWorkspaceId', 'selectedResourceTypes']);
    }

    public function executeTransfer(): void
    {
        if (! $this->sourceWorkspaceId || ! $this->targetWorkspaceId) {
            $this->actionMessage = 'Please select both source and target workspaces.';
            $this->actionType = 'error';

            return;
        }

        if ($this->sourceWorkspaceId === $this->targetWorkspaceId) {
            $this->actionMessage = 'Source and target workspaces cannot be the same.';
            $this->actionType = 'error';

            return;
        }

        if (empty($this->selectedResourceTypes)) {
            $this->actionMessage = 'Please select at least one resource type to transfer.';
            $this->actionType = 'error';

            return;
        }

        $source = Workspace::findOrFail($this->sourceWorkspaceId);
        $target = Workspace::findOrFail($this->targetWorkspaceId);
        $resourceTypes = $this->resourceTypes;
        $transferred = [];

        DB::transaction(function () use ($source, $target, $resourceTypes, &$transferred) {
            foreach ($this->selectedResourceTypes as $type) {
                if (! isset($resourceTypes[$type])) {
                    continue;
                }

                $relation = $resourceTypes[$type]['relation'];
                $count = $source->{$relation}()->count();

                if ($count > 0) {
                    $source->{$relation}()->update(['workspace_id' => $target->id]);
                    $transferred[$resourceTypes[$type]['label']] = $count;
                }
            }
        });

        $this->closeTransfer();

        if (empty($transferred)) {
            $this->actionMessage = 'No resources were transferred (source had no resources of selected types).';
            $this->actionType = 'warning';
        } else {
            $summary = collect($transferred)
                ->map(fn ($count, $label) => "{$count} {$label}")
                ->join(', ');
            $this->actionMessage = "Transferred {$summary} from '{$source->name}' to '{$target->name}'.";
            $this->actionType = 'success';
        }

        unset($this->workspaces);
    }

    #[Computed]
    public function allUsers()
    {
        return User::orderBy('name')->get(['id', 'name', 'email']);
    }

    public function openChangeOwner(int $workspaceId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $this->ownerWorkspaceId = $workspaceId;
        $this->newOwnerId = $workspace->owner()?->id;
        $this->showOwnerModal = true;
    }

    public function closeChangeOwner(): void
    {
        $this->showOwnerModal = false;
        $this->reset(['ownerWorkspaceId', 'newOwnerId']);
    }

    public function changeOwner(): void
    {
        if (! $this->ownerWorkspaceId || ! $this->newOwnerId) {
            $this->actionMessage = 'Please select a new owner.';
            $this->actionType = 'error';

            return;
        }

        $workspace = Workspace::findOrFail($this->ownerWorkspaceId);
        $newOwner = User::findOrFail($this->newOwnerId);
        $oldOwner = $workspace->owner();

        DB::transaction(function () use ($workspace, $newOwner, $oldOwner) {
            // Remove owner role from current owner (if exists)
            if ($oldOwner) {
                $workspace->users()->updateExistingPivot($oldOwner->id, ['role' => 'member']);
            }

            // Check if new owner is already a member
            if ($workspace->users()->where('user_id', $newOwner->id)->exists()) {
                // Update existing membership to owner
                $workspace->users()->updateExistingPivot($newOwner->id, ['role' => 'owner']);
            } else {
                // Add new owner to workspace
                $workspace->users()->attach($newOwner->id, ['role' => 'owner']);
            }
        });

        $this->closeChangeOwner();
        $this->actionMessage = "Ownership of '{$workspace->name}' transferred to {$newOwner->name}.";
        $this->actionType = 'success';
        unset($this->workspaces);
    }

    public function openResources(int $workspaceId, string $type): void
    {
        $this->resourcesWorkspaceId = $workspaceId;
        $this->resourcesType = $type;
        $this->selectedResources = [];
        $this->resourcesTargetWorkspaceId = null;
        $this->showResourcesModal = true;
    }

    public function closeResources(): void
    {
        $this->showResourcesModal = false;
        $this->reset(['resourcesWorkspaceId', 'resourcesType', 'selectedResources', 'resourcesTargetWorkspaceId']);
    }

    #[Computed]
    public function currentResources(): array
    {
        if (! $this->resourcesWorkspaceId || ! $this->resourcesType) {
            return [];
        }

        $resourceTypes = $this->resourceTypes;
        if (! isset($resourceTypes[$this->resourcesType])) {
            return [];
        }

        $workspace = Workspace::find($this->resourcesWorkspaceId);
        if (! $workspace) {
            return [];
        }

        $relation = $resourceTypes[$this->resourcesType]['relation'];

        return $workspace->{$relation}()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name ?? $item->title ?? "#{$item->id}",
                    'detail' => $item->url ?? $item->domain ?? $item->email ?? $item->slug ?? null,
                    'created_at' => $item->created_at?->format('d M Y'),
                ];
            })
            ->toArray();
    }

    public function toggleResourceSelection(int $id): void
    {
        if (in_array($id, $this->selectedResources)) {
            $this->selectedResources = array_values(array_diff($this->selectedResources, [$id]));
        } else {
            $this->selectedResources[] = $id;
        }
    }

    public function selectAllResources(): void
    {
        $this->selectedResources = collect($this->currentResources)->pluck('id')->toArray();
    }

    public function deselectAllResources(): void
    {
        $this->selectedResources = [];
    }

    public function transferSelectedResources(): void
    {
        if (empty($this->selectedResources)) {
            $this->actionMessage = 'Please select at least one resource to transfer.';
            $this->actionType = 'error';

            return;
        }

        if (! $this->resourcesTargetWorkspaceId) {
            $this->actionMessage = 'Please select a target workspace.';
            $this->actionType = 'error';

            return;
        }

        if ($this->resourcesWorkspaceId === $this->resourcesTargetWorkspaceId) {
            $this->actionMessage = 'Source and target workspaces cannot be the same.';
            $this->actionType = 'error';

            return;
        }

        $resourceTypes = $this->resourceTypes;
        if (! isset($resourceTypes[$this->resourcesType])) {
            $this->actionMessage = 'Invalid resource type.';
            $this->actionType = 'error';

            return;
        }

        $workspace = Workspace::findOrFail($this->resourcesWorkspaceId);
        $target = Workspace::findOrFail($this->resourcesTargetWorkspaceId);
        $relation = $resourceTypes[$this->resourcesType]['relation'];
        $label = $resourceTypes[$this->resourcesType]['label'];

        $count = $workspace->{$relation}()
            ->whereIn('id', $this->selectedResources)
            ->update(['workspace_id' => $target->id]);

        $this->closeResources();
        $this->actionMessage = "Transferred {$count} {$label} from '{$workspace->name}' to '{$target->name}'.";
        $this->actionType = 'success';
        unset($this->workspaces);
    }

    public function openProvision(int $workspaceId, string $type): void
    {
        $this->provisionWorkspaceId = $workspaceId;
        $this->provisionType = $type;
        $this->provisionName = '';
        $this->provisionUrl = '';
        $this->showProvisionModal = true;
    }

    public function closeProvision(): void
    {
        $this->showProvisionModal = false;
        $this->reset(['provisionWorkspaceId', 'provisionType', 'provisionName', 'provisionUrl', 'provisionSlug']);
    }

    #[Computed]
    public function provisionConfig(): array
    {
        return [
            'bio_pages' => [
                'label' => 'Bio Page',
                'icon' => 'link',
                'color' => 'blue',
                'fields' => ['name', 'slug'],
                'model' => \Core\Mod\Web\Models\Page::class,
                'defaults' => ['type' => 'page', 'is_enabled' => true],
            ],
            'social_accounts' => [
                'label' => 'Social Account',
                'icon' => 'share-nodes',
                'color' => 'purple',
                'fields' => ['name'],
                'model' => \Core\Mod\Social\Models\Account::class,
                'defaults' => ['provider' => 'manual', 'status' => 'active'],
            ],
            'analytics_sites' => [
                'label' => 'Analytics Site',
                'icon' => 'chart-line',
                'color' => 'cyan',
                'fields' => ['name', 'url'],
                'model' => \Core\Mod\Analytics\Models\Website::class,
                'defaults' => ['tracking_enabled' => true, 'is_enabled' => true],
            ],
            'trust_widgets' => [
                'label' => 'Trust Campaign',
                'icon' => 'shield-check',
                'color' => 'emerald',
                'fields' => ['name'],
                'model' => \Core\Mod\Trust\Models\Campaign::class,
                'defaults' => ['status' => 'draft'],
            ],
            'notification_sites' => [
                'label' => 'Notification Site',
                'icon' => 'bell',
                'color' => 'amber',
                'fields' => ['name', 'url'],
                'model' => \Core\Mod\Notify\Models\PushWebsite::class,
                'defaults' => ['status' => 'active'],
            ],
        ];
    }

    public function provisionResource(): void
    {
        $config = $this->provisionConfig[$this->provisionType] ?? null;

        if (! $config || ! class_exists($config['model'])) {
            $this->actionMessage = 'Invalid resource type or model not available.';
            $this->actionType = 'error';

            return;
        }

        if (empty($this->provisionName)) {
            $this->actionMessage = 'Please enter a name.';
            $this->actionType = 'error';

            return;
        }

        if (in_array('url', $config['fields']) && empty($this->provisionUrl)) {
            $this->actionMessage = 'Please enter a URL.';
            $this->actionType = 'error';

            return;
        }

        if (in_array('slug', $config['fields']) && empty($this->provisionSlug)) {
            $this->actionMessage = 'Please enter a slug.';
            $this->actionType = 'error';

            return;
        }

        $workspace = Workspace::findOrFail($this->provisionWorkspaceId);

        $data = array_merge($config['defaults'], [
            'workspace_id' => $workspace->id,
        ]);

        // Handle name - for bio pages it goes in settings
        if ($this->provisionType === 'bio_pages') {
            $data['settings'] = ['page_title' => $this->provisionName];
        } else {
            $data['name'] = $this->provisionName;
        }

        // Add slug for bio pages
        if (in_array('slug', $config['fields']) && $this->provisionSlug) {
            $data['url'] = \Illuminate\Support\Str::slug($this->provisionSlug);
        }

        // Add URL-related fields if applicable
        if (in_array('url', $config['fields']) && $this->provisionUrl) {
            $url = $this->provisionUrl;
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.$url;
            }
            $parsed = parse_url($url);
            $data['url'] = $url;
            $data['host'] = $parsed['host'] ?? null;
            $data['scheme'] = $parsed['scheme'] ?? 'https';
        }

        // Add user_id if the model expects it
        if (auth()->check()) {
            $data['user_id'] = auth()->id();
        }

        try {
            $config['model']::create($data);

            $this->closeProvision();
            $this->actionMessage = "{$config['label']} '{$this->provisionName}' created in '{$workspace->name}'.";
            $this->actionType = 'success';
            unset($this->workspaces);
        } catch (\Exception $e) {
            $this->actionMessage = "Failed to create resource: {$e->getMessage()}";
            $this->actionType = 'error';
        }
    }

    public function getStats(): array
    {
        return [
            'total' => Workspace::count(),
            'active' => Workspace::where('is_active', true)->count(),
            'inactive' => Workspace::where('is_active', false)->count(),
        ];
    }

    public function render()
    {
        return view('tenant::admin.workspace-manager', [
            'stats' => $this->getStats(),
        ])->layout('hub::admin.layouts.app', ['title' => 'Workspace Manager']);
    }
}

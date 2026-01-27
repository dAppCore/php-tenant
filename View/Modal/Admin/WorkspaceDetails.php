<?php

namespace Core\Mod\Tenant\View\Modal\Admin;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WorkspaceDetails extends Component
{
    public Workspace $workspace;

    public string $activeTab = 'overview';

    // Action messages
    public string $actionMessage = '';

    public string $actionType = '';

    // Add member modal
    public bool $showAddMemberModal = false;

    public ?int $newMemberId = null;

    public string $newMemberRole = 'member';

    // Edit member modal
    public bool $showEditMemberModal = false;

    public ?int $editingMemberId = null;

    public string $editingMemberRole = 'member';

    // Edit domain
    public bool $showEditDomainModal = false;

    public string $editingDomain = '';

    // Add package modal
    public bool $showAddPackageModal = false;

    public ?int $selectedPackageId = null;

    // Add entitlement modal
    public bool $showAddEntitlementModal = false;

    public ?string $selectedFeatureCode = null;

    public string $entitlementType = 'enable'; // enable, add_limit, unlimited

    public ?int $entitlementLimit = null;

    public string $entitlementDuration = 'permanent'; // permanent, duration

    public ?string $entitlementExpiresAt = null;

    public function mount(int $id): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for workspace administration.');
        }

        $this->workspace = Workspace::findOrFail($id);
    }

    #[Computed]
    public function teamMembers()
    {
        return $this->workspace->users()
            ->orderByRaw("FIELD(user_workspace.role, 'owner', 'admin', 'member')")
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableUsers()
    {
        $existingIds = $this->workspace->users()->pluck('users.id')->toArray();

        return User::whereNotIn('id', $existingIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function resourceCounts(): array
    {
        $counts = [];
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();

        $resources = [
            ['relation' => 'bioPages', 'label' => 'Bio Pages', 'icon' => 'link', 'color' => 'blue', 'model' => \Core\Mod\Web\Models\Page::class],
            ['relation' => 'bioProjects', 'label' => 'Bio Projects', 'icon' => 'folder', 'color' => 'indigo', 'model' => \Core\Mod\Web\Models\Project::class],
            ['relation' => 'socialAccounts', 'label' => 'Social Accounts', 'icon' => 'share-nodes', 'color' => 'purple', 'model' => \Core\Mod\Social\Models\Account::class],
            ['relation' => 'socialPosts', 'label' => 'Social Posts', 'icon' => 'paper-plane', 'color' => 'pink', 'model' => \Core\Mod\Social\Models\Post::class],
            ['relation' => 'analyticsSites', 'label' => 'Analytics Sites', 'icon' => 'chart-line', 'color' => 'cyan', 'model' => \Core\Mod\Analytics\Models\Website::class],
            ['relation' => 'trustWidgets', 'label' => 'Trust Campaigns', 'icon' => 'shield-check', 'color' => 'emerald', 'model' => \Core\Mod\Trust\Models\Campaign::class],
            ['relation' => 'notificationSites', 'label' => 'Notification Sites', 'icon' => 'bell', 'color' => 'amber', 'model' => \Core\Mod\Notify\Models\PushWebsite::class],
            ['relation' => 'contentItems', 'label' => 'Content Items', 'icon' => 'file-lines', 'color' => 'slate', 'model' => \Core\Mod\Content\Models\ContentItem::class],
            ['relation' => 'apiKeys', 'label' => 'API Keys', 'icon' => 'key', 'color' => 'rose', 'model' => \Core\Mod\Api\Models\ApiKey::class],
        ];

        foreach ($resources as $resource) {
            if (class_exists($resource['model'])) {
                try {
                    $counts[] = [
                        'label' => $resource['label'],
                        'icon' => $resource['icon'],
                        'color' => $resource['color'],
                        'count' => $this->workspace->{$resource['relation']}()->count(),
                    ];
                } catch (\Exception $e) {
                    // Skip if relation fails
                }
            }
        }

        return $counts;
    }

    #[Computed]
    public function recentActivity()
    {
        $activities = collect();

        // Entitlement logs
        if (class_exists(\Core\Mod\Tenant\Models\EntitlementLog::class)) {
            try {
                $logs = $this->workspace->entitlementLogs()
                    ->with('user', 'feature')
                    ->latest()
                    ->take(10)
                    ->get()
                    ->map(fn ($log) => [
                        'type' => 'entitlement',
                        'icon' => $log->action === 'allowed' ? 'check-circle' : 'times-circle',
                        'color' => $log->action === 'allowed' ? 'green' : 'red',
                        'message' => ($log->user?->name ?? 'System').' '.($log->action === 'allowed' ? 'used' : 'was denied').' '.$log->feature?->name,
                        'detail' => $log->reason,
                        'created_at' => $log->created_at,
                    ]);
                $activities = $activities->merge($logs);
            } catch (\Exception $e) {
                // Skip
            }
        }

        // Usage records
        if (class_exists(\Core\Mod\Tenant\Models\UsageRecord::class)) {
            try {
                $usage = $this->workspace->usageRecords()
                    ->with('user', 'feature')
                    ->latest()
                    ->take(10)
                    ->get()
                    ->map(fn ($record) => [
                        'type' => 'usage',
                        'icon' => 'chart-bar',
                        'color' => 'blue',
                        'message' => ($record->user?->name ?? 'System').' used '.$record->quantity.' '.$record->feature?->name,
                        'detail' => null,
                        'created_at' => $record->created_at,
                    ]);
                $activities = $activities->merge($usage);
            } catch (\Exception $e) {
                // Skip
            }
        }

        return $activities->sortByDesc('created_at')->take(15)->values();
    }

    #[Computed]
    public function activePackages()
    {
        return $this->workspace->workspacePackages()
            ->with('package')
            ->active()
            ->get();
    }

    #[Computed]
    public function subscriptionInfo(): ?array
    {
        $subscription = $this->workspace->activeSubscription();

        if (! $subscription) {
            return null;
        }

        return [
            'plan' => $subscription->plan_name ?? 'Unknown',
            'status' => $subscription->status,
            'current_period_end' => $subscription->current_period_end?->format('d M Y'),
            'amount' => $subscription->amount ? number_format($subscription->amount / 100, 2) : null,
            'currency' => $subscription->currency ?? 'GBP',
        ];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Team management

    public function openAddMember(): void
    {
        $this->newMemberId = null;
        $this->newMemberRole = 'member';
        $this->showAddMemberModal = true;
    }

    public function closeAddMember(): void
    {
        $this->showAddMemberModal = false;
        $this->reset(['newMemberId', 'newMemberRole']);
    }

    public function addMember(): void
    {
        if (! $this->newMemberId) {
            $this->actionMessage = 'Please select a user.';
            $this->actionType = 'error';

            return;
        }

        $user = User::findOrFail($this->newMemberId);

        $this->workspace->users()->attach($user->id, ['role' => $this->newMemberRole]);

        $this->closeAddMember();
        $this->actionMessage = "{$user->name} added to workspace as {$this->newMemberRole}.";
        $this->actionType = 'success';
        unset($this->teamMembers, $this->availableUsers);
    }

    public function openEditMember(int $userId): void
    {
        $member = $this->workspace->users()->where('user_id', $userId)->first();
        if (! $member) {
            return;
        }

        $this->editingMemberId = $userId;
        $this->editingMemberRole = $member->pivot->role ?? 'member';
        $this->showEditMemberModal = true;
    }

    public function closeEditMember(): void
    {
        $this->showEditMemberModal = false;
        $this->reset(['editingMemberId', 'editingMemberRole']);
    }

    public function updateMemberRole(): void
    {
        if (! $this->editingMemberId) {
            return;
        }

        $this->workspace->users()->updateExistingPivot($this->editingMemberId, [
            'role' => $this->editingMemberRole,
        ]);

        $user = User::find($this->editingMemberId);
        $this->closeEditMember();
        $this->actionMessage = "{$user?->name}'s role updated to {$this->editingMemberRole}.";
        $this->actionType = 'success';
        unset($this->teamMembers);
    }

    public function removeMember(int $userId): void
    {
        $member = $this->workspace->users()->where('user_id', $userId)->first();

        if ($member?->pivot?->role === 'owner') {
            $this->actionMessage = 'Cannot remove the workspace owner. Transfer ownership first.';
            $this->actionType = 'error';

            return;
        }

        $this->workspace->users()->detach($userId);

        $this->actionMessage = "{$member?->name} removed from workspace.";
        $this->actionType = 'success';
        unset($this->teamMembers, $this->availableUsers);
    }

    // Domain management

    public function openEditDomain(): void
    {
        $this->editingDomain = $this->workspace->domain ?? '';
        $this->showEditDomainModal = true;
    }

    public function closeEditDomain(): void
    {
        $this->showEditDomainModal = false;
        $this->reset(['editingDomain']);
    }

    public function saveDomain(): void
    {
        $domain = trim($this->editingDomain);

        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        $this->workspace->update(['domain' => $domain ?: null]);
        $this->workspace->refresh();

        $this->closeEditDomain();
        $this->actionMessage = $domain ? "Domain updated to {$domain}." : 'Domain removed.';
        $this->actionType = 'success';
    }

    // Entitlements tab

    #[Computed]
    public function allPackages()
    {
        return \Core\Mod\Tenant\Models\Package::active()
            ->ordered()
            ->get();
    }

    #[Computed]
    public function allFeatures()
    {
        return \Core\Mod\Tenant\Models\Feature::active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function activeBoosts()
    {
        return $this->workspace->boosts()
            ->usable()
            ->orderBy('feature_code')
            ->get();
    }

    #[Computed]
    public function entitlementStats(): array
    {
        $resolved = $this->resolvedEntitlements;
        $total = 0;
        $allowed = 0;
        $denied = 0;
        $nearLimit = 0;

        foreach ($resolved as $category => $features) {
            foreach ($features as $feature) {
                $total++;
                if ($feature['allowed']) {
                    $allowed++;
                    if ($feature['near_limit']) {
                        $nearLimit++;
                    }
                } else {
                    $denied++;
                }
            }
        }

        return [
            'total' => $total,
            'allowed' => $allowed,
            'denied' => $denied,
            'near_limit' => $nearLimit,
            'packages' => $this->workspacePackages->count(),
            'boosts' => $this->activeBoosts->count(),
        ];
    }

    #[Computed]
    public function workspacePackages()
    {
        return $this->workspace->workspacePackages()
            ->with(['package.features'])
            ->get();
    }

    #[Computed]
    public function usageSummary()
    {
        try {
            return $this->workspace->getUsageSummary();
        } catch (\Exception $e) {
            return collect();
        }
    }

    #[Computed]
    public function resolvedEntitlements()
    {
        try {
            return app(\Core\Mod\Tenant\Services\EntitlementService::class)
                ->getUsageSummary($this->workspace);
        } catch (\Exception $e) {
            return collect();
        }
    }

    public function openAddPackage(): void
    {
        $this->selectedPackageId = null;
        $this->showAddPackageModal = true;
    }

    public function closeAddPackage(): void
    {
        $this->showAddPackageModal = false;
        $this->reset(['selectedPackageId']);
    }

    public function addPackage(): void
    {
        if (! $this->selectedPackageId) {
            $this->actionMessage = 'Please select a package.';
            $this->actionType = 'error';

            return;
        }

        $package = \Core\Mod\Tenant\Models\Package::findOrFail($this->selectedPackageId);

        // Check if already assigned
        $existing = $this->workspace->workspacePackages()
            ->where('package_id', $package->id)
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            $this->actionMessage = "Package '{$package->name}' is already assigned.";
            $this->actionType = 'error';

            return;
        }

        \Core\Mod\Tenant\Models\WorkspacePackage::create([
            'workspace_id' => $this->workspace->id,
            'package_id' => $package->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->closeAddPackage();
        $this->actionMessage = "Package '{$package->name}' assigned to workspace.";
        $this->actionType = 'success';
        unset($this->workspacePackages, $this->activePackages);
    }

    public function removePackage(int $workspacePackageId): void
    {
        $wp = \Core\Mod\Tenant\Models\WorkspacePackage::where('workspace_id', $this->workspace->id)
            ->findOrFail($workspacePackageId);

        $packageName = $wp->package?->name ?? 'Package';
        $wp->delete();

        $this->actionMessage = "Package '{$packageName}' removed from workspace.";
        $this->actionType = 'success';
        unset($this->workspacePackages, $this->activePackages);
    }

    public function suspendPackage(int $workspacePackageId): void
    {
        $wp = \Core\Mod\Tenant\Models\WorkspacePackage::where('workspace_id', $this->workspace->id)
            ->findOrFail($workspacePackageId);

        $wp->suspend();

        $this->actionMessage = "Package '{$wp->package?->name}' suspended.";
        $this->actionType = 'warning';
        unset($this->workspacePackages, $this->activePackages);
    }

    public function reactivatePackage(int $workspacePackageId): void
    {
        $wp = \Core\Mod\Tenant\Models\WorkspacePackage::where('workspace_id', $this->workspace->id)
            ->findOrFail($workspacePackageId);

        $wp->reactivate();

        $this->actionMessage = "Package '{$wp->package?->name}' reactivated.";
        $this->actionType = 'success';
        unset($this->workspacePackages, $this->activePackages);
    }

    // Entitlement (Boost) management

    public function openAddEntitlement(): void
    {
        $this->selectedFeatureCode = null;
        $this->entitlementType = 'enable';
        $this->entitlementLimit = null;
        $this->entitlementDuration = 'permanent';
        $this->entitlementExpiresAt = null;
        $this->showAddEntitlementModal = true;
    }

    public function closeAddEntitlement(): void
    {
        $this->showAddEntitlementModal = false;
        $this->reset(['selectedFeatureCode', 'entitlementType', 'entitlementLimit', 'entitlementDuration', 'entitlementExpiresAt']);
    }

    public function addEntitlement(): void
    {
        if (! $this->selectedFeatureCode) {
            $this->actionMessage = 'Please select a feature.';
            $this->actionType = 'error';

            return;
        }

        $feature = \Core\Mod\Tenant\Models\Feature::where('code', $this->selectedFeatureCode)->first();

        if (! $feature) {
            $this->actionMessage = 'Feature not found.';
            $this->actionType = 'error';

            return;
        }

        // Map type to boost type constant
        $boostType = match ($this->entitlementType) {
            'enable' => \Core\Mod\Tenant\Models\Boost::BOOST_TYPE_ENABLE,
            'add_limit' => \Core\Mod\Tenant\Models\Boost::BOOST_TYPE_ADD_LIMIT,
            'unlimited' => \Core\Mod\Tenant\Models\Boost::BOOST_TYPE_UNLIMITED,
            default => \Core\Mod\Tenant\Models\Boost::BOOST_TYPE_ENABLE,
        };

        $durationType = $this->entitlementDuration === 'permanent'
            ? \Core\Mod\Tenant\Models\Boost::DURATION_PERMANENT
            : \Core\Mod\Tenant\Models\Boost::DURATION_DURATION;

        \Core\Mod\Tenant\Models\Boost::create([
            'workspace_id' => $this->workspace->id,
            'feature_code' => $this->selectedFeatureCode,
            'boost_type' => $boostType,
            'duration_type' => $durationType,
            'limit_value' => $this->entitlementType === 'add_limit' ? $this->entitlementLimit : null,
            'consumed_quantity' => 0,
            'status' => \Core\Mod\Tenant\Models\Boost::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => $this->entitlementExpiresAt ? \Carbon\Carbon::parse($this->entitlementExpiresAt) : null,
            'metadata' => ['granted_by' => auth()->id(), 'granted_at' => now()->toDateTimeString()],
        ]);

        $this->closeAddEntitlement();
        $this->actionMessage = "Entitlement '{$feature->name}' granted to workspace.";
        $this->actionType = 'success';
        unset($this->activeBoosts, $this->resolvedEntitlements, $this->entitlementStats);
    }

    public function removeBoost(int $boostId): void
    {
        $boost = \Core\Mod\Tenant\Models\Boost::where('workspace_id', $this->workspace->id)
            ->findOrFail($boostId);

        $featureCode = $boost->feature_code;
        $boost->cancel();

        $this->actionMessage = "Entitlement '{$featureCode}' removed.";
        $this->actionType = 'success';
        unset($this->activeBoosts, $this->resolvedEntitlements, $this->entitlementStats);
    }

    public function render()
    {
        return view('tenant::admin.workspace-details')
            ->layout('hub::admin.layouts.app', ['title' => 'Workspace: '.$this->workspace->name]);
    }
}

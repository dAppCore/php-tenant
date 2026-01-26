<div>
    {{-- Header --}}
    <div class="mb-8">
        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-4">
            <a href="{{ route('hub.admin.workspaces') }}" class="hover:text-gray-700 dark:hover:text-gray-200">
                <core:icon name="arrow-left" class="mr-1" />
                Workspaces
            </a>
            <span>/</span>
            <span>{{ $workspace->name }}</span>
        </div>

        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-xl bg-{{ $workspace->color ?? 'gray' }}-500/20 flex items-center justify-center">
                    <core:icon name="{{ $workspace->icon ?? 'folder' }}" class="text-2xl text-{{ $workspace->color ?? 'gray' }}-600 dark:text-{{ $workspace->color ?? 'gray' }}-400" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $workspace->name }}</h1>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ $workspace->slug }}</span>
                        @if($workspace->is_active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-500/20 text-green-600 dark:text-green-400">
                            Active
                        </span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-500/20 text-gray-600 dark:text-gray-400">
                            Inactive
                        </span>
                        @endif
                    </div>
                </div>
            </div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-violet-500/20 text-violet-600 dark:text-violet-400">
                <core:icon name="crown" class="mr-1.5" />
                Hades Only
            </span>
        </div>
    </div>

    {{-- Action message --}}
    @if($actionMessage)
    <div class="mb-6 p-4 rounded-lg {{ $actionType === 'success' ? 'bg-green-500/20 text-green-700 dark:text-green-400' : ($actionType === 'warning' ? 'bg-amber-500/20 text-amber-700 dark:text-amber-400' : 'bg-red-500/20 text-red-700 dark:text-red-400') }}">
        <div class="flex items-center">
            <core:icon name="{{ $actionType === 'success' ? 'check-circle' : ($actionType === 'warning' ? 'triangle-exclamation' : 'circle-xmark') }}" class="mr-2" />
            {{ $actionMessage }}
        </div>
    </div>
    @endif

    {{-- Tabs --}}
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav class="flex gap-6" aria-label="Tabs">
            @foreach([
                'overview' => ['label' => 'Overview', 'icon' => 'gauge'],
                'team' => ['label' => 'Team', 'icon' => 'users'],
                'entitlements' => ['label' => 'Entitlements', 'icon' => 'box'],
                'resources' => ['label' => 'Resources', 'icon' => 'boxes-stacked'],
                'activity' => ['label' => 'Activity', 'icon' => 'clock-rotate-left'],
            ] as $tab => $info)
            <button
                wire:click="setTab('{{ $tab }}')"
                class="flex items-center gap-2 py-3 px-1 border-b-2 text-sm font-medium transition {{ $activeTab === $tab ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
            >
                <core:icon name="{{ $info['icon'] }}" />
                {{ $info['label'] }}
            </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    <div class="min-h-[400px]">
        {{-- Overview Tab --}}
        @if($activeTab === 'overview')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Quick Stats --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Owner Card --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Workspace Owner</h3>
                    @php $owner = $workspace->owner(); @endphp
                    @if($owner)
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center">
                            <core:icon name="user" class="text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $owner->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $owner->email }}</div>
                        </div>
                    </div>
                    @else
                    <div class="text-gray-500 dark:text-gray-400">No owner assigned</div>
                    @endif
                </div>

                {{-- Packages Card --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Active Packages</h3>
                    @if($this->activePackages->count() > 0)
                    <div class="space-y-2">
                        @foreach($this->activePackages as $wp)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                                    <core:icon name="box" class="text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-gray-100">{{ $wp->package?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-gray-500">{{ $wp->package?->code ?? '' }}</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-500/20 text-green-600 dark:text-green-400">
                                    {{ ucfirst($wp->status) }}
                                </span>
                                @if($wp->expires_at)
                                <div class="text-xs text-gray-500 mt-1">Expires {{ $wp->expires_at->format('d M Y') }}</div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-gray-500 dark:text-gray-400">No packages assigned</div>
                    @endif
                </div>

                {{-- Boosts Card --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Active Boosts</h3>
                    @if($this->activeBoosts->count() > 0)
                    <div class="space-y-2">
                        @foreach($this->activeBoosts as $boost)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center">
                                    <core:icon name="bolt" class="text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-gray-100 font-mono text-sm">{{ $boost->feature_code }}</div>
                                    <div class="text-xs text-gray-500 capitalize">{{ str_replace('_', ' ', $boost->boost_type) }}@if($boost->limit_value) · +{{ number_format($boost->limit_value) }}@endif</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-500/20 text-purple-600 dark:text-purple-400">
                                    {{ ucfirst($boost->status) }}
                                </span>
                                @if($boost->expires_at)
                                <div class="text-xs text-gray-500 mt-1">Expires {{ $boost->expires_at->format('d M Y') }}</div>
                                @else
                                <div class="text-xs text-green-500 mt-1">Permanent</div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-gray-500 dark:text-gray-400">No boosts active</div>
                    @endif
                </div>

                {{-- Subscription Card --}}
                @if($this->subscriptionInfo)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Subscription</h3>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                                <core:icon name="credit-card" class="text-purple-600 dark:text-purple-400" />
                            </div>
                            <div>
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ $this->subscriptionInfo['plan'] }}</div>
                                <div class="text-sm text-gray-500">Renews {{ $this->subscriptionInfo['current_period_end'] }}</div>
                            </div>
                        </div>
                        @if($this->subscriptionInfo['amount'])
                        <div class="text-right">
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $this->subscriptionInfo['currency'] }} {{ $this->subscriptionInfo['amount'] }}</div>
                            <div class="text-xs text-gray-500">/month</div>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>

            {{-- Sidebar Stats --}}
            <div class="space-y-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-4">Quick Stats</h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Team Members</span>
                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $this->teamMembers->count() }}</span>
                        </div>
                        @foreach(array_slice($this->resourceCounts, 0, 5) as $resource)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $resource['label'] }}</span>
                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $resource['count'] }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Workspace Info --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-4">Details</h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">Created</div>
                            <div class="text-gray-800 dark:text-gray-100">{{ $workspace->created_at->format('d M Y') }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>Domain</span>
                                <button wire:click="openEditDomain" class="text-blue-500 hover:text-blue-600 text-xs">
                                    <core:icon name="pencil" />
                                </button>
                            </div>
                            <div class="text-gray-800 dark:text-gray-100">{{ $workspace->domain ?: 'Not set' }}</div>
                        </div>
                        @if($workspace->wp_connector_enabled)
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">WP Connector</div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-500/20 text-green-600">Connected</span>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Team Tab --}}
        @if($activeTab === 'team')
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 class="font-medium text-gray-800 dark:text-gray-100">Team Members ({{ $this->teamMembers->count() }})</h3>
                <flux:button size="sm" wire:click="openAddMember">
                    <core:icon name="plus" class="mr-1" />
                    Add Member
                </flux:button>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->teamMembers as $member)
                <div class="px-5 py-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700/20">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-500/20 flex items-center justify-center">
                            <core:icon name="{{ $member->pivot->role === 'owner' ? 'crown' : 'user' }}" class="text-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-600 dark:text-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-400" />
                        </div>
                        <div>
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $member->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $member->email }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-500/20 text-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-600 dark:text-{{ $member->pivot->role === 'owner' ? 'amber' : ($member->pivot->role === 'admin' ? 'blue' : 'gray') }}-400">
                            {{ ucfirst($member->pivot->role) }}
                        </span>
                        @if($member->pivot->role !== 'owner')
                        <div class="flex items-center gap-1">
                            <button wire:click="openEditMember({{ $member->id }})" class="p-1.5 text-blue-600 hover:bg-blue-500/20 rounded-lg transition" title="Edit role">
                                <core:icon name="pencil" />
                            </button>
                            <button wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from this workspace?" class="p-1.5 text-red-600 hover:bg-red-500/20 rounded-lg transition" title="Remove">
                                <core:icon name="trash" />
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                    No team members found.
                </div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- Entitlements Tab --}}
        @if($activeTab === 'entitlements')
        <div class="space-y-6">
            {{-- Stats Header --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium text-gray-800 dark:text-gray-100">Entitlement Overview</h3>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" wire:click="openAddPackage">
                            <core:icon name="box" class="mr-1" />
                            Add Package
                        </flux:button>
                        <flux:button size="sm" variant="primary" wire:click="openAddEntitlement">
                            <core:icon name="plus" class="mr-1" />
                            Add Entitlement
                        </flux:button>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg">
                        <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $this->entitlementStats['total'] }}</div>
                        <div class="text-xs text-gray-500">Total Features</div>
                    </div>
                    <div class="text-center p-3 bg-green-500/10 rounded-lg">
                        <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $this->entitlementStats['allowed'] }}</div>
                        <div class="text-xs text-gray-500">Allowed</div>
                    </div>
                    <div class="text-center p-3 bg-red-500/10 rounded-lg">
                        <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $this->entitlementStats['denied'] }}</div>
                        <div class="text-xs text-gray-500">Not Included</div>
                    </div>
                    <div class="text-center p-3 bg-amber-500/10 rounded-lg">
                        <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $this->entitlementStats['near_limit'] }}</div>
                        <div class="text-xs text-gray-500">Near Limit</div>
                    </div>
                    <div class="text-center p-3 bg-blue-500/10 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $this->entitlementStats['packages'] }}</div>
                        <div class="text-xs text-gray-500">Packages</div>
                    </div>
                    <div class="text-center p-3 bg-purple-500/10 rounded-lg">
                        <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $this->entitlementStats['boosts'] }}</div>
                        <div class="text-xs text-gray-500">Boosts</div>
                    </div>
                </div>
            </div>

            {{-- Active Boosts Section --}}
            @if($this->activeBoosts->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-medium text-gray-800 dark:text-gray-100">
                        <core:icon name="bolt" class="mr-2 text-purple-500" />
                        Active Boosts ({{ $this->activeBoosts->count() }})
                    </h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->activeBoosts as $boost)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center">
                                <core:icon name="bolt" class="text-sm text-purple-600 dark:text-purple-400" />
                            </div>
                            <div>
                                <div class="font-medium text-gray-800 dark:text-gray-100 font-mono text-sm">{{ $boost->feature_code }}</div>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <span class="capitalize">{{ str_replace('_', ' ', $boost->boost_type) }}</span>
                                    @if($boost->limit_value)
                                    <span>· +{{ number_format($boost->limit_value) }}</span>
                                    @endif
                                    @if($boost->expires_at)
                                    <span class="{{ $boost->expires_at->isPast() ? 'text-red-500' : '' }}">
                                        · Expires {{ $boost->expires_at->format('d M Y') }}
                                    </span>
                                    @else
                                    <span class="text-green-500">· Permanent</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <button wire:click="removeBoost({{ $boost->id }})" wire:confirm="Remove this boost?" class="p-1.5 text-red-600 hover:bg-red-500/20 rounded-lg transition" title="Remove">
                            <core:icon name="trash" class="text-sm" />
                        </button>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Resolved Entitlements by Category --}}
            @forelse($this->resolvedEntitlements as $category => $features)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-medium text-gray-800 dark:text-gray-100 capitalize">{{ $category ?: 'General' }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($features as $entitlement)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            {{-- Status indicator --}}
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0
                                {{ $entitlement['allowed'] ? ($entitlement['near_limit'] ? 'bg-amber-500/20' : 'bg-green-500/20') : 'bg-red-500/20' }}">
                                <core:icon
                                    name="{{ $entitlement['allowed'] ? ($entitlement['near_limit'] ? 'triangle-exclamation' : 'check') : 'xmark' }}"
                                    class="{{ $entitlement['allowed'] ? ($entitlement['near_limit'] ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400') : 'text-red-600 dark:text-red-400' }}"
                                />
                            </div>
                            <div class="min-w-0">
                                <div class="font-medium text-gray-800 dark:text-gray-100 truncate">{{ $entitlement['name'] }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $entitlement['code'] }}</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            {{-- Type badge --}}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $entitlement['type'] === 'boolean' ? 'bg-blue-500/20 text-blue-600 dark:text-blue-400' :
                                   ($entitlement['unlimited'] ? 'bg-purple-500/20 text-purple-600 dark:text-purple-400' : 'bg-gray-500/20 text-gray-600 dark:text-gray-400') }}">
                                @if($entitlement['type'] === 'boolean')
                                    Toggle
                                @elseif($entitlement['unlimited'])
                                    Unlimited
                                @else
                                    Limit
                                @endif
                            </span>

                            {{-- Usage info --}}
                            @if($entitlement['type'] !== 'boolean' && !$entitlement['unlimited'] && $entitlement['allowed'])
                            <div class="text-right min-w-[100px]">
                                <div class="text-sm text-gray-800 dark:text-gray-100">
                                    <span class="font-medium">{{ number_format($entitlement['used'] ?? 0) }}</span>
                                    <span class="text-gray-500">/ {{ number_format($entitlement['limit']) }}</span>
                                </div>
                                @if($entitlement['limit'] > 0)
                                @php $percent = $entitlement['percentage'] ?? 0; @endphp
                                <div class="mt-1 h-1.5 w-24 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden">
                                    <div class="h-full {{ $percent > 90 ? 'bg-red-500' : ($percent > 70 ? 'bg-amber-500' : 'bg-green-500') }} rounded-full" style="width: {{ $percent }}%"></div>
                                </div>
                                @endif
                            </div>
                            @elseif($entitlement['unlimited'])
                            <div class="text-right min-w-[100px]">
                                <span class="text-sm text-purple-600 dark:text-purple-400">
                                    <core:icon name="infinity" class="mr-1" />
                                    {{ number_format($entitlement['used'] ?? 0) }} used
                                </span>
                            </div>
                            @elseif(!$entitlement['allowed'])
                            <div class="text-right min-w-[100px]">
                                <span class="text-sm text-red-600 dark:text-red-400">Not included</span>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @empty
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-8 text-center text-gray-500 dark:text-gray-400">
                No entitlements configured.
            </div>
            @endforelse

            {{-- Packages Section --}}
            @if($this->workspacePackages->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-medium text-gray-800 dark:text-gray-100">
                        <core:icon name="box" class="mr-2 text-gray-400" />
                        Assigned Packages ({{ $this->workspacePackages->count() }})
                    </h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->workspacePackages as $wp)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-{{ $wp->package?->color ?? 'gray' }}-500/20 flex items-center justify-center">
                                <core:icon name="{{ $wp->package?->icon ?? 'box' }}" class="text-sm text-{{ $wp->package?->color ?? 'gray' }}-600 dark:text-{{ $wp->package?->color ?? 'gray' }}-400" />
                            </div>
                            <div>
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ $wp->package?->name ?? 'Unknown' }}</div>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <span class="font-mono">{{ $wp->package?->code ?? '' }}</span>
                                    @if($wp->expires_at)
                                    <span class="{{ $wp->expires_at->isPast() ? 'text-red-500' : '' }}">
                                        · Expires {{ $wp->expires_at->format('d M Y') }}
                                    </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $wp->status === 'active' ? 'green' : ($wp->status === 'suspended' ? 'amber' : 'gray') }}-500/20 text-{{ $wp->status === 'active' ? 'green' : ($wp->status === 'suspended' ? 'amber' : 'gray') }}-600">
                                {{ ucfirst($wp->status) }}
                            </span>
                            @if($wp->status === 'active')
                            <button wire:click="suspendPackage({{ $wp->id }})" class="p-1 text-amber-600 hover:bg-amber-500/20 rounded transition" title="Suspend">
                                <core:icon name="pause" class="text-sm" />
                            </button>
                            @elseif($wp->status === 'suspended')
                            <button wire:click="reactivatePackage({{ $wp->id }})" class="p-1 text-green-600 hover:bg-green-500/20 rounded transition" title="Reactivate">
                                <core:icon name="play" class="text-sm" />
                            </button>
                            @endif
                            <button wire:click="removePackage({{ $wp->id }})" wire:confirm="Remove this package from the workspace?" class="p-1 text-red-600 hover:bg-red-500/20 rounded transition" title="Remove">
                                <core:icon name="trash" class="text-sm" />
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- Resources Tab --}}
        @if($activeTab === 'resources')
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($this->resourceCounts as $resource)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-lg bg-{{ $resource['color'] }}-500/20 flex items-center justify-center">
                        <core:icon name="{{ $resource['icon'] }}" class="text-{{ $resource['color'] }}-600 dark:text-{{ $resource['color'] }}-400" />
                    </div>
                </div>
                <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($resource['count']) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $resource['label'] }}</div>
            </div>
            @endforeach
        </div>

        @if(count($this->resourceCounts) === 0)
        <div class="text-center py-12 text-gray-500 dark:text-gray-400">
            No resources configured for this workspace.
        </div>
        @endif
        @endif

        {{-- Activity Tab --}}
        @if($activeTab === 'activity')
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="font-medium text-gray-800 dark:text-gray-100">Recent Activity</h3>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->recentActivity as $activity)
                <div class="px-5 py-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-{{ $activity['color'] }}-500/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <core:icon name="{{ $activity['icon'] }}" class="text-sm text-{{ $activity['color'] }}-600 dark:text-{{ $activity['color'] }}-400" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-gray-800 dark:text-gray-100">{{ $activity['message'] }}</div>
                        @if($activity['detail'])
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $activity['detail'] }}</div>
                        @endif
                        <div class="text-xs text-gray-400 mt-1">{{ $activity['created_at']->diffForHumans() }}</div>
                    </div>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                    No recent activity found.
                </div>
                @endforelse
            </div>
        </div>
        @endif
    </div>

    {{-- Add Member Modal --}}
    <core:modal wire:model="showAddMemberModal" class="max-w-md">
        <core:heading size="lg">Add Team Member</core:heading>

        <div class="mt-4 space-y-4">
            <flux:select wire:model="newMemberId" label="User">
                <flux:select.option value="">Select user...</flux:select.option>
                @foreach($this->availableUsers as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="newMemberRole" label="Role">
                <flux:select.option value="member">Member</flux:select.option>
                <flux:select.option value="admin">Admin</flux:select.option>
                <flux:select.option value="owner">Owner</flux:select.option>
            </flux:select>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeAddMember">Cancel</flux:button>
                <flux:button variant="primary" wire:click="addMember">Add Member</flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Edit Member Modal --}}
    <core:modal wire:model="showEditMemberModal" class="max-w-md">
        <core:heading size="lg">Edit Member Role</core:heading>

        <div class="mt-4 space-y-4">
            <flux:select wire:model="editingMemberRole" label="Role">
                <flux:select.option value="member">Member</flux:select.option>
                <flux:select.option value="admin">Admin</flux:select.option>
                <flux:select.option value="owner">Owner</flux:select.option>
            </flux:select>

            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3">
                <flux:text size="sm" class="text-amber-800 dark:text-amber-200">
                    Changing to Owner will transfer ownership from the current owner.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeEditMember">Cancel</flux:button>
                <flux:button variant="primary" wire:click="updateMemberRole">Update Role</flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Edit Domain Modal --}}
    <core:modal wire:model="showEditDomainModal" class="max-w-md">
        <core:heading size="lg">Edit Domain</core:heading>

        <div class="mt-4 space-y-4">
            <flux:input wire:model="editingDomain" label="Domain" placeholder="example.com" />

            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3">
                <flux:text size="sm" class="text-blue-800 dark:text-blue-200">
                    Enter the domain without the protocol (e.g., example.com not https://example.com).
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeEditDomain">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveDomain">Save Domain</flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Add Package Modal --}}
    <core:modal wire:model="showAddPackageModal" class="max-w-md">
        <core:heading size="lg">Add Package</core:heading>

        <div class="mt-4 space-y-4">
            <flux:select wire:model="selectedPackageId" variant="listbox" searchable label="Package" placeholder="Search packages...">
                @foreach($this->allPackages as $package)
                <flux:select.option value="{{ $package->id }}">
                    {{ $package->name }} ({{ $package->code }})
                </flux:select.option>
                @endforeach
            </flux:select>

            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3">
                <flux:text size="sm" class="text-amber-800 dark:text-amber-200">
                    The package will be assigned immediately with no expiry date. You can modify or remove it later.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeAddPackage">Cancel</flux:button>
                <flux:button variant="primary" wire:click="addPackage">Add Package</flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Add Entitlement Modal --}}
    <core:modal wire:model="showAddEntitlementModal" class="max-w-lg">
        <core:heading size="lg">Add Entitlement</core:heading>

        <div class="mt-4 space-y-4">
            <flux:select wire:model="selectedFeatureCode" variant="listbox" searchable label="Feature" placeholder="Search features...">
                @foreach($this->allFeatures->groupBy('category') as $category => $features)
                <flux:select.option disabled>── {{ ucfirst($category ?: 'General') }} ──</flux:select.option>
                @foreach($features as $feature)
                <flux:select.option value="{{ $feature->code }}">
                    {{ $feature->name }} ({{ $feature->code }})
                </flux:select.option>
                @endforeach
                @endforeach
            </flux:select>

            <flux:select wire:model.live="entitlementType" label="Type">
                <flux:select.option value="enable">Enable (Toggle on)</flux:select.option>
                <flux:select.option value="add_limit">Add Limit (Extra quota)</flux:select.option>
                <flux:select.option value="unlimited">Unlimited</flux:select.option>
            </flux:select>

            @if($entitlementType === 'add_limit')
            <flux:input wire:model="entitlementLimit" type="number" label="Limit Value" placeholder="e.g. 100" min="1" />
            @endif

            <flux:select wire:model.live="entitlementDuration" label="Duration">
                <flux:select.option value="permanent">Permanent</flux:select.option>
                <flux:select.option value="duration">Expires on date</flux:select.option>
            </flux:select>

            @if($entitlementDuration === 'duration')
            <flux:input wire:model="entitlementExpiresAt" type="date" label="Expires At" />
            @endif

            <div class="rounded-lg bg-purple-50 dark:bg-purple-900/20 p-3">
                <flux:text size="sm" class="text-purple-800 dark:text-purple-200">
                    This will create a boost that grants the selected feature directly to this workspace, independent of packages.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeAddEntitlement">Cancel</flux:button>
                <flux:button variant="primary" wire:click="addEntitlement">Add Entitlement</flux:button>
            </div>
        </div>
    </core:modal>
</div>

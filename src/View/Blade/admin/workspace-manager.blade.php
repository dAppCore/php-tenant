<admin:module title="{{ __('tenant::tenant.admin.title') }}" subtitle="{{ __('tenant::tenant.admin.subtitle') }}">
    <x-slot:actions>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-violet-500/20 text-violet-600 dark:text-violet-400">
            <core:icon name="crown" class="mr-1.5" />
            {{ __('tenant::tenant.admin.hades_only') }}
        </span>
    </x-slot:actions>

    {{-- Action message --}}
    @if($actionMessage)
    <div class="mb-6 p-4 rounded-lg {{ $actionType === 'success' ? 'bg-green-500/20 text-green-700 dark:text-green-400' : ($actionType === 'warning' ? 'bg-amber-500/20 text-amber-700 dark:text-amber-400' : 'bg-red-500/20 text-red-700 dark:text-red-400') }}">
        <div class="flex items-center">
            <core:icon name="{{ $actionType === 'success' ? 'check-circle' : ($actionType === 'warning' ? 'triangle-exclamation' : 'circle-xmark') }}" class="mr-2" />
            {{ $actionMessage }}
        </div>
    </div>
    @endif

    {{-- Stats Grid --}}
    <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-4">
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($stats['total']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.stats.total') }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-4">
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ number_format($stats['active']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.stats.active') }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xs p-4">
            <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ number_format($stats['inactive']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.stats.inactive') }}</div>
        </div>
    </div>

    {{-- Search --}}
    <admin:filter-bar cols="2">
        <admin:search model="search" placeholder="{{ __('tenant::tenant.admin.search_placeholder') }}" />
        <admin:clear-filters :fields="['search']" />
    </admin:filter-bar>

    {{-- Workspace Table --}}
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-xs uppercase text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/20">
                        <th class="px-4 py-3 text-left font-medium">{{ __('tenant::tenant.admin.table.workspace') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ __('tenant::tenant.admin.table.owner') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.bio') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.social') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.analytics') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.trust') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.notify') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.commerce') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('tenant::tenant.admin.table.status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ __('tenant::tenant.admin.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @forelse($this->workspaces as $workspace)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/20">
                        <td class="px-4 py-3">
                            <a href="{{ route('hub.admin.workspaces.details', $workspace->id) }}" class="flex items-center group">
                                <div class="w-8 h-8 rounded-lg bg-{{ $workspace->color ?? 'gray' }}-500/20 flex items-center justify-center mr-3">
                                    <core:icon name="{{ $workspace->icon ?? 'folder' }}" class="text-{{ $workspace->color ?? 'gray' }}-600 dark:text-{{ $workspace->color ?? 'gray' }}-400" />
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition">{{ $workspace->name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ $workspace->slug }}</div>
                                </div>
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            @php $owner = $workspace->owner(); @endphp
                            @if($owner)
                            <div class="text-sm text-gray-600 dark:text-gray-400">{{ $owner->name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500">{{ $owner->email }}</div>
                            @else
                            <span class="text-xs text-gray-400">{{ __('tenant::tenant.admin.table.no_owner') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @php
                                $bioPages = $workspace->bio_pages_count ?? 0;
                                $bioProjects = $workspace->bio_projects_count ?? 0;
                            @endphp
                            @if($bioPages > 0 || $bioProjects > 0)
                            <button wire:click="openResources({{ $workspace->id }}, 'bio_pages')" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-500/20 text-blue-600 dark:text-blue-400 hover:bg-blue-500/30 transition cursor-pointer">
                                {{ $bioPages }}p / {{ $bioProjects }}proj
                            </button>
                            @else
                            <button wire:click="openProvision({{ $workspace->id }}, 'bio_pages')" class="text-xs text-gray-400 hover:text-blue-500 hover:bg-blue-500/10 px-2 py-0.5 rounded transition cursor-pointer" title="{{ __('tenant::tenant.admin.actions.provision') }}">
                                <core:icon name="plus" class="inline-block" />
                            </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(($workspace->social_accounts_count ?? 0) > 0)
                            <button wire:click="openResources({{ $workspace->id }}, 'social_accounts')" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-500/20 text-purple-600 dark:text-purple-400 hover:bg-purple-500/30 transition cursor-pointer">
                                {{ $workspace->social_accounts_count }}
                            </button>
                            @else
                            <button wire:click="openProvision({{ $workspace->id }}, 'social_accounts')" class="text-xs text-gray-400 hover:text-purple-500 hover:bg-purple-500/10 px-2 py-0.5 rounded transition cursor-pointer" title="{{ __('tenant::tenant.admin.actions.provision') }}">
                                <core:icon name="plus" class="inline-block" />
                            </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(($workspace->analytics_sites_count ?? 0) > 0)
                            <button wire:click="openResources({{ $workspace->id }}, 'analytics_sites')" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-500/20 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/30 transition cursor-pointer">
                                {{ $workspace->analytics_sites_count }}
                            </button>
                            @else
                            <button wire:click="openProvision({{ $workspace->id }}, 'analytics_sites')" class="text-xs text-gray-400 hover:text-cyan-500 hover:bg-cyan-500/10 px-2 py-0.5 rounded transition cursor-pointer" title="{{ __('tenant::tenant.admin.actions.provision') }}">
                                <core:icon name="plus" class="inline-block" />
                            </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(($workspace->trust_widgets_count ?? 0) > 0)
                            <button wire:click="openResources({{ $workspace->id }}, 'trust_widgets')" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/30 transition cursor-pointer">
                                {{ $workspace->trust_widgets_count }}
                            </button>
                            @else
                            <button wire:click="openProvision({{ $workspace->id }}, 'trust_widgets')" class="text-xs text-gray-400 hover:text-emerald-500 hover:bg-emerald-500/10 px-2 py-0.5 rounded transition cursor-pointer" title="{{ __('tenant::tenant.admin.actions.provision') }}">
                                <core:icon name="plus" class="inline-block" />
                            </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if(($workspace->notification_sites_count ?? 0) > 0)
                            <button wire:click="openResources({{ $workspace->id }}, 'notification_sites')" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-500/20 text-amber-600 dark:text-amber-400 hover:bg-amber-500/30 transition cursor-pointer">
                                {{ $workspace->notification_sites_count }}
                            </button>
                            @else
                            <button wire:click="openProvision({{ $workspace->id }}, 'notification_sites')" class="text-xs text-gray-400 hover:text-amber-500 hover:bg-amber-500/10 px-2 py-0.5 rounded transition cursor-pointer" title="{{ __('tenant::tenant.admin.actions.provision') }}">
                                <core:icon name="plus" class="inline-block" />
                            </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs text-gray-400">-</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($workspace->is_active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-500/20 text-green-600 dark:text-green-400">
                                {{ __('tenant::tenant.admin.table.active') }}
                            </span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-500/20 text-gray-600 dark:text-gray-400">
                                {{ __('tenant::tenant.admin.table.inactive') }}
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('hub.admin.workspaces.details', $workspace->id) }}" class="p-1.5 text-gray-600 hover:bg-gray-500/20 rounded-lg transition" title="{{ __('tenant::tenant.admin.actions.view_details') }}">
                                    <core:icon name="eye" />
                                </a>
                                <button wire:click="openEdit({{ $workspace->id }})" class="p-1.5 text-blue-600 hover:bg-blue-500/20 rounded-lg transition" title="{{ __('tenant::tenant.admin.actions.edit') }}">
                                    <core:icon name="pencil" />
                                </button>
                                <button wire:click="openChangeOwner({{ $workspace->id }})" class="p-1.5 text-amber-600 hover:bg-amber-500/20 rounded-lg transition" title="{{ __('tenant::tenant.admin.actions.change_owner') }}">
                                    <core:icon name="user-pen" />
                                </button>
                                <button wire:click="openTransfer({{ $workspace->id }})" class="p-1.5 text-purple-600 hover:bg-purple-500/20 rounded-lg transition" title="{{ __('tenant::tenant.admin.actions.transfer') }}">
                                    <core:icon name="arrow-right-arrow-left" />
                                </button>
                                <button wire:click="delete({{ $workspace->id }})" wire:confirm="{{ __('tenant::tenant.admin.confirm_delete') }}" class="p-1.5 text-red-600 hover:bg-red-500/20 rounded-lg transition" title="{{ __('tenant::tenant.admin.actions.delete') }}">
                                    <core:icon name="trash" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            {{ __('tenant::tenant.admin.table.empty') }}
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($this->workspaces->hasPages())
        <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700/60">
            {{ $this->workspaces->links() }}
        </div>
        @endif
    </div>

    {{-- Edit Workspace Modal --}}
    <core:modal wire:model="editingId" class="max-w-md">
        <core:heading size="lg">{{ __('tenant::tenant.admin.edit_modal.title') }}</core:heading>

        <form wire:submit="save" class="mt-4 space-y-4">
            <flux:input wire:model="name" label="{{ __('tenant::tenant.admin.edit_modal.name') }}" placeholder="{{ __('tenant::tenant.admin.edit_modal.name_placeholder') }}" required />
            <flux:input wire:model="slug" label="{{ __('tenant::tenant.admin.edit_modal.slug') }}" placeholder="{{ __('tenant::tenant.admin.edit_modal.slug_placeholder') }}" required class="font-mono" />

            <div class="flex items-center gap-2">
                <flux:switch wire:model="isActive" />
                <flux:label>{{ __('tenant::tenant.admin.edit_modal.active') }}</flux:label>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeEdit">{{ __('tenant::tenant.admin.edit_modal.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('tenant::tenant.admin.edit_modal.save') }}</flux:button>
            </div>
        </form>
    </core:modal>

    {{-- Transfer Resources Modal --}}
    <core:modal wire:model="showTransferModal" class="max-w-lg">
        <core:heading size="lg">{{ __('tenant::tenant.admin.transfer_modal.title') }}</core:heading>

        <div class="mt-4 space-y-4">
            @if($sourceWorkspaceId)
            @php $sourceWorkspace = $this->allWorkspaces->firstWhere('id', $sourceWorkspaceId); @endphp
            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3">
                <flux:text size="sm" class="text-blue-800 dark:text-blue-200">
                    <strong>{{ __('tenant::tenant.admin.transfer_modal.source') }}:</strong> {{ $sourceWorkspace?->name ?? 'Unknown' }} ({{ $sourceWorkspace?->slug ?? '' }})
                </flux:text>
            </div>
            @endif

            <flux:select wire:model="targetWorkspaceId" label="{{ __('tenant::tenant.admin.transfer_modal.target_workspace') }}">
                <flux:select.option value="">{{ __('tenant::tenant.admin.transfer_modal.select_target') }}</flux:select.option>
                @foreach($this->allWorkspaces as $ws)
                    @if($ws->id !== $sourceWorkspaceId)
                    <flux:select.option value="{{ $ws->id }}">{{ $ws->name }} ({{ $ws->slug }})</flux:select.option>
                    @endif
                @endforeach
            </flux:select>

            <flux:field>
                <flux:label>{{ __('tenant::tenant.admin.transfer_modal.resources_label') }}</flux:label>
                <div class="space-y-2 mt-2">
                    @foreach($this->resourceTypes as $key => $type)
                    <flux:checkbox wire:model="selectedResourceTypes" value="{{ $key }}" label="{{ $type['label'] }}" />
                    @endforeach
                </div>
            </flux:field>

            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3">
                <flux:text size="sm" class="text-amber-800 dark:text-amber-200">
                    {{ __('tenant::tenant.admin.transfer_modal.warning') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeTransfer">{{ __('tenant::tenant.admin.transfer_modal.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="executeTransfer" :disabled="!$targetWorkspaceId || empty($selectedResourceTypes)">
                    {{ __('tenant::tenant.admin.transfer_modal.transfer') }}
                </flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Change Owner Modal --}}
    <core:modal wire:model="showOwnerModal" class="max-w-md">
        <core:heading size="lg">{{ __('tenant::tenant.admin.owner_modal.title') }}</core:heading>

        <div class="mt-4 space-y-4">
            @if($ownerWorkspaceId)
            @php $ownerWorkspace = $this->allWorkspaces->firstWhere('id', $ownerWorkspaceId); @endphp
            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3">
                <flux:text size="sm" class="text-blue-800 dark:text-blue-200">
                    <strong>{{ __('tenant::tenant.admin.owner_modal.workspace') }}:</strong> {{ $ownerWorkspace?->name ?? 'Unknown' }}
                </flux:text>
            </div>
            @endif

            <flux:select wire:model="newOwnerId" label="{{ __('tenant::tenant.admin.owner_modal.new_owner') }}">
                <flux:select.option value="">{{ __('tenant::tenant.admin.owner_modal.select_owner') }}</flux:select.option>
                @foreach($this->allUsers as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</flux:select.option>
                @endforeach
            </flux:select>

            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3">
                <flux:text size="sm" class="text-amber-800 dark:text-amber-200">
                    {{ __('tenant::tenant.admin.owner_modal.warning') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeChangeOwner">{{ __('tenant::tenant.admin.owner_modal.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="changeOwner">
                    {{ __('tenant::tenant.admin.owner_modal.change') }}
                </flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Resource Viewer Modal --}}
    <core:modal wire:model="showResourcesModal" class="max-w-2xl">
        @php
            $resourceWorkspace = $this->allWorkspaces->firstWhere('id', $resourcesWorkspaceId);
            $resourceTypeInfo = $this->resourceTypes[$resourcesType] ?? null;
        @endphp
        <core:heading size="lg">
            {{ $resourceTypeInfo['label'] ?? 'Resources' }}
            <span class="text-sm font-normal text-gray-500">{{ __('tenant::tenant.admin.resources_modal.in') }} {{ $resourceWorkspace?->name ?? 'Unknown' }}</span>
        </core:heading>

        <div class="mt-4 space-y-4">
            {{-- Selection controls --}}
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="selectAllResources">{{ __('tenant::tenant.admin.resources_modal.select_all') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="deselectAllResources">{{ __('tenant::tenant.admin.resources_modal.deselect_all') }}</flux:button>
                </div>
                <span class="text-sm text-gray-500">{{ __('tenant::tenant.admin.resources_modal.selected', ['count' => count($selectedResources)]) }}</span>
            </div>

            {{-- Resource list --}}
            <div class="max-h-80 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($this->currentResources as $resource)
                <div
                    wire:click="toggleResourceSelection({{ $resource['id'] }})"
                    class="flex items-center gap-3 p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/30 transition {{ in_array($resource['id'], $selectedResources) ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}"
                >
                    <div class="flex-shrink-0">
                        <div class="w-5 h-5 rounded border-2 flex items-center justify-center {{ in_array($resource['id'], $selectedResources) ? 'bg-blue-500 border-blue-500' : 'border-gray-300 dark:border-gray-600' }}">
                            @if(in_array($resource['id'], $selectedResources))
                            <core:icon name="check" class="text-white text-xs" />
                            @endif
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate">{{ $resource['name'] }}</div>
                        @if($resource['detail'])
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $resource['detail'] }}</div>
                        @endif
                    </div>
                    <div class="flex-shrink-0 text-xs text-gray-400">
                        {{ $resource['created_at'] }}
                    </div>
                </div>
                @empty
                <div class="p-4 text-center text-gray-500">{{ __('tenant::tenant.admin.resources_modal.no_resources') }}</div>
                @endforelse
            </div>

            {{-- Transfer section --}}
            @if(count($this->currentResources) > 0)
            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                <flux:heading size="sm" class="mb-3">{{ __('tenant::tenant.admin.resources_modal.transfer_selected') }}</flux:heading>
                <div class="flex items-end gap-3">
                    <div class="flex-1">
                        <flux:select wire:model="resourcesTargetWorkspaceId" label="{{ __('tenant::tenant.admin.transfer_modal.target_workspace') }}">
                            <flux:select.option value="">{{ __('tenant::tenant.admin.resources_modal.select_workspace') }}</flux:select.option>
                            @foreach($this->allWorkspaces as $ws)
                                @if($ws->id !== $resourcesWorkspaceId)
                                <flux:select.option value="{{ $ws->id }}">{{ $ws->name }} ({{ $ws->slug }})</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:button variant="primary" wire:click="transferSelectedResources">
                        {{ trans_choice('tenant::tenant.admin.resources_modal.transfer_items', count($selectedResources), ['count' => count($selectedResources)]) }}
                    </flux:button>
                </div>
            </div>
            @endif

            <div class="flex justify-end pt-2">
                <flux:button variant="ghost" wire:click="closeResources">{{ __('tenant::tenant.admin.resources_modal.close') }}</flux:button>
            </div>
        </div>
    </core:modal>

    {{-- Provision Resource Modal --}}
    <core:modal wire:model="showProvisionModal" class="max-w-md">
        @php
            $provisionWorkspace = $this->allWorkspaces->firstWhere('id', $provisionWorkspaceId);
            $config = $this->provisionConfig[$provisionType] ?? null;
        @endphp
        <core:heading size="lg">
            <div class="flex items-center gap-2">
                @if($config)
                <div class="w-8 h-8 rounded-lg bg-{{ $config['color'] }}-500/20 flex items-center justify-center">
                    <core:icon name="{{ $config['icon'] }}" class="text-{{ $config['color'] }}-600 dark:text-{{ $config['color'] }}-400" />
                </div>
                @endif
                <span>{{ __('tenant::tenant.admin.provision_modal.create', ['type' => $config['label'] ?? 'Resource']) }}</span>
            </div>
        </core:heading>

        <div class="mt-4 space-y-4">
            @if($provisionWorkspace)
            <div class="rounded-lg bg-gray-50 dark:bg-gray-700/30 p-3">
                <flux:text size="sm" class="text-gray-600 dark:text-gray-300">
                    <strong>{{ __('tenant::tenant.admin.provision_modal.workspace') }}:</strong> {{ $provisionWorkspace->name }}
                </flux:text>
            </div>
            @endif

            <flux:input wire:model="provisionName" label="{{ __('tenant::tenant.admin.provision_modal.name') }}" placeholder="{{ __('tenant::tenant.admin.provision_modal.name_placeholder') }}" required />

            @if($config && in_array('slug', $config['fields'] ?? []))
            <flux:input wire:model="provisionSlug" label="{{ __('tenant::tenant.admin.provision_modal.slug') }}" placeholder="{{ __('tenant::tenant.admin.provision_modal.slug_placeholder') }}" required class="font-mono" />
            @endif

            @if($config && in_array('url', $config['fields'] ?? []))
            <flux:input wire:model="provisionUrl" label="{{ __('tenant::tenant.admin.provision_modal.url') }}" placeholder="{{ __('tenant::tenant.admin.provision_modal.url_placeholder') }}" required />
            @endif

            <div class="flex justify-end gap-2 pt-4">
                <flux:button variant="ghost" wire:click="closeProvision">{{ __('tenant::tenant.admin.provision_modal.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="provisionResource">
                    {{ __('tenant::tenant.admin.provision_modal.create', ['type' => $config['label'] ?? 'Resource']) }}
                </flux:button>
            </div>
        </div>
    </core:modal>
</admin:module>

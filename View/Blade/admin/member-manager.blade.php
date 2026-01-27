<admin:module title="{{ __('tenant::tenant.admin.member_manager.title') }}" subtitle="{{ __('tenant::tenant.admin.member_manager.subtitle') }}">
    <admin:flash />

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/30">
                    <flux:icon name="users" class="size-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['total_members']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.stats.total_members') }}</div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/30">
                    <flux:icon name="user-group" class="size-5 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['with_team']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.stats.with_team') }}</div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-amber-100 dark:bg-amber-900/30">
                    <flux:icon name="adjustments-horizontal" class="size-5 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['with_custom_permissions']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.stats.with_custom') }}</div>
                </div>
            </div>
        </div>
    </div>

    <admin:filter-bar cols="3">
        <admin:search model="search" placeholder="{{ __('tenant::tenant.admin.member_manager.search.placeholder') }}" />
        <admin:filter model="workspaceFilter" :options="$this->workspaces" placeholder="{{ __('tenant::tenant.admin.member_manager.filter.all_workspaces') }}" />
        <admin:filter model="teamFilter" :options="$this->teamsForFilter" placeholder="{{ __('tenant::tenant.admin.member_manager.filter.all_teams') }}" />
    </admin:filter-bar>

    {{-- Bulk action bar --}}
    @if(count($selected) > 0)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:icon name="check-circle" class="text-blue-600 size-5" />
            <span class="text-sm font-medium text-blue-700 dark:text-blue-300">
                {{ __('tenant::tenant.admin.member_manager.bulk.selected', ['count' => count($selected)]) }}
            </span>
        </div>
        <div class="flex items-center gap-2">
            @if($workspaceFilter)
            <flux:button wire:click="openBulkAssignModal" size="sm" variant="ghost" icon="user-group">
                {{ __('tenant::tenant.admin.member_manager.bulk.assign_team') }}
            </flux:button>
            @endif
            <flux:button wire:click="bulkRemoveFromTeam" wire:confirm="{{ __('tenant::tenant.admin.member_manager.confirm.bulk_remove_team') }}" size="sm" variant="ghost" icon="user-minus">
                {{ __('tenant::tenant.admin.member_manager.bulk.remove_team') }}
            </flux:button>
            <flux:button wire:click="bulkClearPermissions" wire:confirm="{{ __('tenant::tenant.admin.member_manager.confirm.bulk_clear_permissions') }}" size="sm" variant="ghost" icon="shield-exclamation">
                {{ __('tenant::tenant.admin.member_manager.bulk.clear_permissions') }}
            </flux:button>
            <flux:button wire:click="clearSelection" size="sm" variant="ghost" icon="x-mark">
                {{ __('tenant::tenant.admin.member_manager.bulk.clear') }}
            </flux:button>
        </div>
    </div>
    @endif

    {{-- Members table --}}
    @if($this->members->isEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm">
            <div class="text-center py-16 px-6">
                <div class="mx-auto size-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4">
                    <flux:icon name="users" class="size-8 text-zinc-400 dark:text-zinc-500" />
                </div>
                <flux:heading size="lg" class="text-gray-900 dark:text-gray-100">{{ __('tenant::tenant.admin.member_manager.empty_state.title') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400 max-w-md mx-auto">{{ __('tenant::tenant.admin.member_manager.empty_state.description') }}</flux:text>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left">
                                <flux:checkbox wire:model.live="selectAll" />
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.member') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.workspace') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.team') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.permissions') }}</th>
                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.member_manager.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                        @foreach($this->members as $member)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                {{-- Checkbox --}}
                                <td class="whitespace-nowrap px-4 py-4">
                                    <flux:checkbox wire:model.live="selected" value="{{ $member->id }}" />
                                </td>

                                {{-- Member info --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if($member->user?->avatar_url)
                                            <img src="{{ $member->user->avatar_url }}" alt="" class="size-8 rounded-full" />
                                        @else
                                            <div class="size-8 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                                <flux:icon name="user" class="size-4 text-gray-500" />
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $member->user?->name ?? __('tenant::tenant.common.unknown') }}</div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $member->user?->email }}</div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Workspace --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $member->workspace?->name ?? __('tenant::tenant.common.na') }}</div>
                                </td>

                                {{-- Team --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    @if($member->team)
                                        <flux:badge size="sm" color="{{ $member->team->colour }}">
                                            {{ $member->team->name }}
                                        </flux:badge>
                                    @else
                                        <span class="text-sm text-gray-400">{{ __('tenant::tenant.admin.member_manager.labels.no_team') }}</span>
                                    @endif
                                </td>

                                {{-- Legacy role --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span class="text-sm text-gray-500 dark:text-gray-400 capitalize">{{ $member->role }}</span>
                                </td>

                                {{-- Custom permissions indicator --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    @php
                                        $customPerms = $member->custom_permissions ?? [];
                                        $grantCount = count(array_filter($customPerms, fn($p) => !str_starts_with($p, '-')));
                                        $revokeCount = count(array_filter($customPerms, fn($p) => str_starts_with($p, '-')));
                                    @endphp
                                    @if(!empty($customPerms))
                                        <div class="flex items-center gap-1">
                                            @if($grantCount > 0)
                                                <flux:badge size="sm" color="green">+{{ $grantCount }}</flux:badge>
                                            @endif
                                            @if($revokeCount > 0)
                                                <flux:badge size="sm" color="red">-{{ $revokeCount }}</flux:badge>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400">{{ __('tenant::tenant.admin.member_manager.labels.inherited') }}</span>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <flux:dropdown align="end">
                                        <flux:button variant="ghost" icon="ellipsis-vertical" size="sm" square />
                                        <flux:menu>
                                            <flux:menu.item wire:click="openAssignModal({{ $member->id }})" icon="user-group">
                                                {{ __('tenant::tenant.admin.member_manager.actions.assign_team') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="openPermissionsModal({{ $member->id }})" icon="key">
                                                {{ __('tenant::tenant.admin.member_manager.actions.custom_permissions') }}
                                            </flux:menu.item>
                                            @if($member->team_id)
                                            <flux:menu.item wire:click="removeFromTeam({{ $member->id }})" icon="user-minus">
                                                {{ __('tenant::tenant.admin.member_manager.actions.remove_from_team') }}
                                            </flux:menu.item>
                                            @endif
                                            @if(!empty($member->custom_permissions))
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="clearPermissions({{ $member->id }})" wire:confirm="{{ __('tenant::tenant.admin.member_manager.confirm.clear_permissions') }}" icon="shield-exclamation" variant="danger">
                                                {{ __('tenant::tenant.admin.member_manager.actions.clear_permissions') }}
                                            </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($this->members->hasPages())
                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    {{ $this->members->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Assign to Team Modal --}}
    <core:modal wire:model="showAssignModal" class="max-w-md">
        <core:heading size="lg">
            {{ __('tenant::tenant.admin.member_manager.assign_modal.title') }}
        </core:heading>

        <form wire:submit="saveAssignment" class="space-y-4 mt-4">
            <core:select
                wire:model="assignTeamId"
                label="{{ __('tenant::tenant.admin.member_manager.assign_modal.team') }}"
            >
                <option value="">{{ __('tenant::tenant.admin.member_manager.assign_modal.no_team') }}</option>
                @foreach($this->teamsForAssignment as $team)
                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                @endforeach
            </core:select>

            <div class="flex justify-end gap-2 pt-4">
                <core:button variant="ghost" wire:click="closeAssignModal">
                    {{ __('tenant::tenant.admin.member_manager.modal.actions.cancel') }}
                </core:button>
                <core:button type="submit" variant="primary">
                    {{ __('tenant::tenant.admin.member_manager.modal.actions.save') }}
                </core:button>
            </div>
        </form>
    </core:modal>

    {{-- Custom Permissions Modal --}}
    <core:modal wire:model="showPermissionsModal" class="max-w-3xl">
        <core:heading size="lg">
            {{ __('tenant::tenant.admin.member_manager.permissions_modal.title') }}
        </core:heading>

        @if($this->memberForPermissions)
        <div class="mt-4 space-y-6">
            {{-- Member info --}}
            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                @if($this->memberForPermissions->user?->avatar_url)
                    <img src="{{ $this->memberForPermissions->user->avatar_url }}" alt="" class="size-10 rounded-full" />
                @else
                    <div class="size-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                        <flux:icon name="user" class="size-5 text-gray-500" />
                    </div>
                @endif
                <div>
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $this->memberForPermissions->user?->name }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('tenant::tenant.admin.member_manager.permissions_modal.team_permissions', ['team' => $this->memberForPermissions->team?->name ?? __('tenant::tenant.common.none')]) }}
                    </div>
                </div>
            </div>

            <div class="text-sm text-gray-600 dark:text-gray-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg">
                <flux:icon name="information-circle" class="inline size-4 mr-1" />
                {{ __('tenant::tenant.admin.member_manager.permissions_modal.description') }}
            </div>

            <form wire:submit="savePermissions" class="space-y-6">
                {{-- Granted permissions (additions) --}}
                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        <flux:icon name="plus-circle" class="inline size-4 mr-1 text-green-600" />
                        {{ __('tenant::tenant.admin.member_manager.permissions_modal.grant_label') }}
                    </label>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 max-h-48 overflow-y-auto pr-2">
                        @foreach($this->permissionGroups as $groupKey => $group)
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 border border-green-200 dark:border-green-800">
                                <div class="font-medium text-green-900 dark:text-green-100 mb-2 text-sm">{{ $group['label'] }}</div>
                                <div class="space-y-1.5">
                                    @foreach($group['permissions'] as $permKey => $permLabel)
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                wire:model="grantedPermissions"
                                                value="{{ $permKey }}"
                                                class="rounded border-green-300 text-green-600 focus:ring-green-500"
                                            />
                                            <span class="text-xs text-green-800 dark:text-green-200">{{ $permLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Revoked permissions (removals) --}}
                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        <flux:icon name="minus-circle" class="inline size-4 mr-1 text-red-600" />
                        {{ __('tenant::tenant.admin.member_manager.permissions_modal.revoke_label') }}
                    </label>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 max-h-48 overflow-y-auto pr-2">
                        @foreach($this->permissionGroups as $groupKey => $group)
                            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 border border-red-200 dark:border-red-800">
                                <div class="font-medium text-red-900 dark:text-red-100 mb-2 text-sm">{{ $group['label'] }}</div>
                                <div class="space-y-1.5">
                                    @foreach($group['permissions'] as $permKey => $permLabel)
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                wire:model="revokedPermissions"
                                                value="{{ $permKey }}"
                                                class="rounded border-red-300 text-red-600 focus:ring-red-500"
                                            />
                                            <span class="text-xs text-red-800 dark:text-red-200">{{ $permLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <core:button variant="ghost" wire:click="closePermissionsModal">
                        {{ __('tenant::tenant.admin.member_manager.modal.actions.cancel') }}
                    </core:button>
                    <core:button type="submit" variant="primary">
                        {{ __('tenant::tenant.admin.member_manager.modal.actions.save') }}
                    </core:button>
                </div>
            </form>
        </div>
        @endif
    </core:modal>

    {{-- Bulk Assign Modal --}}
    <core:modal wire:model="showBulkAssignModal" class="max-w-md">
        <core:heading size="lg">
            {{ __('tenant::tenant.admin.member_manager.bulk_assign_modal.title') }}
        </core:heading>

        <form wire:submit="bulkAssignTeam" class="space-y-4 mt-4">
            <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg text-sm text-blue-700 dark:text-blue-300">
                {{ __('tenant::tenant.admin.member_manager.bulk_assign_modal.description', ['count' => count($selected)]) }}
            </div>

            <core:select
                wire:model="bulkTeamId"
                label="{{ __('tenant::tenant.admin.member_manager.bulk_assign_modal.team') }}"
            >
                <option value="">{{ __('tenant::tenant.admin.member_manager.bulk_assign_modal.no_team') }}</option>
                @foreach($this->teamsForBulkAssignment as $team)
                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                @endforeach
            </core:select>

            <div class="flex justify-end gap-2 pt-4">
                <core:button variant="ghost" wire:click="closeBulkAssignModal">
                    {{ __('tenant::tenant.admin.member_manager.modal.actions.cancel') }}
                </core:button>
                <core:button type="submit" variant="primary">
                    {{ __('tenant::tenant.admin.member_manager.modal.actions.assign') }}
                </core:button>
            </div>
        </form>
    </core:modal>
</admin:module>

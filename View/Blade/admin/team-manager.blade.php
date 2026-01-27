<admin:module title="{{ __('tenant::tenant.admin.team_manager.title') }}" subtitle="{{ __('tenant::tenant.admin.team_manager.subtitle') }}">
    <admin:flash />

    <x-slot:actions>
        <div class="flex items-center gap-2">
            @if($workspaceFilter)
            <flux:dropdown>
                <flux:button variant="ghost" icon="ellipsis-vertical" />
                <flux:menu>
                    <flux:menu.item wire:click="seedDefaultTeams({{ $workspaceFilter }})" icon="sparkles">
                        {{ __('tenant::tenant.admin.team_manager.actions.seed_defaults') }}
                    </flux:menu.item>
                    <flux:menu.item wire:click="migrateMembers({{ $workspaceFilter }})" icon="arrow-right-arrow-left">
                        {{ __('tenant::tenant.admin.team_manager.actions.migrate_members') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            @endif
            <flux:button wire:click="openCreateTeam" variant="primary" icon="plus">
                {{ __('tenant::tenant.admin.team_manager.actions.create_team') }}
            </flux:button>
        </div>
    </x-slot:actions>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/30">
                    <flux:icon name="users" class="size-5 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['total_teams']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.stats.total_teams') }}</div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/30">
                    <flux:icon name="user" class="size-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['total_members']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.stats.total_members') }}</div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-green-100 dark:bg-green-900/30">
                    <flux:icon name="check-circle" class="size-5 text-green-600 dark:text-green-400" />
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->stats['members_with_team']) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.stats.members_assigned') }}</div>
                </div>
            </div>
        </div>
    </div>

    <admin:filter-bar cols="2">
        <admin:search model="search" placeholder="{{ __('tenant::tenant.admin.team_manager.search.placeholder') }}" />
        <admin:filter model="workspaceFilter" :options="$this->workspaces" placeholder="{{ __('tenant::tenant.admin.team_manager.filter.all_workspaces') }}" />
    </admin:filter-bar>

    {{-- Teams table --}}
    @if($this->teams->isEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm">
            <div class="text-center py-16 px-6">
                <div class="mx-auto size-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4">
                    <flux:icon name="users" class="size-8 text-zinc-400 dark:text-zinc-500" />
                </div>
                <flux:heading size="lg" class="text-gray-900 dark:text-gray-100">{{ __('tenant::tenant.admin.team_manager.empty_state.title') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400 max-w-md mx-auto">{{ __('tenant::tenant.admin.team_manager.empty_state.description') }}</flux:text>
                <div class="mt-6 flex items-center justify-center gap-3">
                    <flux:button wire:click="openCreateTeam" variant="primary" icon="plus">
                        {{ __('tenant::tenant.admin.team_manager.actions.create_team') }}
                    </flux:button>
                    @if($workspaceFilter)
                    <flux:button wire:click="seedDefaultTeams({{ $workspaceFilter }})" variant="ghost" icon="sparkles">
                        {{ __('tenant::tenant.admin.team_manager.actions.seed_defaults') }}
                    </flux:button>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.columns.team') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.columns.workspace') }}</th>
                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.columns.members') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.columns.permissions') }}</th>
                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('tenant::tenant.admin.team_manager.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                        @foreach($this->teams as $team)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                {{-- Team info --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-8 rounded-lg bg-{{ $team->colour }}-500/20 flex items-center justify-center">
                                            <flux:icon name="users" class="size-4 text-{{ $team->colour }}-600 dark:text-{{ $team->colour }}-400" />
                                        </div>
                                        <div class="space-y-0.5">
                                            <div class="font-medium text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                                {{ $team->name }}
                                                @if($team->is_system)
                                                    <flux:badge size="sm" color="violet">{{ __('tenant::tenant.admin.team_manager.badges.system') }}</flux:badge>
                                                @endif
                                                @if($team->is_default)
                                                    <flux:badge size="sm" color="blue">{{ __('tenant::tenant.admin.team_manager.badges.default') }}</flux:badge>
                                                @endif
                                            </div>
                                            @if($team->description)
                                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs">{{ $team->description }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                {{-- Workspace --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $team->workspace?->name ?? __('tenant::tenant.common.na') }}</div>
                                </td>

                                {{-- Member count --}}
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <flux:badge size="sm" color="{{ $team->colour }}">{{ number_format($team->members_count) }}</flux:badge>
                                </td>

                                {{-- Permissions count --}}
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ count($team->permissions ?? []) }} {{ __('tenant::tenant.admin.team_manager.labels.permissions') }}
                                    </div>
                                </td>

                                {{-- Actions --}}
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <flux:dropdown align="end">
                                        <flux:button variant="ghost" icon="ellipsis-vertical" size="sm" square />
                                        <flux:menu>
                                            <flux:menu.item wire:click="openEditTeam({{ $team->id }})" icon="pencil">
                                                {{ __('tenant::tenant.admin.team_manager.actions.edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item href="{{ route('hub.admin.tenant.members', ['workspaceFilter' => $team->workspace_id, 'teamFilter' => $team->id]) }}" icon="users">
                                                {{ __('tenant::tenant.admin.team_manager.actions.view_members') }}
                                            </flux:menu.item>
                                            @unless($team->is_system)
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="deleteTeam({{ $team->id }})" wire:confirm="{{ __('tenant::tenant.admin.team_manager.confirm.delete_team') }}" icon="trash" variant="danger">
                                                {{ __('tenant::tenant.admin.team_manager.actions.delete') }}
                                            </flux:menu.item>
                                            @endunless
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($this->teams->hasPages())
                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    {{ $this->teams->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Create/Edit Team Modal --}}
    <core:modal wire:model="showTeamModal" class="max-w-2xl">
        <core:heading size="lg">
            {{ $editingTeamId ? __('tenant::tenant.admin.team_manager.modal.title_edit') : __('tenant::tenant.admin.team_manager.modal.title_create') }}
        </core:heading>

        <form wire:submit="saveTeam" class="space-y-4 mt-4">
            <core:select
                wire:model="teamWorkspaceId"
                label="{{ __('tenant::tenant.admin.team_manager.modal.fields.workspace') }}"
                required
            >
                <option value="">{{ __('tenant::tenant.admin.team_manager.modal.fields.select_workspace') }}</option>
                @foreach($this->workspaces as $workspace)
                    <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                @endforeach
            </core:select>

            <div class="grid grid-cols-2 gap-4">
                <core:input
                    wire:model="teamName"
                    label="{{ __('tenant::tenant.admin.team_manager.modal.fields.name') }}"
                    placeholder="{{ __('tenant::tenant.admin.team_manager.modal.fields.name_placeholder') }}"
                    required
                />

                @unless($editingTeamId)
                <core:input
                    wire:model="teamSlug"
                    label="{{ __('tenant::tenant.admin.team_manager.modal.fields.slug') }}"
                    placeholder="{{ __('tenant::tenant.admin.team_manager.modal.fields.slug_placeholder') }}"
                    description="{{ __('tenant::tenant.admin.team_manager.modal.fields.slug_description') }}"
                />
                @endunless
            </div>

            <core:textarea
                wire:model="teamDescription"
                label="{{ __('tenant::tenant.admin.team_manager.modal.fields.description') }}"
                rows="2"
            />

            <div class="grid grid-cols-2 gap-4">
                <core:select
                    wire:model="teamColour"
                    label="{{ __('tenant::tenant.admin.team_manager.modal.fields.colour') }}"
                >
                    @foreach($this->colourOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </core:select>

                <div class="flex items-end pb-1">
                    <core:checkbox
                        wire:model="teamIsDefault"
                        label="{{ __('tenant::tenant.admin.team_manager.modal.fields.is_default') }}"
                    />
                </div>
            </div>

            {{-- Permissions matrix --}}
            <div class="space-y-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('tenant::tenant.admin.team_manager.modal.fields.permissions') }}
                </label>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 max-h-72 overflow-y-auto pr-2">
                    @foreach($this->permissionGroups as $groupKey => $group)
                        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                            <div class="font-medium text-gray-900 dark:text-gray-100 mb-2 text-sm">{{ $group['label'] }}</div>
                            <div class="space-y-2">
                                @foreach($group['permissions'] as $permKey => $permLabel)
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            wire:model="teamPermissions"
                                            value="{{ $permKey }}"
                                            class="rounded border-gray-300 text-violet-600 focus:ring-violet-500"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $permLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <core:button variant="ghost" wire:click="closeTeamModal">
                    {{ __('tenant::tenant.admin.team_manager.modal.actions.cancel') }}
                </core:button>
                <core:button type="submit" variant="primary">
                    {{ $editingTeamId ? __('tenant::tenant.admin.team_manager.modal.actions.update') : __('tenant::tenant.admin.team_manager.modal.actions.create') }}
                </core:button>
            </div>
        </form>
    </core:modal>
</admin:module>

<div>
    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-flux::card>
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-blue-100 p-3 dark:bg-blue-900/30">
                    <x-flux::icon name="webhook" class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ number_format($this->stats['total']) }}</div>
                    <div class="text-sm text-zinc-500">Total Webhooks</div>
                </div>
            </div>
        </x-flux::card>

        <x-flux::card>
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-green-100 p-3 dark:bg-green-900/30">
                    <x-flux::icon name="check-circle" class="h-5 w-5 text-green-600 dark:text-green-400" />
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ number_format($this->stats['active']) }}</div>
                    <div class="text-sm text-zinc-500">Active</div>
                </div>
            </div>
        </x-flux::card>

        <x-flux::card>
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-red-100 p-3 dark:bg-red-900/30">
                    <x-flux::icon name="exclamation-triangle" class="h-5 w-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ number_format($this->stats['circuit_broken']) }}</div>
                    <div class="text-sm text-zinc-500">Circuit Broken</div>
                </div>
            </div>
        </x-flux::card>
    </div>

    {{-- Message --}}
    @if($message)
        <div class="mb-4">
            <x-flux::alert :variant="$messageType === 'error' ? 'danger' : 'success'" dismissible wire:click="clearMessage">
                {{ $message }}
            </x-flux::alert>
        </div>
    @endif

    {{-- Filters --}}
    <x-flux::card class="mb-6">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <x-flux::input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by name or URL..."
                    icon="magnifying-glass"
                />
            </div>

            <div class="w-48">
                <x-flux::select wire:model.live="workspaceId">
                    <option value="">All Workspaces</option>
                    @foreach($this->workspaces as $workspace)
                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                    @endforeach
                </x-flux::select>
            </div>

            <div class="w-40">
                <x-flux::select wire:model.live="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="circuit_broken">Circuit Broken</option>
                </x-flux::select>
            </div>

            <x-flux::button wire:click="create" variant="primary">
                <x-flux::icon name="plus" class="mr-2 h-4 w-4" />
                New Webhook
            </x-flux::button>
        </div>
    </x-flux::card>

    {{-- Webhooks Table --}}
    <x-flux::card>
        <x-flux::table>
            <x-flux::table.head>
                <x-flux::table.row>
                    <x-flux::table.header>Webhook</x-flux::table.header>
                    <x-flux::table.header>Workspace</x-flux::table.header>
                    <x-flux::table.header>Events</x-flux::table.header>
                    <x-flux::table.header>Status</x-flux::table.header>
                    <x-flux::table.header>Deliveries</x-flux::table.header>
                    <x-flux::table.header class="text-right">Actions</x-flux::table.header>
                </x-flux::table.row>
            </x-flux::table.head>
            <x-flux::table.body>
                @forelse($this->webhooks as $webhook)
                    <x-flux::table.row>
                        <x-flux::table.cell>
                            <div>
                                <div class="font-medium">{{ $webhook->name }}</div>
                                <div class="text-xs text-zinc-500 truncate max-w-xs" title="{{ $webhook->url }}">
                                    {{ $webhook->url }}
                                </div>
                            </div>
                        </x-flux::table.cell>

                        <x-flux::table.cell>
                            <span class="text-sm">{{ $webhook->workspace?->name ?? 'N/A' }}</span>
                        </x-flux::table.cell>

                        <x-flux::table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach($webhook->events as $event)
                                    <x-flux::badge size="sm" color="purple">{{ $event }}</x-flux::badge>
                                @endforeach
                            </div>
                        </x-flux::table.cell>

                        <x-flux::table.cell>
                            @if($webhook->isCircuitBroken())
                                <x-flux::badge color="red">Circuit Broken</x-flux::badge>
                            @elseif($webhook->is_active)
                                <x-flux::badge color="green">Active</x-flux::badge>
                            @else
                                <x-flux::badge color="zinc">Inactive</x-flux::badge>
                            @endif

                            @if($webhook->last_delivery_status)
                                <div class="mt-1">
                                    <x-flux::badge size="sm" :color="$webhook->last_delivery_status->value === 'success' ? 'green' : ($webhook->last_delivery_status->value === 'failed' ? 'red' : 'amber')">
                                        Last: {{ ucfirst($webhook->last_delivery_status->value) }}
                                    </x-flux::badge>
                                </div>
                            @endif
                        </x-flux::table.cell>

                        <x-flux::table.cell>
                            <button
                                wire:click="viewDeliveries({{ $webhook->id }})"
                                class="text-blue-600 hover:underline"
                            >
                                {{ number_format($webhook->deliveries_count) }} deliveries
                            </button>
                        </x-flux::table.cell>

                        <x-flux::table.cell class="text-right">
                            <x-flux::dropdown>
                                <x-slot:trigger>
                                    <x-flux::button variant="ghost" size="sm">
                                        <x-flux::icon name="ellipsis-vertical" class="h-4 w-4" />
                                    </x-flux::button>
                                </x-slot:trigger>

                                <x-flux::dropdown.item wire:click="edit({{ $webhook->id }})">
                                    <x-flux::icon name="pencil" class="mr-2 h-4 w-4" />
                                    Edit
                                </x-flux::dropdown.item>

                                <x-flux::dropdown.item wire:click="testWebhook({{ $webhook->id }})">
                                    <x-flux::icon name="paper-airplane" class="mr-2 h-4 w-4" />
                                    Send Test
                                </x-flux::dropdown.item>

                                <x-flux::dropdown.item wire:click="viewDeliveries({{ $webhook->id }})">
                                    <x-flux::icon name="queue-list" class="mr-2 h-4 w-4" />
                                    View Deliveries
                                </x-flux::dropdown.item>

                                <x-flux::dropdown.item wire:click="regenerateSecret({{ $webhook->id }})">
                                    <x-flux::icon name="key" class="mr-2 h-4 w-4" />
                                    Regenerate Secret
                                </x-flux::dropdown.item>

                                @if($webhook->isCircuitBroken())
                                    <x-flux::dropdown.item wire:click="resetCircuitBreaker({{ $webhook->id }})">
                                        <x-flux::icon name="arrow-path" class="mr-2 h-4 w-4" />
                                        Reset Circuit Breaker
                                    </x-flux::dropdown.item>
                                @endif

                                <x-flux::dropdown.divider />

                                <x-flux::dropdown.item wire:click="toggleActive({{ $webhook->id }})">
                                    @if($webhook->is_active)
                                        <x-flux::icon name="pause" class="mr-2 h-4 w-4" />
                                        Disable
                                    @else
                                        <x-flux::icon name="play" class="mr-2 h-4 w-4" />
                                        Enable
                                    @endif
                                </x-flux::dropdown.item>

                                <x-flux::dropdown.item
                                    wire:click="delete({{ $webhook->id }})"
                                    wire:confirm="Are you sure you want to delete this webhook?"
                                    variant="danger"
                                >
                                    <x-flux::icon name="trash" class="mr-2 h-4 w-4" />
                                    Delete
                                </x-flux::dropdown.item>
                            </x-flux::dropdown>
                        </x-flux::table.cell>
                    </x-flux::table.row>
                @empty
                    <x-flux::table.row>
                        <x-flux::table.cell colspan="6" class="text-center py-8 text-zinc-500">
                            No webhooks found. Create one to get started.
                        </x-flux::table.cell>
                    </x-flux::table.row>
                @endforelse
            </x-flux::table.body>
        </x-flux::table>

        <div class="mt-4">
            {{ $this->webhooks->links() }}
        </div>
    </x-flux::card>

    {{-- Create/Edit Modal --}}
    <x-flux::modal wire:model="showFormModal" max-width="lg">
        <x-flux::modal.header>
            {{ $editingId ? 'Edit Webhook' : 'Create Webhook' }}
        </x-flux::modal.header>

        <x-flux::modal.body>
            <div class="space-y-4">
                @if(!$editingId)
                    <x-flux::field>
                        <x-flux::label>Workspace</x-flux::label>
                        <x-flux::select wire:model="workspaceId">
                            <option value="">Select a workspace...</option>
                            @foreach($this->workspaces as $workspace)
                                <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                            @endforeach
                        </x-flux::select>
                        <x-flux::error name="workspaceId" />
                    </x-flux::field>
                @endif

                <x-flux::field>
                    <x-flux::label>Name</x-flux::label>
                    <x-flux::input wire:model="name" placeholder="My Webhook" />
                    <x-flux::error name="name" />
                </x-flux::field>

                <x-flux::field>
                    <x-flux::label>URL</x-flux::label>
                    <x-flux::input wire:model="url" type="url" placeholder="https://example.com/webhook" />
                    <x-flux::error name="url" />
                    <x-flux::description>The endpoint that will receive webhook POST requests.</x-flux::description>
                </x-flux::field>

                <x-flux::field>
                    <x-flux::label>Events</x-flux::label>
                    <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        @foreach($this->availableEvents as $eventKey => $eventInfo)
                            <label class="flex items-start gap-3 cursor-pointer">
                                <x-flux::checkbox
                                    wire:model="events"
                                    value="{{ $eventKey }}"
                                />
                                <div>
                                    <div class="font-medium">{{ $eventInfo['name'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $eventInfo['description'] }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    <x-flux::error name="events" />
                </x-flux::field>

                <x-flux::field>
                    <x-flux::label>Max Retry Attempts</x-flux::label>
                    <x-flux::input wire:model="maxAttempts" type="number" min="1" max="10" />
                    <x-flux::description>Number of times to retry failed deliveries (1-10).</x-flux::description>
                </x-flux::field>

                <x-flux::field>
                    <label class="flex items-center gap-2">
                        <x-flux::checkbox wire:model="isActive" />
                        <span>Active</span>
                    </label>
                    <x-flux::description>Inactive webhooks will not receive any events.</x-flux::description>
                </x-flux::field>
            </div>
        </x-flux::modal.body>

        <x-flux::modal.footer>
            <x-flux::button wire:click="closeFormModal" variant="ghost">Cancel</x-flux::button>
            <x-flux::button wire:click="save" variant="primary">
                {{ $editingId ? 'Update' : 'Create' }}
            </x-flux::button>
        </x-flux::modal.footer>
    </x-flux::modal>

    {{-- Deliveries Modal --}}
    <x-flux::modal wire:model="showDeliveriesModal" max-width="3xl">
        <x-flux::modal.header>
            Delivery History
        </x-flux::modal.header>

        <x-flux::modal.body>
            <x-flux::table>
                <x-flux::table.head>
                    <x-flux::table.row>
                        <x-flux::table.header>Event</x-flux::table.header>
                        <x-flux::table.header>Status</x-flux::table.header>
                        <x-flux::table.header>HTTP</x-flux::table.header>
                        <x-flux::table.header>Attempts</x-flux::table.header>
                        <x-flux::table.header>Time</x-flux::table.header>
                        <x-flux::table.header></x-flux::table.header>
                    </x-flux::table.row>
                </x-flux::table.head>
                <x-flux::table.body>
                    @forelse($this->recentDeliveries as $delivery)
                        <x-flux::table.row>
                            <x-flux::table.cell>
                                <x-flux::badge color="purple">{{ $delivery->getEventDisplayName() }}</x-flux::badge>
                            </x-flux::table.cell>

                            <x-flux::table.cell>
                                <x-flux::badge :color="$delivery->getStatusColour()">
                                    {{ ucfirst($delivery->status->value) }}
                                </x-flux::badge>
                            </x-flux::table.cell>

                            <x-flux::table.cell>
                                {{ $delivery->http_status ?? '-' }}
                            </x-flux::table.cell>

                            <x-flux::table.cell>
                                {{ $delivery->attempts }}
                            </x-flux::table.cell>

                            <x-flux::table.cell>
                                <span title="{{ $delivery->created_at }}">
                                    {{ $delivery->created_at->diffForHumans() }}
                                </span>
                            </x-flux::table.cell>

                            <x-flux::table.cell>
                                @if($delivery->isFailed())
                                    <x-flux::button
                                        wire:click="retryDelivery({{ $delivery->id }})"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Retry
                                    </x-flux::button>
                                @endif
                            </x-flux::table.cell>
                        </x-flux::table.row>
                    @empty
                        <x-flux::table.row>
                            <x-flux::table.cell colspan="6" class="text-center py-8 text-zinc-500">
                                No deliveries yet.
                            </x-flux::table.cell>
                        </x-flux::table.row>
                    @endforelse
                </x-flux::table.body>
            </x-flux::table>
        </x-flux::modal.body>

        <x-flux::modal.footer>
            <x-flux::button wire:click="closeDeliveriesModal" variant="ghost">Close</x-flux::button>
        </x-flux::modal.footer>
    </x-flux::modal>

    {{-- Secret Modal --}}
    <x-flux::modal wire:model="showSecretModal" max-width="md">
        <x-flux::modal.header>
            Webhook Secret
        </x-flux::modal.header>

        <x-flux::modal.body>
            <x-flux::alert variant="warning" class="mb-4">
                Save this secret now. It will not be shown again.
            </x-flux::alert>

            <div class="rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-zinc-800 break-all">
                {{ $displaySecret }}
            </div>

            <p class="mt-4 text-sm text-zinc-500">
                Use this secret to verify webhook signatures. The signature is sent in the
                <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">X-Signature</code> header
                and is a HMAC-SHA256 hash of the JSON payload.
            </p>
        </x-flux::modal.body>

        <x-flux::modal.footer>
            <x-flux::button wire:click="closeSecretModal" variant="primary">
                I've saved the secret
            </x-flux::button>
        </x-flux::modal.footer>
    </x-flux::modal>
</div>

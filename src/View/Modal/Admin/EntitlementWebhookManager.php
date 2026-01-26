<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\View\Modal\Admin;

use Core\Mod\Tenant\Models\EntitlementWebhook;
use Core\Mod\Tenant\Models\EntitlementWebhookDelivery;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\EntitlementWebhookService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Entitlement Webhooks')]
class EntitlementWebhookManager extends Component
{
    use WithPagination;

    // Filter state
    public ?int $workspaceId = null;

    public string $search = '';

    public string $statusFilter = '';

    // Create/Edit modal state
    public bool $showFormModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public array $events = [];

    public bool $isActive = true;

    public int $maxAttempts = 3;

    // Deliveries modal state
    public bool $showDeliveriesModal = false;

    public ?int $viewingWebhookId = null;

    // Secret modal state
    public bool $showSecretModal = false;

    public ?string $displaySecret = null;

    // Messages
    public string $message = '';

    public string $messageType = 'success';

    protected $queryString = [
        'search' => ['except' => ''],
        'workspaceId' => ['except' => null],
        'statusFilter' => ['except' => ''],
    ];

    protected array $rules = [
        'name' => 'required|string|max:255',
        'url' => 'required|url|max:2048',
        'events' => 'required|array|min:1',
        'events.*' => 'string',
        'isActive' => 'boolean',
        'maxAttempts' => 'required|integer|min:1|max:10',
    ];

    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for webhook administration.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingWorkspaceId(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function webhooks()
    {
        return EntitlementWebhook::query()
            ->with('workspace')
            ->withCount('deliveries')
            ->when($this->workspaceId, fn ($q) => $q->where('workspace_id', $this->workspaceId))
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('url', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter === 'active', fn ($q) => $q->active())
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->statusFilter === 'circuit_broken', fn ($q) => $q->where('failure_count', '>=', EntitlementWebhook::MAX_FAILURES))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function workspaces()
    {
        return Workspace::query()
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableEvents(): array
    {
        return app(EntitlementWebhookService::class)->getAvailableEvents();
    }

    #[Computed]
    public function recentDeliveries()
    {
        if (! $this->viewingWebhookId) {
            return collect();
        }

        return EntitlementWebhookDelivery::query()
            ->where('webhook_id', $this->viewingWebhookId)
            ->latest('created_at')
            ->limit(50)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Create/Edit Methods
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'url', 'events', 'maxAttempts']);
        $this->isActive = true;
        $this->maxAttempts = 3;
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);

        $this->editingId = $webhook->id;
        $this->name = $webhook->name;
        $this->url = $webhook->url;
        $this->events = $webhook->events;
        $this->isActive = $webhook->is_active;
        $this->maxAttempts = $webhook->max_attempts;
        $this->workspaceId = $webhook->workspace_id;
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->validate();

        // Filter events to only valid ones
        $validEvents = array_intersect($this->events, EntitlementWebhook::EVENTS);

        if (empty($validEvents)) {
            $this->addError('events', 'At least one valid event must be selected.');

            return;
        }

        if ($this->editingId) {
            $webhook = EntitlementWebhook::findOrFail($this->editingId);
            $webhook->update([
                'name' => $this->name,
                'url' => $this->url,
                'events' => $validEvents,
                'is_active' => $this->isActive,
                'max_attempts' => $this->maxAttempts,
            ]);

            $this->setMessage('Webhook updated successfully.');
        } else {
            if (! $this->workspaceId) {
                $this->addError('workspaceId', 'Please select a workspace.');

                return;
            }

            $workspace = Workspace::findOrFail($this->workspaceId);
            $webhook = app(EntitlementWebhookService::class)->register(
                workspace: $workspace,
                name: $this->name,
                url: $this->url,
                events: $validEvents
            );

            $webhook->update([
                'is_active' => $this->isActive,
                'max_attempts' => $this->maxAttempts,
            ]);

            // Show the secret to the user
            $this->displaySecret = $webhook->secret;
            $this->showSecretModal = true;

            $this->setMessage('Webhook created successfully. Please save the secret below.');
        }

        $this->showFormModal = false;
        $this->reset(['editingId', 'name', 'url', 'events']);
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->reset(['editingId', 'name', 'url', 'events']);
        $this->resetValidation();
    }

    // -------------------------------------------------------------------------
    // Action Methods
    // -------------------------------------------------------------------------

    public function toggleActive(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);
        $webhook->update(['is_active' => ! $webhook->is_active]);

        $this->setMessage($webhook->is_active ? 'Webhook enabled.' : 'Webhook disabled.');
    }

    public function delete(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);
        $webhook->delete();

        $this->setMessage('Webhook deleted.');
    }

    public function testWebhook(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);
        $delivery = app(EntitlementWebhookService::class)->testWebhook($webhook);

        if ($delivery->isSucceeded()) {
            $this->setMessage('Test webhook sent successfully.');
        } else {
            $this->setMessage('Test webhook failed. Check delivery history for details.', 'error');
        }
    }

    public function regenerateSecret(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);
        $secret = $webhook->regenerateSecret();

        $this->displaySecret = $secret;
        $this->showSecretModal = true;
    }

    public function resetCircuitBreaker(int $id): void
    {
        $webhook = EntitlementWebhook::findOrFail($id);
        app(EntitlementWebhookService::class)->resetCircuitBreaker($webhook);

        $this->setMessage('Webhook re-enabled and failure count reset.');
    }

    // -------------------------------------------------------------------------
    // Deliveries Modal
    // -------------------------------------------------------------------------

    public function viewDeliveries(int $id): void
    {
        $this->viewingWebhookId = $id;
        $this->showDeliveriesModal = true;
    }

    public function closeDeliveriesModal(): void
    {
        $this->showDeliveriesModal = false;
        $this->viewingWebhookId = null;
    }

    public function retryDelivery(int $deliveryId): void
    {
        $delivery = EntitlementWebhookDelivery::findOrFail($deliveryId);

        try {
            $result = app(EntitlementWebhookService::class)->retryDelivery($delivery);

            if ($result->isSucceeded()) {
                $this->setMessage('Delivery retried successfully.');
            } else {
                $this->setMessage('Retry failed. Check delivery details.', 'error');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }
    }

    // -------------------------------------------------------------------------
    // Secret Modal
    // -------------------------------------------------------------------------

    public function closeSecretModal(): void
    {
        $this->showSecretModal = false;
        $this->displaySecret = null;
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    protected function setMessage(string $message, string $type = 'success'): void
    {
        $this->message = $message;
        $this->messageType = $type;
    }

    public function clearMessage(): void
    {
        $this->message = '';
    }

    #[Computed]
    public function stats(): array
    {
        $query = EntitlementWebhook::query();

        if ($this->workspaceId) {
            $query->where('workspace_id', $this->workspaceId);
        }

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'circuit_broken' => (clone $query)->where('failure_count', '>=', EntitlementWebhook::MAX_FAILURES)->count(),
        ];
    }

    public function render(): View
    {
        return view('tenant::admin.entitlement-webhook-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Entitlement Webhooks']);
    }
}

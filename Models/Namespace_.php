<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Namespace model - universal tenant boundary for products.
 *
 * A namespace provides a clean ownership boundary where products belong to
 * a namespace rather than directly to User/Workspace. The namespace itself
 * has polymorphic ownership (User or Workspace can own).
 *
 * Ownership patterns:
 * - Individual user: User → Namespace → Products
 * - Agency: Workspace → Namespace(s) → Products (one per client)
 * - Team member: User in Workspace → access to Workspace's Namespaces
 */
class Namespace_ extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'namespaces';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'owner_type',
        'owner_id',
        'workspace_id',
        'settings',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $namespace) {
            if (empty($namespace->uuid)) {
                $namespace->uuid = (string) Str::uuid();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ownership Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the owner of the namespace (User or Workspace).
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the workspace for billing aggregation (if set).
     *
     * This is separate from owner - a user-owned namespace can still
     * have a workspace context for billing purposes.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Check if this namespace is owned by a user.
     */
    public function isOwnedByUser(): bool
    {
        return $this->owner_type === User::class;
    }

    /**
     * Check if this namespace is owned by a workspace.
     */
    public function isOwnedByWorkspace(): bool
    {
        return $this->owner_type === Workspace::class;
    }

    /**
     * Get the owner as User (or null if workspace-owned).
     */
    public function getOwnerUser(): ?User
    {
        if ($this->isOwnedByUser()) {
            return $this->owner;
        }

        return null;
    }

    /**
     * Get the owner as Workspace (or null if user-owned).
     */
    public function getOwnerWorkspace(): ?Workspace
    {
        if ($this->isOwnedByWorkspace()) {
            return $this->owner;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entitlement Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Active package assignments for this namespace.
     */
    public function namespacePackages(): HasMany
    {
        return $this->hasMany(NamespacePackage::class);
    }

    /**
     * Active boosts for this namespace.
     */
    public function boosts(): HasMany
    {
        return $this->hasMany(Boost::class);
    }

    /**
     * Usage records for this namespace.
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /**
     * Entitlement logs for this namespace.
     */
    public function entitlementLogs(): HasMany
    {
        return $this->hasMany(EntitlementLog::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Settings & Configuration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a setting value from the settings JSON column.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a setting value in the settings JSON column.
     */
    public function setSetting(string $key, mixed $value): self
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;

        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scope to only active namespaces.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Scope to namespaces owned by a specific user.
     */
    public function scopeOwnedByUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('owner_type', User::class)
            ->where('owner_id', $userId);
    }

    /**
     * Scope to namespaces owned by a specific workspace.
     */
    public function scopeOwnedByWorkspace($query, Workspace|int $workspace)
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('owner_type', Workspace::class)
            ->where('owner_id', $workspaceId);
    }

    /**
     * Scope to namespaces accessible by a user (owned by user OR owned by user's workspaces).
     */
    public function scopeAccessibleBy($query, User $user)
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id');

        return $query->where(function ($q) use ($user, $workspaceIds) {
            // User-owned namespaces
            $q->where(function ($q2) use ($user) {
                $q2->where('owner_type', User::class)
                    ->where('owner_id', $user->id);
            });

            // Workspace-owned namespaces (where user is a member)
            if ($workspaceIds->isNotEmpty()) {
                $q->orWhere(function ($q2) use ($workspaceIds) {
                    $q2->where('owner_type', Workspace::class)
                        ->whereIn('owner_id', $workspaceIds);
                });
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if a user has access to this namespace.
     */
    public function isAccessibleBy(User $user): bool
    {
        // User owns the namespace directly
        if ($this->isOwnedByUser() && $this->owner_id === $user->id) {
            return true;
        }

        // Workspace owns the namespace and user is a member
        if ($this->isOwnedByWorkspace()) {
            return $user->workspaces()->where('workspaces.id', $this->owner_id)->exists();
        }

        return false;
    }

    /**
     * Get the billing context for this namespace.
     *
     * Returns workspace if set, otherwise falls back to owner's default workspace.
     */
    public function getBillingContext(): ?Workspace
    {
        // Explicit workspace set for billing
        if ($this->workspace_id) {
            return $this->workspace;
        }

        // Workspace-owned: use the owner workspace
        if ($this->isOwnedByWorkspace()) {
            return $this->owner;
        }

        // User-owned: fall back to user's default workspace
        if ($this->isOwnedByUser() && $this->owner) {
            return $this->owner->defaultHostWorkspace();
        }

        return null;
    }

    /**
     * Get the route key name for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

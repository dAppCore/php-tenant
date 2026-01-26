<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Scopes;

use Core\Mod\Tenant\Exceptions\MissingWorkspaceContextException;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope for workspace tenancy.
 *
 * SECURITY: This scope enforces workspace isolation by:
 * 1. Automatically filtering queries to the current workspace context
 * 2. Throwing an exception when workspace context is missing (prevents silent data leaks)
 *
 * Can be disabled per-query using withoutGlobalScope() when intentionally
 * querying across workspaces (e.g., admin operations, CLI commands).
 *
 * To opt-out a model from strict enforcement, set $workspaceScopeStrict = false
 * on the model class.
 */
class WorkspaceScope implements Scope
{
    /**
     * Whether strict mode is enabled globally.
     * When true, throws exception if no workspace context is available.
     * Can be disabled for testing or CLI commands.
     */
    protected static bool $strictModeEnabled = true;

    /**
     * Enable strict mode (throws on missing context).
     */
    public static function enableStrictMode(): void
    {
        self::$strictModeEnabled = true;
    }

    /**
     * Disable strict mode (silently returns empty results).
     * Use with caution - primarily for testing or admin contexts.
     */
    public static function disableStrictMode(): void
    {
        self::$strictModeEnabled = false;
    }

    /**
     * Check if strict mode is enabled.
     */
    public static function isStrictModeEnabled(): bool
    {
        return self::$strictModeEnabled;
    }

    /**
     * Run a callback with strict mode disabled.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutStrictMode(callable $callback): mixed
    {
        $wasStrict = self::$strictModeEnabled;
        self::$strictModeEnabled = false;

        try {
            return $callback();
        } finally {
            self::$strictModeEnabled = $wasStrict;
        }
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @throws MissingWorkspaceContextException When no workspace context is available in strict mode
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply if workspace_id column exists on the model
        if (! $this->hasWorkspaceColumn($model)) {
            return;
        }

        // Get current workspace (returns Workspace model instance)
        $workspace = Workspace::current();

        if ($workspace) {
            $builder->where($model->getTable().'.workspace_id', $workspace->id);

            return;
        }

        // No workspace context available
        if ($this->shouldEnforceStrictMode($model)) {
            throw MissingWorkspaceContextException::forScope(
                class_basename($model)
            );
        }

        // Non-strict mode: return empty result set (fail safe)
        $builder->whereRaw('1 = 0');
    }

    /**
     * Check if the model has a workspace_id column.
     */
    protected function hasWorkspaceColumn(Model $model): bool
    {
        $fillable = $model->getFillable();
        $guarded = $model->getGuarded();

        // Check if workspace_id is in fillable or not in guarded
        return in_array('workspace_id', $fillable, true)
            || (count($guarded) === 1 && $guarded[0] === '*')
            || ! in_array('workspace_id', $guarded, true);
    }

    /**
     * Determine if strict mode should be enforced for a model.
     */
    protected function shouldEnforceStrictMode(Model $model): bool
    {
        // Check global strict mode setting
        if (! self::$strictModeEnabled) {
            return false;
        }

        // Check if model has opted out of strict mode
        if (property_exists($model, 'workspaceScopeStrict') && $model->workspaceScopeStrict === false) {
            return false;
        }

        // Check if running from console (CLI commands may need to work without context)
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        return true;
    }

    /**
     * Extend the query builder with workspace-specific methods.
     */
    public function extend(Builder $builder): void
    {
        // Add method to set workspace context for a query
        $builder->macro('forWorkspace', function (Builder $builder, Workspace|int $workspace) {
            $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

            return $builder->withoutGlobalScope(WorkspaceScope::class)
                ->where($builder->getModel()->getTable().'.workspace_id', $workspaceId);
        });

        // Add method to query across all workspaces (use with caution)
        $builder->macro('acrossWorkspaces', function (Builder $builder) {
            return $builder->withoutGlobalScope(WorkspaceScope::class);
        });

        // Add method to get current workspace for a query
        $builder->macro('currentWorkspaceId', function (Builder $builder) {
            $workspace = Workspace::current();

            return $workspace?->id;
        });
    }
}

<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntitlementLog extends Model
{
    use HasFactory;

    protected $table = 'entitlement_logs';

    protected $fillable = [
        'workspace_id',
        'namespace_id',
        'action',
        'entity_type',
        'entity_id',
        'user_id',
        'source',
        'old_values',
        'new_values',
        'metadata',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Action constants.
     */
    public const ACTION_PACKAGE_PROVISIONED = 'package.provisioned';

    public const ACTION_PACKAGE_SUSPENDED = 'package.suspended';

    public const ACTION_PACKAGE_CANCELLED = 'package.cancelled';

    public const ACTION_PACKAGE_REACTIVATED = 'package.reactivated';

    public const ACTION_PACKAGE_RENEWED = 'package.renewed';

    public const ACTION_PACKAGE_EXPIRED = 'package.expired';

    public const ACTION_BOOST_PROVISIONED = 'boost.provisioned';

    public const ACTION_BOOST_CONSUMED = 'boost.consumed';

    public const ACTION_BOOST_EXHAUSTED = 'boost.exhausted';

    public const ACTION_BOOST_EXPIRED = 'boost.expired';

    public const ACTION_BOOST_CANCELLED = 'boost.cancelled';

    public const ACTION_USAGE_RECORDED = 'usage.recorded';

    public const ACTION_USAGE_DENIED = 'usage.denied';

    public const ACTION_CYCLE_RESET = 'cycle.reset';

    /**
     * Source constants.
     */
    public const SOURCE_BLESTA = 'blesta';

    public const SOURCE_COMMERCE = 'commerce';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_API = 'api';

    /**
     * The workspace this log belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The namespace this log belongs to.
     */
    public function namespace(): BelongsTo
    {
        return $this->belongsTo(Namespace_::class, 'namespace_id');
    }

    /**
     * The user who triggered this action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to a specific action.
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to a specific entity.
     */
    public function scopeForEntity($query, string $entityType, ?int $entityId = null)
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    /**
     * Scope to a specific source.
     */
    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Create a log entry for a package action.
     */
    public static function logPackageAction(
        Workspace $workspace,
        string $action,
        WorkspacePackage $workspacePackage,
        ?User $user = null,
        ?string $source = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'workspace_id' => $workspace->id,
            'action' => $action,
            'entity_type' => WorkspacePackage::class,
            'entity_id' => $workspacePackage->id,
            'user_id' => $user?->id,
            'source' => $source,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a log entry for a boost action.
     */
    public static function logBoostAction(
        Workspace $workspace,
        string $action,
        Boost $boost,
        ?User $user = null,
        ?string $source = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'workspace_id' => $workspace->id,
            'action' => $action,
            'entity_type' => Boost::class,
            'entity_id' => $boost->id,
            'user_id' => $user?->id,
            'source' => $source,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a log entry for a usage action.
     */
    public static function logUsageAction(
        Workspace $workspace,
        string $action,
        string $featureCode,
        ?User $user = null,
        ?string $source = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'workspace_id' => $workspace->id,
            'action' => $action,
            'entity_type' => 'feature',
            'entity_id' => null,
            'user_id' => $user?->id,
            'source' => $source,
            'old_values' => null,
            'new_values' => ['feature_code' => $featureCode],
            'metadata' => $metadata,
        ]);
    }
}

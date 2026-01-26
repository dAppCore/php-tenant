<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Core\Mod\Tenant\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Workspace Team - defines permissions for members within a workspace.
 *
 * Teams provide role-based access control at the workspace level. Members
 * can belong to a team and optionally have custom permission overrides.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array|null $permissions
 * @property bool $is_default
 * @property bool $is_system
 * @property string $colour
 * @property int $sort_order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WorkspaceTeam extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $table = 'workspace_teams';

    // ─────────────────────────────────────────────────────────────────────────
    // Team Constants
    // ─────────────────────────────────────────────────────────────────────────

    public const TEAM_OWNER = 'owner';

    public const TEAM_ADMIN = 'admin';

    public const TEAM_MEMBER = 'member';

    public const TEAM_VIEWER = 'viewer';

    // ─────────────────────────────────────────────────────────────────────────
    // Permission Constants - Workspace
    // ─────────────────────────────────────────────────────────────────────────

    public const PERM_WORKSPACE_SETTINGS = 'workspace.manage_settings';

    public const PERM_WORKSPACE_MEMBERS = 'workspace.manage_members';

    public const PERM_WORKSPACE_BILLING = 'workspace.manage_billing';

    public const PERM_WORKSPACE_TEAMS = 'workspace.manage_teams';

    public const PERM_WORKSPACE_DELETE = 'workspace.delete';

    // ─────────────────────────────────────────────────────────────────────────
    // Permission Constants - Products (generic pattern: [product].read/write/admin)
    // ─────────────────────────────────────────────────────────────────────────

    // Bio service
    public const PERM_BIO_READ = 'bio.read';

    public const PERM_BIO_WRITE = 'bio.write';

    public const PERM_BIO_ADMIN = 'bio.admin';

    // Social service
    public const PERM_SOCIAL_READ = 'social.read';

    public const PERM_SOCIAL_WRITE = 'social.write';

    public const PERM_SOCIAL_ADMIN = 'social.admin';

    // Analytics service
    public const PERM_ANALYTICS_READ = 'analytics.read';

    public const PERM_ANALYTICS_WRITE = 'analytics.write';

    public const PERM_ANALYTICS_ADMIN = 'analytics.admin';

    // Trust service
    public const PERM_TRUST_READ = 'trust.read';

    public const PERM_TRUST_WRITE = 'trust.write';

    public const PERM_TRUST_ADMIN = 'trust.admin';

    // Notify service
    public const PERM_NOTIFY_READ = 'notify.read';

    public const PERM_NOTIFY_WRITE = 'notify.write';

    public const PERM_NOTIFY_ADMIN = 'notify.admin';

    // Support service
    public const PERM_SUPPORT_READ = 'support.read';

    public const PERM_SUPPORT_WRITE = 'support.write';

    public const PERM_SUPPORT_ADMIN = 'support.admin';

    // Commerce/billing
    public const PERM_COMMERCE_READ = 'commerce.read';

    public const PERM_COMMERCE_WRITE = 'commerce.write';

    public const PERM_COMMERCE_ADMIN = 'commerce.admin';

    // API management
    public const PERM_API_READ = 'api.read';

    public const PERM_API_WRITE = 'api.write';

    public const PERM_API_ADMIN = 'api.admin';

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_default',
        'is_system',
        'colour',
        'sort_order',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $team) {
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get members assigned to this team via the pivot.
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class, 'team_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scope to default teams only.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to system teams only.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to custom (non-system) teams only.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Permission Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if this team has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        // Check for exact match
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Check for wildcard permissions (e.g., 'bio.*' matches 'bio.read')
        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -1); // Remove the '*'
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if this team has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this team has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Grant a permission to this team.
     */
    public function grantPermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];

        if (! in_array($permission, $permissions, true)) {
            $permissions[] = $permission;
            $this->update(['permissions' => $permissions]);
        }

        return $this;
    }

    /**
     * Revoke a permission from this team.
     */
    public function revokePermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_values(array_filter($permissions, fn ($p) => $p !== $permission));

        $this->update(['permissions' => $permissions]);

        return $this;
    }

    /**
     * Set all permissions for this team.
     */
    public function setPermissions(array $permissions): self
    {
        $this->update(['permissions' => $permissions]);

        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Static Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all available permissions grouped by category.
     */
    public static function getAvailablePermissions(): array
    {
        return [
            'workspace' => [
                'label' => 'Workspace',
                'permissions' => [
                    self::PERM_WORKSPACE_SETTINGS => 'Manage settings',
                    self::PERM_WORKSPACE_MEMBERS => 'Manage members',
                    self::PERM_WORKSPACE_TEAMS => 'Manage teams',
                    self::PERM_WORKSPACE_BILLING => 'Manage billing',
                    self::PERM_WORKSPACE_DELETE => 'Delete workspace',
                ],
            ],
            'bio' => [
                'label' => 'BioHost',
                'permissions' => [
                    self::PERM_BIO_READ => 'View pages',
                    self::PERM_BIO_WRITE => 'Create and edit pages',
                    self::PERM_BIO_ADMIN => 'Full access',
                ],
            ],
            'social' => [
                'label' => 'SocialHost',
                'permissions' => [
                    self::PERM_SOCIAL_READ => 'View posts and accounts',
                    self::PERM_SOCIAL_WRITE => 'Create and edit posts',
                    self::PERM_SOCIAL_ADMIN => 'Full access',
                ],
            ],
            'analytics' => [
                'label' => 'AnalyticsHost',
                'permissions' => [
                    self::PERM_ANALYTICS_READ => 'View analytics',
                    self::PERM_ANALYTICS_WRITE => 'Configure tracking',
                    self::PERM_ANALYTICS_ADMIN => 'Full access',
                ],
            ],
            'trust' => [
                'label' => 'TrustHost',
                'permissions' => [
                    self::PERM_TRUST_READ => 'View campaigns',
                    self::PERM_TRUST_WRITE => 'Create and edit campaigns',
                    self::PERM_TRUST_ADMIN => 'Full access',
                ],
            ],
            'notify' => [
                'label' => 'NotifyHost',
                'permissions' => [
                    self::PERM_NOTIFY_READ => 'View notifications',
                    self::PERM_NOTIFY_WRITE => 'Send notifications',
                    self::PERM_NOTIFY_ADMIN => 'Full access',
                ],
            ],
            'support' => [
                'label' => 'SupportHost',
                'permissions' => [
                    self::PERM_SUPPORT_READ => 'View conversations',
                    self::PERM_SUPPORT_WRITE => 'Reply to conversations',
                    self::PERM_SUPPORT_ADMIN => 'Full access',
                ],
            ],
            'commerce' => [
                'label' => 'Commerce',
                'permissions' => [
                    self::PERM_COMMERCE_READ => 'View orders and invoices',
                    self::PERM_COMMERCE_WRITE => 'Manage orders',
                    self::PERM_COMMERCE_ADMIN => 'Full access',
                ],
            ],
            'api' => [
                'label' => 'API',
                'permissions' => [
                    self::PERM_API_READ => 'View API keys',
                    self::PERM_API_WRITE => 'Create API keys',
                    self::PERM_API_ADMIN => 'Full access',
                ],
            ],
        ];
    }

    /**
     * Get flat list of all permission keys.
     */
    public static function getAllPermissionKeys(): array
    {
        $keys = [];
        foreach (self::getAvailablePermissions() as $group) {
            $keys = array_merge($keys, array_keys($group['permissions']));
        }

        return $keys;
    }

    /**
     * Get default permissions for a given team type.
     */
    public static function getDefaultPermissionsFor(string $teamSlug): array
    {
        return match ($teamSlug) {
            self::TEAM_OWNER => self::getAllPermissionKeys(), // Owner gets all permissions
            self::TEAM_ADMIN => array_filter(
                self::getAllPermissionKeys(),
                fn ($p) => ! in_array($p, [
                    self::PERM_WORKSPACE_DELETE,
                    self::PERM_WORKSPACE_BILLING,
                ], true)
            ),
            self::TEAM_MEMBER => [
                self::PERM_BIO_READ,
                self::PERM_BIO_WRITE,
                self::PERM_SOCIAL_READ,
                self::PERM_SOCIAL_WRITE,
                self::PERM_ANALYTICS_READ,
                self::PERM_TRUST_READ,
                self::PERM_TRUST_WRITE,
                self::PERM_NOTIFY_READ,
                self::PERM_NOTIFY_WRITE,
                self::PERM_SUPPORT_READ,
                self::PERM_SUPPORT_WRITE,
                self::PERM_COMMERCE_READ,
                self::PERM_API_READ,
            ],
            self::TEAM_VIEWER => [
                self::PERM_BIO_READ,
                self::PERM_SOCIAL_READ,
                self::PERM_ANALYTICS_READ,
                self::PERM_TRUST_READ,
                self::PERM_NOTIFY_READ,
                self::PERM_SUPPORT_READ,
                self::PERM_COMMERCE_READ,
                self::PERM_API_READ,
            ],
            default => [],
        };
    }

    /**
     * Get the default team definitions for seeding.
     */
    public static function getDefaultTeamDefinitions(): array
    {
        return [
            [
                'name' => 'Owner',
                'slug' => self::TEAM_OWNER,
                'description' => 'Full ownership access to the workspace.',
                'permissions' => self::getDefaultPermissionsFor(self::TEAM_OWNER),
                'is_system' => true,
                'colour' => 'violet',
                'sort_order' => 1,
            ],
            [
                'name' => 'Admin',
                'slug' => self::TEAM_ADMIN,
                'description' => 'Administrative access without billing or deletion rights.',
                'permissions' => self::getDefaultPermissionsFor(self::TEAM_ADMIN),
                'is_system' => true,
                'colour' => 'blue',
                'sort_order' => 2,
            ],
            [
                'name' => 'Member',
                'slug' => self::TEAM_MEMBER,
                'description' => 'Standard member access to create and edit content.',
                'permissions' => self::getDefaultPermissionsFor(self::TEAM_MEMBER),
                'is_system' => true,
                'is_default' => true,
                'colour' => 'emerald',
                'sort_order' => 3,
            ],
            [
                'name' => 'Viewer',
                'slug' => self::TEAM_VIEWER,
                'description' => 'Read-only access to view content.',
                'permissions' => self::getDefaultPermissionsFor(self::TEAM_VIEWER),
                'is_system' => true,
                'colour' => 'zinc',
                'sort_order' => 4,
            ],
        ];
    }

    /**
     * Get available colour options for teams.
     */
    public static function getColourOptions(): array
    {
        return [
            'zinc' => 'Grey',
            'red' => 'Red',
            'orange' => 'Orange',
            'amber' => 'Amber',
            'yellow' => 'Yellow',
            'lime' => 'Lime',
            'green' => 'Green',
            'emerald' => 'Emerald',
            'teal' => 'Teal',
            'cyan' => 'Cyan',
            'sky' => 'Sky',
            'blue' => 'Blue',
            'indigo' => 'Indigo',
            'violet' => 'Violet',
            'purple' => 'Purple',
            'fuchsia' => 'Fuchsia',
            'pink' => 'Pink',
            'rose' => 'Rose',
        ];
    }
}

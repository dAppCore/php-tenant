<?php

declare(strict_types=1);

namespace Core\Tenant\Models;

use App\Services\WorkspaceService;
use Core\Mod\Analytics\Models\AnalyticsGoal;
use Core\Mod\Analytics\Models\AnalyticsWebsite;
use Core\Mod\Analytics\Models\Goal;
use Core\Mod\Analytics\Models\Website;
use Core\Mod\Api\Models\ApiKey;
use Core\Mod\Api\Models\WebhookEndpoint;
use Core\Mod\Content\Models\ContentAuthor;
use Core\Mod\Content\Models\ContentItem;
use Core\Mod\Notify\Models\PushCampaign;
use Core\Mod\Notify\Models\PushFlow;
use Core\Mod\Notify\Models\PushSegment;
use Core\Mod\Notify\Models\PushWebsite;
use Core\Mod\Social\Models\Account;
use Core\Mod\Social\Models\Analytics;
use Core\Mod\Social\Models\Audience;
use Core\Mod\Social\Models\FacebookInsight;
use Core\Mod\Social\Models\HashtagGroup;
use Core\Mod\Social\Models\ImportedPost;
use Core\Mod\Social\Models\InstagramInsight;
use Core\Mod\Social\Models\Media;
use Core\Mod\Social\Models\Metric;
use Core\Mod\Social\Models\PinterestAnalytic;
use Core\Mod\Social\Models\Post;
use Core\Mod\Social\Models\PostingSchedule;
use Core\Mod\Social\Models\Template;
use Core\Mod\Social\Models\Variable;
use Core\Mod\Social\Models\Webhook;
use Core\Mod\Trees\Models\TreePlanting;
use Core\Mod\Trust\Models\Campaign;
use Core\Mod\Trust\Models\Notification;
use Core\Tenant\Database\Factories\WorkspaceFactory;
use Core\Tenant\Notifications\WorkspaceInvitationNotification;
use Core\Tenant\Services\EntitlementResult;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Mod\Commerce\Models\Invoice;
use Mod\Commerce\Models\Order;
use Mod\Commerce\Models\PaymentMethod;
use Mod\Commerce\Models\Subscription;

class Workspace extends Model
{
    use HasFactory;

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'icon',
        'color',
        'description',
        'type',
        'settings',
        'is_active',
        'sort_order',
        // WP Connector fields (secret excluded for security)
        'wp_connector_enabled',
        'wp_connector_url',
        'wp_connector_verified_at',
        'wp_connector_last_sync',
        'wp_connector_config',
        // Billing fields
        'stripe_customer_id',
        'btcpay_customer_id',
        'billing_name',
        'billing_email',
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country',
        'vat_number',
        'tax_id',
        'tax_exempt',
    ];

    /**
     * Guarded attributes (sensitive data that should not be mass-assigned).
     */
    protected $guarded = [
        'wp_connector_secret',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'wp_connector_enabled' => 'boolean',
        'wp_connector_verified_at' => 'datetime',
        'wp_connector_last_sync' => 'datetime',
        'wp_connector_config' => 'array',
        'tax_exempt' => 'boolean',
    ];

    /**
     * Hidden attributes (sensitive data).
     */
    protected $hidden = [
        'wp_connector_secret',
    ];

    /**
     * Get the users that have access to this workspace.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_workspace')
            ->withPivot(['role', 'is_default', 'team_id', 'custom_permissions', 'joined_at', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Get workspace members (via the enhanced pivot model).
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * Get teams defined for this workspace.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(WorkspaceTeam::class);
    }

    /**
     * Get the workspace owner (user with 'owner' role).
     */
    public function owner(): ?User
    {
        return $this->users()
            ->wherePivot('role', 'owner')
            ->first();
    }

    /**
     * Get the default team for new members.
     */
    public function defaultTeam(): ?WorkspaceTeam
    {
        return $this->teams()->where('is_default', true)->first();
    }

    /**
     * Active package assignments for this workspace.
     */
    public function workspacePackages(): HasMany
    {
        return $this->hasMany(WorkspacePackage::class);
    }

    /**
     * Get pending invitations for this workspace.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * Get pending invitations only.
     */
    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->pending();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Namespace Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all namespaces owned by this workspace.
     */
    public function namespaces(): MorphMany
    {
        return $this->morphMany(Namespace_::class, 'owner');
    }

    /**
     * Get the workspace's default namespace.
     */
    public function defaultNamespace(): ?Namespace_
    {
        return $this->namespaces()
            ->where('is_default', true)
            ->active()
            ->first()
            ?? $this->namespaces()->active()->ordered()->first();
    }

    /**
     * The package definitions assigned to this workspace.
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'entitlement_workspace_packages', 'workspace_id', 'package_id')
            ->withPivot(['status', 'starts_at', 'expires_at', 'metadata'])
            ->withTimestamps();
    }

    /**
     * Get a setting from the settings JSON column.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Active boosts for this workspace.
     */
    public function boosts(): HasMany
    {
        return $this->hasMany(Boost::class);
    }

    /**
     * Usage records for this workspace.
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /**
     * Entitlement logs for this workspace.
     */
    public function entitlementLogs(): HasMany
    {
        return $this->hasMany(EntitlementLog::class);
    }

    /**
     * Usage alert history for this workspace.
     */
    public function usageAlerts(): HasMany
    {
        return $this->hasMany(UsageAlertHistory::class);
    }

    /**
     * Get active (unresolved) usage alerts for this workspace.
     */
    public function activeUsageAlerts(): HasMany
    {
        return $this->usageAlerts()->whereNull('resolved_at');
    }

    // SocialHost Relationships (Native)

    /**
     * Get social accounts for this workspace.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Get social posts for this workspace.
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get social media templates for this workspace.
     */
    public function socialTemplates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Get social media files for this workspace.
     */
    public function socialMedia(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * Get social hashtag groups for this workspace.
     */
    public function socialHashtagGroups(): HasMany
    {
        return $this->hasMany(HashtagGroup::class);
    }

    /**
     * Get social webhooks for this workspace.
     */
    public function socialWebhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * Get social analytics for this workspace.
     */
    public function socialAnalytics(): HasMany
    {
        return $this->hasMany(Analytics::class);
    }

    /**
     * Get social variables for this workspace.
     */
    public function socialVariables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * Get posting schedule for this workspace.
     */
    public function socialPostingSchedule(): HasMany
    {
        return $this->hasMany(PostingSchedule::class);
    }

    /**
     * Get imported posts for this workspace.
     */
    public function socialImportedPosts(): HasMany
    {
        return $this->hasMany(ImportedPost::class);
    }

    /**
     * Get social metrics for this workspace.
     */
    public function socialMetrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    /**
     * Get audience data for this workspace.
     */
    public function socialAudience(): HasMany
    {
        return $this->hasMany(Audience::class);
    }

    /**
     * Get Facebook insights for this workspace.
     */
    public function socialFacebookInsights(): HasMany
    {
        return $this->hasMany(FacebookInsight::class);
    }

    /**
     * Get Instagram insights for this workspace.
     */
    public function socialInstagramInsights(): HasMany
    {
        return $this->hasMany(InstagramInsight::class);
    }

    /**
     * Get Pinterest analytics for this workspace.
     */
    public function socialPinterestAnalytics(): HasMany
    {
        return $this->hasMany(PinterestAnalytic::class);
    }

    /**
     * Check if this workspace has SocialHost enabled (has connected social accounts).
     */
    public function hasSocialHost(): bool
    {
        return $this->socialAccounts()->exists();
    }

    /**
     * Get count of connected social accounts.
     */
    public function socialAccountsCount(): int
    {
        return $this->socialAccounts()->count();
    }

    // NOTE: Bio service relationships (bioPages, bioProjects, bioDomains, bioPixels)
    // have been moved to the Host UK app's Mod\Bio module.

    // AnalyticsHost Relationships

    /**
     * Get analytics websites for this workspace (AnalyticsHost).
     */
    public function analyticsSites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /**
     * Get social analytics websites for this workspace (legacy, for SocialHost analytics).
     */
    public function socialAnalyticsWebsites(): HasMany
    {
        return $this->hasMany(AnalyticsWebsite::class);
    }

    /**
     * Get analytics goals for this workspace (AnalyticsHost).
     */
    public function analyticsGoals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * Get social analytics goals for this workspace (legacy, for SocialHost analytics).
     */
    public function socialAnalyticsGoals(): HasMany
    {
        return $this->hasMany(AnalyticsGoal::class);
    }

    // TrustHost Relationships

    /**
     * Get social proof campaigns (TrustHost widgets) for this workspace.
     */
    public function trustWidgets(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Get social proof notifications for this workspace.
     */
    public function trustNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // NotifyHost Relationships

    /**
     * Get push notification websites for this workspace.
     */
    public function notificationSites(): HasMany
    {
        return $this->hasMany(PushWebsite::class);
    }

    /**
     * Get push campaigns for this workspace.
     */
    public function pushCampaigns(): HasMany
    {
        return $this->hasMany(PushCampaign::class);
    }

    /**
     * Get push flows for this workspace.
     */
    public function pushFlows(): HasMany
    {
        return $this->hasMany(PushFlow::class);
    }

    /**
     * Get push segments for this workspace.
     */
    public function pushSegments(): HasMany
    {
        return $this->hasMany(PushSegment::class);
    }

    // API & Webhooks Relationships

    /**
     * Get API keys for this workspace.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Get webhook endpoints for this workspace.
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * Get entitlement webhooks for this workspace.
     */
    public function entitlementWebhooks(): HasMany
    {
        return $this->hasMany(EntitlementWebhook::class);
    }

    // Trees for Agents Relationships

    /**
     * Get tree plantings for this workspace.
     */
    public function treePlantings(): HasMany
    {
        return $this->hasMany(TreePlanting::class);
    }

    /**
     * Get total trees planted for this workspace.
     */
    public function treesPlanted(): int
    {
        return $this->treePlantings()
            ->whereIn('status', ['confirmed', 'planted'])
            ->sum('trees');
    }

    /**
     * Get trees planted this year for this workspace.
     */
    public function treesThisYear(): int
    {
        return $this->treePlantings()
            ->whereIn('status', ['confirmed', 'planted'])
            ->whereYear('created_at', now()->year)
            ->sum('trees');
    }

    // Content & Media Relationships

    /**
     * Get content items for this workspace.
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * Get content authors for this workspace.
     */
    public function contentAuthors(): HasMany
    {
        return $this->hasMany(ContentAuthor::class);
    }

    // Commerce Relationships (defined in app Mod\Commerce)

    /**
     * Get subscriptions for this workspace.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get invoices for this workspace.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get payment methods for this workspace.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * Get orders for this workspace.
     */
    public function orders(): MorphMany
    {
        return $this->morphMany(Order::class, 'orderable');
    }

    // Helper Methods

    /**
     * Get the currently active workspace from request context.
     *
     * Returns the Workspace model instance (not array).
     */
    public static function current(): ?self
    {
        // Try to get from request attributes (set by middleware)
        if (request()->attributes->has('workspace_model')) {
            return request()->attributes->get('workspace_model');
        }

        // Try to get from authenticated user's default workspace
        if (auth()->check() && auth()->user() instanceof User) {
            return auth()->user()->defaultHostWorkspace();
        }

        // Try to resolve from subdomain via WorkspaceService
        $workspaceService = app(WorkspaceService::class);
        $slug = $workspaceService->currentSlug();

        return static::where('slug', $slug)->first();
    }

    /**
     * Check if workspace can use a feature.
     */
    public function can(string $featureCode, int $quantity = 1): EntitlementResult
    {
        return app(EntitlementService::class)->can($this, $featureCode, $quantity);
    }

    /**
     * Record usage of a feature.
     */
    public function recordUsage(string $featureCode, int $quantity = 1, ?User $user = null, ?array $metadata = null): UsageRecord
    {
        return app(EntitlementService::class)->recordUsage($this, $featureCode, $quantity, $user, $metadata);
    }

    /**
     * Get usage summary for all features.
     */
    public function getUsageSummary(): Collection
    {
        return app(EntitlementService::class)->getUsageSummary($this);
    }

    /**
     * Check if workspace has a specific package.
     */
    public function hasPackage(string $packageCode): bool
    {
        return $this->workspacePackages()
            ->whereHas('package', fn ($q) => $q->where('code', $packageCode))
            ->active()
            ->exists();
    }

    /**
     * Check if workspace has Apollo tier.
     */
    public function isApollo(): bool
    {
        return $this->can('tier.apollo')->isAllowed();
    }

    /**
     * Check if workspace has Hades tier.
     */
    public function isHades(): bool
    {
        return $this->can('tier.hades')->isAllowed();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Workspace Invitations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Invite a user to this workspace by email.
     *
     * @param  string  $email  The email address to invite
     * @param  string  $role  The role to assign (owner, admin, member)
     * @param  User|null  $invitedBy  The user sending the invitation
     * @param  int  $expiresInDays  Number of days until invitation expires
     */
    public function invite(string $email, string $role = 'member', ?User $invitedBy = null, int $expiresInDays = 7): WorkspaceInvitation
    {
        // Check if there's already a pending invitation for this email
        $existing = $this->invitations()
            ->where('email', $email)
            ->pending()
            ->first();

        if ($existing) {
            // Update existing invitation (keep existing hashed token)
            $existing->update([
                'role' => $role,
                'invited_by' => $invitedBy?->id,
                'expires_at' => now()->addDays($expiresInDays),
            ]);

            // For re-sends, we need to generate a new token since we can't retrieve the old plaintext
            $plaintextToken = WorkspaceInvitation::generateToken();
            $existing->token = $plaintextToken;
            $existing->save();

            // Send notification with the new plaintext token
            $existing->notify(new WorkspaceInvitationNotification($existing, $plaintextToken));

            return $existing;
        }

        // Generate plaintext token (will be hashed on save via model boot)
        $plaintextToken = WorkspaceInvitation::generateToken();

        // Create new invitation (token gets hashed in the creating event)
        $invitation = $this->invitations()->create([
            'email' => $email,
            'token' => $plaintextToken,
            'role' => $role,
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        // Send notification with the plaintext token (not the hashed one)
        $invitation->notify(new WorkspaceInvitationNotification($invitation, $plaintextToken));

        return $invitation;
    }

    /**
     * Accept an invitation to this workspace using a token.
     *
     * @param  string  $token  The invitation token
     * @param  User  $user  The user accepting the invitation
     * @return bool True if accepted, false if invalid/expired
     */
    public static function acceptInvitation(string $token, User $user): bool
    {
        $invitation = WorkspaceInvitation::findPendingByToken($token);

        if (! $invitation) {
            return false;
        }

        return $invitation->accept($user);
    }

    /**
     * Get the external CMS URL for this workspace.
     */
    public function getCmsUrlAttribute(): string
    {
        return 'https://'.$this->domain;
    }

    /**
     * Scope to only active workspaces.
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
     * Convert to array format used by WorkspaceService.
     */
    public function toServiceArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
        ];
    }

    /**
     * Generate a new webhook secret for the WP connector.
     */
    public function generateWpConnectorSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->update(['wp_connector_secret' => $secret]);

        return $secret;
    }

    /**
     * Enable the WP connector with a URL.
     */
    public function enableWpConnector(string $url): self
    {
        $this->update([
            'wp_connector_enabled' => true,
            'wp_connector_url' => rtrim($url, '/'),
            'wp_connector_secret' => $this->wp_connector_secret ?? bin2hex(random_bytes(32)),
        ]);

        return $this;
    }

    /**
     * Disable the WP connector.
     */
    public function disableWpConnector(): self
    {
        $this->update([
            'wp_connector_enabled' => false,
            'wp_connector_verified_at' => null,
        ]);

        return $this;
    }

    /**
     * Mark the WP connector as verified.
     */
    public function markWpConnectorVerified(): self
    {
        $this->update(['wp_connector_verified_at' => now()]);

        return $this;
    }

    /**
     * Update the last sync timestamp.
     */
    public function touchWpConnectorSync(): self
    {
        $this->update(['wp_connector_last_sync' => now()]);

        return $this;
    }

    /**
     * Check if the WP connector is active and verified.
     */
    public function hasActiveWpConnector(): bool
    {
        return $this->wp_connector_enabled
            && ! empty($this->wp_connector_url)
            && ! empty($this->wp_connector_secret);
    }

    /**
     * Get the webhook URL that external CMS should POST to.
     */
    public function getWpConnectorWebhookUrlAttribute(): string
    {
        return route('api.webhook.content').'?workspace='.$this->slug;
    }

    /**
     * Validate an incoming webhook signature.
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->wp_connector_secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->wp_connector_secret);

        return hash_equals($expected, $signature);
    }
}

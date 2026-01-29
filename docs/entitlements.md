---
title: Entitlements
description: Guide to the entitlement system for feature access and usage limits
updated: 2026-01-29
---

# Entitlement System

The entitlement system controls feature access, usage limits, and billing integration for workspaces and namespaces.

## Quick Start

### Check Feature Access

```php
use Core\Tenant\Services\EntitlementService;

$entitlements = app(EntitlementService::class);

// Check if workspace can use a feature
$result = $entitlements->can($workspace, 'ai.credits', quantity: 5);

if ($result->isAllowed()) {
    // Perform action
    $entitlements->recordUsage($workspace, 'ai.credits', quantity: 5, user: $user);
} else {
    // Handle denial
    return response()->json([
        'error' => $result->reason,
        'limit' => $result->limit,
        'used' => $result->used,
    ], 403);
}
```

### Via Workspace Model

```php
$result = $workspace->can('social.accounts');

if ($result->isAllowed()) {
    $workspace->recordUsage('social.accounts');
}
```

## Concepts

### Features

Features are defined in the `entitlement_features` table:

| Field | Description |
|-------|-------------|
| `code` | Unique identifier (e.g., `ai.credits`, `social.accounts`) |
| `type` | `boolean`, `limit`, or `unlimited` |
| `reset_type` | `none`, `monthly`, or `rolling` |
| `rolling_window_days` | Days for rolling window |
| `parent_feature_id` | For hierarchical features (pool sharing) |

**Feature Types:**

| Type | Behaviour |
|------|-----------|
| `boolean` | Binary on/off access |
| `limit` | Numeric limit with usage tracking |
| `unlimited` | Feature enabled with no limits |

**Reset Types:**

| Type | Behaviour |
|------|-----------|
| `none` | Usage accumulates forever |
| `monthly` | Resets at billing cycle start |
| `rolling` | Rolling window (e.g., last 30 days) |

### Packages

Packages bundle features with specific limits:

```php
// Example package definition
$package = Package::create([
    'code' => 'creator',
    'name' => 'Creator Plan',
    'is_base_package' => true,
    'monthly_price' => 19.99,
]);

// Attach features
$package->features()->attach($aiCreditsFeature->id, ['limit_value' => 100]);
$package->features()->attach($socialAccountsFeature->id, ['limit_value' => 5]);
```

### Workspace Packages

Packages are provisioned to workspaces:

```php
$workspacePackage = $entitlements->provisionPackage($workspace, 'creator', [
    'source' => EntitlementLog::SOURCE_BLESTA,
    'billing_cycle_anchor' => now(),
    'blesta_service_id' => 'srv_12345',
]);
```

**Statuses:**
- `active` - Package is in use
- `suspended` - Temporarily disabled (e.g., payment failed)
- `cancelled` - Permanently ended
- `expired` - Past expiry date

### Boosts

Boosts provide temporary limit increases:

```php
$boost = $entitlements->provisionBoost($workspace, 'ai.credits', [
    'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
    'limit_value' => 50,
    'duration_type' => Boost::DURATION_CYCLE_BOUND,
]);
```

**Boost Types:**

| Type | Effect |
|------|--------|
| `add_limit` | Adds to package limit |
| `enable` | Enables boolean feature |
| `unlimited` | Makes feature unlimited |

**Duration Types:**

| Type | Expiry |
|------|--------|
| `cycle_bound` | Expires at billing cycle end |
| `duration` | Expires after set `expires_at` |
| `permanent` | Never expires |

## API Reference

### EntitlementService

#### can()

Check if a workspace can use a feature:

```php
public function can(
    Workspace $workspace,
    string $featureCode,
    int $quantity = 1
): EntitlementResult
```

**Returns `EntitlementResult` with:**
- `isAllowed(): bool`
- `isDenied(): bool`
- `isUnlimited(): bool`
- `limit: ?int`
- `used: int`
- `remaining: ?int`
- `reason: ?string`
- `featureCode: string`
- `getUsagePercentage(): ?float`
- `isNearLimit(): bool` (>80%)
- `isAtLimit(): bool` (100%)

#### canForNamespace()

Check entitlement for a namespace with cascade:

```php
public function canForNamespace(
    Namespace_ $namespace,
    string $featureCode,
    int $quantity = 1
): EntitlementResult
```

Cascade order:
1. Namespace-level packages
2. Workspace pool (if `namespace->workspace_id` set)
3. User tier (if namespace owned by user)

#### recordUsage()

Record feature usage:

```php
public function recordUsage(
    Workspace $workspace,
    string $featureCode,
    int $quantity = 1,
    ?User $user = null,
    ?array $metadata = null
): UsageRecord
```

#### provisionPackage()

Assign a package to a workspace:

```php
public function provisionPackage(
    Workspace $workspace,
    string $packageCode,
    array $options = []
): WorkspacePackage
```

**Options:**
- `source` - `system`, `blesta`, `admin`, `user`
- `billing_cycle_anchor` - Start of billing cycle
- `expires_at` - Package expiry date
- `blesta_service_id` - External billing reference
- `metadata` - Additional data

#### provisionBoost()

Add a temporary boost:

```php
public function provisionBoost(
    Workspace $workspace,
    string $featureCode,
    array $options = []
): Boost
```

**Options:**
- `boost_type` - `add_limit`, `enable`, `unlimited`
- `duration_type` - `cycle_bound`, `duration`, `permanent`
- `limit_value` - Amount to add (for `add_limit`)
- `expires_at` - Expiry date (for `duration`)

#### suspendWorkspace() / reactivateWorkspace()

Manage workspace package status:

```php
$entitlements->suspendWorkspace($workspace, 'blesta');
$entitlements->reactivateWorkspace($workspace, 'admin');
```

#### getUsageSummary()

Get all feature usage for a workspace:

```php
$summary = $entitlements->getUsageSummary($workspace);

// Returns Collection grouped by category:
// [
//   'ai' => [
//     ['code' => 'ai.credits', 'limit' => 100, 'used' => 50, ...],
//   ],
//   'social' => [
//     ['code' => 'social.accounts', 'limit' => 5, 'used' => 3, ...],
//   ],
// ]
```

## Namespace-Level Entitlements

For products that operate at namespace level:

```php
$result = $entitlements->canForNamespace($namespace, 'bio.pages');

if ($result->isAllowed()) {
    $entitlements->recordNamespaceUsage($namespace, 'bio.pages', user: $user);
}

// Provision namespace-specific package
$entitlements->provisionNamespacePackage($namespace, 'bio-pro');
```

## Usage Alerts

The `UsageAlertService` monitors usage and sends notifications:

```php
// Check single workspace
$alerts = app(UsageAlertService::class)->checkWorkspace($workspace);

// Check all workspaces (scheduled command)
php artisan tenant:check-usage-alerts
```

**Alert Thresholds:**
- 80% - Warning
- 90% - Critical
- 100% - Limit reached

**Notification Channels:**
- Email to workspace owner
- Webhook events (`limit_warning`, `limit_reached`)

## Billing Integration

### Blesta API

External endpoints for billing system integration:

```
POST /api/entitlements          - Provision package
POST /api/entitlements/{id}/suspend    - Suspend
POST /api/entitlements/{id}/unsuspend  - Reactivate
POST /api/entitlements/{id}/cancel     - Cancel
POST /api/entitlements/{id}/renew      - Renew
GET  /api/entitlements/{id}     - Get details
```

### Cross-App API

For other Host UK services to check entitlements:

```
GET  /api/entitlements/check    - Check feature access
POST /api/entitlements/usage    - Record usage
GET  /api/entitlements/summary  - Get usage summary
```

## Webhooks

Subscribe to entitlement events:

```php
$webhookService = app(EntitlementWebhookService::class);

$webhook = $webhookService->register($workspace,
    name: 'Usage Alerts',
    url: 'https://api.example.com/hooks/entitlements',
    events: ['limit_warning', 'limit_reached']
);
```

**Available Events:**
- `limit_warning` - 80%/90% threshold
- `limit_reached` - 100% threshold
- `package_changed` - Package add/change/remove
- `boost_activated` - New boost
- `boost_expired` - Boost expired

**Payload Format:**

```json
{
  "event": "limit_warning",
  "data": {
    "workspace_id": 123,
    "feature_code": "ai.credits",
    "threshold": 80,
    "used": 80,
    "limit": 100
  },
  "timestamp": "2026-01-29T12:00:00Z"
}
```

**Verification:**

```php
$isValid = $webhookService->verifySignature(
    $payload,
    $request->header('X-Signature'),
    $webhook->secret
);
```

## Best Practices

### Check Before Action

Always check entitlements before performing the action:

```php
// Bad: Check after action
$account = SocialAccount::create([...]);
if (!$workspace->can('social.accounts')->isAllowed()) {
    $account->delete();
    throw new \Exception('Limit exceeded');
}

// Good: Check before action
$result = $workspace->can('social.accounts');
if ($result->isDenied()) {
    throw new EntitlementException($result->reason);
}
$account = SocialAccount::create([...]);
$workspace->recordUsage('social.accounts');
```

### Use Transactions

For atomic check-and-record:

```php
DB::transaction(function () use ($workspace, $user) {
    $result = $workspace->can('ai.credits', 10);

    if ($result->isDenied()) {
        throw new EntitlementException($result->reason);
    }

    // Perform AI generation
    $output = $aiService->generate($prompt);

    // Record usage
    $workspace->recordUsage('ai.credits', 10, $user, [
        'model' => 'claude-3',
        'tokens' => 1500,
    ]);

    return $output;
});
```

### Cache Considerations

Entitlement checks are cached for 5 minutes. For real-time accuracy:

```php
// Force cache refresh
$entitlements->invalidateCache($workspace);
$result = $entitlements->can($workspace, 'feature');
```

### Feature Code Conventions

Use dot notation for feature codes:

```
service.feature
service.feature.subfeature
```

Examples:
- `ai.credits`
- `social.accounts`
- `social.posts.scheduled`
- `bio.pages`
- `analytics.websites`

### Hierarchical Features

For shared pools, use parent features:

```php
// Parent feature (pool)
$aiCredits = Feature::create([
    'code' => 'ai.credits',
    'type' => Feature::TYPE_LIMIT,
]);

// Child feature (uses parent pool)
$aiGeneration = Feature::create([
    'code' => 'ai.generation',
    'parent_feature_id' => $aiCredits->id,
]);

// Both check against ai.credits pool
$workspace->can('ai.generation'); // Uses ai.credits limit
```

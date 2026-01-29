---
title: Architecture
description: Technical architecture of the core-tenant multi-tenancy package
updated: 2026-01-29
---

# core-tenant Architecture

This document describes the technical architecture of the core-tenant package, which provides multi-tenancy, user management, and entitlement systems for the Host UK platform.

## Overview

core-tenant is the foundational tenancy layer that enables:

- **Workspaces** - The primary tenant boundary (organisations, teams)
- **Namespaces** - Product-level isolation within or across workspaces
- **Entitlements** - Feature access control, usage limits, and billing integration
- **User Management** - Authentication, 2FA, and workspace membership

## Core Concepts

### Tenant Hierarchy

```
User
├── owns Workspaces (can own multiple)
│   ├── has WorkspacePackages (entitlements)
│   ├── has Boosts (temporary limit increases)
│   ├── has Members (users with roles/permissions)
│   ├── has Teams (permission groups)
│   └── owns Namespaces (product boundaries)
└── owns Namespaces (personal, not workspace-linked)
```

### Workspace

The `Workspace` model is the primary tenant boundary. All tenant-scoped data references a workspace_id.

**Key Properties:**
- `slug` - URL-safe unique identifier
- `domain` - Optional custom domain
- `settings` - JSON configuration blob
- `stripe_customer_id` / `btcpay_customer_id` - Billing integration

**Relationships:**
- `users()` - Members via pivot table
- `workspacePackages()` - Active entitlement packages
- `boosts()` - Temporary limit increases
- `namespaces()` - Owned namespaces (polymorphic)

### Namespace

The `Namespace_` model provides a universal product boundary. Products belong to namespaces rather than directly to users/workspaces.

**Ownership Patterns:**
1. **User-owned**: Individual creator with personal namespace
2. **Workspace-owned**: Agency managing client namespaces
3. **User with workspace billing**: Personal namespace but billed to workspace

**Entitlement Cascade:**
1. Check namespace-level packages first
2. Fall back to workspace pool (if namespace has workspace_id)
3. Fall back to user tier (for user-owned namespaces)

### BelongsToWorkspace Trait

Models that are workspace-scoped should use the `BelongsToWorkspace` trait:

```php
class Account extends Model
{
    use BelongsToWorkspace;
}
```

**Security Features:**
- Auto-assigns `workspace_id` on create (or throws exception)
- Provides `ownedByCurrentWorkspace()` scope
- Auto-invalidates workspace cache on model changes

**Strict Mode:**
When `WorkspaceScope::isStrictModeEnabled()` is true:
- Creating models without workspace context throws `MissingWorkspaceContextException`
- Querying without context throws exception
- This prevents accidental cross-tenant data access

## Entitlement System

### Feature Types

Features (`entitlement_features` table) have three types:

| Type | Description | Example |
|------|-------------|---------|
| `boolean` | On/off access | Beta features |
| `limit` | Numeric limit with usage tracking | 100 AI credits/month |
| `unlimited` | No limit | Unlimited social accounts |

### Reset Types

| Type | Description |
|------|-------------|
| `none` | No reset (cumulative) |
| `monthly` | Resets at billing cycle start |
| `rolling` | Rolling window (e.g., last 30 days) |

### Package Model

Packages bundle features with specific limits:

```
Package (creator)
├── Feature: ai.credits (limit: 100)
├── Feature: social.accounts (limit: 5)
└── Feature: tier.apollo (boolean)
```

### Boost Model

Boosts provide temporary limit increases:

| Boost Type | Description |
|------------|-------------|
| `add_limit` | Adds to existing limit |
| `enable` | Enables a boolean feature |
| `unlimited` | Makes feature unlimited |

| Duration Type | Description |
|---------------|-------------|
| `cycle_bound` | Expires at billing cycle end |
| `duration` | Expires after set period |
| `permanent` | Never expires |

### Entitlement Check Flow

```
EntitlementService::can($workspace, 'ai.credits', quantity: 5)
│
├─> Get Feature by code
│   └─> Get pool feature code (for hierarchical features)
│
├─> Calculate total limit
│   ├─> Sum limits from active WorkspacePackages
│   └─> Add remaining limits from active Boosts
│
├─> Get current usage
│   ├─> Check reset type (monthly/rolling/none)
│   └─> Sum UsageRecords in window
│
└─> Return EntitlementResult
    ├─> allowed: bool
    ├─> limit: int|null
    ├─> used: int
    ├─> remaining: int|null
    └─> reason: string (if denied)
```

### Caching Strategy

Entitlement data is cached with 5-minute TTL:
- `entitlement:{workspace_id}:limit:{feature_code}`
- `entitlement:{workspace_id}:usage:{feature_code}`

Cache invalidation occurs on:
- Package provision/suspend/cancel
- Boost provision/expire
- Usage recording

## Service Layer

### WorkspaceManager

Manages workspace context and basic CRUD:

```php
$manager = app(WorkspaceManager::class);
$manager->setCurrent($workspace);     // Set context
$manager->loadBySlug('acme');         // Load by slug
$manager->create($user, $attrs);      // Create workspace
$manager->addUser($workspace, $user); // Add member
```

### EntitlementService

Core API for entitlement checks and management:

```php
$service = app(EntitlementService::class);

// Check feature access
$result = $service->can($workspace, 'ai.credits', quantity: 5);
if ($result->isAllowed()) {
    // Record usage after action
    $service->recordUsage($workspace, 'ai.credits', quantity: 5);
}

// Provision packages
$service->provisionPackage($workspace, 'creator', [
    'source' => 'blesta',
    'billing_cycle_anchor' => now(),
]);

// Suspend/reactivate
$service->suspendWorkspace($workspace);
$service->reactivateWorkspace($workspace);
```

### WorkspaceTeamService

Manages teams and permissions:

```php
$teamService = app(WorkspaceTeamService::class);
$teamService->forWorkspace($workspace);

// Check permissions
if ($teamService->hasPermission($user, 'social.write')) {
    // User can write social content
}

// Team management
$team = $teamService->createTeam([
    'name' => 'Content Creators',
    'permissions' => ['social.read', 'social.write'],
]);
$teamService->addMemberToTeam($user, $team);
```

### WorkspaceCacheManager

Workspace-scoped caching with tag support:

```php
$cache = app(WorkspaceCacheManager::class);

// Cache workspace data
$data = $cache->remember($workspace, 'expensive-query', 300, function () {
    return ExpensiveModel::forWorkspace($workspace)->get();
});

// Flush workspace cache
$cache->flush($workspace);
```

## Middleware

### RequireWorkspaceContext

Ensures workspace context before processing:

```php
Route::middleware('workspace.required')->group(function () {
    // Routes here require workspace context
});

// With user access validation
Route::middleware('workspace.required:validate')->group(function () {
    // Also validates user has access to workspace
});
```

Workspace resolved from (in order):
1. Request attribute `workspace_model`
2. `Workspace::current()` (session/auth)
3. Request input `workspace_id`
4. Header `X-Workspace-ID`
5. Query param `workspace`

### CheckWorkspacePermission

Checks user has specific permissions:

```php
Route::middleware('workspace.permission:social.write')->group(function () {
    // Requires social.write permission
});

// Multiple permissions (OR logic)
Route::middleware('workspace.permission:admin,owner')->group(function () {
    // Requires admin OR owner role
});
```

## Event System

### Lifecycle Events

The module uses event-driven lazy loading:

```php
class Boot extends ServiceProvider
{
    public static array $listens = [
        AdminPanelBooting::class => 'onAdminPanel',
        ApiRoutesRegistering::class => 'onApiRoutes',
        WebRoutesRegistering::class => 'onWebRoutes',
        ConsoleBooting::class => 'onConsole',
    ];
}
```

### Entitlement Webhooks

External systems can subscribe to entitlement events:

| Event | Trigger |
|-------|---------|
| `limit_warning` | Usage at 80% or 90% |
| `limit_reached` | Usage at 100% |
| `package_changed` | Package add/change/remove |
| `boost_activated` | Boost provisioned |
| `boost_expired` | Boost expired |

Webhooks include:
- HMAC-SHA256 signature verification
- Automatic retry with exponential backoff
- Circuit breaker after consecutive failures

## Two-Factor Authentication

### TotpService

RFC 6238 compliant TOTP implementation:

```php
$totp = app(TwoFactorAuthenticationProvider::class);

// Generate secret
$secret = $totp->generateSecretKey(); // 160-bit base32

// Generate QR code URL
$url = $totp->qrCodeUrl('AppName', $user->email, $secret);

// Verify code
if ($totp->verify($secret, $userCode)) {
    // Valid
}
```

### TwoFactorAuthenticatable Trait

Add to User model:

```php
class User extends Authenticatable
{
    use TwoFactorAuthenticatable;
}

// Enable 2FA
$secret = $user->enableTwoFactorAuth();
// User scans QR, enters code
if ($user->verifyTwoFactorCode($code)) {
    $recoveryCodes = $user->confirmTwoFactorAuth();
}

// Disable
$user->disableTwoFactorAuth();
```

## Database Schema

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts |
| `workspaces` | Tenant organisations |
| `user_workspace` | User-workspace pivot |
| `namespaces` | Product boundaries |

### Entitlement Tables

| Table | Purpose |
|-------|---------|
| `entitlement_features` | Feature definitions |
| `entitlement_packages` | Package definitions |
| `entitlement_package_features` | Package-feature pivot |
| `entitlement_workspace_packages` | Workspace package assignments |
| `entitlement_namespace_packages` | Namespace package assignments |
| `entitlement_boosts` | Active boosts |
| `entitlement_usage_records` | Usage tracking |
| `entitlement_logs` | Audit log |

### Team Tables

| Table | Purpose |
|-------|---------|
| `workspace_teams` | Team definitions |
| `workspace_invitations` | Pending invitations |

## Configuration

The package uses these config keys:

```php
// config/core.php
return [
    'workspace_cache' => [
        'enabled' => true,
        'ttl' => 300,
        'prefix' => 'workspace_cache',
        'use_tags' => true,
    ],
];
```

## Testing

Tests are in `tests/Feature/` using Pest:

```bash
composer test                              # All tests
vendor/bin/pest tests/Feature/EntitlementServiceTest.php  # Single file
vendor/bin/pest --filter="can method"     # Filter by name
```

Key test files:
- `EntitlementServiceTest.php` - Core entitlement logic
- `WorkspaceSecurityTest.php` - Tenant isolation
- `WorkspaceCacheTest.php` - Caching behaviour
- `TwoFactorAuthenticatableTest.php` - 2FA flows

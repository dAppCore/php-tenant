# Core Tenant

[![CI](https://github.com/lthn/php-tenant/actions/workflows/ci.yml/badge.svg)](https://github.com/lthn/php-tenant/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/lthn/php-tenant)](https://packagist.org/packages/lthn/php-tenant)
[![Laravel](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x-FF2D20?logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/License-EUPL--1.2-blue.svg)](LICENSE)

Multi-tenancy module for the Core PHP Framework providing users, workspaces, and entitlements.

## Features

- **Users & Authentication** - User management with 2FA support
- **Workspaces** - Multi-tenant workspace boundaries
- **Entitlements** - Feature access, packages, and usage tracking
- **Account Management** - User settings, account deletion
- **Referrals** - Referral system support
- **Usage Alerts** - Configurable usage threshold alerts

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- Core PHP Framework (`lthn/php`)

## Installation

```bash
composer require lthn/php-tenant
```

The service provider will be auto-discovered.

Run migrations:

```bash
php artisan migrate
```

## Usage

### Workspace Management

```php
use Core\Mod\Tenant\Services\WorkspaceManager;
use Core\Mod\Tenant\Services\WorkspaceService;

// Get current workspace
$workspace = app(WorkspaceManager::class)->current();

// Create a new workspace
$workspace = app(WorkspaceService::class)->create([
    'name' => 'My Workspace',
    'owner_id' => $user->id,
]);
```

### Entitlements

```php
use Core\Mod\Tenant\Services\EntitlementService;

$entitlements = app(EntitlementService::class);

// Check if workspace has access to a feature
if ($entitlements->hasAccess($workspace, 'premium_feature')) {
    // Feature is enabled
}

// Check usage limits
$usage = $entitlements->getUsage($workspace, 'api_calls');
```

### Middleware

The module provides middleware for workspace-based access control:

```php
// In your routes
Route::middleware('workspace.permission:manage-users')->group(function () {
    // Routes requiring manage-users permission
});
```

## Models

| Model | Description |
|-------|-------------|
| `User` | Application users |
| `Workspace` | Tenant workspace boundaries |
| `WorkspaceMember` | Workspace membership with roles |
| `Entitlement` | Feature/package entitlements |
| `UsageRecord` | Usage tracking records |
| `Referral` | Referral tracking |

## Events

The module fires events for key actions:

- `WorkspaceCreated`
- `WorkspaceMemberAdded`
- `WorkspaceMemberRemoved`
- `EntitlementChanged`
- `UsageAlertTriggered`

## Artisan Commands

```bash
# Refresh user statistics
php artisan tenant:refresh-user-stats

# Process scheduled account deletions
php artisan tenant:process-deletions

# Check usage alerts
php artisan tenant:check-usage-alerts

# Reset billing cycles
php artisan tenant:reset-billing-cycles
```

## Configuration

The module uses the Core PHP configuration system. Key settings can be configured per-workspace or system-wide.

## Documentation

- [Core PHP Framework](https://github.com/host-uk/core-php)
- [Getting Started Guide](https://core.help/guide/)

## License

EUPL-1.2 (European Union Public Licence)

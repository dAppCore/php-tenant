# Core Tenant

Multi-tenancy module for Core PHP Framework.

## Quick Reference

```bash
composer test                 # Run tests
composer pint                 # Fix code style
```

## Architecture

This module provides the multi-tenancy foundation:

- **Users** - Application users with 2FA support
- **Workspaces** - Tenant boundaries with team members
- **Entitlements** - Feature access, packages, usage tracking
- **Account Management** - Settings, scheduled deletions

### Key Services

| Service | Purpose |
|---------|---------|
| `WorkspaceManager` | Current workspace context |
| `WorkspaceService` | Workspace CRUD operations |
| `EntitlementService` | Feature access & usage |
| `UserStatsService` | User statistics |
| `UsageAlertService` | Usage threshold alerts |

### Models

```
src/Models/
├── User.php              # Application user
├── Workspace.php         # Tenant workspace
├── WorkspaceMember.php   # Membership with roles
├── Entitlement.php       # Feature entitlements
├── UsageRecord.php       # Usage tracking
└── Referral.php          # Referral tracking
```

### Middleware

- `RequireAdminDomain` - Restrict to admin domain
- `CheckWorkspacePermission` - Permission-based access

## Event Listeners

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    WebRoutesRegistering::class => 'onWebRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

## Namespace

All classes use `Core\Mod\Tenant\` namespace.

## Testing

Tests use Orchestra Testbench. Run with:

```bash
composer test
```

## License

EUPL-1.2 (copyleft, GPL-compatible).

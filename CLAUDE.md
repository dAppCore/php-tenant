# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer test                    # Run all tests
composer lint                    # Fix code style (Pint)
vendor/bin/pest tests/Feature/AuthenticationTest.php  # Run single test
vendor/bin/pint --dirty          # Format changed files only
```

## Namespace

All classes use `Core\Tenant\` namespace (PSR-4 autoloaded from root).

## Architecture

Multi-tenancy module for Core PHP Framework using event-driven lazy loading.

### Module Registration (Boot.php)

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    WebRoutesRegistering::class => 'onWebRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

Routes, views, commands, and Livewire components register only when their events fire.

### Key Services (Singletons)

| Service | Purpose |
|---------|---------|
| `WorkspaceManager` | Current workspace context |
| `WorkspaceService` | Workspace CRUD, session switching |
| `EntitlementService` | Feature access, package limits, usage |
| `EntitlementWebhookService` | Webhook delivery with circuit breaker |
| `WorkspaceCacheManager` | Workspace-scoped caching with tags |
| `UsageAlertService` | Usage threshold alerts |
| `TotpService` | 2FA TOTP generation/validation |

### Workspace Isolation

The `BelongsToWorkspace` trait enforces tenancy:
- Auto-assigns `workspace_id` on create
- Throws `MissingWorkspaceContextException` if no workspace context
- Provides `forWorkspaceCached()` and `ownedByCurrentWorkspaceCached()` query methods
- Auto-invalidates workspace cache on model save/delete

### Entitlement System

Features have types: `boolean`, `limit`, `unlimited`. Usage is tracked via `UsageRecord` with rolling window or monthly resets. Packages bundle features. Boosts provide temporary limit increases.

## Coding Standards

- **UK English**: colour, organisation, centre (not American spellings)
- **Strict types**: `declare(strict_types=1);` in every file
- **Type hints**: All parameters and return types
- **Pest**: Write tests using Pest, not PHPUnit syntax
- **Flux Pro**: Use Flux components, not vanilla Alpine
- **Font Awesome**: Use FA icons, not Heroicons

## Testing

Uses Pest with Orchestra Testbench. Tests are in `tests/Feature/`.

```php
it('displays the workspace home', function () {
    $workspace = Workspace::factory()->create();

    $this->get("/workspace/{$workspace->uuid}")
        ->assertOk();
});
```
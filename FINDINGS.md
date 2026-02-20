# Phase 0 Findings — core/php-tenant

**Date:** 2026-02-20
**Branch:** feat/phase-0-assessment
**Analyst:** Clotho (darbs-claude)
**Issue:** #2

---

## 1. Environment

| Tool     | Version     | Status  |
|----------|-------------|---------|
| PHP      | 8.3.6       | OK      |
| Composer | 2.9.5       | OK      |
| Vendor   | —           | MISSING |

### 1.1 Composer Install

```
composer install --no-interaction
```

**Result: FAILED**

```
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires host-uk/core, it could not be found in any version,
      there may be a typo in the package name.
```

**Root cause:** `host-uk/core` (the host framework) is a private package. The `composer.json` has no `repositories` section to point Composer at the private registry or a local path.

**Resolution needed:** One of:
1. Add a `repositories` entry pointing to the Forgejo package registry (`darbs.lthn.ai`), or
2. Set `COMPOSER_AUTH` env var with appropriate credentials, or
3. Add a local path repository for development if `core` is checked out alongside this package.

Until resolved, **all tooling that requires `vendor/` is blocked** (tests, Pint, PHPStan).

---

## 2. Test Baseline

**Status: CANNOT RUN** — vendor directory is empty.

### Test inventory (static count)

| File | Lines |
|------|-------|
| `tests/Feature/AccountDeletionTest.php` | ~120 |
| `tests/Feature/AuthenticationTest.php` | ~160 |
| `tests/Feature/EntitlementApiTest.php` | ~700 |
| `tests/Feature/EntitlementServiceTest.php` | ~800 |
| `tests/Feature/ProfileTest.php` | ~100 |
| `tests/Feature/ResetBillingCyclesTest.php` | ~180 |
| `tests/Feature/SettingsTest.php` | ~120 |
| `tests/Feature/TwoFactorAuthenticatableTest.php` | ~140 |
| `tests/Feature/UsageAlertServiceTest.php` | ~180 |
| `tests/Feature/WaitlistTest.php` | ~120 |
| `tests/Feature/WorkspaceCacheTest.php` | ~300 |
| `tests/Feature/WorkspaceInvitationTest.php` | ~255 |
| `tests/Feature/WorkspaceSecurityTest.php` | ~433 |
| `tests/Feature/WorkspaceTenancyTest.php` | ~165 |
| `tests/Feature/Guards/AccessTokenGuardTest.php` | ~180 |
| **Total** | **~4,053 lines across 15 files** |

Tests use Pest with Orchestra Testbench. Coverage appears comprehensive for workspace- and entitlement-level tests.

**Pass/fail counts:** Cannot determine — blocked by missing vendor.

---

## 3. Code Quality (Static Review)

### 3.1 Pint / PHPStan

Cannot run — vendor missing. Tools (`vendor/bin/pint`, `vendor/bin/phpstan`) not available.

### 3.2 Missing `declare(strict_types=1)` — identified via static scan

The following files are missing the strict types declaration despite this being a documented coding standard:

| File | Priority |
|------|----------|
| `Models/AccountDeletionRequest.php` | P3 |
| `Models/Boost.php` | P3 |
| `Models/EntitlementLog.php` | P3 |
| `Models/Feature.php` | P3 |
| `Models/Package.php` | P3 |
| `Models/UsageRecord.php` | P3 |
| `Models/WaitlistEntry.php` | P3 |
| `Models/WorkspacePackage.php` | P3 |
| `Services/EntitlementResult.php` | P3 |

> NOTE: TODO DX-001 records this as fixed for `Models/Workspace.php`, `Models/User.php`, and `Services/EntitlementService.php` — but 9 other files still lack the declaration.

---

## 4. Architecture Review

### 4.1 Workspace Isolation — BelongsToWorkspace Trait

**File:** `Concerns/BelongsToWorkspace.php`

The trait is the primary tenancy enforcement mechanism for Eloquent models.

**Isolation mechanisms:**
1. `bootBelongsToWorkspace()` registers an Eloquent `creating` hook that:
   - Reads the current workspace from request attributes (`workspace_model`) or the authenticated user's default workspace
   - Auto-assigns `workspace_id` if not already set
   - Throws `MissingWorkspaceContextException` if workspace context is absent and strict mode is on

2. `scopeOwnedByCurrentWorkspace(Builder $query)` filters queries to the current workspace. In non-strict mode it returns `whereRaw('1 = 0')` (empty, fail-safe).

3. Cache invalidation is wired to `saved` and `deleted` model events via `clearWorkspaceCache()`.

**Opt-out:** Setting `protected bool $workspaceContextRequired = false;` on a model disables the exception — useful for legacy code but discouraged.

**Workspace resolution order:**
```
request()->attributes->get('workspace_model')   // set by ResolveWorkspaceFromSubdomain
  → auth()->user()->defaultHostWorkspace()       // falls back to auth user
  → null → MissingWorkspaceContextException
```

### 4.2 WorkspaceScope — Global Query Scope

**File:** `Scopes/WorkspaceScope.php`

An Eloquent global scope (implements `Scope`) that automatically filters all queries by the current workspace.

Key design decisions:
- `static bool $strictModeEnabled = true` — global toggle
- `withoutStrictMode(callable $callback)` — safe scoped disable (restores on exit)
- Builder macros: `forWorkspace(Workspace|int)`, `acrossWorkspaces()`, `currentWorkspaceId()`
- Console commands bypass strict mode automatically (`runningInConsole()` + not unit tests)
- Models can opt out: `public bool $workspaceScopeStrict = false;`

### 4.3 Middleware for Tenant Resolution

**Files:** `Middleware/`

| Middleware | Purpose |
|-----------|---------|
| `ResolveWorkspaceFromSubdomain` | Resolves workspace from `{slug}.host.uk.com` subdomain. Hardcoded mappings (hub→main, bio→bio, etc.). Sets `workspace_model` on request attributes. |
| `RequireWorkspaceContext` | Validates workspace exists; validates user has access. Resolution order: subdomain attr → `Workspace::current()` → `workspace_id` input → `X-Workspace-ID` header → `?workspace` query param. Logs denied attempts. |
| `CheckWorkspacePermission` | Per-permission authorisation within a resolved workspace. |
| `RequireAdminDomain` | Restricts routes to admin subdomains (hub, www, hestia). |
| `ResolveNamespace` | Resolves current namespace from `?namespace` query, `X-Namespace` header, or session. |

**Subdomain hardcoding concern:** `ResolveWorkspaceFromSubdomain` contains a hardcoded mapping of subdomains → workspace slugs. Adding a new service workspace requires a code change. Consider moving to database/config.

### 4.4 Migration Patterns

**Files:** `Migrations/`

| Migration | Tables created/modified |
|-----------|------------------------|
| `0001_01_01_000000_create_tenant_tables.php` | 15 tables: users, workspaces, namespaces, entitlement_features, entitlement_packages, entitlement_package_features, entitlement_workspace_packages, entitlement_namespace_packages, entitlement_boosts, entitlement_usage_records, entitlement_logs, user_two_factor_auth, sessions, password_reset_tokens, user_workspace |
| `2026_01_26_000000_create_workspace_invitations_table.php` | workspace_invitations |
| `2026_01_26_120000_create_usage_alert_history_table.php` | usage_alert_history |
| `2026_01_26_140000_create_entitlement_webhooks_tables.php` | entitlement_webhooks, entitlement_webhook_deliveries |
| `2026_01_26_140000_create_workspace_teams_table.php` | workspace_teams |
| `2026_01_29_000000_add_performance_indexes.php` | Indexes on users.tier, namespaces.slug, workspaces.{is_active,type,domain}, user_workspace.team_id, entitlement_usage_records.user_id, entitlement_logs.user_id |

All tables use appropriate composite indexes on commonly filtered columns.

---

## 5. Critical Bug: Missing `namespace_id` Columns in Entitlement Tables

**Severity: P1 — Runtime crash**

### 5.1 Description

The namespace-level entitlement features (`recordNamespaceUsage`, `provisionNamespaceBoost`) will fail at runtime with a database error because the underlying tables are missing the `namespace_id` column.

**Models that declare `namespace_id` as fillable:**

| Model | File |
|-------|------|
| `UsageRecord` | `Models/UsageRecord.php:18` |
| `Boost` | `Models/Boost.php:17` |

**Service methods that write `namespace_id`:**

| Method | File |
|--------|------|
| `EntitlementService::recordNamespaceUsage()` | `Services/EntitlementService.php:453` |
| `EntitlementService::provisionNamespaceBoost()` | `Services/EntitlementService.php:1861` |

**Migrations that DO NOT create the column:**

- `entitlement_usage_records` — `0001_01_01_000000_create_tenant_tables.php:248-261`
- `entitlement_boosts` — `0001_01_01_000000_create_tenant_tables.php:226-245`

**Query methods that filter by `namespace_id` without it existing:**

- `EntitlementService::getNamespaceCurrentUsage()` lines 1605, 1615, 1622 — `WHERE namespace_id = ?`
- `Namespace_::boosts()` relationship — references a non-existent FK

### 5.2 Impact

Any call to:
- `$entitlementService->recordNamespaceUsage($namespace, 'links')`
- `$entitlementService->provisionNamespaceBoost($namespace, 'links', [...])`
- `$entitlementService->canForNamespace($namespace, 'links')` (usage query path)

will throw:
```
SQLSTATE[HY000]: General error: 1 table entitlement_usage_records has no column named namespace_id
```

The test suite in `tests/Feature/EntitlementServiceTest.php` likely exercises these paths and would be failing if runnable.

### 5.3 Fix Required

A migration is needed to add `namespace_id` (nullable FK → namespaces, null on delete) to:
1. `entitlement_usage_records`
2. `entitlement_boosts`

Plus indexes: `(namespace_id, feature_code, recorded_at)` on usage records, `(namespace_id, feature_code, status)` on boosts.

---

## 6. Summary of Open Issues

| ID | Severity | Description | Status |
|----|----------|-------------|--------|
| BUG-001 | **P1** | `namespace_id` column missing from `entitlement_usage_records` and `entitlement_boosts` migrations — runtime crash | **NEW** |
| DX-003 | P3 | 9 model/service files missing `declare(strict_types=1)` | NEW |
| ENV-001 | P0-blocker | `composer install` fails — `host-uk/core` repository not configured | NEW |
| ARCH-001 | P4 | `ResolveWorkspaceFromSubdomain` hardcodes subdomain→workspace mappings; new services require code change | NEW |

Existing items from `TODO.md` P1–P3 (SEC-001 through SEC-006, DX-001, DX-002, TEST-001, TEST-002, PERF-001, PERF-002) are marked Fixed in the TODO and have corresponding code in place. These will be verifiable once `composer install` succeeds and tests run.

---

## 7. Recommended Next Steps

1. **Immediate:** Fix `composer.json` to resolve `host-uk/core` (add repository entry or document setup instructions in README).
2. **Immediate:** Add migration for `namespace_id` columns on `entitlement_usage_records` and `entitlement_boosts` — this is a production crash bug.
3. **Short-term:** Once vendor installs, run full test suite and document pass/fail baseline.
4. **Short-term:** Add `declare(strict_types=1)` to the 9 remaining files.
5. **Medium-term:** Consider moving subdomain→workspace mapping to config/database.

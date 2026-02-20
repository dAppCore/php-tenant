# Discovery Scan — February 2026

**Date:** 2026-02-20
**Scanner:** Clotho (automated scan)
**Issue:** core/php-tenant#3

## Summary

Automated scan of all PHP source files, migrations, routes, tests, and documentation. 34 issues created plus 1 roadmap tracking issue.

## Issues Created

### Security (P1-equivalent)

| Issue | Description |
|-------|-------------|
| #9 | `WorkspaceInvitation::findByToken` O(n) timing attack (1000 bcrypt checks per request) |

### Bug Fixes

| Issue | Description |
|-------|-------------|
| #7 | Hardcoded domain `hub.host.uk.com` in `EntitlementApiController` |
| #8 | Hardcoded domain `hub.host.uk.com` in `WorkspaceController` (store + switch) |
| #10 | `namespaces.workspace_id` nullOnDelete may orphan namespaces on workspace deletion |
| #12 | `feature_code` in `usage_alert_history` lacks referential integrity |
| #13 | `UserStatsService` has 5 unimplemented TODO stubs (quotas always return 0/empty) |
| #28 | README.md shows incorrect namespace `Core\Mod\Tenant` (should be `Core\Tenant`) |

### Performance

| Issue | Description |
|-------|-------------|
| #11 | Missing composite index on `user_workspace(workspace_id, role)` |
| #14 | N+1 query in `NamespaceService::groupedForUser` |

### Refactors

| Issue | Description |
|-------|-------------|
| #5 | Clarify `WorkspaceScope` vs `BelongsToWorkspace` architecture |
| #6 | `User` model has undefined external class relationships |
| #18 | Missing return type hints on `Workspace` model relationships |
| #19 | `EntitlementException` needs hierarchy of subtypes |
| #20 | Inconsistent API error response format across controllers |
| #24 | `WorkspaceMember` role strings should be a PHP 8.1 enum |

### Missing Tests

| Issue | Description |
|-------|-------------|
| #15 | `WorkspaceTeamService` — zero test coverage |
| #16 | `EntitlementWebhookService` — no tests for dispatch, circuit breaker, SSRF |
| #17 | `TotpService` edge cases (clock drift, malformed secrets) |
| #29 | `WorkspaceController` API endpoints |
| #30 | `NamespaceService` |
| #34 | Mutation testing with Infection PHP |

### Features / Enhancements

| Issue | Description |
|-------|-------------|
| #21 | Lazy-load `Workspace` relationships (30+ defined) |
| #22 | Soft deletes for `WorkspaceInvitation` |
| #23 | Invitation resend with rate limiting |
| #25 | Configurable invitation expiry (currently hardcoded 7 days) |
| #35 | Workspace ownership transfer |
| #36 | Bulk workspace invitation |
| #37 | Workspace activity audit log |

### Chores

| Issue | Description |
|-------|-------------|
| #26 | Add PHPStan/Larastan to dev dependencies |
| #27 | Pin `host-uk/core` to stable version (currently `dev-main`) |
| #31 | IDE helper annotations for Eloquent models |
| #32 | Artisan command for manual package provisioning |

### Documentation

| Issue | Description |
|-------|-------------|
| #33 | OpenAPI/Swagger documentation for all API endpoints |

## Roadmap

#38 — `roadmap: php-tenant production readiness` contains the full prioritised checklist.

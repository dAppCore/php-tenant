# core-tenant TODO

Comprehensive task list for improving the multi-tenancy package. Items are prioritised by impact and urgency.

## Legend

- **P1** - Critical/Security (must fix immediately)
- **P2** - High (affects production quality)
- **P3** - Medium (should address soon)
- **P4** - Low (quality of life improvements)
- **P5** - Nice-to-have (future enhancements)
- **P6** - Backlog (ideas for later consideration)

---

## P1 - Critical / Security

### SEC-001: Add rate limiting to EntitlementApiController
**Status:** Fixed (2026-01-29)
**File:** `Controllers/EntitlementApiController.php`

The Blesta API endpoints (`store`, `suspend`, `unsuspend`, `cancel`, `renew`) lack rate limiting. A compromised API key could be used to mass-provision or cancel packages.

**Resolution:**
- Added `#[RateLimit(limit: 60, window: 60, key: 'entitlement-api')]` attribute to controller class
- Documented recommended route configuration with `api.rate` and `throttle:60,1` middleware
- Rate limiting at 60 requests/minute per API key when routes are registered

---

### SEC-002: Validate API authentication on EntitlementApiController routes
**Status:** Fixed (2026-01-29)
**File:** `Routes/api.php`, `Controllers/EntitlementApiController.php`

The Blesta API controller routes are not visible in `api.php` - they may be registered elsewhere or missing authentication. Verify all Blesta API endpoints require proper API key authentication.

**Resolution:**
- Added comprehensive PHPDoc to controller documenting required authentication
- Documented required middleware: `api.auth:entitlements.write`, `api.rate`, `throttle:60,1`
- Routes are currently commented out in core-commerce/routes/api.php but controller is ready
- When enabled, routes require API key with `entitlements.write` scope

---

### SEC-003: Encrypt 2FA secrets at rest
**Status:** Fixed (Jan 2026, commit a35cbc9)
**File:** `Concerns/TwoFactorAuthenticatable.php`, `Migrations/0001_01_01_000000_create_tenant_tables.php`

The `user_two_factor_auth.secret` column stores TOTP secrets. While marked as `text`, these should be encrypted at rest using Laravel's `encrypted:string` cast.

**Acceptance Criteria:**
- Add `'secret_key' => 'encrypted'` cast to UserTwoFactorAuth model
- Create migration to encrypt existing secrets
- Verify decryption works correctly in TotpService

---

### SEC-004: Audit workspace invitation token security
**Status:** Fixed (Jan 2026, commit a35cbc9)
**File:** `Models/WorkspaceInvitation.php`

Invitation tokens are 64-character random strings, which is good. However:
- Tokens should be hashed when stored (store hash, compare with hash_equals)
- Add brute-force protection for invitation acceptance endpoint
- Consider shorter expiry for high-privilege roles (owner/admin)

**Acceptance Criteria:**
- Store hashed tokens instead of plaintext
- Add rate limiting to invitation acceptance
- Add configurable expiry per role type

---

### SEC-005: Add CSRF protection to webhook test endpoint
**Status:** Fixed (2026-01-29)
**File:** `Controllers/Api/EntitlementWebhookController.php`, `Services/EntitlementWebhookService.php`

The `test` endpoint triggers an outbound HTTP request. Ensure it cannot be abused as a server-side request forgery (SSRF) vector.

**Resolution:**
- Added `PreventsSSRF` trait to `EntitlementWebhookService`
- Created `InvalidWebhookUrlException` for SSRF validation failures
- All webhook operations (register, update, test, retry) now validate URLs:
  - Blocks localhost and loopback addresses (127.0.0.0/8, ::1)
  - Blocks private networks (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
  - Blocks link-local addresses and reserved ranges
  - Blocks local domains (.local, .localhost, .internal)
  - Requires HTTPS for all webhooks
  - Validates DNS resolution to prevent rebinding attacks
- Added `SafeWebhookUrl` validation rule to controller store/update
- Added timeout (10s) and connect timeout (5s) limits

---

### SEC-006: Validate workspace_id in RequireWorkspaceContext middleware
**Status:** Fixed (2026-01-29)
**File:** `Middleware/RequireWorkspaceContext.php`

The middleware accepts workspace_id from multiple sources (header, query, input) without validating the authenticated user's access in all code paths.

**Resolution:**
- Changed default behaviour to ALWAYS validate user access (breaking change)
- Added `isValidWorkspaceId()` check to validate workspace ID is positive integer
- Added `logWorkspaceAccessAttempt()` for security monitoring
- Logs denied/invalid attempts at warning level, granted at debug level (debug mode only)
- To skip validation (NOT RECOMMENDED), pass `skip_validation` parameter
- Logs include: workspace_id, user_id, IP, user agent, URL, source of workspace context

---

## P2 - High Priority

### DX-001: Add strict_types declaration to all PHP files
**Status:** Open
**Files:** Multiple files missing declaration

Several files are missing `declare(strict_types=1);`:
- `Models/Workspace.php`
- `Models/User.php`
- `Services/EntitlementService.php`

**Acceptance Criteria:**
- Add strict_types to all PHP files
- Run tests to verify no type coercion issues

---

### DX-002: Document EntitlementService public API
**Status:** Open
**File:** `Services/EntitlementService.php`

The EntitlementService is the core API for entitlement checks but lacks comprehensive PHPDoc. External consumers need clear documentation.

**Acceptance Criteria:**
- Add complete PHPDoc to all public methods
- Document exception conditions
- Add @throws annotations where applicable
- Create usage examples in documentation

---

### TEST-001: Add tests for namespace-level entitlements
**Status:** Open
**File:** `tests/Feature/EntitlementServiceTest.php`

The test file covers workspace-level entitlements but not namespace-level (`canForNamespace`, `recordNamespaceUsage`, etc.).

**Acceptance Criteria:**
- Test `canForNamespace()` with various ownership scenarios
- Test entitlement cascade (namespace -> workspace -> user tier)
- Test `provisionNamespacePackage()` and `provisionNamespaceBoost()`
- Test namespace cache invalidation

---

### TEST-002: Add integration tests for EntitlementApiController
**Status:** Open
**File:** `tests/Feature/EntitlementApiTest.php`

Need HTTP-level integration tests for the API endpoints, including authentication, validation, and error cases.

**Acceptance Criteria:**
- Test all CRUD operations via HTTP
- Test validation error responses
- Test authentication failures
- Test rate limiting (once implemented)

---

### PERF-001: Optimise EntitlementService cache invalidation
**Status:** Open
**File:** `Services/EntitlementService.php`

The `invalidateCache()` method iterates all features and clears each key individually. This is O(n) where n = feature count.

**Acceptance Criteria:**
- Use cache tags when available (Redis)
- Implement version-based cache busting
- Benchmark before/after with 100+ features

---

### PERF-002: Add database indexes for common queries
**Status:** Open
**File:** `Migrations/0001_01_01_000000_create_tenant_tables.php`

Missing indexes identified:
- `users.tier` (for tier-based queries)
- `namespaces.slug` (currently only unique in combination)
- `entitlement_usage_records.user_id`

**Acceptance Criteria:**
- Create migration adding missing indexes
- Verify query plan improvements with EXPLAIN

---

### CODE-001: Extract WorkspaceScope to separate file
**Status:** Open
**File:** `Scopes/WorkspaceScope.php`

The WorkspaceScope class exists but is referenced in BelongsToWorkspace trait without actually being applied as a global scope. Clarify the architecture.

**Acceptance Criteria:**
- Document when WorkspaceScope vs BelongsToWorkspace should be used
- Consider applying WorkspaceScope as a proper global scope
- Update CLAUDE.md with guidance

---

### CODE-002: Consolidate User model relationships
**Status:** Open
**File:** `Models/User.php`

The User model has many undefined relationships (Page, Project, Domain, Pixel, etc.) that reference classes not in this package. These should either be:
1. Moved to the consuming application
2. Made conditional on class existence

**Acceptance Criteria:**
- Audit all relationships for undefined classes
- Add `class_exists()` guards or move to app layer
- Document which relationships are package-native vs app-specific

---

### CODE-003: Remove hardcoded domain in EntitlementApiController
**Status:** Open
**File:** `Controllers/EntitlementApiController.php`, Line 80

The workspace creation uses hardcoded domain `'hub.host.uk.com'`. This should be configurable.

**Acceptance Criteria:**
- Move to config value
- Add sensible default
- Document in CLAUDE.md

---

## P3 - Medium Priority

### DX-003: Add return type hints to all Workspace relationships
**Status:** Open
**File:** `Models/Workspace.php`

Many relationship methods have correct docblocks but inconsistent return types. Standardise for IDE support.

**Acceptance Criteria:**
- Add explicit return types to all relationship methods
- Verify PHPStan/Larastan passes at level 6+

---

### DX-004: Create EntitlementException subtypes
**Status:** Open
**File:** `Exceptions/EntitlementException.php`

Currently there's a single EntitlementException. Consider subtypes:
- `LimitExceededException`
- `PackageNotFoundException`
- `FeatureNotFoundException`

**Acceptance Criteria:**
- Create exception hierarchy
- Update EntitlementService to throw specific exceptions
- Update documentation with exception types

---

### TEST-003: Add tests for WorkspaceTeamService
**Status:** Open
**File:** `Services/WorkspaceTeamService.php`

No dedicated test file for WorkspaceTeamService. The service handles team CRUD, permissions, and member management.

**Acceptance Criteria:**
- Test team creation/update/deletion
- Test permission checks (hasPermission, hasAnyPermission, hasAllPermissions)
- Test member assignment to teams
- Test default team seeding
- Test member migration from roles to teams

---

### TEST-004: Add tests for EntitlementWebhookService
**Status:** Open
**File:** `Services/EntitlementWebhookService.php`

Need tests for webhook dispatch, signature verification, and circuit breaker functionality.

**Acceptance Criteria:**
- Test webhook registration
- Test event dispatch (sync and async)
- Test signature signing and verification
- Test circuit breaker trigger and reset
- Test delivery retry logic

---

### TEST-005: Add edge case tests for TotpService
**Status:** Open
**File:** `Services/TotpService.php`

Current tests may not cover:
- Clock drift (WINDOW parameter)
- Invalid base32 input
- Empty/null code handling

**Acceptance Criteria:**
- Test verification with clock drift
- Test malformed secret handling
- Test edge cases in base32 encode/decode

---

### PERF-003: Lazy-load Workspace relationships
**Status:** Open
**File:** `Models/Workspace.php`

The Workspace model has 30+ relationships. Many are to external packages (Core\Mod\Social, etc.). Consider:
- Marking heavy relationships as lazy
- Using `withCount` instead of loading full relations for counts

**Acceptance Criteria:**
- Audit which relationships are commonly N+1 issues
- Add `$with` property sparingly
- Document recommended eager loading patterns

---

### CODE-004: Standardise error responses across API controllers
**Status:** Open
**Files:** `Controllers/EntitlementApiController.php`, `Controllers/Api/EntitlementWebhookController.php`

Error response formats vary. Standardise to consistent structure:
```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE"
}
```

**Acceptance Criteria:**
- Create API response trait or service
- Apply to all API controllers
- Document response format

---

### CODE-005: Add validation for webhook URL in registration
**Status:** Open
**File:** `Services/EntitlementWebhookService.php`

The `register()` method doesn't validate the webhook URL format or accessibility.

**Acceptance Criteria:**
- Validate URL format (must be https in production)
- Optionally verify URL is reachable
- Block internal IP ranges

---

### FEAT-001: Add soft deletes to WorkspaceInvitation
**Status:** Open
**File:** `Models/WorkspaceInvitation.php`

Invitations are currently hard-deleted. Soft deletes would preserve audit trail.

**Acceptance Criteria:**
- Add SoftDeletes trait
- Update delete operations
- Add migration for deleted_at column

---

### FEAT-002: Add invitation resend functionality
**Status:** Open
**File:** `Models/WorkspaceInvitation.php`

Users may miss invitation emails. Add ability to resend with updated expiry.

**Acceptance Criteria:**
- Add `resend()` method to WorkspaceInvitation
- Extend expiry on resend
- Track resend count/timestamps
- Rate limit resends

---

## P4 - Low Priority

### DX-005: Add IDE helper annotations
**Status:** Open

Add `@mixin` and `@method` annotations for better IDE autocomplete with Eloquent.

**Acceptance Criteria:**
- Add annotations to all models
- Document pattern for future models

---

### DX-006: Create artisan command for provisioning packages
**Status:** Open

Manual package provisioning via tinker is error-prone. Add CLI command.

**Acceptance Criteria:**
- `php artisan tenant:provision-package {workspace} {package}`
- Add interactive mode
- Support dry-run option

---

### TEST-006: Add mutation testing
**Status:** Open

Run infection/mutation testing to verify test quality.

**Acceptance Criteria:**
- Add infection to dev dependencies
- Configure for core services
- Achieve >80% mutation score on critical code

---

### CODE-006: Extract constants from WorkspaceMember
**Status:** Open
**File:** `Models/WorkspaceMember.php`

Role constants should be in an enum for type safety.

**Acceptance Criteria:**
- Create WorkspaceMemberRole enum
- Update model to use enum
- Update all role comparisons

---

### CODE-007: Add configurable invitation expiry
**Status:** Open
**File:** `Models/Workspace.php`, Line 654

The `invite()` method has hardcoded 7-day expiry. Make configurable.

**Acceptance Criteria:**
- Add config key `tenant.invitation_expiry_days`
- Document configuration option

---

### FEAT-003: Add workspace transfer ownership
**Status:** Open

Allow workspace owners to transfer ownership to another member.

**Acceptance Criteria:**
- Add `transferOwnership()` method to WorkspaceManager
- Require confirmation from new owner
- Log ownership transfer in audit log

---

### FEAT-004: Add bulk invitation support
**Status:** Open

Allow inviting multiple users at once (CSV upload or multi-email input).

**Acceptance Criteria:**
- Add `inviteMany()` method
- Support CSV email import
- Handle duplicates gracefully

---

## P5 - Nice to Have

### DX-007: Add OpenAPI/Swagger documentation
**Status:** Open

Generate API documentation from route definitions.

**Acceptance Criteria:**
- Add scramble or l5-swagger
- Document all API endpoints
- Include authentication requirements

---

### FEAT-005: Add workspace activity log
**Status:** Open

Track all significant workspace actions for audit purposes.

**Acceptance Criteria:**
- Log member additions/removals
- Log permission changes
- Log package/boost changes
- Provide query interface

---

### FEAT-006: Add usage forecasting
**Status:** Open

Predict when a workspace will hit limits based on usage trends.

**Acceptance Criteria:**
- Track daily usage aggregates
- Implement simple linear projection
- Show "estimated days until limit" in dashboard

---

### FEAT-007: Add webhook event filtering
**Status:** Open

Allow webhooks to filter events by additional criteria (e.g., specific features only).

**Acceptance Criteria:**
- Add filter configuration to webhook
- Support feature code patterns
- Support threshold filtering for limit events

---

## P6 - Backlog / Ideas

### IDEA-001: GraphQL API for entitlements
Consider adding GraphQL endpoint for more flexible entitlement queries.

### IDEA-002: Real-time usage updates
WebSocket support for live usage updates in dashboard.

### IDEA-003: Entitlement simulation mode
Allow testing "what if I upgrade" scenarios without actual changes.

### IDEA-004: Multi-region support
Support for workspace data residency requirements.

### IDEA-005: Workspace templates
Pre-configured workspace setups for different use cases.

---

## Completed

_Move items here when done with completion date._

<!-- Example:
### SEC-001: Add rate limiting to API
**Status:** Done (2026-01-29)
**PR:** #123
-->

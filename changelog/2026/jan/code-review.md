# Tenant Module Review

**Updated:** 2026-01-21 - All implementations verified complete. Rate limiting, deletion logging, query optimisation, soft deletes for WorkspacePackage, and cache invalidation optimisation implemented

## Overview

The Tenant module is the core multi-tenancy system for Host Hub, handling:
- **Users and Authentication**: User model, 2FA, API tokens, email verification
- **Workspaces**: The tenant boundary - users belong to workspaces, resources are scoped to workspaces
- **Entitlements**: Feature access control via packages, boosts, and usage tracking
- **Account Management**: Account deletion with grace period, settings
- **Referrals**: Agent referral tracking for the Trees programme

This is a foundational module that other modules depend on heavily.

## Production Readiness Score: 92/100 (was 90/100 - cache optimisation and soft deletes added 2026-01-21)

The module has solid architecture, good test coverage for core functionality, proper security patterns, and configuration externalisation. Critical namespace issues fixed in Wave 2. Cache invalidation now uses efficient version-based approach. WorkspacePackage now has soft deletes for audit history.

## Critical Issues (Must Fix)

- [x] **Workspace.packages() uses wrong namespace**: FIXED - Now uses `Mod\Tenant\Models\Package::class`
- [x] **Workspace.bioPages/bioProjects/bioDomains/bioPixels use wrong namespace**: FIXED - 12 namespace replacements from `\App\Models\BioLink\*` to `Mod\Web\Models\*` across DemoTestUserSeeder.php, WorkspaceDetails.php, and WorkspaceManager.php
- [ ] **User boosts relationship may conflict**: User has a `boosts()` relationship but Boost has `workspace_id` as the primary foreign key, not `user_id`. The relationship exists but the intended use case is unclear
- [x] **WorkspaceService.get() bypasses user authorisation**: VERIFIED SECURE - The `get()` method calls `getModel()` which correctly queries through `$user->workspaces()` relationship, ensuring only accessible workspaces are returned. The review item was outdated.

## Recommended Improvements

- [x] **Add rate limiting to referral tracking**: DONE - `ReferralController::track()` now has rate limiting applied.
- [x] **Add logging to account deletion confirmation**: DONE - `ConfirmDeletion` Livewire component now logs deletion actions.
- [x] **Cache invalidation in EntitlementService is expensive**: DONE - Refactored to use version-based cache keys. `buildCacheKey()` now includes version in key (e.g., `entitlement:{id}:v{version}:limit:{code}`). Invalidation simply increments the version, making all old keys stale without iteration.
- [x] **WorkspaceManager.setDefault() could be optimised**: DONE - Refactored to use a single optimised query.
- [ ] **Add index hints for UsageRecord queries**: `getTotalUsage()` and `getRollingUsage()` query by workspace_id + feature_code + recorded_at - ensure composite index exists
- [x] **Consider soft deletes for WorkspacePackage**: DONE - Added `SoftDeletes` trait to WorkspacePackage model and migration `2026_01_21_200000_add_soft_deletes_to_workspace_packages.php` for the `deleted_at` column.
- [x] **EntitlementService.getTotalLimit() cache key does not include version**: DONE - Cache keys now include version via `buildCacheKey()` method. All entitlement cache keys use format `entitlement:{id}:v{version}:{type}:{code}`.

## Missing Features (Future)

- [ ] **UserStatsService has multiple TODO comments**: Lines 83-93 for social accounts, scheduled posts, and storage usage tracking
- [ ] **Workspace teams/roles beyond owner**: Current pivot only has 'role' but no team management UI or additional role types implemented
- [ ] **Entitlement webhook notifications**: No webhook dispatch when limits are reached or packages change
- [ ] **Usage alerts/notifications**: No mechanism to notify users when approaching limits
- [ ] **Billing cycle reset automation**: `expireCycleBoundBoosts()` exists but no scheduler entry visible
- [ ] **Web routes file missing**: No `Routes/web.php` file exists despite the Boot.php checking for it
- [ ] **Workspace invitation system**: No invite flow for adding users to workspaces

## Test Coverage Assessment

**Well Tested (Good Coverage):**
- `EntitlementServiceTest.php` - Comprehensive coverage of can(), recordUsage(), provisionPackage/Boost, suspend/reactivate, revokePackage (600+ lines)
- `AccountDeletionTest.php` - Full lifecycle including model methods, job, and command (300+ lines)
- `WorkspaceTenancyTest.php` - Workspace isolation, scoping, relationships
- `AccessTokenGuardTest.php` - Token authentication, expiry, creation, revocation

**Tested but Limited:**
- `EntitlementApiTest.php` - Tests API endpoints but uses non-existent package code 'social-creator' (tests may fail)
- `AuthenticationTest.php`, `ProfileTest.php`, `SettingsTest.php` - Exist but not reviewed in detail

**Missing Test Coverage:**
- [ ] `WorkspaceService` - No dedicated tests
- [ ] `WorkspaceManager` - No dedicated tests
- [ ] `UserStatsService` - No tests
- [ ] `ResolveWorkspaceFromSubdomain` middleware - No tests
- [ ] `ReferralController` - No tests
- [ ] `BelongsToWorkspace` trait - Only integration tests via WorkspaceTenancyTest
- [ ] `TwoFactorAuthenticatable` concern - Test file exists but not reviewed
- [ ] Livewire components (ConfirmDeletion, CancelDeletion, WorkspaceHome) - No dedicated tests

## Security Concerns

**Positive Security Patterns:**
- Tokens stored as SHA-256 hashes (UserToken)
- Password re-verification required for account deletion (ConfirmDeletion)
- LIKE pattern escaping in WorkspaceService.findBySubdomain() (SQL injection prevention)
- Sensitive fields ($hidden) on User and Workspace models
- WP connector secret guarded against mass assignment

**Concerns:**
- [x] **WorkspaceService.get() has no authorisation**: VERIFIED SECURE - Method correctly uses `$user->workspaces()` relationship, not a raw query
- [ ] **ReferralController stores IP in cookie**: IP address stored in JSON cookie - GDPR consideration, should be session-only
- [ ] **No CSRF protection visible for Livewire deletion**: executeDelete() is called via Livewire dispatch - verify CSRF is enforced
- [ ] **Workspace.validateWebhookSignature() timing attack**: Uses hash_equals which is good, but hash_hmac result should also be compared in constant time
- [ ] **User tier bypass for email verification**: Hades users bypass email verification - ensure this is intentional business logic

## Notes

### Architecture Observations
- Clean separation between WorkspaceManager (request-scoped operations) and WorkspaceService (session/persistence)
- EntitlementService is well-designed with proper caching, logging, and transaction handling
- Good use of value objects (EntitlementResult) for type safety
- Backward compatibility aliases in Boot.php for migration from old namespace

### Code Quality
- Consistent use of strict types
- Good docblocks on most public methods
- Uses Laravel conventions (factories, seeders, Pest tests)
- Models have proper casts, fillable/guarded definitions

### Configuration
- Proper config externalisation in `config/tenant.php`
- Environment variables for cache TTL and grace period
- No hardcoded secrets or credentials found

### Dual Entitlement Systems
The module has two entitlement approaches that may cause confusion:
1. **UserTier enum** (FREE/APOLLO/HADES) - Used on User model, defines features as simple array
2. **Package/Feature/Boost system** - Used on Workspace model, full database-driven entitlements

These should be reconciled - currently User.getTier() returns enum-based limits while Workspace.can() uses the database-driven system.

### Dependencies
The module has relationships to models in other modules:
- `Mod\Analytics\Models\*`
- `Mod\Social\Models\*`
- `Mod\Web\Models\*`
- `Mod\Trust\Models\*`
- `Mod\Notify\Models\*`
- `Mod\Commerce\Models\*`
- `Mod\Trees\Models\*`
- `Mod\Api\Models\*`
- `Mod\Content\Models\*`

These cross-module dependencies are appropriate for a tenant module but ensure circular dependencies are avoided.

---
title: Security
description: Security considerations and audit notes for core-tenant
updated: 2026-01-29
---

# Security Considerations

This document outlines security considerations, implemented protections, and known areas requiring attention in the core-tenant package.

## Multi-Tenant Data Isolation

### Workspace Scope Enforcement

The primary security mechanism is the `BelongsToWorkspace` trait which enforces workspace isolation at the model level.

**How it works:**

1. **Strict Mode** (default in web requests): Queries without workspace context throw `MissingWorkspaceContextException`
2. **Auto-assignment**: Creating models without explicit `workspace_id` uses current context or throws
3. **Cache invalidation**: Model changes automatically invalidate workspace-scoped cache

**Code paths:**

```php
// SAFE: Explicit workspace context
Account::forWorkspace($workspace)->get();

// SAFE: Uses current workspace from request
Account::ownedByCurrentWorkspace()->get();

// THROWS in strict mode: No workspace context
Account::query()->get(); // MissingWorkspaceContextException

// DANGEROUS: Bypasses scope - use with caution
Account::query()->acrossWorkspaces()->get();
WorkspaceScope::withoutStrictMode(fn() => Account::all());
```

### Middleware Protection

| Middleware | Purpose |
|------------|---------|
| `RequireWorkspaceContext` | Ensures workspace is set before route handling |
| `CheckWorkspacePermission` | Validates user has required permissions |

**Recommendation:** Always use `workspace.required:validate` for user-facing routes to ensure the authenticated user actually has access to the resolved workspace.

### Known Gaps

1. **SEC-006**: The `RequireWorkspaceContext` middleware accepts workspace from headers/query params without mandatory user access validation. The `validate` parameter should be the default.

2. **Cross-tenant API**: The `EntitlementApiController` accepts workspace lookups by email, which could allow enumeration of user-workspace associations. Consider adding authentication scopes.

## Authentication Security

### Password Storage

Passwords are hashed using bcrypt via Laravel's `hashed` cast:

```php
protected function casts(): array
{
    return [
        'password' => 'hashed',
    ];
}
```

### Two-Factor Authentication

**Implemented:**
- TOTP (RFC 6238) with 30-second time steps
- 6-digit codes with SHA-1 HMAC
- Clock drift tolerance (1 window each direction)
- 8 recovery codes (20 characters each)

**Security Considerations:**

1. **SEC-003**: TOTP secrets are stored in plaintext. Should use Laravel's `encrypted` cast.
   - File: `Models/UserTwoFactorAuth.php`
   - Risk: Database breach exposes all 2FA secrets
   - Mitigation: Use `'secret_key' => 'encrypted'` cast

2. Recovery codes are stored as JSON array. Consider hashing each code individually.

3. No brute-force protection on TOTP verification endpoint (rate limiting should be applied at route level).

### Session Security

Standard Laravel session handling with:
- `sessions` table for database driver
- IP address and user agent tracking
- `remember_token` for persistent sessions

## API Security

### Blesta Integration API

The `EntitlementApiController` provides endpoints for external billing system integration:

| Endpoint | Risk | Mitigation |
|----------|------|------------|
| `POST /store` | Creates users/workspaces | Requires API auth |
| `POST /suspend/{id}` | Suspends access | Requires API auth |
| `POST /cancel/{id}` | Cancels packages | Requires API auth |

**Known Issues:**

1. **SEC-001**: No rate limiting on API endpoints
   - Risk: Compromised API key could mass-provision accounts
   - Mitigation: Add rate limiting middleware

2. **SEC-002**: API authentication not visible in `Routes/api.php`
   - Action: Verify Blesta routes have proper auth middleware

### Webhook Security

**Implemented:**
- HMAC-SHA256 signature on all payloads
- `X-Signature` header for verification
- 32-byte random secrets (256-bit)

**Code:**
```php
// Signing (outbound)
$signature = hash_hmac('sha256', json_encode($payload), $webhook->secret);

// Verification (inbound)
$expected = hash_hmac('sha256', $payload, $secret);
return hash_equals($expected, $signature);
```

**Known Issues:**

1. **SEC-005**: Webhook test endpoint could be SSRF vector
   - Risk: Attacker could probe internal network via webhook URL
   - Mitigation: Validate URLs against blocklist, prevent internal IPs

### Invitation Tokens

**Implemented:**
- 64-character random tokens (`Str::random(64)`)
- Expiration dates with default 7-day TTL
- Single-use (marked accepted_at after use)

**Known Issues:**

1. **SEC-004**: Tokens stored in plaintext
   - Risk: Database breach exposes all pending invitations
   - Mitigation: Store hash, compare with `hash_equals()`

2. No rate limiting on invitation acceptance endpoint
   - Risk: Brute-force token guessing (though 64 chars is large keyspace)
   - Mitigation: Add rate limiting, log failed attempts

## Input Validation

### EntitlementApiController

```php
$validated = $request->validate([
    'email' => 'required|email',
    'name' => 'required|string|max:255',
    'product_code' => 'required|string',
    'billing_cycle_anchor' => 'nullable|date',
    'expires_at' => 'nullable|date',
    'blesta_service_id' => 'nullable|string',
]);
```

**Note:** `blesta_service_id` and `product_code` are not sanitised for special characters. Consider adding regex validation if these are displayed in UI.

### Workspace Manager Validation Rules

The `WorkspaceManager` provides scoped uniqueness rules:

```php
// Ensures uniqueness within workspace
$manager->uniqueRule('social_accounts', 'handle', softDelete: true);
```

## Logging and Audit

### Entitlement Logs

All entitlement changes are logged to `entitlement_logs`:

```php
EntitlementLog::logPackageAction(
    $workspace,
    EntitlementLog::ACTION_PACKAGE_PROVISIONED,
    $workspacePackage,
    source: EntitlementLog::SOURCE_BLESTA,
    newValues: $workspacePackage->toArray()
);
```

**Logged actions:**
- Package provision/suspend/cancel/reactivate/renew
- Boost provision/expire/cancel
- Usage recording

**Not logged (should consider):**
- Workspace creation/deletion
- Member additions/removals
- Permission changes
- Login attempts

### Security Event Logging

Currently limited. Recommend adding:
- Failed authentication attempts
- 2FA setup/disable events
- Invitation accept/reject
- API key usage

## Sensitive Data Handling

### Hidden Attributes

```php
// User model
protected $hidden = [
    'password',
    'remember_token',
];

// Workspace model
protected $hidden = [
    'wp_connector_secret',
];
```

### Guarded Attributes

```php
// Workspace model
protected $guarded = [
    'wp_connector_secret',
];
```

**Note:** Using `$fillable` is generally safer than `$guarded` for sensitive models.

## Recommendations

### Immediate (P1)

1. Add rate limiting to all API endpoints
2. Encrypt 2FA secrets at rest
3. Hash invitation tokens before storage
4. Validate webhook URLs against SSRF attacks
5. Make user access validation default in RequireWorkspaceContext

### Short-term (P2)

1. Add comprehensive security event logging
2. Implement brute-force protection for:
   - 2FA verification
   - Invitation acceptance
   - Password reset
3. Add API scopes for entitlement operations
4. Implement session fingerprinting (detect session hijacking)

### Long-term (P3)

1. Consider WebAuthn/FIDO2 as 2FA alternative
2. Implement cryptographic binding between user sessions and workspace access
3. Add anomaly detection for unusual entitlement patterns
4. Consider field-level encryption for sensitive workspace data

## Security Testing

### Existing Tests

- `WorkspaceSecurityTest.php` - Tests tenant isolation
- `TwoFactorAuthenticatableTest.php` - Tests 2FA flows

### Recommended Additional Tests

1. Test workspace scope bypass attempts
2. Test API authentication failure handling
3. Test rate limiting behaviour
4. Test SSRF protection on webhook URLs
5. Test invitation token brute-force protection

## Compliance Notes

### GDPR Considerations

1. **Account Deletion**: `ProcessAccountDeletion` job handles user data removal
2. **Data Export**: Not currently implemented (consider adding)
3. **Consent Tracking**: Not in scope of this package

### PCI DSS

If handling payment data:
- `stripe_customer_id` and `btcpay_customer_id` are stored (tokens, not card data)
- No direct card handling in this package
- Billing details (name, address) stored in workspace model

## Incident Response

If you discover a security vulnerability:

1. Do not disclose publicly
2. Contact: security@host.uk.com (hypothetical)
3. Include: Vulnerability description, reproduction steps, impact assessment

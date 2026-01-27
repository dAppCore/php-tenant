# Core-Tenant - January 2026

## Features Implemented

### Workspace as Universal Tenant (TASK-003)

Consolidated tenancy model with workspace as the primary organisational unit.

**Changes:**
- All workspace_id columns now nullable for system-level entities
- Workspace invitations system
- User tier system (free, pro, hades)
- Namespace system for sub-workspace scoping

**Models:**
- `Workspace` - enhanced with invitation support
- `WorkspaceInvitation` - new model with notification
- `Namespace` - sub-workspace resource grouping
- `User` - tier management

**Files:**
- `Models/Workspace.php`
- `Models/WorkspaceInvitation.php`
- `Models/Namespace.php`
- `Notifications/WorkspaceInvitationNotification.php`

---

### Web Routes

Created `Routes/web.php` with:
- Account deletion flow
- Workspace management routes
- Invitation acceptance

---

### Two-Factor Authentication

User 2FA support with TOTP.

**Files:**
- `Models/UserTwoFactorAuth.php`
- Migration for 2FA table

---

### Soft Deletes

Added soft delete support to User model for GDPR compliance.

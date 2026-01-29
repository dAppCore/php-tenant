---
title: Teams and Permissions
description: Guide to workspace teams and permission management
updated: 2026-01-29
---

# Teams and Permissions

The team system provides fine-grained access control within workspaces through role-based teams with configurable permissions.

## Overview

```
Workspace
├── Teams (permission groups)
│   ├── Owners (system team)
│   ├── Admins (system team)
│   ├── Members (system team, default)
│   └── Custom teams...
└── Members (users in workspace)
    └── assigned to Team (or custom_permissions)
```

## Quick Start

### Check Permissions

```php
use Core\Tenant\Services\WorkspaceTeamService;

$teamService = app(WorkspaceTeamService::class);
$teamService->forWorkspace($workspace);

// Single permission
if ($teamService->hasPermission($user, 'social.write')) {
    // User can create/edit social content
}

// Any of multiple permissions
if ($teamService->hasAnyPermission($user, ['admin', 'owner'])) {
    // User is admin or owner
}

// All permissions required
if ($teamService->hasAllPermissions($user, ['social.read', 'social.write'])) {
    // User has both permissions
}
```

### Via Middleware

```php
// Single permission
Route::middleware('workspace.permission:social.write')
    ->group(function () {
        Route::post('/posts', [PostController::class, 'store']);
    });

// Multiple permissions (OR logic)
Route::middleware('workspace.permission:admin,owner')
    ->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
    });
```

## System Teams

Three system teams are created by default:

### Owners

```php
[
    'slug' => 'owner',
    'permissions' => ['*'],  // All permissions
    'is_system' => true,
]
```

Workspace owners have unrestricted access to all features and settings.

### Admins

```php
[
    'slug' => 'admin',
    'permissions' => [
        'workspace.read',
        'workspace.manage_settings',
        'workspace.manage_members',
        'workspace.manage_billing',
        // ... all service permissions
    ],
    'is_system' => true,
]
```

Admins can manage workspace settings and members but cannot delete the workspace or transfer ownership.

### Members

```php
[
    'slug' => 'member',
    'permissions' => [
        'workspace.read',
        'social.read', 'social.write',
        'bio.read', 'bio.write',
        // ... basic service access
    ],
    'is_system' => true,
    'is_default' => true,
]
```

Default team for new members. Can use services but not manage workspace settings.

## Permission Structure

### Workspace Permissions

| Permission | Description |
|------------|-------------|
| `workspace.read` | View workspace details |
| `workspace.manage_settings` | Edit workspace settings |
| `workspace.manage_members` | Invite/remove members |
| `workspace.manage_billing` | View/manage billing |

### Service Permissions

Each service follows the pattern: `service.read`, `service.write`, `service.delete`

| Service | Permissions |
|---------|-------------|
| Social | `social.read`, `social.write`, `social.delete` |
| Bio | `bio.read`, `bio.write`, `bio.delete` |
| Analytics | `analytics.read`, `analytics.write` |
| Notify | `notify.read`, `notify.write` |
| Trust | `trust.read`, `trust.write` |
| API | `api.read`, `api.write` |

### Wildcard Permission

The `*` permission grants access to everything. Only used by the Owners team.

## WorkspaceTeamService API

### Team Management

```php
$teamService = app(WorkspaceTeamService::class);
$teamService->forWorkspace($workspace);

// List teams
$teams = $teamService->getTeams();

// Get specific team
$team = $teamService->getTeam($teamId);
$team = $teamService->getTeamBySlug('content-creators');

// Get default team for new members
$defaultTeam = $teamService->getDefaultTeam();

// Create custom team
$team = $teamService->createTeam([
    'name' => 'Content Creators',
    'slug' => 'content-creators',
    'description' => 'Team for content creation staff',
    'permissions' => ['social.read', 'social.write', 'bio.read', 'bio.write'],
    'colour' => 'blue',
]);

// Update team
$teamService->updateTeam($team, [
    'permissions' => [...$team->permissions, 'analytics.read'],
]);

// Delete team (non-system only)
$teamService->deleteTeam($team);
```

### Member Management

```php
// Get member record
$member = $teamService->getMember($user);

// List all members
$members = $teamService->getMembers();

// List team members
$teamMembers = $teamService->getTeamMembers($team);

// Assign member to team
$teamService->addMemberToTeam($user, $team);

// Remove from team
$teamService->removeMemberFromTeam($user);

// Set custom permissions (override team)
$teamService->setMemberCustomPermissions($user, [
    'social.read',
    'social.write',
    // No social.delete
]);
```

### Permission Checks

```php
// Get effective permissions
$permissions = $teamService->getMemberPermissions($user);
// Returns: ['workspace.read', 'social.read', 'social.write', ...]

// Check single permission
$teamService->hasPermission($user, 'social.write');

// Check any permission (OR)
$teamService->hasAnyPermission($user, ['admin', 'owner']);

// Check all permissions (AND)
$teamService->hasAllPermissions($user, ['social.read', 'social.write']);

// Role checks
$teamService->isOwner($user);
$teamService->isAdmin($user);
```

## WorkspaceMember Model

The `WorkspaceMember` model represents the user-workspace relationship:

```php
$member = WorkspaceMember::where('workspace_id', $workspace->id)
    ->where('user_id', $user->id)
    ->first();

// Properties
$member->role;              // 'owner', 'admin', 'member'
$member->team_id;           // Associated team
$member->custom_permissions; // Override permissions (JSON)
$member->joined_at;
$member->invited_by;

// Relationships
$member->user;
$member->team;
$member->inviter;

// Permission methods
$member->getEffectivePermissions(); // Team + custom permissions
$member->hasPermission('social.write');
$member->hasAnyPermission(['admin', 'owner']);
$member->hasAllPermissions(['social.read', 'social.write']);

// Role checks
$member->isOwner();
$member->isAdmin();
```

### Permission Resolution

Effective permissions are resolved in order:

1. **Role-based**: Owner role grants `*`, Admin role grants admin permissions
2. **Team permissions**: Permissions from assigned team
3. **Custom permissions**: If set, completely override team permissions

```php
public function getEffectivePermissions(): array
{
    // 1. Owner has all permissions
    if ($this->isOwner()) {
        return ['*'];
    }

    // 2. Custom permissions override team
    if (!empty($this->custom_permissions)) {
        return $this->custom_permissions;
    }

    // 3. Team permissions
    return $this->team?->permissions ?? [];
}
```

## Workspace Invitations

### Invite Users

```php
// Via Workspace model
$invitation = $workspace->invite(
    email: 'newuser@example.com',
    role: 'member',
    invitedBy: $currentUser,
    expiresInDays: 7
);

// Invitation sent via WorkspaceInvitationNotification
```

### Accept Invitation

```php
// Find and accept
$invitation = WorkspaceInvitation::findPendingByToken($token);

if ($invitation && $invitation->accept($user)) {
    // User added to workspace
}

// Or via Workspace static method
Workspace::acceptInvitation($token, $user);
```

### Invitation States

```php
$invitation->isPending();  // Not accepted, not expired
$invitation->isExpired();  // Past expires_at
$invitation->isAccepted(); // Has accepted_at
```

## Custom Teams

### Creating Custom Teams

```php
$team = $teamService->createTeam([
    'name' => 'Social Media Managers',
    'slug' => 'social-managers',
    'description' => 'Team for managing social media accounts',
    'permissions' => [
        'workspace.read',
        'social.read',
        'social.write',
        'social.delete',
        'analytics.read',
    ],
    'colour' => 'purple',
    'is_default' => false,
]);
```

### Making Team Default

```php
$teamService->updateTeam($team, ['is_default' => true]);
// Other teams automatically have is_default set to false
```

### Deleting Teams

```php
// Only non-system teams can be deleted
// Teams with members cannot be deleted

if ($team->is_system) {
    throw new \RuntimeException('Cannot delete system teams');
}

if ($teamService->countTeamMembers($team) > 0) {
    throw new \RuntimeException('Remove members first');
}

$teamService->deleteTeam($team);
```

## Seeding Default Teams

When creating a new workspace:

```php
$teamService->forWorkspace($workspace);
$teams = $teamService->seedDefaultTeams();

// Or ensure they exist (idempotent)
$teams = $teamService->ensureDefaultTeams();
```

### Migrating Existing Members

If migrating from role-based to team-based:

```php
$migrated = $teamService->migrateExistingMembers();
// Assigns members to teams based on their role:
// owner -> Owners team
// admin -> Admins team
// member -> Members team
```

## Best Practices

### Use Middleware for Route Protection

```php
Route::middleware(['auth', 'workspace.required', 'workspace.permission:social.write'])
    ->group(function () {
        Route::resource('posts', PostController::class);
    });
```

### Check Permissions in Controllers

```php
public function store(Request $request)
{
    $teamService = app(WorkspaceTeamService::class);
    $teamService->forWorkspace($request->attributes->get('workspace_model'));

    if (!$teamService->hasPermission($request->user(), 'social.write')) {
        abort(403, 'You do not have permission to create posts');
    }

    // ...
}
```

### Use Policies with Teams

```php
class PostPolicy
{
    public function create(User $user): bool
    {
        $teamService = app(WorkspaceTeamService::class);
        return $teamService->hasPermission($user, 'social.write');
    }

    public function delete(User $user, Post $post): bool
    {
        $teamService = app(WorkspaceTeamService::class);
        return $teamService->hasPermission($user, 'social.delete');
    }
}
```

### Permission Naming Conventions

Follow the pattern: `service.action`

- `service.read` - View resources
- `service.write` - Create/edit resources
- `service.delete` - Delete resources
- `workspace.manage_*` - Workspace admin actions

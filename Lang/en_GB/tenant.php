<?php

declare(strict_types=1);

/**
 * Tenant module translations (en_GB).
 *
 * Multi-tenant workspace management translations.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Workspace Home
    |--------------------------------------------------------------------------
    */
    'workspace' => [
        'welcome' => 'Welcome',
        'powered_by' => 'Powered by :name\'s creator toolkit. Manage, publish, and grow your online presence.',
        'manage_content' => 'Manage Content',
        'get_early_access' => 'Get early access',
        'view_content' => 'View Content',
        'latest_posts' => 'Latest Posts',
        'pages' => 'Pages',
        'read_more' => 'Read more',
        'untitled' => 'Untitled',
        'no_content' => [
            'title' => 'No content yet',
            'message' => 'This workspace doesn\'t have any published content.',
        ],
        'create_content' => 'Create Content',
        'part_of_toolkit' => 'Part of the :name Toolkit',
        'toolkit_description' => 'Access all creator services from one unified platform',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Deletion
    |--------------------------------------------------------------------------
    */
    'deletion' => [
        'invalid' => [
            'title' => 'Link Invalid or Expired',
            'message' => 'This deletion link is no longer valid. It may have been cancelled or already used.',
        ],
        'verify' => [
            'title' => 'Verify Your Identity',
            'description' => 'Enter your password to confirm immediate account deletion for :name',
            'password_label' => 'Password',
            'password_placeholder' => 'Enter your password',
            'submit' => 'Verify & Continue',
            'changed_mind' => 'Changed your mind?',
            'cancel_link' => 'Cancel deletion',
        ],
        'confirm' => [
            'title' => 'Final Confirmation',
            'warning' => 'This action is permanent and irreversible.',
            'will_delete' => 'The following will be permanently deleted:',
            'items' => [
                'profile' => 'Your profile and personal data',
                'workspaces' => 'All workspaces you own',
                'content' => 'All content, media, and settings',
                'social' => 'Social connections and scheduled posts',
            ],
            'cancel' => 'Cancel',
            'delete_forever' => 'Delete Forever',
        ],
        'deleting' => [
            'title' => 'Deleting Account',
            'messages' => [
                'social' => 'Disconnecting social accounts...',
                'posts' => 'Removing scheduled posts...',
                'media' => 'Deleting media files...',
                'workspaces' => 'Removing workspaces...',
                'personal' => 'Erasing personal data...',
                'final' => 'Finalizing deletion...',
            ],
        ],
        'goodbye' => [
            'title' => 'F.I.N.',
            'deleted' => 'Your account has been deleted.',
            'thanks' => 'Thank you for being part of our journey.',
        ],
        'cancelled' => [
            'title' => 'Deletion Cancelled',
            'message' => 'Your account deletion has been cancelled. Your account is safe and will remain active.',
            'go_to_profile' => 'Go to Profile',
        ],
        'cancel_invalid' => [
            'title' => 'Link Invalid',
            'message' => 'This cancellation link is no longer valid. The deletion may have already been cancelled or completed.',
        ],
        'processing' => 'Processing...',
        'return_home' => 'Return Home',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin - Workspace Manager
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'title' => 'Workspace Manager',
        'subtitle' => 'Manage workspaces and transfer resources',
        'hades_only' => 'Hades Only',
        'stats' => [
            'total' => 'Total Workspaces',
            'active' => 'Active',
            'inactive' => 'Inactive',
        ],
        'search_placeholder' => 'Search workspaces by name or slug...',
        'table' => [
            'workspace' => 'Workspace',
            'owner' => 'Owner',
            'bio' => 'Bio',
            'social' => 'Social',
            'analytics' => 'Analytics',
            'trust' => 'Trust',
            'notify' => 'Notify',
            'commerce' => 'Commerce',
            'status' => 'Status',
            'actions' => 'Actions',
            'no_owner' => 'No owner',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'empty' => 'No workspaces found matching your criteria.',
        ],
        'actions' => [
            'view_details' => 'View details',
            'edit' => 'Edit workspace',
            'change_owner' => 'Change owner',
            'transfer' => 'Transfer resources',
            'delete' => 'Delete workspace',
            'provision' => 'Provision new',
        ],
        'confirm_delete' => 'Are you sure you want to delete this workspace? This cannot be undone.',
        'edit_modal' => [
            'title' => 'Edit Workspace',
            'name' => 'Name',
            'name_placeholder' => 'Workspace name',
            'slug' => 'Slug',
            'slug_placeholder' => 'workspace-slug',
            'active' => 'Active',
            'cancel' => 'Cancel',
            'save' => 'Save Changes',
        ],
        'transfer_modal' => [
            'title' => 'Transfer Resources',
            'source' => 'Source',
            'target_workspace' => 'Target Workspace',
            'select_target' => 'Select target workspace...',
            'resources_label' => 'Resources to Transfer',
            'warning' => 'Warning: This will move all selected resource types from the source workspace to the target workspace. This action cannot be undone.',
            'cancel' => 'Cancel',
            'transfer' => 'Transfer Resources',
        ],
        'owner_modal' => [
            'title' => 'Change Workspace Owner',
            'workspace' => 'Workspace',
            'new_owner' => 'New Owner',
            'select_owner' => 'Select new owner...',
            'warning' => 'The current owner will be demoted to a member. If the new owner is not already a member, they will be added to the workspace.',
            'cancel' => 'Cancel',
            'change' => 'Change Owner',
        ],
        'resources_modal' => [
            'in' => 'in',
            'select_all' => 'Select All',
            'deselect_all' => 'Deselect All',
            'selected' => ':count selected',
            'no_resources' => 'No resources found.',
            'transfer_selected' => 'Transfer Selected',
            'select_workspace' => 'Select workspace...',
            'transfer_items' => 'Transfer :count Item|Transfer :count Items',
            'close' => 'Close',
        ],
        'provision_modal' => [
            'create' => 'Create :type',
            'workspace' => 'Workspace',
            'name' => 'Name',
            'name_placeholder' => 'Enter name...',
            'slug' => 'Slug',
            'slug_placeholder' => 'my-page',
            'url' => 'URL',
            'url_placeholder' => 'https://example.com',
            'cancel' => 'Cancel',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Alerts
    |--------------------------------------------------------------------------
    */
    'usage_alerts' => [
        'threshold' => [
            'warning' => 'Warning',
            'critical' => 'Critical',
            'limit_reached' => 'Limit Reached',
        ],
        'status' => [
            'ok' => 'OK',
            'approaching' => 'Approaching Limit',
            'at_limit' => 'At Limit',
        ],
        'labels' => [
            'used' => 'Used',
            'limit' => 'Limit',
            'remaining' => 'Remaining',
            'percentage' => 'Usage',
            'feature' => 'Feature',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Emails
    |--------------------------------------------------------------------------
    */
    'emails' => [
        'usage_alert' => [
            'warning' => [
                'subject' => ':feature usage at :percentage%',
                'heading' => 'Usage Warning',
                'body' => 'Your **:workspace** workspace is approaching its **:feature** limit.',
                'usage_line' => 'Current usage: :used of :limit (:percentage%)',
                'remaining_line' => 'Remaining: :remaining',
                'action_text' => 'Consider upgrading your plan to ensure uninterrupted service.',
            ],
            'critical' => [
                'subject' => 'Urgent: :feature usage at :percentage%',
                'heading' => 'Critical Usage Alert',
                'body' => 'Your **:workspace** workspace is almost at its **:feature** limit.',
                'usage_line' => 'Current usage: :used of :limit (:percentage%)',
                'remaining_line' => 'Only :remaining remaining',
                'action_text' => 'Upgrade now to avoid any service interruptions.',
            ],
            'limit_reached' => [
                'subject' => ':feature limit reached',
                'heading' => 'Limit Reached',
                'body' => 'Your **:workspace** workspace has reached its **:feature** limit.',
                'usage_line' => 'Usage: :used of :limit (100%)',
                'options_heading' => 'You will not be able to use this feature until:',
                'options' => [
                    'upgrade' => 'You upgrade to a higher plan',
                    'reset' => 'Your usage resets (if applicable)',
                    'reduce' => 'You reduce your current usage',
                ],
            ],
            'view_usage' => 'View Usage',
            'upgrade_plan' => 'Upgrade Plan',
            'help_text' => 'If you have questions about your plan, please contact our support team.',
        ],
        'deletion_requested' => [
            'subject' => 'Account Deletion Scheduled',
            'greeting' => 'Hi :name,',
            'scheduled' => 'Your :app account has been scheduled for permanent deletion.',
            'auto_delete' => 'Your account will be automatically deleted on :date (in :days days).',
            'will_delete' => 'What will be deleted:',
            'items' => [
                'profile' => 'Your profile and personal information',
                'workspaces' => 'All workspaces you own',
                'content' => 'All content, media, and settings',
                'social' => 'Social media connections and scheduled posts',
            ],
            'delete_now' => 'Want to delete immediately?',
            'delete_now_description' => 'Click the button below to delete your account right now:',
            'delete_button' => 'Delete Now',
            'changed_mind' => 'Changed your mind?',
            'changed_mind_description' => 'Click below to cancel the deletion and keep your account:',
            'cancel_button' => 'Cancel Deletion',
            'not_requested' => 'Did not request this?',
            'not_requested_description' => 'If you did not request account deletion, click the cancel button above immediately and change your password.',
        ],
        'boost_expired' => [
            'subject_single' => ':feature boost expired - :workspace',
            'subject_multiple' => ':count boosts expired - :workspace',
            'body_single' => 'A boost for **:feature** has expired in your **:workspace** workspace.',
            'body_multiple' => 'The following boosts have expired in your **:workspace** workspace:',
            'cycle_bound_note' => 'This was a cycle-bound boost that ended with your billing period.',
            'action_text' => 'You can purchase additional boosts or upgrade your plan to restore this capacity.',
            'boost_types' => [
                'unlimited' => 'Unlimited access',
                'enable' => 'Feature access',
                'add_limit' => '+:total capacity (:consumed used)',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing Cycles
    |--------------------------------------------------------------------------
    */
    'billing' => [
        'cycle_reset' => 'Your billing cycle has been reset.',
        'boosts_expired' => ':count boost(s) have expired.',
        'usage_reset' => 'Usage counters have been reset for the new billing period.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Common
    |--------------------------------------------------------------------------
    */
    'common' => [
        'na' => 'N/A',
        'none' => 'None',
        'unknown' => 'Unknown',
    ],

    /*
    |--------------------------------------------------------------------------
    | Errors
    |--------------------------------------------------------------------------
    */
    'errors' => [
        'hades_required' => 'Hades tier required for this feature.',
        'unauthenticated' => 'You must be logged in to access this resource.',
        'no_workspace' => 'No workspace context available.',
        'insufficient_permissions' => 'You do not have permission to perform this action.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin - Team Manager
    |--------------------------------------------------------------------------
    */
    'admin' => [
        // ... existing admin translations will be merged ...

        'team_manager' => [
            'title' => 'Workspace Teams',
            'subtitle' => 'Manage teams and role-based permissions for workspaces',

            'stats' => [
                'total_teams' => 'Total Teams',
                'total_members' => 'Total Members',
                'members_assigned' => 'Assigned to Teams',
            ],

            'search' => [
                'placeholder' => 'Search teams by name...',
            ],

            'filter' => [
                'all_workspaces' => 'All Workspaces',
            ],

            'columns' => [
                'team' => 'Team',
                'workspace' => 'Workspace',
                'members' => 'Members',
                'permissions' => 'Permissions',
                'actions' => 'Actions',
            ],

            'labels' => [
                'permissions' => 'permissions',
            ],

            'badges' => [
                'system' => 'System',
                'default' => 'Default',
            ],

            'actions' => [
                'create_team' => 'Create Team',
                'edit' => 'Edit',
                'delete' => 'Delete',
                'view_members' => 'View Members',
                'seed_defaults' => 'Seed Defaults',
                'migrate_members' => 'Migrate Members',
            ],

            'confirm' => [
                'delete_team' => 'Are you sure you want to delete this team? Members will be unassigned.',
            ],

            'empty_state' => [
                'title' => 'No teams found',
                'description' => 'Create teams to organise members and control permissions in your workspaces.',
            ],

            'modal' => [
                'title_create' => 'Create Team',
                'title_edit' => 'Edit Team',

                'fields' => [
                    'workspace' => 'Workspace',
                    'select_workspace' => 'Select workspace...',
                    'name' => 'Name',
                    'name_placeholder' => 'e.g. Editors',
                    'slug' => 'Slug',
                    'slug_placeholder' => 'e.g. editors',
                    'slug_description' => 'Leave blank to auto-generate from name.',
                    'description' => 'Description',
                    'colour' => 'Colour',
                    'is_default' => 'Default team for new members',
                    'permissions' => 'Permissions',
                ],

                'actions' => [
                    'cancel' => 'Cancel',
                    'create' => 'Create Team',
                    'update' => 'Update Team',
                ],
            ],

            'messages' => [
                'team_created' => 'Team created successfully.',
                'team_updated' => 'Team updated successfully.',
                'team_deleted' => 'Team deleted successfully.',
                'cannot_delete_system' => 'Cannot delete system teams.',
                'cannot_delete_has_members' => 'Cannot delete team with :count assigned member(s). Remove members first.',
                'defaults_seeded' => 'Default teams have been seeded successfully.',
                'members_migrated' => ':count member(s) have been migrated to teams.',
            ],
        ],

        'member_manager' => [
            'title' => 'Workspace Members',
            'subtitle' => 'Manage member team assignments and custom permissions',

            'stats' => [
                'total_members' => 'Total Members',
                'with_team' => 'Assigned to Team',
                'with_custom' => 'With Custom Permissions',
            ],

            'search' => [
                'placeholder' => 'Search members by name or email...',
            ],

            'filter' => [
                'all_workspaces' => 'All Workspaces',
                'all_teams' => 'All Teams',
            ],

            'columns' => [
                'member' => 'Member',
                'workspace' => 'Workspace',
                'team' => 'Team',
                'role' => 'Legacy Role',
                'permissions' => 'Custom',
                'actions' => 'Actions',
            ],

            'labels' => [
                'no_team' => 'No team',
                'inherited' => 'Inherited',
            ],

            'actions' => [
                'assign_team' => 'Assign to Team',
                'remove_from_team' => 'Remove from Team',
                'custom_permissions' => 'Custom Permissions',
                'clear_permissions' => 'Clear Custom Permissions',
            ],

            'confirm' => [
                'clear_permissions' => 'Are you sure you want to clear all custom permissions for this member?',
                'bulk_remove_team' => 'Are you sure you want to remove the selected members from their teams?',
                'bulk_clear_permissions' => 'Are you sure you want to clear custom permissions for all selected members?',
            ],

            'bulk' => [
                'selected' => ':count selected',
                'assign_team' => 'Assign Team',
                'remove_team' => 'Remove Team',
                'clear_permissions' => 'Clear Permissions',
                'clear' => 'Clear',
            ],

            'empty_state' => [
                'title' => 'No members found',
                'description' => 'No members match your current filter criteria.',
            ],

            'modal' => [
                'actions' => [
                    'cancel' => 'Cancel',
                    'save' => 'Save',
                    'assign' => 'Assign',
                ],
            ],

            'assign_modal' => [
                'title' => 'Assign to Team',
                'team' => 'Team',
                'no_team' => 'No team (remove assignment)',
            ],

            'permissions_modal' => [
                'title' => 'Custom Permissions',
                'team_permissions' => 'Team: :team',
                'description' => 'Custom permissions override the team permissions. Grant additional permissions or revoke specific ones.',
                'grant_label' => 'Grant Additional Permissions',
                'revoke_label' => 'Revoke Permissions',
            ],

            'bulk_assign_modal' => [
                'title' => 'Bulk Assign Team',
                'description' => 'Assign :count selected member(s) to a team.',
                'team' => 'Team',
                'no_team' => 'No team (remove assignment)',
            ],

            'messages' => [
                'team_assigned' => 'Member assigned to team successfully.',
                'removed_from_team' => 'Member removed from team successfully.',
                'permissions_updated' => 'Custom permissions updated successfully.',
                'permissions_cleared' => 'Custom permissions cleared successfully.',
                'no_members_selected' => 'No members selected.',
                'invalid_team' => 'Invalid team selected.',
                'bulk_team_assigned' => ':count member(s) assigned to team.',
                'bulk_removed_from_team' => ':count member(s) removed from team.',
                'bulk_permissions_cleared' => 'Custom permissions cleared for :count member(s).',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlement Webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'events' => [
            'limit_warning' => 'Limit Warning',
            'limit_reached' => 'Limit Reached',
            'package_changed' => 'Package Changed',
            'boost_activated' => 'Boost Activated',
            'boost_expired' => 'Boost Expired',
        ],
        'messages' => [
            'created' => 'Webhook created successfully.',
            'updated' => 'Webhook updated successfully.',
            'deleted' => 'Webhook deleted successfully.',
            'test_success' => 'Test webhook sent successfully.',
            'test_failed' => 'Test webhook failed.',
            'secret_regenerated' => 'Secret regenerated successfully.',
            'circuit_reset' => 'Webhook re-enabled and failure count reset.',
            'retry_success' => 'Delivery retried successfully.',
            'retry_failed' => 'Retry failed.',
        ],
        'labels' => [
            'name' => 'Name',
            'url' => 'URL',
            'events' => 'Events',
            'status' => 'Status',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'circuit_broken' => 'Circuit Broken',
            'secret' => 'Secret',
            'max_attempts' => 'Max Retry Attempts',
            'deliveries' => 'Deliveries',
        ],
        'descriptions' => [
            'url' => 'The endpoint that will receive webhook POST requests.',
            'max_attempts' => 'Number of times to retry failed deliveries (1-10).',
            'inactive' => 'Inactive webhooks will not receive any events.',
            'secret' => 'Use this secret to verify webhook signatures. The signature is sent in the X-Signature header and is a HMAC-SHA256 hash of the JSON payload.',
            'save_secret' => 'Save this secret now. It will not be shown again.',
        ],
    ],
];

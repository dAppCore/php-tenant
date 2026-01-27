<?php

namespace Core\Core\Tenant\Tests\Feature;

use Core\Mod\Analytics\Models\Website;
use Core\Mod\Social\Models\Account;
use Core\Core\Tenant\Models\User;
use Core\Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test workspace tenancy and isolation.
 */
class WorkspaceTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected User $userB;

    protected Workspace $workspaceA;

    protected Workspace $workspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two users with their own workspaces
        $this->userA = User::factory()->create(['name' => 'User A']);
        $this->userB = User::factory()->create(['name' => 'User B']);

        $this->workspaceA = Workspace::factory()->create(['name' => 'Workspace A']);
        $this->workspaceB = Workspace::factory()->create(['name' => 'Workspace B']);

        // Attach users to their workspaces
        $this->userA->hostWorkspaces()->attach($this->workspaceA, ['role' => 'owner', 'is_default' => true]);
        $this->userB->hostWorkspaces()->attach($this->workspaceB, ['role' => 'owner', 'is_default' => true]);
    }

    public function test_workspace_has_relationship_methods_for_all_services()
    {
        $workspace = Workspace::factory()->create();

        // Test that all relationship methods exist and return correct type
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->socialAccounts());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->socialPosts());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->analyticsSites());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->trustWidgets());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->notificationSites());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $workspace->pushCampaigns());
        // NOTE: bioPages relationship has been moved to Host UK app's Mod\Bio module
    }

    public function test_workspace_current_resolves_from_authenticated_user()
    {
        $this->actingAs($this->userA);

        $current = Workspace::current();

        $this->assertNotNull($current);
        $this->assertEquals($this->workspaceA->id, $current->id);
    }

    public function test_workspace_scoping_isolates_data_between_workspaces()
    {
        // Create social accounts for each workspace
        $accountA = Account::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'name' => 'Account A',
        ]);

        $accountB = Account::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Account B',
        ]);

        // User A should only see their workspace's account
        $this->actingAs($this->userA);
        $accountsForUserA = Account::ownedByCurrentWorkspace()->get();
        $this->assertCount(1, $accountsForUserA);
        $this->assertEquals('Account A', $accountsForUserA->first()->name);

        // User B should only see their workspace's account
        $this->actingAs($this->userB);
        $accountsForUserB = Account::ownedByCurrentWorkspace()->get();
        $this->assertCount(1, $accountsForUserB);
        $this->assertEquals('Account B', $accountsForUserB->first()->name);
    }

    public function test_workspace_relationships_return_correct_models()
    {
        // Create various resources for workspace A
        Account::factory()->create(['workspace_id' => $this->workspaceA->id]);
        Account::factory()->create(['workspace_id' => $this->workspaceA->id]);
        Website::factory()->create(['workspace_id' => $this->workspaceA->id]);

        // Create some for workspace B (should not appear)
        Account::factory()->create(['workspace_id' => $this->workspaceB->id]);

        $this->assertEquals(2, $this->workspaceA->socialAccounts()->count());
        $this->assertEquals(1, $this->workspaceA->analyticsSites()->count());

        // Workspace B should have different counts
        $this->assertEquals(1, $this->workspaceB->socialAccounts()->count());
    }

    public function test_models_with_workspace_trait_auto_assign_workspace_on_create()
    {
        $this->actingAs($this->userA);

        // When creating a model with BelongsToWorkspace trait,
        // it should auto-assign the current user's workspace
        $account = Account::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'provider' => 'twitter',
            'provider_id' => '12345',
            'name' => 'Test Account',
            'credentials' => collect(['access_token' => 'test-token']),
        ]);

        $this->assertEquals($this->workspaceA->id, $account->workspace_id);
    }

    public function test_workspace_scope_prevents_cross_workspace_access()
    {
        $accountA = Account::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'uuid' => 'uuid-a',
        ]);

        $accountB = Account::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'uuid' => 'uuid-b',
        ]);

        $this->actingAs($this->userA);

        // User A should be able to find their account
        $found = Account::ownedByCurrentWorkspace()->where('uuid', 'uuid-a')->first();
        $this->assertNotNull($found);

        // User A should NOT be able to find User B's account via scoped query
        $notFound = Account::ownedByCurrentWorkspace()->where('uuid', 'uuid-b')->first();
        $this->assertNull($notFound);

        // But should be able to find it if scope is explicitly bypassed
        $foundWithoutScope = Account::withoutGlobalScopes()->where('uuid', 'uuid-b')->first();
        $this->assertNotNull($foundWithoutScope);
    }

    public function test_belongs_to_workspace_method_checks_ownership()
    {
        $accountA = Account::factory()->create(['workspace_id' => $this->workspaceA->id]);
        $accountB = Account::factory()->create(['workspace_id' => $this->workspaceB->id]);

        $this->assertTrue($accountA->belongsToWorkspace($this->workspaceA));
        $this->assertFalse($accountA->belongsToWorkspace($this->workspaceB));

        $this->assertTrue($accountB->belongsToWorkspace($this->workspaceB));
        $this->assertFalse($accountB->belongsToWorkspace($this->workspaceA));
    }
}

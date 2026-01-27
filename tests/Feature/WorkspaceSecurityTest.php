<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Social\Models\Account;
use Core\Mod\Tenant\Concerns\BelongsToWorkspace;
use Core\Mod\Tenant\Exceptions\MissingWorkspaceContextException;
use Core\Mod\Tenant\Middleware\RequireWorkspaceContext;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Test workspace context security enforcement.
 *
 * These tests verify that the multi-tenant data isolation security measures
 * work correctly and prevent cross-tenant data access.
 */
class WorkspaceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable strict mode for tests
        WorkspaceScope::enableStrictMode();

        $this->user = User::factory()->create(['name' => 'Test User']);
        $this->workspace = Workspace::factory()->create(['name' => 'Test Workspace']);
        $this->user->hostWorkspaces()->attach($this->workspace, ['role' => 'owner', 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        // Reset to default state
        WorkspaceScope::enableStrictMode();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MissingWorkspaceContextException Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_exception_for_model_has_correct_message(): void
    {
        $exception = MissingWorkspaceContextException::forModel('Account', 'query');

        $this->assertStringContainsString('Account', $exception->getMessage());
        $this->assertStringContainsString('query', $exception->getMessage());
        $this->assertEquals('query', $exception->getOperation());
        $this->assertEquals('Account', $exception->getModel());
    }

    public function test_exception_for_create_has_correct_message(): void
    {
        $exception = MissingWorkspaceContextException::forCreate('Account');

        $this->assertStringContainsString('Account', $exception->getMessage());
        $this->assertStringContainsString('create', $exception->getMessage());
        $this->assertEquals('create', $exception->getOperation());
    }

    public function test_exception_for_scope_has_correct_message(): void
    {
        $exception = MissingWorkspaceContextException::forScope('Account');

        $this->assertStringContainsString('Account', $exception->getMessage());
        $this->assertStringContainsString('scope', $exception->getMessage());
        $this->assertEquals('scope', $exception->getOperation());
    }

    public function test_exception_renders_json_for_api_requests(): void
    {
        $exception = MissingWorkspaceContextException::forMiddleware();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = $exception->render($request);

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals('missing_workspace_context', $content['error']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkspaceScope Strict Mode Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_workspace_scope_throws_in_strict_mode_without_context(): void
    {
        WorkspaceScope::enableStrictMode();

        // Ensure no workspace context
        request()->attributes->remove('workspace_model');

        $this->expectException(MissingWorkspaceContextException::class);
        $this->expectExceptionMessage('scope');

        // This should throw because no workspace context is available
        Account::query()->get();
    }

    public function test_workspace_scope_works_with_valid_context(): void
    {
        $this->actingAs($this->user);

        // Create an account for this workspace
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // Set workspace context
        request()->attributes->set('workspace_model', $this->workspace);

        // Should not throw
        $accounts = Account::query()->get();

        $this->assertCount(1, $accounts);
    }

    public function test_workspace_scope_strict_mode_can_be_disabled(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');

        WorkspaceScope::disableStrictMode();

        // Should not throw, but return empty result
        $accounts = Account::query()->get();

        $this->assertCount(0, $accounts);

        // Re-enable for other tests
        WorkspaceScope::enableStrictMode();
    }

    public function test_without_strict_mode_callback_restores_state(): void
    {
        WorkspaceScope::enableStrictMode();
        $this->assertTrue(WorkspaceScope::isStrictModeEnabled());

        WorkspaceScope::withoutStrictMode(function () {
            $this->assertFalse(WorkspaceScope::isStrictModeEnabled());
        });

        $this->assertTrue(WorkspaceScope::isStrictModeEnabled());
    }

    public function test_for_workspace_macro_bypasses_strict_mode(): void
    {
        // Ensure no current workspace context
        request()->attributes->remove('workspace_model');

        // Create data
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // forWorkspace should work even without global context
        $accounts = Account::query()->forWorkspace($this->workspace)->get();

        $this->assertCount(1, $accounts);
    }

    public function test_across_workspaces_macro_bypasses_strict_mode(): void
    {
        // Ensure no current workspace context
        request()->attributes->remove('workspace_model');

        // Create data in multiple workspaces
        $workspace2 = Workspace::factory()->create();

        WorkspaceScope::withoutStrictMode(function () use ($workspace2) {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
            Account::factory()->create(['workspace_id' => $workspace2->id]);
        });

        // acrossWorkspaces should work without context
        $accounts = Account::query()->acrossWorkspaces()->get();

        $this->assertCount(2, $accounts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BelongsToWorkspace Trait Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_creating_model_without_workspace_throws_in_strict_mode(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');
        WorkspaceScope::enableStrictMode();

        $this->expectException(MissingWorkspaceContextException::class);
        $this->expectExceptionMessage('create');

        Account::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'provider' => 'twitter',
            'provider_id' => '12345',
            'name' => 'Test Account',
            'credentials' => collect(['access_token' => 'test-token']),
        ]);
    }

    public function test_creating_model_with_explicit_workspace_id_succeeds(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');

        // Should succeed because workspace_id is explicitly provided
        $account = Account::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'provider' => 'twitter',
            'provider_id' => '12345',
            'name' => 'Test Account',
            'credentials' => collect(['access_token' => 'test-token']),
        ]);

        $this->assertEquals($this->workspace->id, $account->workspace_id);
    }

    public function test_creating_model_with_workspace_context_auto_assigns(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        $account = Account::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'provider' => 'twitter',
            'provider_id' => '12345',
            'name' => 'Test Account',
            'credentials' => collect(['access_token' => 'test-token']),
        ]);

        $this->assertEquals($this->workspace->id, $account->workspace_id);
    }

    public function test_owned_by_current_workspace_throws_without_context(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');
        WorkspaceScope::enableStrictMode();

        $this->expectException(MissingWorkspaceContextException::class);

        Account::ownedByCurrentWorkspace()->get();
    }

    public function test_owned_by_current_workspace_cached_throws_without_context(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');
        WorkspaceScope::enableStrictMode();

        $this->expectException(MissingWorkspaceContextException::class);

        Account::ownedByCurrentWorkspaceCached();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RequireWorkspaceContext Middleware Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_middleware_throws_without_workspace_context(): void
    {
        $middleware = new RequireWorkspaceContext;
        $request = Request::create('/test', 'GET');

        $this->expectException(MissingWorkspaceContextException::class);

        $middleware->handle($request, fn () => response('OK'));
    }

    public function test_middleware_passes_with_workspace_model_attribute(): void
    {
        $middleware = new RequireWorkspaceContext;
        $request = Request::create('/test', 'GET');
        $request->attributes->set('workspace_model', $this->workspace);

        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_resolves_workspace_from_header(): void
    {
        $middleware = new RequireWorkspaceContext;
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Workspace-ID', (string) $this->workspace->id);

        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($this->workspace->id, $request->attributes->get('workspace_model')->id);
    }

    public function test_middleware_resolves_workspace_from_query(): void
    {
        $middleware = new RequireWorkspaceContext;
        $request = Request::create('/test?workspace='.$this->workspace->slug, 'GET');

        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_validates_user_access_when_requested(): void
    {
        $middleware = new RequireWorkspaceContext;

        // Create another workspace the user doesn't have access to
        $otherWorkspace = Workspace::factory()->create(['name' => 'Other Workspace']);

        $this->actingAs($this->user);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('workspace_model', $otherWorkspace);

        $this->expectException(MissingWorkspaceContextException::class);
        $this->expectExceptionMessage('do not have access');

        $middleware->handle($request, fn () => response('OK'), 'validate');
    }

    public function test_middleware_allows_access_to_user_workspace(): void
    {
        $middleware = new RequireWorkspaceContext;

        $this->actingAs($this->user);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('workspace_model', $this->workspace);

        $response = $middleware->handle($request, fn () => response('OK'), 'validate');

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-Tenant Isolation Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_query_other_workspace_data_with_scoped_query(): void
    {
        $workspace2 = Workspace::factory()->create(['name' => 'Workspace 2']);

        // Create accounts in both workspaces (bypass strict mode for setup)
        WorkspaceScope::withoutStrictMode(function () use ($workspace2) {
            Account::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Account 1']);
            Account::factory()->create(['workspace_id' => $workspace2->id, 'name' => 'Account 2']);
        });

        // Set context to workspace 1
        request()->attributes->set('workspace_model', $this->workspace);

        // Should only see workspace 1's accounts
        $accounts = Account::query()->get();
        $this->assertCount(1, $accounts);
        $this->assertEquals('Account 1', $accounts->first()->name);
    }

    public function test_model_belongs_to_workspace_check_works(): void
    {
        $workspace2 = Workspace::factory()->create();

        $account = null;
        WorkspaceScope::withoutStrictMode(function () use (&$account) {
            $account = Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        $this->assertTrue($account->belongsToWorkspace($this->workspace));
        $this->assertTrue($account->belongsToWorkspace($this->workspace->id));
        $this->assertFalse($account->belongsToWorkspace($workspace2));
        $this->assertFalse($account->belongsToWorkspace($workspace2->id));
    }

    public function test_model_belongs_to_current_workspace_check_works(): void
    {
        $workspace2 = Workspace::factory()->create();

        $account1 = null;
        $account2 = null;
        WorkspaceScope::withoutStrictMode(function () use (&$account1, &$account2, $workspace2) {
            $account1 = Account::factory()->create(['workspace_id' => $this->workspace->id]);
            $account2 = Account::factory()->create(['workspace_id' => $workspace2->id]);
        });

        // Set current workspace
        request()->attributes->set('workspace_model', $this->workspace);

        $this->assertTrue($account1->belongsToCurrentWorkspace());
        $this->assertFalse($account2->belongsToCurrentWorkspace());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Model Opt-Out Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_model_can_opt_out_of_strict_workspace_context(): void
    {
        // Create a test model class that opts out
        $model = new class extends Model
        {
            use BelongsToWorkspace;

            protected $table = 'test_models';

            protected bool $workspaceContextRequired = false;
        };

        // Ensure no workspace context
        request()->attributes->remove('workspace_model');
        WorkspaceScope::enableStrictMode();

        // Should not throw because model opted out
        $this->assertFalse($model->requiresWorkspaceContext());
    }
}

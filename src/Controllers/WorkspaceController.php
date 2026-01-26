<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Controllers;

use Core\Front\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mod\Api\Controllers\Concerns\HasApiResponses;
use Mod\Api\Controllers\Concerns\ResolvesWorkspace;
use Mod\Api\Resources\PaginatedCollection;
use Mod\Api\Resources\WorkspaceResource;
use Mod\Tenant\Models\User;
use Mod\Tenant\Models\Workspace;

/**
 * Workspace API controller.
 *
 * Provides CRUD operations for workspaces via REST API.
 * Supports both API key and session authentication.
 */
class WorkspaceController extends Controller
{
    use HasApiResponses;
    use ResolvesWorkspace;

    /**
     * List all workspaces the user has access to.
     *
     * GET /api/v1/workspaces
     */
    public function index(Request $request): PaginatedCollection|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        $query = $user->workspaces()
            ->withCount(['users', 'bioPages'])
            ->orderBy('user_workspace.is_default', 'desc')
            ->orderBy('workspaces.name', 'asc');

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('workspaces.name', 'like', '%'.$request->input('search').'%');
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $workspaces = $query->paginate($perPage);

        return new PaginatedCollection($workspaces, WorkspaceResource::class);
    }

    /**
     * Get the current workspace.
     *
     * GET /api/v1/workspaces/current
     */
    public function current(Request $request): WorkspaceResource|JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            return $this->noWorkspaceResponse();
        }

        $workspace->loadCount(['users', 'bioPages']);

        return new WorkspaceResource($workspace);
    }

    /**
     * Get a single workspace.
     *
     * GET /api/v1/workspaces/{workspace}
     */
    public function show(Request $request, Workspace $workspace): WorkspaceResource|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        // Verify user has access to workspace
        $hasAccess = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->exists();

        if (! $hasAccess) {
            return $this->notFoundResponse('Workspace');
        }

        $workspace->loadCount(['users', 'bioPages']);

        return new WorkspaceResource($workspace);
    }

    /**
     * Create a new workspace.
     *
     * POST /api/v1/workspaces
     */
    public function store(Request $request): WorkspaceResource|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:workspaces,slug',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'type' => 'nullable|string|in:personal,team,agency,custom',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']).'-'.\Illuminate\Support\Str::random(6);
        }

        // Set default domain
        $validated['domain'] = 'hub.host.uk.com';
        $validated['type'] = $validated['type'] ?? 'custom';

        $workspace = Workspace::create($validated);

        // Attach user as owner
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'is_default' => false,
        ]);

        $workspace->loadCount(['users', 'bioPages']);

        return new WorkspaceResource($workspace);
    }

    /**
     * Update a workspace.
     *
     * PUT /api/v1/workspaces/{workspace}
     */
    public function update(Request $request, Workspace $workspace): WorkspaceResource|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        // Verify user has owner/admin access
        $pivot = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()
            ?->pivot;

        if (! $pivot || ! in_array($pivot->role, ['owner', 'admin'], true)) {
            return $this->accessDeniedResponse('You do not have permission to update this workspace.');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:workspaces,slug,'.$workspace->id,
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
        ]);

        $workspace->update($validated);
        $workspace->loadCount(['users', 'bioPages']);

        return new WorkspaceResource($workspace);
    }

    /**
     * Delete a workspace.
     *
     * DELETE /api/v1/workspaces/{workspace}
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        // Verify user is the owner
        $pivot = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()
            ?->pivot;

        if (! $pivot || $pivot->role !== 'owner') {
            return $this->accessDeniedResponse('Only the workspace owner can delete a workspace.');
        }

        // Prevent deleting user's only workspace
        $workspaceCount = $user->workspaces()->count();
        if ($workspaceCount <= 1) {
            return response()->json([
                'error' => 'cannot_delete',
                'message' => 'You cannot delete your only workspace.',
            ], 422);
        }

        $workspace->delete();

        return response()->json(null, 204);
    }

    /**
     * Switch to a workspace (set as default).
     *
     * POST /api/v1/workspaces/{workspace}/switch
     */
    public function switch(Request $request, Workspace $workspace): WorkspaceResource|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->accessDeniedResponse('Authentication required.');
        }

        // Verify user has access
        $hasAccess = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->exists();

        if (! $hasAccess) {
            return $this->notFoundResponse('Workspace');
        }

        // Use a single transaction with optimised query:
        // Clear all defaults and set the new one in one operation using raw update
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $workspace) {
            // Clear all existing defaults for this user's hub workspaces
            \Illuminate\Support\Facades\DB::table('user_workspace')
                ->where('user_id', $user->id)
                ->whereIn('workspace_id', function ($query) {
                    $query->select('id')
                        ->from('workspaces')
                        ->where('domain', 'hub.host.uk.com');
                })
                ->update(['is_default' => false]);

            // Set the new default
            \Illuminate\Support\Facades\DB::table('user_workspace')
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->update(['is_default' => true]);
        });

        $workspace->loadCount(['users', 'bioPages']);

        return new WorkspaceResource($workspace);
    }
}

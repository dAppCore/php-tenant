<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exception thrown when a workspace-scoped operation is attempted without workspace context.
 *
 * This is a SECURITY exception - it prevents cross-tenant data access by failing fast
 * when workspace context is missing, rather than falling back to a default workspace.
 */
class MissingWorkspaceContextException extends Exception
{
    public function __construct(
        string $message = 'Workspace context is required for this operation.',
        public readonly ?string $operation = null,
        public readonly ?string $model = null,
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for a model operation.
     */
    public static function forModel(string $model, string $operation = 'query'): self
    {
        return new self(
            message: "Workspace context is required to {$operation} {$model}. No workspace is currently set.",
            operation: $operation,
            model: $model
        );
    }

    /**
     * Create exception for creating a model.
     */
    public static function forCreate(string $model): self
    {
        return new self(
            message: "Cannot create {$model} without workspace context. Ensure a workspace is set before creating workspace-scoped resources.",
            operation: 'create',
            model: $model
        );
    }

    /**
     * Create exception for query scope.
     */
    public static function forScope(string $model): self
    {
        return new self(
            message: "Cannot apply workspace scope to {$model} without workspace context. Use ->withoutGlobalScope(WorkspaceScope::class) if intentionally querying across workspaces.",
            operation: 'scope',
            model: $model
        );
    }

    /**
     * Create exception for middleware.
     */
    public static function forMiddleware(): self
    {
        return new self(
            message: 'This route requires workspace context. Ensure you are accessing through a valid workspace subdomain or have a workspace session.',
            operation: 'middleware'
        );
    }

    /**
     * Get the operation that failed.
     */
    public function getOperation(): ?string
    {
        return $this->operation;
    }

    /**
     * Get the model class that was involved.
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'missing_workspace_context',
                'operation' => $this->operation,
                'model' => $this->model,
            ], $this->getCode());
        }

        // For web requests, show a user-friendly error page
        if (view()->exists('errors.workspace-required')) {
            return response()->view('errors.workspace-required', [
                'message' => $this->getMessage(),
            ], $this->getCode());
        }

        return response($this->getMessage(), $this->getCode());
    }

    /**
     * Report the exception (for logging/monitoring).
     */
    public function report(): bool
    {
        // Log this as a potential security issue - workspace context was missing
        // where it should have been present
        logger()->warning('Missing workspace context', [
            'operation' => $this->operation,
            'model' => $this->model,
            'url' => request()->url(),
            'user_id' => auth()->id(),
        ]);

        // Return true to indicate we've handled reporting
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Exceptions;

use Exception;

/**
 * Exception thrown when an entitlement check fails.
 */
class EntitlementException extends Exception
{
    public function __construct(
        string $message = 'You have reached your limit for this feature.',
        public readonly ?string $featureCode = null,
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the feature code that was denied.
     */
    public function getFeatureCode(): ?string
    {
        return $this->featureCode;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'feature_code' => $this->featureCode,
            ], $this->getCode());
        }

        return redirect()->back()
            ->with('error', $this->getMessage());
    }
}

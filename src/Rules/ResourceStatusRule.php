<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Rules;

use Closure;
use Core\Mod\Social\Enums\ResourceStatus;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value is a valid ResourceStatus enum value.
 *
 * This ensures that status fields only accept ENABLED (1) or DISABLED (0)
 * as defined in the ResourceStatus enum.
 *
 * Used for validating status changes on social resources such as:
 * - Bio links
 * - Bio link blocks
 * - Social accounts
 * - Social templates
 * - Webhooks
 */
class ResourceStatusRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The attribute being validated
     * @param  mixed  $value  The value being validated
     * @param  Closure  $fail  Closure to call if validation fails
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, [ResourceStatus::DISABLED->value, ResourceStatus::ENABLED->value], true)) {
            $fail('The :attribute must be either enabled or disabled.');
        }
    }
}

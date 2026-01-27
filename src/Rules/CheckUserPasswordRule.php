<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Rules;

use Closure;
use Core\Core\Tenant\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * Validates that the provided value matches the user's current password.
 *
 * This is typically used for password confirmation flows where a user
 * must provide their current password before changing it or performing
 * sensitive operations.
 */
class CheckUserPasswordRule implements ValidationRule
{
    /**
     * Create a new rule instance.
     *
     * @param  User  $user  The user whose password should be checked
     * @param  string|null  $message  Optional custom error message
     */
    public function __construct(
        protected User $user,
        protected ?string $message = null
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The attribute being validated
     * @param  mixed  $value  The value being validated
     * @param  Closure  $fail  Closure to call if validation fails
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Hash::check($value, $this->user->password)) {
            $fail($this->message ?: 'The password is incorrect.');
        }
    }
}

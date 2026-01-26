@php
    $appName = config('core.app.name', __('core::core.brand.name'));
@endphp

<x-mail::message>
# {{ __('tenant::tenant.emails.deletion_requested.subject') }}

{{ __('tenant::tenant.emails.deletion_requested.greeting', ['name' => $user->name]) }}

{{ __('tenant::tenant.emails.deletion_requested.scheduled', ['app' => $appName]) }}

**{{ __('tenant::tenant.emails.deletion_requested.auto_delete', ['date' => $expiresAt->format('F j, Y \a\t g:i A'), 'days' => $daysRemaining]) }}**

**{{ __('tenant::tenant.emails.deletion_requested.will_delete') }}**
- {{ __('tenant::tenant.emails.deletion_requested.items.profile') }}
- {{ __('tenant::tenant.emails.deletion_requested.items.workspaces') }}
- {{ __('tenant::tenant.emails.deletion_requested.items.content') }}
- {{ __('tenant::tenant.emails.deletion_requested.items.social') }}

**{{ __('tenant::tenant.emails.deletion_requested.delete_now') }}**
{{ __('tenant::tenant.emails.deletion_requested.delete_now_description') }}

<x-mail::button :url="$confirmationUrl" color="error">
{{ __('tenant::tenant.emails.deletion_requested.delete_button') }}
</x-mail::button>

**{{ __('tenant::tenant.emails.deletion_requested.changed_mind') }}**
{{ __('tenant::tenant.emails.deletion_requested.changed_mind_description') }}

<x-mail::button :url="$cancelUrl" color="success">
{{ __('tenant::tenant.emails.deletion_requested.cancel_button') }}
</x-mail::button>

**{{ __('tenant::tenant.emails.deletion_requested.not_requested') }}**
{{ __('tenant::tenant.emails.deletion_requested.not_requested_description') }}

Thanks,<br>
{{ $appName }}

<x-mail::subcopy>
{{ __('tenant::tenant.emails.deletion_requested.delete_button') }}: {{ $confirmationUrl }}<br>
{{ __('tenant::tenant.emails.deletion_requested.cancel_button') }}: {{ $cancelUrl }}
</x-mail::subcopy>
</x-mail::message>

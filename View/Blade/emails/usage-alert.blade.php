@php
    $appName = config('core.app.name', __('core::core.brand.name'));
    $isLimit = $threshold === \Core\Mod\Tenant\Models\UsageAlertHistory::THRESHOLD_LIMIT;
    $isCritical = $threshold === \Core\Mod\Tenant\Models\UsageAlertHistory::THRESHOLD_CRITICAL;
@endphp

<x-mail::message>
@if($isLimit)
# {{ __('tenant::tenant.emails.usage_alert.limit_reached.heading') }}

{{ __('tenant::tenant.emails.usage_alert.limit_reached.body', ['workspace' => $workspaceName, 'feature' => $featureName]) }}

**{{ __('tenant::tenant.emails.usage_alert.limit_reached.usage_line', ['used' => $used, 'limit' => $limit]) }}**

**{{ __('tenant::tenant.emails.usage_alert.limit_reached.options_heading') }}**
- {{ __('tenant::tenant.emails.usage_alert.limit_reached.options.upgrade') }}
- {{ __('tenant::tenant.emails.usage_alert.limit_reached.options.reset') }}
- {{ __('tenant::tenant.emails.usage_alert.limit_reached.options.reduce') }}

<x-mail::button :url="$upgradeUrl" color="primary">
{{ __('tenant::tenant.emails.usage_alert.upgrade_plan') }}
</x-mail::button>

@elseif($isCritical)
# {{ __('tenant::tenant.emails.usage_alert.critical.heading') }}

{{ __('tenant::tenant.emails.usage_alert.critical.body', ['workspace' => $workspaceName, 'feature' => $featureName]) }}

**{{ __('tenant::tenant.emails.usage_alert.critical.usage_line', ['used' => $used, 'limit' => $limit, 'percentage' => $percentage]) }}**

**{{ __('tenant::tenant.emails.usage_alert.critical.remaining_line', ['remaining' => $remaining]) }}**

{{ __('tenant::tenant.emails.usage_alert.critical.action_text') }}

<x-mail::button :url="$upgradeUrl" color="primary">
{{ __('tenant::tenant.emails.usage_alert.upgrade_plan') }}
</x-mail::button>

@else
# {{ __('tenant::tenant.emails.usage_alert.warning.heading') }}

{{ __('tenant::tenant.emails.usage_alert.warning.body', ['workspace' => $workspaceName, 'feature' => $featureName]) }}

**{{ __('tenant::tenant.emails.usage_alert.warning.usage_line', ['used' => $used, 'limit' => $limit, 'percentage' => $percentage]) }}**

**{{ __('tenant::tenant.emails.usage_alert.warning.remaining_line', ['remaining' => $remaining]) }}**

{{ __('tenant::tenant.emails.usage_alert.warning.action_text') }}

<x-mail::button :url="$usageUrl" color="success">
{{ __('tenant::tenant.emails.usage_alert.view_usage') }}
</x-mail::button>

@endif

{{ __('tenant::tenant.emails.usage_alert.help_text') }}

Thanks,<br>
{{ $appName }}
</x-mail::message>

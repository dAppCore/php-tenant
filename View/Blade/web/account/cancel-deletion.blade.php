<div class="w-full max-w-md mx-auto px-6 text-center">
    @if($status === 'success')
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-900/30 flex items-center justify-center">
            <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.cancelled.title') }}</h1>
        <p class="text-gray-400 mb-8">{{ __('tenant::tenant.deletion.cancelled.message') }}</p>
        <a href="{{ route('hub.account') }}" class="inline-flex items-center px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white font-medium rounded-lg transition-colors">
            {{ __('tenant::tenant.deletion.cancelled.go_to_profile') }}
        </a>
    @elseif($status === 'invalid')
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gray-800 flex items-center justify-center">
            <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.cancel_invalid.title') }}</h1>
        <p class="text-gray-400 mb-8">{{ __('tenant::tenant.deletion.cancel_invalid.message') }}</p>
        <a href="/" class="inline-flex items-center px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white font-medium rounded-lg transition-colors">
            {{ __('tenant::tenant.deletion.return_home') }}
        </a>
    @else
        <div class="animate-spin w-12 h-12 mx-auto mb-6 border-4 border-violet-400 border-t-transparent rounded-full"></div>
        <p class="text-gray-400">{{ __('tenant::tenant.deletion.processing') }}</p>
    @endif
</div>

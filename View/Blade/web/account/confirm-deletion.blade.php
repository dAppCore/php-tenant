<div class="w-full max-w-md mx-auto px-6">
    {{-- Invalid/Expired Token --}}
    @if($step === 'invalid')
        <div class="text-center">
            <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-gray-800 flex items-center justify-center">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.invalid.title') }}</h1>
            <p class="text-gray-400 mb-8">{{ __('tenant::tenant.deletion.invalid.message') }}</p>
            <a href="/" class="inline-flex items-center px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white font-medium rounded-lg transition-colors">
                {{ __('tenant::tenant.deletion.return_home') }}
            </a>
        </div>
    @endif

    {{-- Step 1: Password Verification --}}
    @if($step === 'verify')
        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-red-900/30 flex items-center justify-center">
                <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.verify.title') }}</h1>
            <p class="text-gray-400">{{ __('tenant::tenant.deletion.verify.description', ['name' => $userName]) }}</p>
        </div>

        <form wire:submit="verifyPassword" class="space-y-6">
            <div>
                <label for="password" class="block text-sm font-medium text-gray-300 mb-2">{{ __('tenant::tenant.deletion.verify.password_label') }}</label>
                <input
                    type="password"
                    id="password"
                    wire:model="password"
                    class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
                    placeholder="{{ __('tenant::tenant.deletion.verify.password_placeholder') }}"
                    autofocus
                    required
                >
                @if($error)
                    <p class="mt-2 text-sm text-red-400">{{ $error }}</p>
                @endif
            </div>

            <button
                type="submit"
                class="w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors"
            >
                {{ __('tenant::tenant.deletion.verify.submit') }}
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            {{ __('tenant::tenant.deletion.verify.changed_mind') }} <a href="{{ route('account.delete.cancel', ['token' => $token]) }}" class="text-violet-400 hover:text-violet-300">{{ __('tenant::tenant.deletion.verify.cancel_link') }}</a>
        </p>
    @endif

    {{-- Step 2: Final Confirmation --}}
    @if($step === 'confirm')
        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-red-900/30 flex items-center justify-center animate-pulse">
                <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.confirm.title') }}</h1>
            <p class="text-gray-400 mb-4">{!! __('tenant::tenant.deletion.confirm.warning') !!}</p>
        </div>

        <div class="bg-red-900/20 border border-red-800/50 rounded-lg p-4 mb-6">
            <h3 class="text-red-400 font-medium mb-2">{{ __('tenant::tenant.deletion.confirm.will_delete') }}</h3>
            <ul class="text-gray-300 text-sm space-y-1">
                <li class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    {{ __('tenant::tenant.deletion.confirm.items.profile') }}
                </li>
                <li class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    {{ __('tenant::tenant.deletion.confirm.items.workspaces') }}
                </li>
                <li class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    {{ __('tenant::tenant.deletion.confirm.items.content') }}
                </li>
                <li class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    {{ __('tenant::tenant.deletion.confirm.items.social') }}
                </li>
            </ul>
        </div>

        <div class="flex gap-4">
            <a
                href="{{ route('account.delete.cancel', ['token' => $token]) }}"
                class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white font-medium rounded-lg transition-colors text-center"
            >
                {{ __('tenant::tenant.deletion.confirm.cancel') }}
            </a>
            <button
                wire:click="confirmDeletion"
                class="flex-1 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors"
            >
                {{ __('tenant::tenant.deletion.confirm.delete_forever') }}
            </button>
        </div>
    @endif

    {{-- Step 3: Deleting Animation --}}
    @if($step === 'deleting')
        <div
            x-data="{
                progress: 0,
                messages: [
                    '{{ __('tenant::tenant.deletion.deleting.messages.social') }}',
                    '{{ __('tenant::tenant.deletion.deleting.messages.posts') }}',
                    '{{ __('tenant::tenant.deletion.deleting.messages.media') }}',
                    '{{ __('tenant::tenant.deletion.deleting.messages.workspaces') }}',
                    '{{ __('tenant::tenant.deletion.deleting.messages.personal') }}',
                    '{{ __('tenant::tenant.deletion.deleting.messages.final') }}'
                ],
                currentMessage: 0
            }"
            x-init="
                let interval = setInterval(() => {
                    progress += 2;
                    if (progress >= 100) {
                        clearInterval(interval);
                        $wire.executeDelete();
                    }
                    currentMessage = Math.min(Math.floor(progress / 17), messages.length - 1);
                }, 100);
            "
            class="text-center"
        >
            <div class="w-20 h-20 mx-auto mb-8 relative">
                <svg class="w-20 h-20 transform -rotate-90" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="45" fill="none" stroke="#1e293b" stroke-width="8"/>
                    <circle
                        cx="50" cy="50" r="45"
                        fill="none"
                        stroke="#ef4444"
                        stroke-width="8"
                        stroke-linecap="round"
                        :stroke-dasharray="'283'"
                        :stroke-dashoffset="283 - (283 * progress / 100)"
                        class="transition-all duration-100"
                    />
                </svg>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="text-xl font-bold text-white" x-text="Math.round(progress) + '%'"></span>
                </div>
            </div>

            <h1 class="text-2xl font-bold text-white mb-2">{{ __('tenant::tenant.deletion.deleting.title') }}</h1>
            <p class="text-gray-400 h-6" x-text="messages[currentMessage]"></p>
        </div>
    @endif

    {{-- Step 4: Goodbye with Typewriter Effect --}}
    @if($step === 'goodbye')
        <div
            x-data="{
                text: '',
                fullText: '{{ __('tenant::tenant.deletion.goodbye.title') }}',
                index: 0,
                showCursor: true,
                showSubtext: false
            }"
            x-init="
                setTimeout(() => {
                    let typeInterval = setInterval(() => {
                        if (index < fullText.length) {
                            text += fullText[index];
                            index++;
                        } else {
                            clearInterval(typeInterval);
                            setTimeout(() => { showSubtext = true; }, 500);
                        }
                    }, 400);
                    setInterval(() => { showCursor = !showCursor; }, 530);
                }, 500);
            "
            class="text-center py-12"
        >
            <div class="mb-8">
                <span
                    class="text-7xl md:text-8xl font-mono font-bold text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-purple-500"
                    x-text="text"
                ></span>
                <span
                    class="text-7xl md:text-8xl font-mono font-bold text-violet-400"
                    :class="{ 'opacity-0': !showCursor }"
                >_</span>
            </div>

            <div
                x-show="showSubtext"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0 translate-y-4"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="space-y-4"
            >
                <p class="text-xl text-gray-400">{{ __('tenant::tenant.deletion.goodbye.deleted') }}</p>
                <p class="text-gray-500">{{ __('tenant::tenant.deletion.goodbye.thanks') }}</p>

                <a
                    href="/"
                    class="inline-flex items-center gap-2 mt-8 px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-medium rounded-lg transition-colors"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    {{ __('tenant::tenant.deletion.return_home') }}
                </a>
            </div>
        </div>
    @endif
</div>

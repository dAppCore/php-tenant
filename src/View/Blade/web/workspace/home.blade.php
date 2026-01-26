@php
    $appName = config('core.app.name', __('core::core.brand.name'));
@endphp

<div>
    <!-- Hero Section -->
    <section class="relative py-12 md:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center max-w-3xl mx-auto">
                <div class="mb-6">
                    <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-medium bg-{{ $workspace['color'] }}-500/10 text-{{ $workspace['color'] }}-400 border border-{{ $workspace['color'] }}-500/20">
                        <i class="fa-solid fa-{{ $workspace['icon'] }} mr-2"></i>
                        {{ $workspace['name'] }}
                    </span>
                </div>
                <h1 class="text-4xl md:text-5xl font-bold text-white mb-6">
                    {{ $workspace['description'] ?? __('tenant::tenant.workspace.welcome') }}
                </h1>
                <p class="text-lg text-slate-400 mb-8">
                    {{ __('tenant::tenant.workspace.powered_by', ['name' => $appName]) }}
                </p>
                <div class="flex flex-wrap justify-center gap-4">
                    @auth
                        <a href="https://hub.{{ config('app.base_domain', 'host.uk.com') }}/hub/content/{{ $workspace['slug'] }}/posts" class="btn text-white bg-{{ $workspace['color'] }}-500 hover:bg-{{ $workspace['color'] }}-600">
                            <i class="fa-solid fa-pen-to-square mr-2"></i>
                            {{ __('tenant::tenant.workspace.manage_content') }}
                        </a>
                    @else
                        <a href="https://hub.{{ config('app.base_domain', 'host.uk.com') }}/waitlist" class="btn text-white bg-{{ $workspace['color'] }}-500 hover:bg-{{ $workspace['color'] }}-600">
                            {{ __('tenant::tenant.workspace.get_early_access') }}
                        </a>
                    @endauth
                    <a href="#content" class="btn text-slate-300 bg-slate-800 hover:bg-slate-700">
                        <i class="fa-solid fa-arrow-down mr-2"></i>
                        {{ __('tenant::tenant.workspace.view_content') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Content Section -->
    <section id="content" class="py-12 md:py-20 border-t border-slate-800">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            @if($loading)
                <div class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-{{ $workspace['color'] }}-500"></div>
                </div>
            @else
                <!-- Posts Grid -->
                @if(!empty($content['posts']))
                    <div class="mb-12">
                        <h2 class="text-2xl font-bold text-white mb-6">{{ __('tenant::tenant.workspace.latest_posts') }}</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach($content['posts'] as $post)
                                <article class="bg-slate-800/50 rounded-xl overflow-hidden border border-slate-700/50 hover:border-{{ $workspace['color'] }}-500/30 transition">
                                    @if(!empty($post['featured_media_url']))
                                        <img src="{{ $post['featured_media_url'] }}" alt="" class="w-full h-48 object-cover">
                                    @else
                                        <div class="w-full h-48 bg-gradient-to-br from-{{ $workspace['color'] }}-500/20 to-{{ $workspace['color'] }}-600/10 flex items-center justify-center">
                                            <i class="fa-solid fa-{{ $workspace['icon'] }} text-4xl text-{{ $workspace['color'] }}-500/40"></i>
                                        </div>
                                    @endif
                                    <div class="p-5">
                                        <h3 class="text-lg font-semibold text-white mb-2 line-clamp-2">
                                            {!! $post['title']['rendered'] ?? __('tenant::tenant.workspace.untitled') !!}
                                        </h3>
                                        <p class="text-sm text-slate-400 line-clamp-3 mb-4">
                                            {!! strip_tags($post['excerpt']['rendered'] ?? '') !!}
                                        </p>
                                        <div class="flex items-center justify-between text-xs text-slate-500">
                                            <span>
                                                <i class="fa-solid fa-calendar mr-1"></i>
                                                {{ \Carbon\Carbon::parse($post['date'])->format('M d, Y') }}
                                            </span>
                                            <a href="{{ $post['link'] ?? '#' }}" class="text-{{ $workspace['color'] }}-400 hover:text-{{ $workspace['color'] }}-300">
                                                {{ __('tenant::tenant.workspace.read_more') }}<span class="sr-only">: {!! $post['title']['rendered'] ?? __('tenant::tenant.workspace.untitled') !!}</span> <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Pages -->
                @if(!empty($content['pages']))
                    <div>
                        <h2 class="text-2xl font-bold text-white mb-6">{{ __('tenant::tenant.workspace.pages') }}</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            @foreach($content['pages'] as $page)
                                <a href="{{ $page['link'] ?? '#' }}" class="flex items-center p-4 bg-slate-800/50 rounded-xl border border-slate-700/50 hover:border-{{ $workspace['color'] }}-500/30 transition group">
                                    <div class="w-10 h-10 rounded-lg bg-{{ $workspace['color'] }}-500/10 flex items-center justify-center mr-4">
                                        <i class="fa-solid fa-file-lines text-{{ $workspace['color'] }}-500"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="font-medium text-white group-hover:text-{{ $workspace['color'] }}-400 transition">
                                            {!! $page['title']['rendered'] ?? __('tenant::tenant.workspace.untitled') !!}
                                        </h3>
                                    </div>
                                    <i class="fa-solid fa-chevron-right text-slate-500 group-hover:text-{{ $workspace['color'] }}-400 transition"></i>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(empty($content['posts']) && empty($content['pages']))
                    <div class="text-center py-12">
                        <img src="/images/vi/vi_dashboard_empty.webp" alt="Vi with empty folder" class="w-32 h-32 mx-auto mb-4 drop-shadow-[0_8px_20px_rgba(139,92,246,0.25)]">
                        <h3 class="text-lg font-medium text-white mb-2">{{ __('tenant::tenant.workspace.no_content.title') }}</h3>
                        <p class="text-slate-400 mb-6">{{ __('tenant::tenant.workspace.no_content.message') }}</p>
                        @auth
                            <a href="https://hub.{{ config('app.base_domain', 'host.uk.com') }}/hub/content/{{ $workspace['slug'] }}/posts" class="btn text-white bg-{{ $workspace['color'] }}-500 hover:bg-{{ $workspace['color'] }}-600">
                                <i class="fa-solid fa-plus mr-2"></i>
                                {{ __('tenant::tenant.workspace.create_content') }}
                            </a>
                        @endauth
                    </div>
                @endif
            @endif
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-12 md:py-20 border-t border-slate-800 bg-slate-800/20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-2xl md:text-3xl font-bold text-white mb-4">{{ __('tenant::tenant.workspace.part_of_toolkit', ['name' => $appName]) }}</h2>
                <p class="text-slate-400">{{ __('tenant::tenant.workspace.toolkit_description') }}</p>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                @php
                    $services = [
                        ['name' => 'BioHost', 'icon' => 'link', 'color' => 'blue', 'slug' => 'link'],
                        ['name' => 'SocialHost', 'icon' => 'share-nodes', 'color' => 'green', 'slug' => 'social'],
                        ['name' => 'Analytics', 'icon' => 'chart-line', 'color' => 'yellow', 'slug' => 'analytics'],
                        ['name' => 'TrustHost', 'icon' => 'shield-check', 'color' => 'orange', 'slug' => 'trust'],
                        ['name' => 'NotifyHost', 'icon' => 'bell', 'color' => 'red', 'slug' => 'notify'],
                        ['name' => 'Hestia', 'icon' => 'globe', 'color' => 'violet', 'slug' => 'main'],
                    ];
                @endphp
                @foreach($services as $service)
                    <a href="https://{{ $service['slug'] === 'main' ? 'hestia' : $service['slug'] }}.{{ config('app.base_domain', 'host.uk.com') }}"
                       class="flex flex-col items-center p-4 rounded-xl {{ $workspace['slug'] === $service['slug'] ? 'bg-' . $service['color'] . '-500/20 border-' . $service['color'] . '-500/30' : 'bg-slate-800/50 border-slate-700/50 hover:border-slate-600' }} border transition">
                        <div class="w-12 h-12 rounded-lg bg-{{ $service['color'] }}-500/20 flex items-center justify-center mb-3">
                            <i class="fa-solid fa-{{ $service['icon'] }} text-xl text-{{ $service['color'] }}-500"></i>
                        </div>
                        <span class="text-sm font-medium text-white">{{ $service['name'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
</div>

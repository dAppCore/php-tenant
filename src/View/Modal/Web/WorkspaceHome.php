<?php

namespace Core\Mod\Tenant\View\Modal\Web;

use Core\Mod\Content\Services\ContentRender;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\WorkspaceService;
use Livewire\Component;

class WorkspaceHome extends Component
{
    public array $workspace = [];

    public array $content = [];

    public bool $loading = true;

    public function mount(?string $workspace = null): void
    {
        $workspaceService = app(WorkspaceService::class);

        // Get workspace from route param or request attributes (from subdomain middleware)
        $slug = $workspace ?? request()->attributes->get('workspace', 'main');

        $this->workspace = $workspaceService->get($slug) ?? $workspaceService->get('main');

        // Load workspace content from native content
        $this->loadContent();
    }

    protected function loadContent(): void
    {
        try {
            $workspaceModel = Workspace::where('slug', $this->workspace['slug'])->first();
            if (! $workspaceModel) {
                $this->content = ['posts' => [], 'pages' => []];
                $this->loading = false;

                return;
            }

            $render = app(ContentRender::class);
            $homepage = $render->getHomepage($workspaceModel);

            $this->content = [
                'posts' => $homepage['posts'] ?? [],
                'pages' => [], // Pages not included in homepage response
            ];
        } catch (\Exception $e) {
            $this->content = [
                'posts' => [],
                'pages' => [],
            ];
        }

        $this->loading = false;
    }

    public function render()
    {
        return view('tenant::web.workspace.home')
            ->layout('components.layouts.workspace', [
                'title' => $this->workspace['name'].' | Host UK',
                'workspace' => $this->workspace,
            ]);
    }
}

<?php

declare(strict_types=1);

namespace Core\Tenant\Tests\Feature;

use Core\Tenant\Enums\UserTier;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Regression coverage for bugs found in consuming applications and confirmed
 * against a live database (see the fleet modernisation report). Each test
 * fails on the pre-fix code and passes on the post-fix code.
 */
class KnownBugRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bug (a): UserFactory filled `account_type`, a column that does not
     * exist — the real column is `tier`. Every User::factory()->create()
     * threw "Unknown column 'account_type'".
     */
    public function test_user_factory_creates_a_user_without_error(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(UserTier::APOLLO, $user->tier);
    }

    public function test_user_factory_hades_state_sets_tier_column(): void
    {
        $user = User::factory()->hades()->create();

        $this->assertSame(UserTier::HADES, $user->tier);
    }

    public function test_user_factory_apollo_state_sets_tier_column(): void
    {
        $user = User::factory()->apollo()->create();

        $this->assertSame(UserTier::APOLLO, $user->tier);
    }

    /**
     * Bug (b): enableWpConnector()/generateWpConnectorSecret() wrote
     * wp_connector_secret through update(), but that column is excluded
     * from $fillable "for security", so Eloquent silently dropped it on
     * every call. The UI reported success and the secret was never set.
     */
    public function test_generate_wp_connector_secret_persists_the_secret(): void
    {
        $workspace = Workspace::factory()->create(['wp_connector_secret' => null]);

        $secret = $workspace->generateWpConnectorSecret();

        $this->assertNotEmpty($secret);
        $this->assertSame($secret, $workspace->fresh()->wp_connector_secret);
    }

    public function test_enable_wp_connector_persists_url_and_secret(): void
    {
        $workspace = Workspace::factory()->create([
            'wp_connector_enabled' => false,
            'wp_connector_url' => null,
            'wp_connector_secret' => null,
        ]);

        $workspace->enableWpConnector('https://example.com/');

        $fresh = $workspace->fresh();
        $this->assertTrue($fresh->wp_connector_enabled);
        $this->assertSame('https://example.com', $fresh->wp_connector_url);
        $this->assertNotEmpty($fresh->wp_connector_secret);
        $this->assertTrue($fresh->hasActiveWpConnector());
    }

    /**
     * Bug (c): getWpConnectorWebhookUrlAttribute() called
     * route('api.webhook.content'), a name nothing registers, which threw
     * RouteNotFoundException and 500'd any page rendering the WP Connector
     * section for a workspace with the connector enabled.
     */
    public function test_wp_connector_webhook_url_does_not_throw_when_route_is_missing(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->enableWpConnector('https://example.com');

        // Must not throw Illuminate\Routing\Exceptions\RouteNotFoundException.
        $url = $workspace->wp_connector_webhook_url;

        $this->assertNull($url);
    }

    /**
     * Bug (d): translations were registered from Lang/en_GB while the file
     * lives at Lang/en_GB/tenant.php — loadTranslationsFrom() appends
     * "/{locale}/{group}.php" to its hint path, so Laravel looked for
     * Lang/en_GB/en_GB/tenant.php and every `tenant::*` key resolved to
     * itself instead of its translated string.
     */
    public function test_tenant_translations_load_under_en_gb_locale(): void
    {
        App::setLocale('en_GB');

        $this->assertSame('Welcome', trans('tenant::tenant.workspace.welcome'));
    }
}

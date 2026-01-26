<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core tenant tables - users, workspaces, namespaces, entitlements.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Users
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('tier')->default('free');
            $table->timestamp('tier_expires_at')->nullable();
            $table->timestamps();
        });

        // 2. Password Reset Tokens
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // 3. Sessions
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // 4. Workspaces (the tenant boundary)
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->default('default');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // WP Connector fields
            $table->boolean('wp_connector_enabled')->default(false);
            $table->string('wp_connector_url')->nullable();
            $table->string('wp_connector_secret')->nullable();
            $table->timestamp('wp_connector_verified_at')->nullable();
            $table->timestamp('wp_connector_last_sync')->nullable();
            $table->json('wp_connector_config')->nullable();

            // Billing fields
            $table->string('stripe_customer_id')->nullable();
            $table->string('btcpay_customer_id')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_address_line1')->nullable();
            $table->string('billing_address_line2')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->boolean('tax_exempt')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        // 5. User Workspace Pivot
        Schema::create('user_workspace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id']);
        });

        // 6. Namespaces
        Schema::create('namespaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 128);
            $table->string('slug', 64);
            $table->string('description', 512)->nullable();
            $table->string('icon', 64)->default('folder');
            $table->string('color', 16)->default('zinc');

            // Polymorphic owner (User::class or Workspace::class)
            $table->morphs('owner');

            // Workspace context for billing aggregation
            $table->foreignId('workspace_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->json('settings')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_type', 'owner_id', 'slug']);
            $table->index(['workspace_id', 'is_active']);
            $table->index(['owner_type', 'owner_id', 'is_active']);
        });

        // 7. Entitlement Features
        Schema::create('entitlement_features', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->enum('type', ['boolean', 'limit', 'unlimited'])->default('boolean');
            $table->enum('reset_type', ['none', 'monthly', 'rolling'])->default('none');
            $table->integer('rolling_window_days')->nullable();
            $table->foreignId('parent_feature_id')->nullable()
                ->constrained('entitlement_features')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'sort_order']);
            $table->index('category');
        });

        // 8. Entitlement Packages
        Schema::create('entitlement_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_stackable')->default(true);
            $table->boolean('is_base_package')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->decimal('setup_fee', 10, 2)->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
            $table->string('btcpay_monthly_price_id')->nullable();
            $table->string('btcpay_yearly_price_id')->nullable();
            $table->string('blesta_package_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('blesta_package_id');
        });

        // 9. Entitlement Package Features
        Schema::create('entitlement_package_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('entitlement_packages')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('entitlement_features')->cascadeOnDelete();
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'feature_id']);
        });

        // 10. Entitlement Workspace Packages
        Schema::create('entitlement_workspace_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('entitlement_packages')->cascadeOnDelete();
            $table->enum('status', ['active', 'suspended', 'cancelled', 'expired'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('billing_cycle_anchor')->nullable();
            $table->string('blesta_service_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status'], 'ent_ws_pkg_ws_status_idx');
            $table->index(['expires_at', 'status'], 'ent_ws_pkg_expires_status_idx');
            $table->index('blesta_service_id');
        });

        // 11. Entitlement Namespace Packages
        Schema::create('entitlement_namespace_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('namespace_id')->constrained('namespaces')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('entitlement_packages')->cascadeOnDelete();
            $table->enum('status', ['active', 'suspended', 'cancelled', 'expired'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['namespace_id', 'status']);
            $table->index(['expires_at', 'status']);
        });

        // 12. Entitlement Boosts
        Schema::create('entitlement_boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->enum('boost_type', ['add_limit', 'enable', 'unlimited'])->default('add_limit');
            $table->enum('duration_type', ['cycle_bound', 'duration', 'permanent'])->default('cycle_bound');
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedBigInteger('consumed_quantity')->default(0);
            $table->enum('status', ['active', 'exhausted', 'expired', 'cancelled'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('blesta_addon_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'feature_code', 'status'], 'ent_boosts_ws_feat_status_idx');
            $table->index(['expires_at', 'status'], 'ent_boosts_expires_status_idx');
            $table->index('feature_code');
            $table->index('blesta_addon_id');
        });

        // 13. Entitlement Usage Records
        Schema::create('entitlement_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->unsignedBigInteger('quantity')->default(1);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['workspace_id', 'feature_code', 'recorded_at'], 'ent_usage_ws_feat_rec_idx');
            $table->index('recorded_at', 'ent_usage_recorded_idx');
            $table->index('feature_code');
        });

        // 14. Entitlement Logs
        Schema::create('entitlement_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'action'], 'ent_logs_ws_action_idx');
            $table->index(['entity_type', 'entity_id'], 'ent_logs_entity_idx');
            $table->index('created_at', 'ent_logs_created_idx');
        });

        // 15. User Two-Factor Auth
        Schema::create('user_two_factor_auth', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('user_two_factor_auth');
        Schema::dropIfExists('entitlement_logs');
        Schema::dropIfExists('entitlement_usage_records');
        Schema::dropIfExists('entitlement_boosts');
        Schema::dropIfExists('entitlement_namespace_packages');
        Schema::dropIfExists('entitlement_workspace_packages');
        Schema::dropIfExists('entitlement_package_features');
        Schema::dropIfExists('entitlement_packages');
        Schema::dropIfExists('entitlement_features');
        Schema::dropIfExists('namespaces');
        Schema::dropIfExists('user_workspace');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::enableForeignKeyConstraints();
    }
};

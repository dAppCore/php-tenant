<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entitlement webhooks for notifying external systems about usage events.
     */
    public function up(): void
    {
        Schema::create('entitlement_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('secret')->nullable(); // Encrypted HMAC secret
            $table->json('events'); // Array of subscribed event types
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('last_delivery_status')->nullable(); // pending, success, failed
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->json('metadata')->nullable(); // Additional configuration
            $table->timestamps();

            $table->index(['workspace_id', 'is_active'], 'ent_wh_ws_active_idx');
            $table->index('uuid');
        });

        Schema::create('entitlement_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('webhook_id')
                ->constrained('entitlement_webhooks')
                ->cascadeOnDelete();
            $table->string('event'); // Event name: limit_warning, limit_reached, etc.
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->string('status'); // pending, success, failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('resend_at')->nullable();
            $table->boolean('resent_manually')->default(false);
            $table->json('payload');
            $table->json('response')->nullable();
            $table->timestamp('created_at');

            $table->index(['webhook_id', 'status'], 'ent_wh_del_wh_status_idx');
            $table->index(['webhook_id', 'created_at'], 'ent_wh_del_wh_created_idx');
            $table->index('uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_webhook_deliveries');
        Schema::dropIfExists('entitlement_webhooks');
    }
};

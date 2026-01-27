<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track usage alert notifications to avoid spamming users.
     */
    public function up(): void
    {
        Schema::create('entitlement_usage_alert_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->unsignedTinyInteger('threshold'); // 80, 90, 100
            $table->timestamp('notified_at');
            $table->timestamp('resolved_at')->nullable(); // When usage dropped below threshold
            $table->json('metadata')->nullable(); // Snapshot of usage at notification time
            $table->timestamps();

            $table->index(['workspace_id', 'feature_code', 'threshold'], 'usage_alert_ws_feat_thresh_idx');
            $table->index(['workspace_id', 'resolved_at'], 'usage_alert_ws_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usage_alert_history');
    }
};

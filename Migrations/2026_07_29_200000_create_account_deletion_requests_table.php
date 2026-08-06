<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking for their account to be removed.
 *
 * The model, the job, the console command, the two Livewire components and the
 * mail template all shipped; the table did not. So /account/settings answered
 * "Base table or view not found" the moment it looked for a pending request —
 * which is every render, because the page has to know whether to show the
 * cancel banner.
 *
 * A request is a row that outlives the click: it is created, it may be
 * confirmed by email, and it is completed by a scheduled job seven days later
 * unless cancelled. Which of those has happened is recorded as four nullable
 * timestamps rather than a status column, because "when" answers "whether" and
 * a status would need all four anyway.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('account_deletion_requests')) {
            return;
        }

        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->id();

            // Cascades: a user who is gone has no pending request to process,
            // and a job that woke up to find one would have nothing to delete.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The link in the confirmation email. Unique because it is looked
            // up on its own, and indexed for the same reason.
            $table->string('token', 128)->unique();

            $table->text('reason')->nullable();

            // When the emailed link stops working. Separate from the seven-day
            // auto-delete: the link is short-lived, the request is not.
            $table->timestamp('expires_at')->nullable();

            // The three ends a request can come to. Null means "not yet", and
            // pendingAutoDelete() is exactly the rows where the last two are
            // both still null.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // The query every page load makes: this user's requests that have
            // neither completed nor been cancelled.
            // Named because the auto-generated name is 65 characters and
            // MariaDB stops at 64 — the unnamed form has never survived a
            // clean migrate on MariaDB, and it dies loudly enough to take
            // every later migration with it.
            $table->index(['user_id', 'completed_at', 'cancelled_at'], 'adr_user_completed_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};

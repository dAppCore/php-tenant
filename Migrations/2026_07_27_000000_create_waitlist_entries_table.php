<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the table the WaitlistEntry model has always expected.
 *
 * The package shipped Models/WaitlistEntry, a factory and a feature test for
 * this table without ever creating it, so any consumer that touched the model
 * got "Base table or view not found: waitlist_entries" — a 500 on every page
 * showing a waitlist position.
 *
 * Columns are taken from the model's $fillable and $casts and from the factory's
 * definition, which together are the only specification that existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded because consumers that hit the missing table may have created
        // it by hand to get moving; this must not fight them for it.
        if (Schema::hasTable('waitlist_entries')) {
            return;
        }

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();

            $table->string('email')->unique();
            $table->string('name')->nullable();

            // Where the signup came from, e.g. direct, twitter, referral.
            $table->string('source', 64)->nullable();

            // Which product the entry is waiting for. Queried with LIKE
            // 'dapp.fm:%' to count a position, so it carries an index.
            $table->string('interest', 128)->nullable()->index();

            $table->string('invite_code', 64)->nullable()->unique();

            // Null until invited, then until registered. scopePending() and
            // scopeInvited() filter on exactly these being null.
            $table->timestamp('invited_at')->nullable()->index();
            $table->timestamp('registered_at')->nullable();

            // Set when the entry converts to an account. Nullable on delete so
            // removing a user does not erase the waitlist history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->string('bonus_code', 64)->nullable();

            $table->timestamps();

            // Position is counted over entries of one interest in signup order.
            $table->index(['interest', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};

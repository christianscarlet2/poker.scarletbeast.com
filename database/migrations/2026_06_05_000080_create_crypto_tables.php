<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The crypto maw. Deposit addresses are HD-derived watch-only addresses unique
 * per user/intent. The scanner daemon watches them; on funding, chips are
 * credited and the coin is swept to the house's cold main wallet.
 */
return new class extends Migration {
    public function up(): void
    {
        // Per-intent deposit addresses derived from the house xpub (watch-only).
        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('currency', ['btc', 'eth']);
            $table->string('address')->unique();
            $table->unsignedBigInteger('derivation_index');
            $table->enum('status', ['watching', 'funded', 'swept', 'expired'])->default('watching');
            $table->string('last_txid')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['currency', 'status']);
            $table->unique(['currency', 'derivation_index']);
        });

        // Confirmed inbound funding events.
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deposit_address_id')->nullable()->constrained('deposit_addresses')->nullOnDelete();
            $table->enum('currency', ['btc', 'eth']);
            $table->string('txid');
            $table->decimal('amount_crypto', 36, 18);
            $table->decimal('rate_usd', 20, 8)->nullable();   // crypto->USD at credit time
            $table->unsignedBigInteger('amount_chips')->default(0);
            $table->unsignedInteger('confirmations')->default(0);
            $table->enum('status', ['seen', 'confirmed', 'credited', 'swept', 'orphaned'])->default('seen');
            $table->string('sweep_txid')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'txid']);
            $table->index('status');
        });

        // Outbound withdrawals to a user-specified address.
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('currency', ['btc', 'eth']);
            $table->string('to_address');
            $table->unsignedBigInteger('amount_chips');
            $table->decimal('amount_crypto', 36, 18);
            $table->decimal('rate_usd', 20, 8);
            $table->decimal('network_fee', 36, 18)->default(0);
            // pending -> approved -> broadcasting -> sent / rejected / failed
            $table->enum('status', ['pending', 'approved', 'broadcasting', 'sent', 'rejected', 'failed'])
                  ->default('pending');
            $table->string('txid')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('deposit_addresses');
    }
};

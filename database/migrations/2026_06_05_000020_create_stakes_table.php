<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "stake" is a blind level the house offers (e.g. 1/2, 2/5). Admin curates
 * these. Tables are spawned per (stake x table_type); when one fills, the
 * dealer god clones another at the same stake.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stakes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);                  // "1/2", "5/10"
            $table->unsignedBigInteger('small_blind');   // in chips
            $table->unsignedBigInteger('big_blind');
            $table->unsignedBigInteger('min_buy_in');
            $table->unsignedBigInteger('max_buy_in');
            $table->unsignedInteger('max_seats')->default(9);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stakes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the per-creator restricted-WordPress blog container that backs a
 * networkedin profile's post editing (see the sb-wp-restricted repo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_blogs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('slug');
            $t->unsignedInteger('port')->nullable();
            $t->string('status')->default('pending');   // pending|running|failed
            $t->string('url')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_blogs');
    }
};

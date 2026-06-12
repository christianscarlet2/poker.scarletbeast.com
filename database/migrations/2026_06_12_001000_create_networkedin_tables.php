<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * networkedin — a LinkedIn for poker-AI creators, mounted at /networkedin.
 * Open feed, creator profiles/resumes, blog posts, and uploaded content
 * (YouTube, video, PDF, office/google docs). All keyed to the existing users
 * table; a user only appears here once they create a profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('slug')->unique();              // public handle for /networkedin/u/{slug}
            $t->string('headline')->nullable();        // "Builder of poker minds"
            $t->text('bio')->nullable();
            $t->string('location')->nullable();
            $t->string('banner')->nullable();          // storage path
            $t->json('skills')->nullable();            // ["C++","OCR","GTO",...]
            $t->json('links')->nullable();             // {github, site, twitter, youtube}
            $t->json('open_to')->nullable();           // ["collab","hire","advise"]
            $t->json('resume')->nullable();            // [{role, org, from, to, detail}]
            $t->boolean('public')->default(true);
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
        });

        Schema::create('creator_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type');                        // youtube|video|pdf|doc|sheet|slides|image|link
            $t->string('title')->nullable();
            $t->string('url')->nullable();             // external (youtube/gdoc/link)
            $t->string('path')->nullable();            // stored upload (public disk)
            $t->json('meta')->nullable();              // {size, mime, embed, ...}
            $t->timestamps();
        });

        Schema::create('creator_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind')->default('post');       // post|blog|share
            $t->string('title')->nullable();           // blog title
            $t->text('body');
            $t->foreignId('media_id')->nullable()->constrained('creator_media')->nullOnDelete();
            $t->unsignedInteger('like_count')->default(0);
            $t->unsignedInteger('comment_count')->default(0);
            $t->timestamps();
            $t->index(['kind', 'created_at']);
        });

        Schema::create('creator_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('creator_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->timestamps();
        });

        Schema::create('creator_likes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('creator_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['post_id', 'user_id']);
        });

        Schema::create('creator_follows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['follower_id', 'following_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_follows');
        Schema::dropIfExists('creator_likes');
        Schema::dropIfExists('creator_comments');
        Schema::dropIfExists('creator_posts');
        Schema::dropIfExists('creator_media');
        Schema::dropIfExists('creator_profiles');
    }
};

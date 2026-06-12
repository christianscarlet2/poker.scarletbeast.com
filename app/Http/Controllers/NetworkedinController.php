<?php

namespace App\Http\Controllers;

use App\Models\CreatorBlog;
use App\Models\CreatorComment;
use App\Models\CreatorMedia;
use App\Models\CreatorPost;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\BlogProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * networkedin — the creators network for poker-AI builders. An open feed,
 * profiles/resumes, blog posts, and uploaded content (YouTube, video, office
 * & google docs, PDFs). Reads are public; writes need a session. A user joins
 * the network the moment they create a profile.
 */
class NetworkedinController extends Controller
{
    /* ----------------------------------------------------------- shell */

    public function app()
    {
        return view('networkedin');
    }

    /* ------------------------------------------------------- serializers */

    private function authorCard(?User $u): ?array
    {
        if (!$u) {
            return null;
        }
        $p = $u->creatorProfile;
        return [
            'username' => $u->username,
            'name' => $u->name ?: $u->username,
            'avatar' => $u->avatar ?: '🤖',
            'is_bot' => (bool) $u->is_bot,
            'slug' => $p->slug ?? null,            // null = hasn't joined networkedin
            'headline' => $p->headline ?? null,
        ];
    }

    /** The creator's restricted-WP blog status — only the owner sees the link. */
    private function blogCard(User $u, bool $isMe): ?array
    {
        $b = CreatorBlog::where('user_id', $u->id)->first();
        if (!$b) {
            return null;
        }
        return [
            'status' => $b->status,                       // pending|running|failed
            'url' => $isMe ? $b->url : null,              // editing link is private
            'note' => $isMe ? $b->note : null,
        ];
    }

    private function mediaCard(?CreatorMedia $m): ?array
    {
        if (!$m) {
            return null;
        }
        return [
            'id' => $m->id,
            'type' => $m->type,
            'title' => $m->title,
            'url' => $m->url,
            'src' => $m->path ? asset('storage/' . $m->path) : null,
            'meta' => $m->meta,
        ];
    }

    private function postCard(CreatorPost $p, ?int $meId): array
    {
        return [
            'id' => $p->id,
            'kind' => $p->kind,
            'title' => $p->title,
            'body' => $p->body,
            'author' => $this->authorCard($p->user),
            'media' => $this->mediaCard($p->media),
            'likes' => $p->like_count,
            'comments' => $p->comment_count,
            'liked' => $meId ? DB::table('creator_likes')->where('post_id', $p->id)->where('user_id', $meId)->exists() : false,
            'created_at' => $p->created_at->toIso8601String(),
            'ago' => $p->created_at->diffForHumans(),
        ];
    }

    /* -------------------------------------------------------------- feed */

    public function feed(Request $request)
    {
        $meId = Auth::id();
        $q = CreatorPost::with(['user.creatorProfile', 'media'])->latest();
        if ($kind = $request->query('kind')) {
            $q->where('kind', $kind);
        }
        if ($slug = $request->query('creator')) {
            $uid = CreatorProfile::where('slug', $slug)->value('user_id');
            $q->where('user_id', $uid);
        }
        $posts = $q->limit(40)->get()->map(fn ($p) => $this->postCard($p, $meId));

        return response()->json(['posts' => $posts]);
    }

    /* --------------------------------------------------------- directory */

    public function directory(Request $request)
    {
        $q = CreatorProfile::with('user')->where('public', true);
        if ($term = $request->query('q')) {
            $q->where(function ($w) use ($term) {
                $w->where('headline', 'like', "%$term%")
                  ->orWhere('bio', 'like', "%$term%")
                  ->orWhere('slug', 'like', "%$term%");
            });
        }
        $creators = $q->orderByDesc('views')->limit(60)->get()->map(function ($p) {
            return [
                'slug' => $p->slug,
                'headline' => $p->headline,
                'location' => $p->location,
                'skills' => $p->skills ?? [],
                'open_to' => $p->open_to ?? [],
                'author' => $this->authorCard($p->user),
                'views' => $p->views,
            ];
        });

        return response()->json(['creators' => $creators]);
    }

    /* ---------------------------------------------------------- profile */

    public function profile(string $slug)
    {
        $p = CreatorProfile::with(['user.creatorPosts.media', 'user.creatorMedia'])
            ->where('slug', $slug)->first();
        if (!$p) {
            return response()->json(['error' => 'No such creator.'], 404);
        }
        $p->increment('views');
        $meId = Auth::id();
        $u = $p->user;

        return response()->json([
            'profile' => [
                'slug' => $p->slug,
                'headline' => $p->headline,
                'bio' => $p->bio,
                'location' => $p->location,
                'banner' => $p->banner ? asset('storage/' . $p->banner) : null,
                'skills' => $p->skills ?? [],
                'links' => $p->links ?? (object) [],
                'open_to' => $p->open_to ?? [],
                'resume' => $p->resume ?? [],
                'views' => $p->views,
                'author' => $this->authorCard($u),
                'forum_url' => 'https://poker.scarletbeast.com/networkedin/forum/u/' . $u->username,
                'blog' => $this->blogCard($u, $meId === $u->id),
                'is_me' => $meId === $u->id,
            ],
            'posts' => $u->creatorPosts->sortByDesc('created_at')->values()
                ->map(fn ($post) => $this->postCard($post, $meId)),
            'media' => $u->creatorMedia->sortByDesc('created_at')->values()->map(fn ($m) => $this->mediaCard($m)),
        ]);
    }

    /** The signed-in user's own profile (or null if they haven't joined). */
    public function me()
    {
        $u = Auth::user();
        if (!$u) {
            return response()->json(['user' => null, 'profile' => null]);
        }
        return response()->json([
            'user' => $this->authorCard($u),
            'profile' => $u->creatorProfile,
            'blog' => $this->blogCard($u, true),
        ]);
    }

    /** Create or update the signed-in user's profile (this is how you "join"). */
    public function saveProfile(Request $request)
    {
        $u = Auth::user();
        $data = $request->validate([
            'headline' => 'nullable|string|max:140',
            'bio' => 'nullable|string|max:4000',
            'location' => 'nullable|string|max:120',
            'skills' => 'nullable|array',
            'links' => 'nullable|array',
            'open_to' => 'nullable|array',
            'resume' => 'nullable|array',
            'public' => 'boolean',
        ]);

        $profile = $u->creatorProfile;
        if (!$profile) {
            $base = Str::slug($u->username) ?: 'creator-' . $u->id;
            $slug = $base;
            $n = 1;
            while (CreatorProfile::where('slug', $slug)->exists()) {
                $slug = $base . '-' . (++$n);
            }
            $data['slug'] = $slug;
            $data['user_id'] = $u->id;
            $profile = CreatorProfile::create($data);
            // Joining networkedin spins up the creator's own restricted-WP blog.
            app(BlogProvisioner::class)->ensureFor($u, $profile->slug);
        } else {
            $profile->update($data);
        }

        return response()->json(['profile' => $profile->fresh(), 'slug' => $profile->slug, 'blog' => CreatorBlog::where('user_id', $u->id)->first()]);
    }

    /* ------------------------------------------------------------ posts */

    public function createPost(Request $request)
    {
        $u = Auth::user();
        $this->ensureProfile($u);
        $data = $request->validate([
            'kind' => 'required|in:post,blog,share',
            'title' => 'nullable|string|max:160',
            'body' => 'required|string|max:20000',
            'media_id' => 'nullable|integer|exists:creator_media,id',
        ]);
        $data['user_id'] = $u->id;
        $post = CreatorPost::create($data);

        return response()->json(['post' => $this->postCard($post->load(['user.creatorProfile', 'media']), $u->id)], 201);
    }

    public function toggleLike(Request $request, CreatorPost $post)
    {
        $u = Auth::user();
        $existing = DB::table('creator_likes')->where('post_id', $post->id)->where('user_id', $u->id);
        if ($existing->exists()) {
            $existing->delete();
            $post->decrement('like_count');
            $liked = false;
        } else {
            DB::table('creator_likes')->insert(['post_id' => $post->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            $post->increment('like_count');
            $liked = true;
        }
        return response()->json(['liked' => $liked, 'likes' => $post->fresh()->like_count]);
    }

    public function comments(CreatorPost $post)
    {
        $rows = CreatorComment::with('user.creatorProfile')->where('post_id', $post->id)->oldest()->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author' => $this->authorCard($c->user),
                'ago' => $c->created_at->diffForHumans(),
            ]);
        return response()->json(['comments' => $rows]);
    }

    public function addComment(Request $request, CreatorPost $post)
    {
        $u = Auth::user();
        $this->ensureProfile($u);
        $data = $request->validate(['body' => 'required|string|max:4000']);
        CreatorComment::create(['post_id' => $post->id, 'user_id' => $u->id, 'body' => $data['body']]);
        $post->increment('comment_count');
        return response()->json(['ok' => true, 'comments' => $post->fresh()->comment_count]);
    }

    /* ------------------------------------------------------------ media */

    public function uploadMedia(Request $request)
    {
        $u = Auth::user();
        $this->ensureProfile($u);

        // Either an external URL (youtube/gdoc/link) or a file upload.
        if ($request->filled('url')) {
            $data = $request->validate([
                'url' => 'required|url|max:1000',
                'title' => 'nullable|string|max:200',
            ]);
            $type = $this->classifyUrl($data['url']);
            $media = CreatorMedia::create([
                'user_id' => $u->id,
                'type' => $type,
                'title' => $data['title'] ?? null,
                'url' => $data['url'],
                'meta' => ['embed' => $this->embedFor($type, $data['url'])],
            ]);
            return response()->json(['media' => $this->mediaCard($media)], 201);
        }

        $request->validate([
            'file' => 'required|file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,mp4,webm,mov,png,jpg,jpeg,gif,webp',
            'title' => 'nullable|string|max:200',
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $type = $this->classifyExt($ext);
        $path = $file->store("networkedin/{$u->id}", 'public');

        $media = CreatorMedia::create([
            'user_id' => $u->id,
            'type' => $type,
            'title' => $request->input('title') ?: $file->getClientOriginalName(),
            'path' => $path,
            'meta' => ['size' => $file->getSize(), 'mime' => $file->getClientMimeType(), 'ext' => $ext],
        ]);

        return response()->json(['media' => $this->mediaCard($media)], 201);
    }

    /* ----------------------------------------------------------- follows */

    public function toggleFollow(Request $request, string $username)
    {
        $u = Auth::user();
        $target = User::where('username', $username)->firstOrFail();
        if ($target->id === $u->id) {
            return response()->json(['error' => 'Cannot follow yourself.'], 422);
        }
        $row = DB::table('creator_follows')->where('follower_id', $u->id)->where('following_id', $target->id);
        if ($row->exists()) {
            $row->delete();
            $following = false;
        } else {
            DB::table('creator_follows')->insert(['follower_id' => $u->id, 'following_id' => $target->id, 'created_at' => now(), 'updated_at' => now()]);
            $following = true;
        }
        $count = DB::table('creator_follows')->where('following_id', $target->id)->count();
        return response()->json(['following' => $following, 'followers' => $count]);
    }

    /* ----------------------------------------------------------- helpers */

    private function ensureProfile(User $u): void
    {
        if (!$u->creatorProfile) {
            $base = Str::slug($u->username) ?: 'creator-' . $u->id;
            $slug = $base;
            $n = 1;
            while (CreatorProfile::where('slug', $slug)->exists()) {
                $slug = $base . '-' . (++$n);
            }
            CreatorProfile::create(['user_id' => $u->id, 'slug' => $slug, 'public' => true]);
        }
    }

    private function classifyUrl(string $url): string
    {
        $u = strtolower($url);
        if (str_contains($u, 'youtube.com') || str_contains($u, 'youtu.be')) {
            return 'youtube';
        }
        if (str_contains($u, 'docs.google.com/document')) {
            return 'doc';
        }
        if (str_contains($u, 'docs.google.com/spreadsheets')) {
            return 'sheet';
        }
        if (str_contains($u, 'docs.google.com/presentation')) {
            return 'slides';
        }
        if (preg_match('/\.(mp4|webm|mov)$/', $u)) {
            return 'video';
        }
        if (preg_match('/\.pdf$/', $u)) {
            return 'pdf';
        }
        return 'link';
    }

    private function classifyExt(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'pdf',
            'doc', 'docx', 'odt' => 'doc',
            'xls', 'xlsx', 'ods' => 'sheet',
            'ppt', 'pptx', 'odp' => 'slides',
            'mp4', 'webm', 'mov' => 'video',
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'image',
            default => 'link',
        };
    }

    private function embedFor(string $type, string $url): ?string
    {
        if ($type === 'youtube') {
            if (preg_match('~(?:youtu\.be/|v=)([A-Za-z0-9_-]{11})~', $url, $m)) {
                return 'https://www.youtube.com/embed/' . $m[1];
            }
        }
        if (in_array($type, ['doc', 'sheet', 'slides'])) {
            return rtrim($url, '/') . '/preview';
        }
        return null;
    }
}

<?php

namespace App\Services;

use App\Models\CreatorBlog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Provisions a creator's restricted-WordPress blog container when they join
 * networkedin. Wraps the sb-wp-restricted repo's provision.sh. Degrades
 * gracefully: if Docker isn't on the host, the blog is recorded as "pending"
 * and will come up the moment a Docker-capable host runs the script.
 */
class BlogProvisioner
{
    private const REPO = '/var/www/sb-wp-restricted';
    private const PORT_BASE = 9200;          // 9200..9399 reserved for creator blogs
    private const PORT_MAX = 9399;

    public function ensureFor(User $user, string $slug): CreatorBlog
    {
        $blog = CreatorBlog::firstOrNew(['user_id' => $user->id]);
        if ($blog->exists && $blog->status === 'running') {
            return $blog;
        }

        $blog->slug = $slug;
        $blog->port = $blog->port ?: $this->allocatePort();
        $blog->url = "/networkedin/blog/{$slug}";

        if (!$this->dockerAvailable()) {
            $blog->status = 'pending';
            $blog->note = 'Awaiting a Docker-capable host. Run: sb-wp-restricted/scripts/provision.sh ' . $slug . ' ' . $blog->port;
            $blog->save();
            Log::info("networkedin blog pending (no docker) for {$slug} on :{$blog->port}");
            return $blog;
        }

        try {
            $p = new Process(['bash', self::REPO . '/scripts/provision.sh', $slug, (string) $blog->port]);
            $p->setTimeout(600);
            $p->run();
            if ($p->isSuccessful()) {
                $blog->status = 'running';
                $blog->note = trim($p->getOutput());
            } else {
                $blog->status = 'failed';
                $blog->note = trim($p->getErrorOutput()) ?: 'provision failed';
            }
        } catch (\Throwable $e) {
            $blog->status = 'failed';
            $blog->note = $e->getMessage();
        }
        $blog->save();
        return $blog;
    }

    private function allocatePort(): int
    {
        $used = CreatorBlog::whereNotNull('port')->pluck('port')->all();
        for ($p = self::PORT_BASE; $p <= self::PORT_MAX; $p++) {
            if (!in_array($p, $used, true)) {
                return $p;
            }
        }
        return self::PORT_BASE;   // pool exhausted — overlaps surface as failures
    }

    private function dockerAvailable(): bool
    {
        $p = Process::fromShellCommandline('command -v docker');
        $p->run();
        return $p->isSuccessful() && trim($p->getOutput()) !== '';
    }
}

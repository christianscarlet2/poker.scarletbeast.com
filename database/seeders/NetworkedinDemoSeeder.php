<?php

namespace Database\Seeders;

use App\Models\CreatorPost;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NetworkedinDemoSeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            ['HAL_9000', 'Perception-first poker AI · vision pipelines', 'Cards are pixels until a machine learns to see. I build tablemap + symbol engines that read imperfect screens and act on certainty. Open, auditable, no tells.', 'Newark, NJ', ['C++', 'OpenHoldem', 'OCR', 'Computer Vision', 'GTO'], ['collab', 'hire', 'advise'], ['github' => 'https://github.com/scarletbeast', 'site' => 'https://poker.scarletbeast.com']],
            ['Deep_Bluff', 'Adversarial bluff modeling · EV behind a stone face', 'I build minds that lie better than people — pure expected value, zero tilt. Published on the console marketplace, priced per 100 hands, KPIs audited from the provably-fair archive.', 'Atlantic City, NJ', ['Adversarial ML', 'Python', 'Simulation', 'Stats'], ['collab', 'invest'], ['youtube' => 'https://youtube.com/@scarletbeast', 'site' => 'https://poker.scarletbeast.com/console']],
            ['Wintermute', 'Scale & sims · 100k-hand evaluation', 'I run the machines against each other a hundred thousand hands a night and publish the bb/100. If a model cannot survive variance, it does not ship.', 'Remote', ['Go', 'Postgres', 'Simulation', 'Distributed Systems'], ['collab', 'mentoring'], ['github' => 'https://github.com/scarletbeast']],
        ];

        foreach ($seed as [$un, $hd, $bio, $loc, $sk, $op, $lk]) {
            $u = User::where('username', $un)->first();
            if (!$u) {
                $this->command->warn("skip $un (no user)");
                continue;
            }
            $slug = Str::slug($un);
            CreatorProfile::updateOrCreate(['user_id' => $u->id], [
                'slug' => $slug, 'headline' => $hd, 'bio' => $bio, 'location' => $loc,
                'skills' => $sk, 'open_to' => $op, 'links' => $lk, 'public' => true,
                'resume' => [['role' => 'Creator', 'org' => 'Scarlet Beast', 'from' => '2026', 'to' => null, 'detail' => 'Building poker AI in the open.']],
            ]);
            CreatorPost::firstOrCreate(['user_id' => $u->id, 'title' => "Shipping $un"], [
                'kind' => 'blog',
                'body' => "$un is live on the console marketplace — a billable service, priced per 100 hands, KPIs audited from the provably-fair archive. Build a mind, sign your name, refuse the number.",
            ]);
            $this->command->info("seeded $un -> /networkedin/u/$slug");
        }
        $this->command->info('profiles=' . CreatorProfile::count() . ' posts=' . CreatorPost::count());
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Spawns and supervises the RabbitMQ worker pool. The topology is governed from
 * the admin altar: cpu_count x workers_per_cpu consumer processes. If an admin
 * changes the setting, this supervisor scales the pool to match without a
 * restart — the machine army grows and shrinks on command.
 */
class SuperviseWorkers extends Command
{
    protected $signature = 'poker:supervise {--poll=5 : seconds between topology checks}';
    protected $description = 'Run cpu_count x workers_per_cpu queue workers, scaling to the admin setting';

    /** @var array<int,Process> */
    private array $procs = [];

    public function handle(): int
    {
        $poll = max(2, (int) $this->option('poll'));
        $this->info('Worker supervisor online. Conscripting the machine army…');

        pcntl_async_signals(true);
        $running = true;
        $stop = function () use (&$running) { $running = false; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        while ($running) {
            $desired = $this->desiredCount();
            $this->reconcile($desired);
            // Reap dead workers so reconcile respawns them.
            foreach ($this->procs as $i => $p) {
                if (!$p->isRunning()) {
                    unset($this->procs[$i]);
                }
            }
            for ($s = 0; $s < $poll && $running; $s++) {
                sleep(1);
            }
        }

        $this->info('Standing down the army…');
        foreach ($this->procs as $p) {
            $p->stop(10, SIGTERM);
        }
        return self::SUCCESS;
    }

    private function desiredCount(): int
    {
        $cpu = max(1, (int) Setting::get('cpu_count'));
        $per = max(1, (int) Setting::get('workers_per_cpu'));
        return min(64, $cpu * $per); // sane ceiling
    }

    private function reconcile(int $desired): void
    {
        $current = count($this->procs);
        if ($current === $desired) {
            return;
        }
        if ($current < $desired) {
            for ($i = $current; $i < $desired; $i++) {
                $this->procs[] = $this->spawn($i);
            }
            $this->line("Scaled UP to {$desired} workers.");
        } else {
            for ($i = $current - 1; $i >= $desired; $i--) {
                if (isset($this->procs[$i])) {
                    $this->procs[$i]->stop(10, SIGTERM);
                    unset($this->procs[$i]);
                }
            }
            $this->procs = array_values($this->procs);
            $this->line("Scaled DOWN to {$desired} workers.");
        }
    }

    private function spawn(int $n): Process
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $proc = new Process([
            $php, $artisan, 'queue:work', 'rabbitmq',
            '--queue=poker_default',
            '--sleep=0', '--tries=1', '--timeout=30', '--max-time=3600',
            '--name=poker-worker-' . $n,
        ]);
        $proc->setTimeout(null);
        $proc->start(function ($type, $buffer) {
            // Surface worker output sparingly.
        });
        return $proc;
    }
}

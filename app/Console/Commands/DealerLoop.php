<?php

namespace App\Console\Commands;

use App\Jobs\TableTickJob;
use App\Models\PokerTable;
use App\Models\Seat;
use App\Services\TableAutoScaler;
use Illuminate\Console\Command;

/**
 * The pit's pulse generator. A single lightweight producer that, every beat,
 * publishes a tick job per living felt onto RabbitMQ — where the worker pool
 * (cpu_count x workers_per_cpu) consumes them in parallel. Also runs the
 * auto-scaler so empty machine felts stay populated and full felts spawn kin.
 */
class DealerLoop extends Command
{
    protected $signature = 'poker:dealer
        {--interval=600 : ms between pulses}
        {--once : single pass}
        {--demo : demo mode — autoscaler packs felts with 4-6 bots}';
    protected $description = 'Drive all live tables by publishing tick jobs to the queue';

    public function handle(TableAutoScaler $scaler): int
    {
        if ($this->option('demo')) {
            \App\Services\DemoMode::enable();
            $this->info('DEMO MODE — the machines re-up forever and pack every felt.');
        }
        $interval = max(150, (int) $this->option('interval'));
        $this->info("Dealer loop online — pulse every {$interval}ms. The felt never sleeps.");
        $beat = 0;

        do {
            $beat++;
            // Every ~3s, let the auto-scaler tend the ecology of tables/bots.
            if ($beat % 5 === 1) {
                if (\App\Services\DemoMode::on()) {
                    \App\Services\DemoMode::heartbeat(); // lets HTTP know demo is live
                }
                try {
                    $scaler->run();
                } catch (\Throwable $e) {
                    $this->error('autoscale: ' . $e->getMessage());
                }
                // ...and the bracket god tend the tournaments: blind clock,
                // bust-outs, table balancing, payouts, scheduled starts.
                try {
                    app(\App\Services\TournamentManager::class)->tick();
                } catch (\Throwable $e) {
                    $this->error('tournament: ' . $e->getMessage());
                }
            }

            $tableIds = PokerTable::where('status', 'active')
                ->whereIn('id', Seat::where('status', '!=', 'empty')->distinct()->pluck('table_id'))
                ->pluck('id');

            foreach ($tableIds as $id) {
                TableTickJob::dispatch($id)->onQueue('poker_default');
            }

            if ($this->option('once')) {
                break;
            }
            usleep($interval * 1000);
        } while (true);

        return self::SUCCESS;
    }
}

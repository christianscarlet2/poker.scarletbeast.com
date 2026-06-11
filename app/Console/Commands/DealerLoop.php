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
    protected $signature = 'poker:dealer {--interval=600 : ms between pulses} {--once : single pass}';
    protected $description = 'Drive all live tables by publishing tick jobs to the queue';

    public function handle(TableAutoScaler $scaler): int
    {
        $interval = max(150, (int) $this->option('interval'));
        $this->info("Dealer loop online — pulse every {$interval}ms. The felt never sleeps.");
        $beat = 0;

        do {
            $beat++;
            // Every ~3s, let the auto-scaler tend the ecology of tables/bots.
            if ($beat % 5 === 1) {
                try {
                    $scaler->run();
                } catch (\Throwable $e) {
                    $this->error('autoscale: ' . $e->getMessage());
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

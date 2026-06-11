<?php

namespace App\Jobs;

use App\Models\PokerTable;
use App\Services\TableManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One pulse of a felt's life, processed off the RabbitMQ queue. Drives bot
 * decisions, the action clock, and the start of the next hand. Kept tiny and
 * idempotent — the row lock inside TableManager serialises concurrent workers.
 */
class TableTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public int $tableId)
    {
    }

    public function handle(TableManager $tm): void
    {
        $table = PokerTable::find($this->tableId);
        if (!$table || $table->status !== 'active') {
            return;
        }
        // Advance the felt as far as it will go in one pulse (bounded), so a
        // burst of folds resolves without waiting for the next dispatch.
        for ($i = 0; $i < 8; $i++) {
            if (!$tm->tick($table)) {
                break;
            }
        }
    }
}

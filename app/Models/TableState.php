<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableState extends Model
{
    protected $primaryKey = 'table_id';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'state' => 'array',
        'version' => 'integer',
        'act_deadline' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(PokerTable::class, 'table_id');
    }
}

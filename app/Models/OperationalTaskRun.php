<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalTaskRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'exit_code' => 'integer',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}

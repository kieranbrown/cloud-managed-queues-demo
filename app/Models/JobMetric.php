<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobMetric extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'float',
            'picked_up_at' => 'float',
            'completed_at' => 'float',
            'failed' => 'boolean',
        ];
    }
}

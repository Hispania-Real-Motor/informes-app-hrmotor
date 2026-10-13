<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSalesforceOrganicInterestDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data_date' => 'date',
        'interest_count' => 'integer',
        'source_interest_sync_run_id' => 'integer',
        'source_interest_cutoff_at' => 'datetime',
        'extracted_at' => 'datetime',
    ];
}

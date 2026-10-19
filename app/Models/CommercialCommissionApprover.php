<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialCommissionApprover extends Model
{
    protected $fillable = ['closure_scope', 'report_user_id', 'configured_at'];

    protected $casts = ['configured_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ReportUser::class, 'report_user_id');
    }
}

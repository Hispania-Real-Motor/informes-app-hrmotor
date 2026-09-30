<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportUserPasswordResetToken extends Model
{
    protected $fillable = [
        'report_user_id',
        'email_hash',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function reportUser(): BelongsTo
    {
        return $this->belongsTo(ReportUser::class);
    }
}

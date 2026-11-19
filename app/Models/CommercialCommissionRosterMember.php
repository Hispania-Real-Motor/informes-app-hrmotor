<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionRosterMember extends Model
{
    protected $table = 'store_manager_commission_roster_members';

    protected $fillable = [
        'month',
        'delegation_key',
        'delegation_name',
        'salesforce_user_id',
        'member_name',
        'coverage_from',
        'coverage_to',
        'source',
        'evidence_reference',
        'recorded_by',
        'history_verified',
    ];

    protected function casts(): array
    {
        return [
            'coverage_from' => 'immutable_datetime',
            'coverage_to' => 'immutable_datetime',
            'history_verified' => 'boolean',
        ];
    }
}

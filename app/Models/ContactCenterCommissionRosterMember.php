<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactCenterCommissionRosterMember extends Model
{
    protected $fillable = [
        'salesforce_user_id',
        'display_name',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_until' => 'immutable_date',
    ];
}

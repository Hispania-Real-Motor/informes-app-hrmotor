<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceUser extends Model
{
    protected $fillable = [
        'salesforce_id',
        'name',
        'email',
        'created_date',
        'profile_name',
        'user_delegation',
        'is_active',
        'commission_appraiser',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'is_active' => 'boolean',
        'commission_appraiser' => 'boolean',
        'raw_payload' => 'array',
    ];
}

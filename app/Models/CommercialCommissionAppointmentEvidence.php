<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionAppointmentEvidence extends Model
{
    protected $fillable = [
        'salesforce_interest_id',
        'appointment_setter_id',
        'appointment_setter_name',
        'appointment_capture_date',
        'salesforce_last_modified_at',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'appointment_capture_date' => 'date',
        'salesforce_last_modified_at' => 'datetime',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];
}

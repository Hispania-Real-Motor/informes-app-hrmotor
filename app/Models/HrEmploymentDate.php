<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrEmploymentDate extends Model
{
    protected $fillable = [
        'salesforce_user_id', 'corporate_email', 'effective_date', 'source_label',
        'source_reference', 'imported_by',
    ];

    protected $casts = ['effective_date' => 'date'];
}

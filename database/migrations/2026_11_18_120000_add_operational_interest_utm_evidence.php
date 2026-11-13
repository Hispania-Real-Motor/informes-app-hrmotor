<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_interest_operational_runs', 'utm_contract_version')) {
            Schema::table('salesforce_interest_operational_runs', function (Blueprint $table): void {
                $table->unsignedTinyInteger('utm_contract_version')->default(1);
            });
        }

        $fields = ['original_source', 'utm_campaign', 'utm_content', 'utm_id', 'utm_medium', 'utm_source', 'utm_term'];
        $missing = [];
        foreach ($fields as $field) {
            $column = $field.'_ciphertext';
            if (! Schema::hasColumn('salesforce_interest_operational_rows', $column)) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            Schema::table('salesforce_interest_operational_rows', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    $table->mediumText($column)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Completed operational runs and their protected observations are immutable.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employment_dates', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_user_id', 18)->nullable()->index();
            $table->string('corporate_email')->nullable()->index();
            $table->date('effective_date');
            $table->string('source_label', 120);
            $table->string('source_reference', 255);
            $table->foreignId('imported_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['salesforce_user_id', 'effective_date'], 'hr_employment_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employment_dates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'sf_interests_rot5_medium_date_id_idx';

    public function up(): void
    {
        Schema::table('salesforce_interests', function (Blueprint $table): void {
            $table->index(
                ['is_deleted', 'medium', 'functional_created_at', 'id'],
                self::INDEX_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_interests', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};

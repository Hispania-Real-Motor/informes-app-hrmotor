<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoCsvDependencyTest extends TestCase
{
    public function test_endpoints_principales_no_usan_modelos_csv(): void
    {
        $files = [
            app_path('Http/Controllers/Reports/Leads/LeadDashboardDataController.php'),
            app_path('Http/Controllers/Reports/ReservationsSales/ReservationsSalesDashboardDataController.php'),
            app_path('Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php'),
            app_path('Services/Reports/Leads/SalesforceInterestDashboardDatasetService.php'),
            app_path('Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php'),
            resource_path('js/reports/leads-dashboard.js'),
            resource_path('js/reports/reservations-sales-dashboard.js'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            $this->assertStringNotContainsString('LeadNormalized', $content, $file);
            $this->assertStringNotContainsString('LeadRaw', $content, $file);
            $this->assertStringNotContainsString('leads_normalized', $content, $file);
            $this->assertStringNotContainsString('leads_raw', $content, $file);
        }
    }

    public function test_snapshot_builder_usa_dataset_por_chunks(): void
    {
        $dataset = file_get_contents(app_path('Services/Reports/Leads/SalesforceInterestDashboardDatasetService.php'));
        $builder = file_get_contents(app_path('Services/Reports/MonthlyCommercial/MonthlyCommercialReportBuilder.php'));

        $this->assertStringContainsString('chunkById', $dataset);
        $this->assertStringContainsString('SalesforceInterestDashboardDatasetService', $builder);
        $this->assertStringNotContainsString('->with(\'activitySummary\')', $builder);
    }
}

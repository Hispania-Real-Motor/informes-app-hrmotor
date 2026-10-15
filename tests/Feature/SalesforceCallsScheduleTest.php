<?php

namespace Tests\Feature;

use Tests\Concerns\AssertsOperationalSchedule;
use Tests\TestCase;

class SalesforceCallsScheduleTest extends TestCase
{
    use AssertsOperationalSchedule;

    public function test_salesforce_calls_sync_is_scheduled_once_before_seo(): void
    {
        $this->assertOperationalSchedule();
    }
}

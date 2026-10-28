<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Container scoped: one immutable generation for the entire request/job/export. */
final class InterestGenerationReader
{
    private ?object $pinned = null;

    private ?Request $request = null;

    public function __construct(private readonly InterestGenerationStore $store) {}

    public function generation(): object
    {
        if ($this->request !== request()) {
            $this->request = request();
            $this->pinned = null;
        }
        if ($this->pinned !== null) {
            return $this->pinned;
        }
        $requested = request()->header('X-Interest-Generation', request()->query('interest_generation'));
        if ($requested !== null && (! is_string($requested) || ! ctype_digit($requested) || strlen($requested) > 18)) {
            throw new InterestDatasetUnavailable('Invalid generation token.');
        }
        $generation = $requested === null ? $this->store->published()
            : DB::table('salesforce_interest_generations')->find((int) $requested);
        if ($generation === null || $generation->status !== 'published') {
            throw new InterestDatasetUnavailable('Requested certified generation is unavailable.');
        }

        return $this->pinned = $generation;
    }

    public function query(string $alias = 'salesforce_interests'): Builder
    {
        return DB::query()->fromSub($this->store->rows($this->generation()->id), $alias);
    }

    public function eloquent(): \Illuminate\Database\Eloquent\Builder
    {
        return SalesforceInterest::query()->fromSub($this->store->rows($this->generation()->id), 'salesforce_interests');
    }

    public function f2(): ReportSyncRun
    {
        return ReportSyncRun::find($this->generation()->interest_run_id)
            ?? throw new InterestDatasetUnavailable('Certified F2 evidence is unavailable.');
    }

    public function f5(): SalesforceInterestActivityRun
    {
        return SalesforceInterestActivityRun::find($this->generation()->activity_run_id)
            ?? throw new InterestDatasetUnavailable('Certified F5 evidence is unavailable.');
    }
}

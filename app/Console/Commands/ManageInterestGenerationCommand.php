<?php

namespace App\Console\Commands;

use App\Services\Salesforce\InterestGenerationStore;
use App\Services\Salesforce\InterestExternalEvidenceService;
use App\Services\Salesforce\SalesforceInterestActivitySyncService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/** Operator-only CLI. No web route exposes generation construction or publication. */
class ManageInterestGenerationCommand extends Command
{
    protected $signature = 'salesforce:interest-generation
        {action : prepare-initial, manifest, external-evidence, or publish-initial}
        {--generation= : Candidate identifier}
        {--evidence= : Local reviewed JSON evidence file}
        {--reason= : Operational reason, 10 to 500 characters}
        {--allow-salesforce-read : Explicitly authorize isolated initial extraction}
        {--approve-publication : Explicitly approve the reviewed first publication}';

    protected $description = 'Build or publish an independently verified initial F2/F5 generation.';

    public function handle(InterestGenerationStore $store, InterestExternalEvidenceService $externalEvidence): int
    {
        try {
            $action = $this->argument('action');
            if ($action === 'prepare-initial') {
                $reason = trim((string) $this->option('reason'));
                if ($this->option('allow-salesforce-read') !== true || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                    throw new RuntimeException('Initial extraction requires explicit read authorization and an operational reason.');
                }
                $cutoff = CarbonImmutable::now('UTC')->subMinutes(2)->startOfMinute();
                $id = $store->begin($cutoff, true);
                try {
                    app(SalesforceInterestSyncService::class)->sync('full', $cutoff, 300, $id);
                    app(SalesforceInterestActivitySyncService::class)->sync($reason, $id);
                } catch (\Throwable $exception) {
                    $store->fail($id);
                    throw $exception;
                }
                $this->line(json_encode($store->manifest($id), JSON_THROW_ON_ERROR));
                $this->info('Candidate built; independent reconciliation and explicit publication are still required.');

                return self::SUCCESS;
            }
            $value = (string) $this->option('generation');
            if (ctype_digit($value) === false || strlen($value) > 18 || (int) $value < 1) {
                throw new RuntimeException('A valid generation identifier is required.');
            }
            $id = (int) $value;
            if ($action === 'external-evidence') {
                $reason = trim((string) $this->option('reason'));
                if ($this->option('allow-salesforce-read') !== true || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                    throw new RuntimeException('External evidence requires explicit read authorization and an operational reason.');
                }
                $this->line(json_encode($externalEvidence->collect($id), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($action === 'manifest') {
                $this->line(json_encode($store->manifest($id), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($action !== 'publish-initial' || $this->option('approve-publication') !== true
                || $store->generation($id)->base_generation_id !== null) {
                throw new RuntimeException('Initial publication requires explicit approval of an initial candidate.');
            }
            $path = (string) $this->option('evidence');
            if (is_file($path) === false || is_readable($path) === false || filesize($path) > 65536) {
                throw new RuntimeException('A bounded, readable reviewed evidence file is required.');
            }
            $evidence = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $store->certify($id, $evidence);
            $store->publish($id);
            $this->info('Reviewed initial generation published. Scheduler state was not changed.');

            return self::SUCCESS;
        } catch (\Throwable) {
            // Do not leak SQL bindings, Salesforce identifiers, payloads, or local file paths.
            $this->error('Generation operation rejected or failed. Review the protected candidate evidence and operator prerequisites.');

            return self::FAILURE;
        }
    }
}

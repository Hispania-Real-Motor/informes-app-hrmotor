<?php

namespace App\Services\Salesforce;

use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesforceClient
{
    public function __construct(
        private readonly SalesforceAuthService $authService,
    ) {}

    public function query(string $soql): array
    {
        return $this->runQuery($soql, false);
    }

    public function queryAll(string $soql): array
    {
        return $this->runQuery($soql, true);
    }

    private function runQuery(string $soql, bool $includeDeleted): array
    {
        $records = [];

        foreach ($this->queryPages($soql, $includeDeleted) as $page) {
            $records = array_merge($records, $page);
        }

        return $records;
    }

    /** @return Generator<int, array<int, array<string, mixed>>> */
    public function queryPages(string $soql, bool $includeDeleted = false): Generator
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendQuery($auth, $soql, $includeDeleted);

        if ($response->status() === 401) {
            $this->authService->clearToken();

            $auth = $this->authService->accessToken();
            $response = $this->sendQuery($auth, $soql, $includeDeleted);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                IntegrationErrorSanitizer::remoteFailure('Salesforce SOQL', $response->status(), $response->json())
            );
        }

        $page = $response->json() ?? [];
        $seen = [];
        $received = 0;
        $expected = $page['totalSize'] ?? null;
        if (! is_int($expected) || $expected < 0) {
            throw new RuntimeException('Salesforce query total size is missing or invalid.');
        }

        while (true) {
            if (! is_array($page) || ! is_array($page['records'] ?? null)
                || ! is_bool($page['done'] ?? null)) {
                throw new RuntimeException('Salesforce query page is incomplete.');
            }
            $received += count($page['records']);
            yield $page['records'];

            if ($page['done']) {
                if ($expected !== null && (! is_int($expected) || $received !== $expected)) {
                    throw new RuntimeException('Salesforce query coverage is incomplete.');
                }
                break;
            }

            $nextRecordsUrl = $page['nextRecordsUrl'] ?? null;
            if (! is_string($nextRecordsUrl)
                || ! preg_match('#^/services/data/v[0-9]+\.[0-9]+/query(?:All)?/[A-Za-z0-9_-]+$#', $nextRecordsUrl)
                || isset($seen[$nextRecordsUrl])) {
                throw new RuntimeException('Salesforce query continuation is missing or invalid.');
            }
            $seen[$nextRecordsUrl] = true;
            $response = Http::withToken($auth['access_token'])
                ->timeout((int) config('salesforce.timeout', 120))
                ->acceptJson()
                ->get(rtrim($auth['instance_url'], '/').$nextRecordsUrl);

            if ($response->status() === 401) {
                $this->authService->clearToken();
                $auth = $this->authService->accessToken();
                $response = Http::withToken($auth['access_token'])
                    ->timeout((int) config('salesforce.timeout', 120))
                    ->acceptJson()
                    ->get(rtrim($auth['instance_url'], '/').$nextRecordsUrl);
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    IntegrationErrorSanitizer::remoteFailure('Salesforce SOQL paginación', $response->status(), $response->json())
                );
            }

            $page = $response->json() ?? [];
        }
    }

    /** Read-only deletion log. Coverage validation belongs to the replication caller. */
    public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $object)) {
            throw new RuntimeException('Invalid deletion-log object.');
        }
        $auth = $this->authService->accessToken();
        $request = fn (array $credentials): Response => Http::withToken($credentials['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))->acceptJson()
            ->get($this->sObjectUrl($credentials, $object).'/deleted/', [
                'start' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'end' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $response = $request($auth);
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $response = $request($this->authService->accessToken());
        }
        if (! $response->successful() || ! is_array($response->json('deletedRecords'))
            || ! is_string($response->json('earliestDateAvailable')) || ! is_string($response->json('latestDateCovered'))) {
            throw new RuntimeException('Salesforce deletion log is unavailable or incomplete.');
        }

        return $response->json();
    }

    public function create(string $object, array $fields): string
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendCreate($auth, $object, $fields);
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $auth = $this->authService->accessToken();
            $response = $this->sendCreate($auth, $object, $fields);
        }
        if (! $response->successful() || blank($response->json('id'))) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure('Salesforce create '.$object, $response->status(), $response->json()));
        }

        return (string) $response->json('id');
    }

    public function update(string $object, string $id, array $fields): void
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendUpdate($auth, $object, $id, $fields);
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $auth = $this->authService->accessToken();
            $response = $this->sendUpdate($auth, $object, $id, $fields);
        }
        if (! $response->successful()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure('Salesforce update '.$object, $response->status(), $response->json()));
        }
    }

    public function describe(string $object): array
    {
        $auth = $this->authService->accessToken();
        $response = Http::withToken($auth['access_token'])->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()->get($this->sObjectUrl($auth, $object).'/describe');
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $auth = $this->authService->accessToken();
            $response = Http::withToken($auth['access_token'])->timeout((int) config('salesforce.timeout', 120))
                ->acceptJson()->get($this->sObjectUrl($auth, $object).'/describe');
        }
        if (! $response->successful()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure('Salesforce describe '.$object, $response->status(), $response->json()));
        }

        return $response->json() ?? [];
    }

    private function sendCreate(array $auth, string $object, array $fields): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->post($this->sObjectUrl($auth, $object), $fields);
    }

    private function sendUpdate(array $auth, string $object, string $id, array $fields): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->patch($this->sObjectUrl($auth, $object).'/'.$id, $fields);
    }

    private function sObjectUrl(array $auth, string $object): string
    {
        return rtrim($auth['instance_url'], '/').'/services/data/'.config('salesforce.api_version').'/sobjects/'.$object;
    }

    private function sendQuery(array $auth, string $soql, bool $includeDeleted = false): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->get($this->queryUrl($auth, $includeDeleted), [
                'q' => $soql,
            ]);
    }

    private function queryUrl(array $auth, bool $includeDeleted = false): string
    {
        return rtrim($auth['instance_url'], '/')
            .'/services/data/'
            .config('salesforce.api_version')
            .($includeDeleted ? '/queryAll' : '/query');
    }
}

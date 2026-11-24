<?php

namespace App\Console\Commands;

use App\Models\ReportUser;
use App\Services\Reports\CommercialCommissions\StoreManagerTeamRosterEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportStoreManagerCommissionRoster extends Command
{
    protected $signature = 'commissions:import-store-manager-roster
        {file : CSV UTF-8 con la evidencia historica del equipo}
        {--dry-run : Valida todas las filas sin persistir}
        {--apply : Persiste la evidencia validada de forma atomica}
        {--imported-by= : Actor o servicio que ejecuta la importacion}';

    protected $description = 'Valida e importa evidencia historica auditable del equipo de jefes de tienda';

    public function handle(StoreManagerTeamRosterEvidenceService $evidence): int
    {
        try {
            $rows = $this->readCsv((string) $this->argument('file'));
            $validated = [];
            $keys = [];
            foreach ($rows as $index => $row) {
                try {
                    $item = $evidence->validate($row);
                    $key = implode('|', [
                        $item['month'],
                        $item['delegation_key'],
                        $item['salesforce_user_id'],
                        $item['coverage_from']->toIso8601String(),
                    ]);
                    if (isset($keys[$key])) {
                        throw ValidationException::withMessages(['roster' => ['Fila duplicada para la misma adscripcion y cobertura.']]);
                    }
                    $keys[$key] = true;
                    $validated[] = $item;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors()['roster'] ?? ['Evidencia de roster no valida.'] as $error) {
                        $this->error('Fila '.($index + 2).': '.$error);
                    }
                }
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (count($validated) !== count($rows)) {
            $this->error('Import cancelado: ninguna fila ha sido persistida.');

            return self::FAILURE;
        }

        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('No se pueden combinar --dry-run y --apply.');

            return self::FAILURE;
        }

        try {
            $evidence->assertNoConflictingCoverage($validated);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['roster'] ?? ['Evidencia de roster incompatible.'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Validacion correcta: '.count($validated).' filas validas; no se ha escrito nada.');

            return self::SUCCESS;
        }

        try {
            $importedBy = $this->resolveImporter((string) $this->option('imported-by'));
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['imported_by'] ?? ['El actor de importación no está autorizado.'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($validated, $evidence, $importedBy): void {
                foreach ($validated as $row) {
                    if ($evidence->hasDefinitiveDelegationClosure($row['month'])) {
                        throw new RuntimeException('No se puede modificar el roster de un periodo de Delegaciones definitivo: '.$row['month'].'.');
                    }
                    $evidence->record($row, $importedBy);
                }
            });
        } catch (RuntimeException|ValidationException $exception) {
            $messages = $exception instanceof ValidationException
                ? ($exception->errors()['roster'] ?? ['No se pudo completar la importacion.'])
                : [$exception->getMessage()];
            foreach ($messages as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info('Import completado: '.count($validated).' filas de roster registradas.');

        return self::SUCCESS;
    }

    private function resolveImporter(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^report-user:(\d+)$/', $value, $matches)) {
            throw ValidationException::withMessages([
                'imported_by' => ['--imported-by debe identificar un ReportUser activo mediante report-user:<id>; no se acepta texto libre.'],
            ]);
        }

        $id = (int) $matches[1];
        $authorized = ReportUser::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->whereIn('role', [
                ReportUser::ROLE_ADMIN,
                ReportUser::ROLE_DIRECTOR,
                ReportUser::ROLE_COMMISSION_AUDITOR,
            ])
            ->exists();
        if (! $authorized) {
            throw ValidationException::withMessages([
                'imported_by' => ['El ReportUser indicado no existe, está inactivo o no tiene un rol autorizado para importar evidencia.'],
            ]);
        }

        return 'report-user:'.$id;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = @fopen(base_path($path), 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se puede leer el CSV indicado.');
        }

        $headers = fgetcsv($handle);
        $required = [
            'month', 'delegation_name', 'salesforce_user_id', 'member_name',
            'coverage_from', 'coverage_to', 'source', 'evidence_reference',
            'recorded_by', 'history_verified',
        ];
        if (! is_array($headers) || $headers !== $required) {
            fclose($handle);
            throw new RuntimeException('El CSV no contiene exactamente las cabeceras requeridas.');
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($headers) !== count($values)) {
                fclose($handle);
                throw new RuntimeException('El CSV contiene una fila con un numero de columnas invalido.');
            }
            $rows[] = array_combine($headers, array_map('trim', $values));
        }
        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('El CSV no contiene evidencia de roster.');
        }

        return $rows;
    }
}

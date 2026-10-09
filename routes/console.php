<?php

use App\Services\Operations\OperationalAlertService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$monitor = static function (Event $event, string $identifier, string $label): Event {
    return $event
        ->onFailure(static function () use ($identifier, $label): void {
            app(OperationalAlertService::class)->open(
                type: 'scheduled_task_failure',
                severity: 'high',
                source: 'scheduler',
                technicalIdentifier: $identifier,
                message: 'La tarea programada '.$label.' ha finalizado con error.',
            );
        })
        ->onSuccess(static function () use ($identifier, $label): void {
            app(OperationalAlertService::class)->resolve(
                type: 'scheduled_task_failure',
                source: 'scheduler',
                technicalIdentifier: $identifier,
                resolution: 'La tarea programada '.$label.' ha vuelto a finalizar correctamente.',
            );
        });
};

$monitor(
    Schedule::command('reports:prune-transversal-data')
        ->dailyAt('00:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(30),
    'reports-prune-transversal-data',
    'retención transversal',
);

$monitor(
    Schedule::command('stock:sync-daily --sales-days=14 --logistics-days=30')
        ->dailyAt('03:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'stock-sync-daily',
    'sincronización diaria de Stock',
);

$monitor(
    Schedule::command('salesforce:sync-delegation-managers --from=2026-07-01')
        ->dailyAt('04:15')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(60),
    'salesforce-sync-delegation-managers',
    'sincronización de responsables de delegación',
);

// The query also includes old Leads modified in this window, so a short
// incremental window refreshes status/owner/type/portal changes and deletions.
$monitor(
    Schedule::command('salesforce:sync-monthly-commercial --days=2')
        ->everyFifteenMinutes()
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(30),
    'salesforce-sync-monthly-commercial',
    'sincronización comercial incremental',
);

// ROT-1 mantiene F2 y F5 en una única secuencia read-only. La cadencia horaria
// es suficiente para el volumen certificado de F5 y evita competir cada 15
// minutos con el pipeline comercial legacy, que conserva otros consumidores.
$monitor(
    Schedule::command('salesforce:sync-interest-reporting')
        ->hourlyAt(5)
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(30),
    'salesforce-sync-interest-reporting',
    'sincronización Interest para informes',
);

// Ventana movil: no requiere editar fechas cada dia. El comando calcula
// [ahora - 120 dias, ahora) e incluye registros antiguos modificados.
$monitor(
    Schedule::command('salesforce:sync-tasaciones --days=120')
        ->dailyAt('01:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(180),
    'salesforce-sync-tasaciones',
    'sincronización de tasaciones',
);

// La inversión se sincroniza antes de la atribución Interest-centric.
$monitor(
    Schedule::command('campaigns:sync-meta --days=120')
        ->dailyAt('01:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(180),
    'campaigns-sync-meta',
    'sincronización de Meta Ads',
);

$monitor(
    Schedule::command('campaigns:sync-google --days=120')
        ->dailyAt('01:45')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(180),
    'campaigns-sync-google',
    'sincronización de Google Ads',
);

$monitor(
    Schedule::command('campaigns:build-attribution --days=120')
        ->dailyAt('08:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(240),
    'campaigns-build-attribution',
    'construcción de atribución de campañas',
);

$monitor(
    Schedule::command('reports:refresh-campaigns --days=120 --store')
        ->dailyAt('08:15')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'reports-refresh-campaigns',
    'refresco del informe de campañas',
);

// Se programa a las 07:10, después de Stock, antes del snapshot directo y
// del bloque de atribución de campañas (08:00). También queda fuera
// del bloque SEO (05:15-06:30). El sync mensual de Leads no escribe Opportunities.
// LastModifiedDate solo descubre registros antiguos modificados; las fechas
// funcionales proceden de los hitos y de OpportunityHistory.
$monitor(
    Schedule::command('salesforce:sync-opportunities --days=2 --modified')
        ->dailyAt('07:10')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(180),
    'salesforce-sync-opportunities',
    'sincronización incremental de Opportunities e historial de Stage',
);

// ROT-3 consume esta evidencia read-only. Se ejecuta después del sync ordinario
// de Opportunities; si este termina excepcionalmente tarde, el contrato de
// cutoff del informe impide usar un snapshot directo obsoleto.
$monitor(
    Schedule::command('salesforce:sync-opportunity-interest-direct --reason="Snapshot diario ROT-3 posterior al sync de Opportunities"')
        ->dailyAt('07:35')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(180),
    'salesforce-sync-opportunity-interest-direct',
    'snapshot directo Opportunity–Interest para ROT-3',
);

$monitor(
    Schedule::command('salesforce:sync-calls --days=7')
        ->dailyAt('04:45')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(60),
    'salesforce-sync-calls',
    'sincronización de llamadas Salesforce',
);

$monitor(
    Schedule::command('seo:sync-search-console --days=120')
        ->dailyAt('05:15')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-sync-search-console',
    'sincronizacion SEO de Search Console',
);

$monitor(
    Schedule::command('seo:sync-salesforce-organic --days=120')
        ->dailyAt('05:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-sync-salesforce-organic',
    'sincronizacion SEO de Leads organicos Salesforce',
);

$monitor(
    Schedule::command('seo:sync-ga4-organic --days=120')
        ->dailyAt('05:45')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-sync-ga4-organic',
    'sincronizacion SEO de Conversiones web organicas GA4',
);

$monitor(
    Schedule::command('seo:sync-technical-health')
        ->dailyAt('06:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-sync-technical-health',
    'comprobacion de salud tecnica SEO',
);

$monitor(
    Schedule::command('seo:build-analytical-snapshots')
        ->dailyAt('06:15')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-build-analytical-snapshots',
    'construcción de snapshots analíticos SEO',
);

$monitor(
    Schedule::command('seo:evaluate-analytical-snapshots')
        ->dailyAt('06:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(120),
    'seo-evaluate-analytical-snapshots',
    'evaluación analítica SEO',
);

$monitor(
    Schedule::command('seo:send-executive-daily-email')
        ->dailyAt('08:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping(30),
    'seo-send-executive-daily-email',
    'envío diario del resumen ejecutivo SEO',
);

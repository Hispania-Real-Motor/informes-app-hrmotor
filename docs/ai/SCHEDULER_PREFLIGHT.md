# Preflight operacional del scheduler

Base revisada: `fd7f6f9c8df9b8b14bf55bf0ab26de83e5d3034e` (`origin/main`).
Zona horaria del catálogo: `Europe/Madrid`.

Este documento es un procedimiento de lectura y decisión. No activa tareas, no
lee Salesforce por sí mismo y no publica generaciones.

## 1. Arquitectura y estado de activación

El único scheduler permitido es `php artisan schedule:run`. Laravel registra
los wrappers `operations:run` desde `routes/console.php`; cada wrapper reclama
una reserva persistente y el catálogo aplica flags y `OPERATIONS_SCHEDULED_TASK_KEYS`.
No debe existir un cron por informe.

Estado inicial recomendado:

```env
CAMPAIGN_CURRENT_STATUS_ENABLED=false
SALESFORCE_INTEREST_MONTHLY_CURRENT_SYNC_ENABLED=false
OPERATIONS_INTEREST_OPERATIONAL_CURRENT_SYNC_ENABLED=false
```

El resto de tareas usa la configuración normal del catálogo. La allowlist tiene
tres estados distintos: variable ausente (`null`) = catálogo normal; variable
presente pero vacía (`''`) = ninguna tarea; lista no vacía = solo esas claves.
Una lista no debe contener comandos, sino claves del catálogo.

## 2. Matriz operacional

| Informe/módulo | Tareas y horario | Dependencias/fuente | Evidencia de actualidad | Fallo y recuperación | Riesgo de activación |
|---|---|---|---|---|---|
| Interests/Leads oficial | `salesforce-sync-interest-reporting`, `5 * * * *` | F2 incremental `Interes__c` + F5 Task/Event, generación candidata | Run F2/F5 completado, cutoff común, candidata certificada y puntero publicado | Lock del pipeline, candidato fallido sin publicar; reintentar por wrapper | Medio; nunca sustituir por provisional |
| Interests mensual provisional | `salesforce-sync-interest-monthly-current`, `10 3 * * *`, desactivada | F2 mensual + F2M + F5 mensual, lectura Salesforce explícitamente autorizada | F2, F2M y F5 `verification.verified=true`, manifiestos y proyección válidos | Checkpoints y reanudación; hasta tres revisiones no verificadas; conserva última proyección válida | Alto; no es fuente oficial ni certifica historia |
| Interests operacional actual | `salesforce-sync-interest-operational-current`, `20 2 * * *`, desactivada; requiere `--allow-salesforce-read` | Run operacional del mes Madrid, calidad, Task/Event y proyección KPI local | Run activo compatible, calidad reconciliada, actividad activa y proyección KPI completada | Reanuda IDs existentes; falla cerrado si el mes no tiene contrato KPI | Alto; provisional, acotado y nunca oficial |
| Campañas | Meta `30 1`, Google `45 1`, atribución `30 8`, refresh `0 9`; reconciliación `20 9-23` | Interests publicado, Opportunities, snapshot directo Opportunity–Interest, Meta/Google | Readiness exige cobertura, runs correctos, snapshot y atribución del mismo linaje | Reconciliación acotada, máximo de intentos existente; no publicar incompleto | Medio; no ejecutar build sin readiness |
| Estado actual Campañas | `campaigns-sync-current-status`, `37 */6`, desactivada | Inventario operativo Meta/Google; independiente de métricas | `report_sync_runs` por cuenta, estado válido y frescura ≤24 h | Fallo parcial hace fallar la tarea; estados desconocidos son no disponibles | Medio; requiere cuentas, límites y revisión humana |
| Reservas/Ventas | Opportunities `10 7`; snapshot directo `35 7`; comercial incremental `*/15` | Opportunity, Stage/History, Interest publicado, usuarios/delegaciones | Runs correctos, cobertura temporal y snapshot directo válido | Ventana modificada de 2 días, reintento/control de presencia; no backfill diario | Medio; cambios tardíos requieren recuperación controlada |
| Comisiones (6 pestañas) | Opportunities, delegaciones, reseñas `20 2`, tasaciones `0 1`; evidencias de citas/entregas son CLI acotadas | Opportunities, User, reseñas, tasaciones, captadores, Interest, citas, Product2 y cierres | Readiness por scope, snapshots y evidencias; cierre definitivo inmutable | Reapertura/ajuste auditado existente; no programar citas/entregas sin periodo aprobado | Alto; riesgo económico y de reescritura de cierres |
| Stock | `stock-sync-daily`, `30 3`, `--sales-days=14 --logistics-days=30` | Product2, Opportunities, logística, fotografías y snapshots | Snapshot diario coherente, validez de venta y alertas sin errores | Lock propio, ventanas acotadas y reconciliación; no sobrescribe importes congelados | Alto; volumen y snapshots económicos |
| Llamadas | `salesforce-sync-calls`, `45 4`, `--days=7` | Task, `CallObject`, usuarios, reglas versionadas | Run completado, filas actualizadas y clasificación auditable | Repetir ventana; reproceso histórico solo procedimiento controlado | Medio; no ocultar llamadas abandonadas ni errores parciales |
| SEO | Search Console `15 5`, Salesforce organic `30 5`, GA4 `45 5`, salud `0 6`, snapshots `15 6`, evaluaciones `30 6` | Proveedores separados; Salesforce usa F2 local publicado | `report_sync_runs`, cobertura diaria, cutoff común mínimo y gates de calidad | Reintento por fuente; ausencia no se convierte en cero | Medio/alto; límites API y thresholding |
| Resumen ejecutivo | `seo-send-executive-daily-email`, `0 8`, solo scheduler | Snapshots/evaluaciones SEO, frescura compartida y salud técnica | Payload diario congelado y ledger de entregas | No reenvía `sent`; `sending` requiere reconciliación | Alto; efecto externo por correo |
| Retención transversal | `reports-prune-transversal-data`, `30 0` | Políticas aprobadas sobre payloads, runs, colas y alertas | Resultado agregado del comando y conservación de punteros/incidencias abiertas | `--dry-run` para alcance; la ejecución real nulifica payloads y elimina filas antiguas | Muy alto; escritura destructiva, solo tras aprobación específica |

## 3. Orden seguro de primera ejecución

1. Verificar release, migraciones, workers, un único scheduler y configuración
   efectiva sin secretos.
2. Mantener desactivados los flags mensual provisional y estado actual de
   campañas durante la validación inicial.
3. Confirmar que existen bootstrap/cobertura inicial de Interests, usuarios y
   delegaciones; ausencia de bootstrap deja los KPI no evaluables.
4. Inspeccionar el monitor y las alertas mediante SQL de solo lectura. No usar
   `operations:check-health` como preflight estricto: además de diagnosticar,
   puede abrir y resolver `operational_alerts`.
5. Habilitar el conjunto ordinario mediante `OPERATIONS_SCHEDULED_TASK_KEYS`,
   excluyendo temporalmente tareas de alto riesgo si el despliegue lo requiere.
6. Supervisar una ventana completa de F2/F5, Opportunities, Stock, llamadas y
   SEO antes de ampliar la allowlist.
7. Activar el pipeline mensual solo con autorización explícita de lectura,
   después de validar capacidad, migraciones y recuperación.
8. Activar estado actual de campañas por separado, tras revisar cuentas Meta y
   Google y ejecutar una primera prueba operacional autorizada.
9. Habilitar el correo ejecutivo únicamente después de disponer de snapshots y
   ledger verificados.
10. Mantener `reports-prune-transversal-data` fuera de la primera ventana. Antes
    de habilitarla, ejecutar su `--dry-run`, revisar el alcance y aprobar por
    separado la retención; nunca usar el modo real como comprobación.

## 4. Preflight de solo lectura

Ejecutar en el release desplegado, con un usuario de mínimo privilegio. Los
comandos siguientes no muestran secretos ni ejecutan sincronizaciones.

```powershell
git rev-parse HEAD
php artisan about --only=environment,application
php artisan schedule:list --timezone=Europe/Madrid
php artisan migrate:status
```

`operations:check-health` no forma parte del bloque estrictamente de lectura:
`OperationalTaskHealthService::check()` puede abrir y resolver alertas. Debe
ejecutarse solo como comprobación operacional autorizada, no como preflight
inocuo.

Comprobar configuración efectiva sin imprimir valores sensibles:

```powershell
php artisan tinker --execute=' $keys = config("operations.scheduled_task_keys"); dump(["monthly_interest_enabled" => (bool) config("salesforce.interest_monthly_current_sync_enabled"), "campaign_status_enabled" => (bool) config("campaign_current_status.enabled"), "scheduled_keys_state" => $keys === null ? "absent_catalog_default" : ($keys === "" ? "present_empty_none" : "restricted_list")]); '
```

Consultas SQL de lectura; ejecutar solo contra una réplica/ventana autorizada:

```sql
SELECT task_key, status, origin, started_at, finished_at, exit_code
FROM operational_task_runs ORDER BY id DESC LIMIT 50;

SELECT task_key, last_run_id, last_success_id, last_failure_id,
       active_run_id, last_completed_id, monitoring_started_at
FROM operational_task_states ORDER BY task_key;

SELECT dataset, source, status, source_cutoff_at, completed_at
FROM report_sync_runs ORDER BY id DESC LIMIT 100;

SELECT id, status, cutoff_at, f2_complete, f5_complete, published_at
FROM salesforce_interest_generations ORDER BY id DESC LIMIT 20;

SELECT id, revision, status, phase, effective_cutoff_at
FROM salesforce_interest_monthly_recoveries ORDER BY id DESC LIMIT 10;
```

El preflight no debe incluir tokens, `client_secret`, payloads Salesforce,
correos, nombres personales ni identificadores remotos.

## 5. Clasificación de comandos

- Diagnóstico estrictamente de lectura: `about`, `schedule:list`,
  `migrate:status` y las consultas SQL anteriores.
- Diagnóstico con escritura local de alertas: `operations:check-health` puede
  abrir o resolver `operational_alerts`; no incluirlo en un preflight de solo
  lectura y ejecutarlo únicamente con autorización operacional.
- Escritura local controlada: `operations:run`, que reclama una reserva y llama
  al comando catalogado. No usarlo durante el preflight salvo una prueba
  autorizada de staging.
- Lectura externa: Interests mensual con `--allow-salesforce-read`, comandos de
  diagnóstico de integraciones y cualquier sincronizador Salesforce. Requieren
  autorización separada y no forman parte del preflight automático.
- Efecto externo: correo ejecutivo. Debe mantenerse fuera de la primera prueba
  hasta verificar snapshots y ledger.
- Retención destructiva: `reports:prune-transversal-data` escribe `raw_payload`
  a `NULL` y elimina filas antiguas de runs, sincronizaciones, colas y alertas.
  Solo `reports:prune-transversal-data --dry-run` es una inspección de alcance;
  incluso el dry-run debe ejecutarse con aprobación porque consulta tablas de
  retención. El modo real nunca forma parte del preflight.

## 6. Pruebas y niveles de evidencia

Pruebas relevantes, que deben ejecutarse en CI o entorno con PHP:

```powershell
php artisan test --filter="OperationalTasksTest|OperationalTaskReviewTest|OperationalMonitorSchedulerTest"
php artisan test --filter="SyncCurrentMonthInterestsCommandTest|InterestMonthlyRecoveryTest|MonthlyInterestMaterializationTest|MonthlyInterestActivityRecoveryTest"
php artisan test --filter="CampaignCurrentStatusTest|CampaignPublicationReadinessTest|CampaignPublicationRetryTest"
php artisan test --filter="ReservationsSalesCommercialPerformanceTest|CommercialCommissionDashboardTest|StockDashboardTest|SalesforceCallsScheduleTest"
php artisan test --filter="SeoSyncCommandsTest|SeoTechnicalHealthSyncTest|SeoExecutiveDailyEmailTest"
vendor/bin/pint --test
composer audit --locked --no-dev
python scripts/check_documentation_integrity.py
git diff --check
```

- CI: pruebas simuladas, contratos de catálogo, locks y publicación.
- Staging: migraciones, colas, duración, memoria, límites API y primera
  ejecución supervisada con proveedores simulados o cuentas de prueba.
- Producción: únicamente evidencia de runs reales, cutoffs, frescura, workers,
  reservas, alertas y cobertura; no debe usarse para validar una migración no
  probada.

## 7. Suspensión y rollback

Ante fallo técnico repetido, reserva huérfana, API parcial, aumento de latencia,
cutoff incoherente o publicación no certificada:

1. Restringir `OPERATIONS_SCHEDULED_TASK_KEYS` o apagar el flag concreto.
2. No liberar manualmente una reserva incierta sin el procedimiento auditado.
3. Conservar runs, journals, snapshots y generaciones.
4. Esperar/reconciliar la ejecución en curso y revisar `operations-health`.
5. Restaurar código/manifest/assets compatibles si procede; no usar
   `migrate:rollback`, `DROP`, limpieza de journals ni repunte manual de
   generaciones.
6. Reanudar solo después de confirmar integridad y una ventana de corte válida.

La suspensión del pipeline mensual no invalida automáticamente la última
proyección provisional válida; una revisión nueva no desplaza la anterior hasta
completar todas sus verificaciones. Ninguna de ellas se convierte en fuente
oficial por rollback.

## 8. Bloqueos pendientes

- No hay evidencia productiva en esta revisión; no se declara el scheduler
  operativo.
- La retención transversal es una tarea catalogada de alto riesgo y queda
  pendiente de aprobación independiente; una allowlist de activación inicial
  debe excluirla aunque el resto del catálogo esté habilitado.
- Deben confirmarse en staging los tiempos y consumo de API de las tareas de
  120 días, Stock y SEO.
- Las evidencias de citas y entregas de Contact Center requieren una decisión de
  periodo antes de cualquier automatización.
- El cambio horario Europe/Madrid debe probarse con `schedule:list` y una
  ejecución controlada; no confiar solo en el cron del host.
## Preflight del entorno de ejecución

Antes de reintentar una tarea fallida, ejecutar el comando siguiente con el
mismo usuario efectivo del cron (`www-data`) dentro del contenedor. Comprueba las
rutas Laravel ordinarias, la conexión MariaDB y realiza una escritura temporal
en el store de caché que elimina inmediatamente.

```bash
docker exec -u www-data -w /var/www laravel-informes-app \
  /usr/local/bin/php artisan operations:preflight
```

El resultado debe acreditar `ok=true`, `cache_round_trip.ok=true`,
`cache_round_trip.cleaned=true`, `database.ok=true` y rutas legibles y
`cache_entries.ok=true` y rutas legibles y escribibles. `cache_entries` inspecciona
como máximo 1.000 entradas reales, sin seguir enlaces simbólicos; si informa
`truncated=true`, la comprobación no es concluyente y debe complementarse con
una inspección de solo lectura del árbol de caché del contenedor. La salida solo contiene UID, usuario efectivo, configuración no
secreta y estados booleanos; no imprime `.env`, tokens, payloads ni mensajes de
excepción.

Las ejecuciones manuales de Artisan que puedan crear caché deben usar también
`docker exec -u www-data`. No se debe corregir este incidente cambiando el cron
a root, usando `chmod -R 777` o ejecutando `chown -R` sobre el volumen completo.

# Operaciones de producción

## Alcance e inventario

Runbook para PHP 8.4 y MariaDB. Las secciones originales describen el entorno histórico de cPanel; la evidencia Docker vigente del 19/10/2026 se detalla en OPS-1 en producción. No se necesita Node/npm para servir los assets compilados.
Este hardening no modifica KPIs, fórmulas, cierres ni reglas funcionales.

- Web humana: `/login`, sesión Laravel y rutas bajo `reports.auth` más
  `report.access:<informe>`.
- API interna entrante verificada: `GET /api/comisiones_comercial`. El consumidor
  concreto no es identificable en el repositorio; debe confirmarse antes de
  retirar la credencial legacy.
- Integración saliente interna: reseñas con Basic Auth; no es una ruta expuesta.
- Otras salidas: Salesforce, Google Ads y Meta Ads mediante secretos de entorno.
- Ejecuciones: `report_sync_runs`, `failed_jobs` y `job_batches`.
- Payloads crudos con pruning seguro: `raw_payload` en `salesforce_users`,
  `salesforce_activities`, `salesforce_calls`,
  `campaign_platform_daily_metrics`, `salesforce_reviews`,
  `salesforce_vehicles`, `salesforce_logistics` y
  `campaign_platform_identifiers`.
- Payloads verificados pero no podables sin cambiar comportamiento:
  `leads_raw`, `salesforce_leads`, `salesforce_opportunities`,
  `salesforce_tasaciones` y `campaign_salesforce_leads`. Los consumen informes,
  reconstrucciones, backfills o snapshots; requieren materialización previa.
- Alertas: `operational_alerts` y el estado legacy
  `stock_availability_alerts`.
- Health `/up`: liveness de Laravel; no valida integraciones, colas ni frescura
  y no revela detalles internos.

## Lifecycle de Opportunities: runbook y cierre de 2026-09-08

### Operación reutilizable

La conciliación parte exclusivamente de Opportunities locales y consulta
Salesforce mediante `queryAll` por lotes. Ejecutar siempre dry-run antes de un
apply aprobado:

```bash
php artisan salesforce:reconcile-opportunity-presence --dry-run --limit=1000
php artisan salesforce:reconcile-opportunity-presence --apply --reason="Motivo operativo aprobado" --limit=1000
```

Continuar con `--after-id=<último-id-local>` cuando corresponda. Un apply que
confirma borrados reconcilia inmediatamente la validez local de Stock. Si esa
segunda fase falla después de persistir lifecycle, reintentar únicamente:

```bash
php artisan stock:reconcile-sale-validity
```

`query_all_deleted` significa borrado confirmado. Una
`presence_reconciliation_missing` es solo ausencia diagnóstica y permanece
reportable. `SystemModstamp` alimenta `salesforce_deleted_at` como evidencia
técnica; no es fecha contractual de borrado y nunca sustituye a
`salesforce_last_modified_at` (`Opportunity.LastModifiedDate`).

### Evidencia del cierre productivo

Tras desplegar PR #38 (`8231929cbda7427de40a163ef79e98f1e94c4be1`):

- dry-run inicial `e35e835d-0945-4aa3-9e5a-d3aa08edae29`: 40.724 filas,
  40.721 IDs encontrados, tres ausentes, 40.718 activos, tres borrados y seis
  cambios previstos; cero errores, conflictos concurrentes o refresh pendientes;
- apply `8fb4089e-20be-4353-b052-9d529a55ec26`: seis filas cambiadas, tres
  marcadas borradas y tres marcadas ausentes;
- dry-run post-apply `2ff8a397-0502-472b-969a-037122d83e09`: cero cambios y
  40.724 filas sin diferencias;
- Stock: 13.625 válidos, 326 inválidos, 66 duplicados y cero `unchecked`.

La inspección posterior confirmó que Salesforce devuelve `SystemModstamp` y
que el spelling anterior `SystemModStamp` dejaba nulo el timestamp, aunque el
estado de tres borrados ya era correcto. Tras desplegar PR #40
(`fe758b0dd7623acff35eb051b17b2b5072ec70b2`):

- dry-run de reparación `ba49a054-50c6-481b-8f26-146182152811`: tres cambios
  previstos, todos borrados confirmados, y ningún missing nuevo;
- apply `8b370540-60ad-4c53-bc86-981210cc91da`: tres filas actualizadas, sin
  concurrencias omitidas ni refresh pendientes;
- dry-run final `b68aa962-7c3c-4bda-87b8-b69410d13e44`: 40.724 filas, 40.721
  encontradas, tres ausentes, 40.718 activas, tres borradas y cero cambios,
  errores, concurrencias omitidas o reactivaciones pendientes;
- Stock final: 13.625 válidos, 326 inválidos, 66 duplicados y cero `unchecked`.

La prueba sintética prevista en sandbox no pudo ejecutarse. No debe registrarse
como superada. La evidencia productiva sí confirmó `queryAll`, los tres
borrados, sus valores reales de `SystemModstamp`, la persistencia posterior y
la idempotencia final.

Fase 7A y Fase 7B son operaciones históricas distintas: sus herramientas están
terminadas, pero no consta su ejecución. Este cierre de lifecycle no las ejecutó
ni las autoriza implícitamente.

## API interna de Comisiones

| Ruta | Método | Consumidor | Auth/autorización | Rate limit | Auditoría | Sensibilidad |
|---|---|---|---|---|---|---|
| `/api/comisiones_comercial` | GET | No verificable en repo | Basic por credencial activa | por integración | log diario estructurado | Salesforce User ID e importes |

Contrato: `salesforce_id` es obligatorio y escalar; `month=YYYY-MM` es opcional.
Con mes explícito solo se construye ese mes. Sin mes se usa
`CommissionMonthResolver` y se mantienen temporalmente los bloques legacy
`current_month` y `previous_closed_month`, además de la fila mensual canónica.
La fila procede exactamente de `commercials.summary_rows`: del snapshot si el
scope está definitivo y del dataset vivo para estados provisional, pendiente o
reabierto. La fila definitiva precede a cualquier validación del perfil, estado
o existencia actual del usuario. `200` con `row=null` queda reservado a un
Salesforce User ID exacto, activo y elegible sin fila; un ID inexistente,
técnico, inactivo o no elegible responde `404` cuando no hay fila congelada; mes
inválido o futuro responde `422`. Un dataset vivo con `ready=false` responde
`503` genérico sin incidencias internas y nunca fabrica importes cero, también
en la respuesta legacy sin `month`.

La ruta conserva, en este orden, `internal.api.audit`, `commissions.api.auth` e
`internal.api.throttle`. La auditoría conserva `X-Request-ID` y no registra query
completa, respuesta, `details`, Salesforce IDs, `Authorization` ni secretos.

La compatibilidad `COMMISSIONS_API_USER` + `COMMISSIONS_API_PASSWORD` se mantiene
durante la transición. La configuración recomendada es un JSON gestionado fuera
del repositorio en `COMMISSIONS_API_CREDENTIALS`:

```json
[
  {
    "integration": "identificador-no-personal",
    "credential_id": "2026-08-a",
    "username": "usuario-basic-acordado",
    "password": "valor-del-gestor-de-secretos"
  }
]
```

Rotación sin downtime: añadir otra entrada con nuevo `credential_id`, desplegar
configuración, limpiar caché, migrar el consumidor, verificar auditoría y retirar
la anterior. Varias entradas pueden compartir integración/usuario. Revocar
eliminando la entrada o con `revoked: true`. Nunca mostrar valores reales en
Tinker, documentación, logs o tickets.

Límites configurables: 120 peticiones autenticadas/minuto y 10 fallos de
autenticación/minuto por defecto. La auditoría registra integración, versión,
endpoint, método, fecha, resultado, status, duración y UUID; nunca IP, query
completa, body, `Authorization`, password ni cookies.

## Logging, cachés y retención

- Producción: `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=90` y
  `LOG_LEVEL=info`.
- `laravel-YYYY-MM-DD.log` e `internal-api-audit-YYYY-MM-DD.log` rotan solo en
  `storage/logs`. Confirmar permisos y cuota de cPanel para 90 días.
- Un procesador redacta Authorization, Basic/Bearer, passwords, tokens, secrets,
  cookies, IDs de sesión y CSRF.
- Las cachés de Leads, Reservas/Ventas, Llamadas y Campañas tienen TTL de 10
  minutos; sus claves incorporan filtros/ámbitos o flags de diagnóstico. Los
  tokens Salesforce tienen clave técnica y TTL OAuth, sin exposición web.

`reports:prune-transversal-data` aplica:

| Política | Entidad real | Acción |
|---|---|---|
| payloads crudos, 2 meses | 8 columnas sin lecturas funcionales | `raw_payload=NULL`; conservar fila normalizada |
| ejecuciones correctas, 1 mes | sync runs completados y batches correctos | borrado por chunks |
| ejecuciones fallidas, 2 semanas | sync runs fallidos, failed jobs/batches | borrado por chunks |
| alertas resueltas, 1 mes | alertas operativas y Stock | borrar solo `resolved` |
| logs técnicos, 3 meses | canales daily y api_audit | rotación 90 días |

No elimina alertas abiertas, jobs pendientes, snapshots, cierres, eventos
económicos, atribuciones, históricos de Stock ni filas normalizadas. El comando
es idempotente, transaccional por chunk y ofrece `--dry-run`.

Se programa a las 00:30 `Europe/Madrid`, antes de la ventana pesada de las
01:00, con `withoutOverlapping(30)`. Los horarios funcionales existentes no
cambian. Cada tarea programada abre una alerta deduplicada al fallar y la
resuelve tras una ejecución correcta.

`withoutOverlapping(N)` limita la vida del lock, pero no termina un proceso
bloqueado. El límite real de proceso debe configurarse y monitorizarse en el
runner de cPanel tras medir cada comando; esa capacidad no es verificable en el
repositorio. Ante un lock aparentemente abandonado, confirmar primero que no
existe ningún proceso activo y solo entonces ejecutar
`php artisan schedule:clear-cache`. Nunca limpiar locks con una sincronización
en curso.

## Alertas administrativas

`/informes/alertas-operativas` exige administrador activo en backend, pagina 25
filas y filtra en servidor. Guarda mensaje sanitizado e identificador técnico,
sin bodies ni secretos. Stock deja de enviar email y de crear/actualizar Tasks
Salesforce; el criterio funcional de apertura/cierre no cambia.

## Configuración de producción

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=90
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
REPORT_LOGIN_MAX_ATTEMPTS=5
REPORT_LOGIN_DECAY_SECONDS=60
COMMISSIONS_API_RATE_LIMIT_PER_MINUTE=120
COMMISSIONS_API_AUTH_FAILURES_PER_MINUTE=10
```

## Pre-migrate

Verificar un backup restaurable de base de datos, configuración/secretos y
`storage/app` necesario. El mecanismo cPanel no está verificado en el repo: no
iniciar hasta probar la restauración. Consultas MariaDB de solo lectura:

```sql
SELECT VERSION();
SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'salesforce_users','salesforce_activities','salesforce_calls',
    'campaign_platform_daily_metrics','salesforce_reviews',
    'salesforce_vehicles','salesforce_logistics','campaign_platform_identifiers',
    'report_sync_runs','stock_availability_alerts','failed_jobs','job_batches'
  )
ORDER BY DATA_LENGTH + INDEX_LENGTH DESC;

SELECT status, COUNT(*) total, MIN(completed_at), MAX(completed_at)
FROM report_sync_runs GROUP BY status;
SELECT state, COUNT(*) total, MIN(resolved_at), MAX(resolved_at)
FROM stock_availability_alerts GROUP BY state;
SELECT COUNT(*) old_raw_payloads FROM salesforce_users
WHERE raw_payload IS NOT NULL
  AND updated_at < DATE_SUB(NOW(), INTERVAL 2 MONTH);
```

Repetir la última consulta para las ocho tablas podables, sin seleccionar ni
exportar el JSON. Para las cinco tablas bloqueadas, medir volumen pero no
modificar datos hasta materializar y probar sus consumidores.

## Despliegue sin Node

1. Confirmar CI verde y artefacto con `public/build/manifest.json` y assets.
2. Verificar backup y consultas pre-migrate.
3. Pausar temporalmente el cron `schedule:run` desde cPanel; no lanzar jobs.
4. Desplegar código aprobado. Esta migración crea tabla e índices en tablas
   voluminosas: usar ventana controlada y mantenimiento mientras se mide el
   locking real. Cambios futuros puramente aditivos, verificados online, no
   requieren downtime por defecto.
5. Ejecutar, sin npm:

```bash
php artisan down --retry=60
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer audit --locked --no-dev
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

El audit debe finalizar sin advisories antes de continuar. Producción instala el
`composer.lock` aprobado; no se ejecuta `composer update` en el servidor.

6. Reanudar `schedule:run` y ejecutar solo checks/dry-run. No ejecutar sync,
   reprocesados, backfills, pruning real ni rotación real como parte implícita.

## Post-migrate

```bash
php artisan migrate:status
php artisan route:list --path=api
php artisan route:list --path=informes/alertas-operativas
php artisan schedule:list
php artisan reports:prune-transversal-data --dry-run --chunk=500
```

Tinker, sin secretos:

```php
config('app.debug') === false;
config('logging.channels.daily.days') === 90;
config('session.secure') === true;
config('session.http_only') === true;
collect(config('services.commissions_api.credentials', []))
    ->map(fn ($item) => collect($item)->only(['integration', 'credential_id', 'username', 'revoked']))
    ->values();
DB::table('operational_alerts')->select('state', DB::raw('COUNT(*) total'))->groupBy('state')->get();
```

```sql
SHOW INDEX FROM report_sync_runs WHERE Key_name = 'report_sync_runs_retention_idx';
SHOW INDEX FROM stock_availability_alerts WHERE Key_name = 'stock_alerts_retention_idx';
SHOW INDEX FROM failed_jobs WHERE Key_name = 'failed_jobs_retention_idx';
SELECT state, severity, COUNT(*) total
FROM operational_alerts GROUP BY state, severity;
SELECT COUNT(*) open_with_resolution FROM operational_alerts
WHERE state = 'open' AND resolved_at IS NOT NULL;
```

Verificar `/up` solo como liveness y una petición sintética autorizada por cada
integración, comprobando status, rate headers y audit log sanitizado.

## SEO/Analytics Lotes 2 y 3

Después de desplegar código y assets, sin ejecutar Node en producción:

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
php artisan seo:diagnose-integrations
php artisan seo:diagnose-integrations --live
```

Solo cuando el diagnóstico live confirme la fuente correspondiente:

```bash
php artisan seo:sync-search-console --days=120
php artisan seo:sync-salesforce-organic --days=120
php artisan seo:sync-ga4-organic --days=120
```

Desde ROT-5, `seo:sync-salesforce-organic` es una proyección local y no depende
del diagnóstico live ni de credenciales Salesforce. Antes de ejecutarla debe
existir un último `report_sync_runs` canónico de
`salesforce_interests/salesforce` en estado `completed` y con
`source_cutoff_at`; el comando falla de forma segura si F2 no cumple el
contrato o cambia durante la proyección. Search Console y GA4 sí conservan sus
diagnósticos remotos.

Verificar después `php artisan schedule:list`, los últimos `report_sync_runs` de
`seo_search_console`/`seo_salesforce_organic_interests`/
`seo_ga4_organic_conversions` y los
cutoffs mostrados en el panel. Para GA4, confirmar previamente property,
timezone, web streams y Key Events con el diagnóstico live; después verificar
`php artisan schedule:list` y el horario 05:45.
No copiar secretos a comandos, logs o documentación. Las migraciones son
aditivas; un rollback de esquema elimina datos SEO ya sincronizados y requiere
backup/análisis explícito, por lo que se prefiere rollback de código compatible
o forward fix.

## SEO/Analytics Lote 4 - Salud técnica

Antes del despliegue, definir sin inventar valores:

```dotenv
SEO_TECHNICAL_SITE_URL=
SEO_TECHNICAL_ALLOWED_HOSTS=
SEO_TECHNICAL_STRATEGIC_URLS=
SEO_TECHNICAL_SITEMAP_URLS=
```

`SITE_URL` es el origen público SEO, no `APP_URL`. Las otras variables son
listas separadas por comas; los hosts son exactos y sin wildcards. Validar con
el propietario SEO todas las URLs estratégicas y sitemaps antes de ejecutar.

Desplegar las migraciones aditivas y refrescar configuración, sin Node:

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
```

La primera comprobación es una acción manual explícita y realiza HTTP contra el
sitio configurado:

```bash
php artisan seo:sync-technical-health
php artisan schedule:list
```

Comprobar después el último `report_sync_runs.dataset=seo_technical_health`, los
contadores no sensibles, las tablas `seo_technical_urls` y
`seo_technical_url_checks`, y el panel Salud técnica. No ejecutar el comando si
el host, allowed hosts o sitemaps no han sido validados. El rollback de esquema
eliminaría el histórico técnico y requiere backup/análisis explícito; se prefiere
rollback de código compatible o forward fix.

## SEO/Analytics Lote 5 - Snapshots comparativos

La migración es aditiva y crea `analytical_metric_snapshots`. Desplegarla sin
ejecutar sincronizaciones implícitas:

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
```

Para disponer de D-364 desde el inicio, el backfill manual recomendado es:

```bash
php artisan seo:sync-search-console --days=400
php artisan seo:sync-salesforce-organic --days=400
php artisan seo:sync-ga4-organic --days=400
php artisan seo:build-analytical-snapshots --days=30
```

Los tres comandos de 400 días son una operación inicial explícita; el scheduler
de fuentes continúa en 120 días. Si no se ejecuta ese backfill, la comparación
semanal funciona con el histórico disponible y D-364 muestra `—`. Verificar sin
exponer identities ni secretos:

Los comandos de ingesta aceptan `--days=1..480`. El builder es distinto: acepta
`--days=1..90`, usa 30 cuando se omite la opción y su scheduler invoca ese
default para reconstruir 30 días. No ampliar el builder a 400 durante el backfill.

En despliegues posteriores a ROT-5, aplicar primero las migraciones que crean
`seo_salesforce_organic_interest_daily_metrics` y la versión de reglas vN+1.
Verificar después un F2 completo/estable, ejecutar la proyección Interest y solo
entonces construir snapshots/evaluaciones. No copiar ni reinterpretar filas de
`seo_salesforce_organic_daily_metrics`: esa tabla y sus identidades Lead son
histórico inmutable.

```bash
php artisan schedule:list
php artisan tinker
```

```php
DB::table('analytical_metric_snapshots')
    ->select('module_key', 'metric_key', DB::raw('COUNT(*) AS snapshots'))
    ->groupBy('module_key', 'metric_key')
    ->orderBy('metric_key')
    ->get();

DB::table('report_sync_runs')
    ->where('dataset', 'seo_analytical_snapshots')
    ->latest('started_at')
    ->first(['status', 'source_cutoff_at', 'stats']);
```

El `source_cutoff_at` del run del builder es un marcador local del máximo
procesado, no el closed-through de una fuente externa. Los snapshots no se
eliminan mediante pruning. El rollback de esquema destruiría histórico
comparativo; se prefiere rollback de código compatible o forward fix.

## SEO/Analytics Lote 6 - Evaluaciones versionadas

La migración es aditiva: crea `analytical_rule_sets`,
`analytical_metric_rules` y `analytical_metric_evaluations`, e inicializa
`seo_rules_v1` con seis reglas. No modifica snapshots ni ingestas. Las
evaluaciones capturan los inputs de clasificación y su fingerprint; los
snapshots siguen siendo proyecciones rolling actualizables.

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
php artisan schedule:list
```

Verificar el bootstrap sin mostrar identities de fuentes:

```php
DB::table('analytical_rule_sets')
    ->where('module_key', 'seo')
    ->get(['version_number', 'version_key', 'status', 'activated_at']);

DB::table('analytical_metric_rules')
    ->where('rule_set_id', DB::table('analytical_rule_sets')
        ->where('module_key', 'seo')->where('version_key', 'seo_rules_v1')
        ->value('id'))
    ->count(); // debe ser 6
```

Solo mientras `seo_rules_v1` sea la única versión activa puede ejecutarse el
backfill inicial:

```bash
php artisan seo:evaluate-analytical-snapshots --days=30
```

Después de crear v2 o superior no ejecutar backfills históricos: el contrato no
reinterpreta retroactivamente hechos antiguos. El scheduler ordinario evalúa
solo la situación actual a las 06:30 Europe/Madrid con lock de 120 minutos.
Comprobar la pantalla SEO, «Señales recientes» y el último
`report_sync_runs.dataset=seo_analytical_evaluations`. Un rollback de esquema
elimina configuración y auditoría; se prefiere rollback de código compatible o
forward fix.

Si una revisión de fuente actualiza un snapshot después de las 06:15, el panel
puede mostrar temporalmente «Evaluación pendiente de actualizar» en lugar del
estado anterior. El evaluador ordinario de las 06:30 actualiza el mismo registro
snapshot/rule set y su fingerprint sin crear duplicados. No intervenir ni copiar
manualmente clasificaciones durante esa ventana.

## SEO/Analytics Lote 7 - Correo ejecutivo diario

La migración aditiva crea settings, fotografías diarias y ledger de entregas.
No ejecutar ningún envío hasta validar el transporte real y configurar
destinatarios desde la aplicación:

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
php artisan schedule:list
```

Verificar en el entorno, sin imprimir `MAIL_PASSWORD`, las variables
`MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. `log`, `array` y
`MAIL_FROM_ADDRESS=hello@example.com` no son operativos. Los destinatarios se
configuran como Administrador/Director en
`/informes/seo-analytics/configuracion`; no se almacenan en `.env`.

`schedule:list` debe mostrar `seo:send-executive-daily-email` a las 08:00
Europe/Madrid. Una validación manual opcional:

```bash
php artisan seo:send-executive-daily-email
```

Ese comando cuenta como el envío real del día. El scheduler posterior no lo
duplica. Verificar `seo_executive_daily_reports`,
`seo_executive_email_deliveries` y
`report_sync_runs.dataset=seo_executive_daily_email` sin copiar emails ni
secretos a logs o tickets. Un delivery `sending` residual no se reintenta
automáticamente: confirmar primero con el proveedor si aceptó el mensaje y
resolverlo mediante procedimiento manual controlado. Si el proveedor confirma
la aceptación, registrar la entrega como `sent` y conservar la fecha; solo si
confirma que no fue aceptada puede devolverse a `failed` para reintento. No
resetear `sending` a ciegas. Un `confirmation_pending_count` positivo indica que
SMTP retornó correctamente pero no pudo verificarse la transición local a
`sent`; el comando falla y ese delivery permanece no reintentable hasta esta
reconciliación. Se prefiere rollback de código compatible o forward
fix; revertir la migración elimina trazabilidad.

## HTTPS detrás del reverse proxy

Producción debe declarar el origen público y únicamente las IP/CIDR reales desde
las que el contenedor recibe tráfico del reverse proxy:

```dotenv
APP_URL=https://informes.app.hrmotor.com
TRUSTED_PROXIES=<ip-o-cidr-real-del-proxy>
```

`TRUSTED_PROXIES` admite varios valores separados por coma. No usar `*`. El
reverse proxy debe enviar como mínimo `X-Forwarded-Proto: https` y conservar el
host público mediante `X-Forwarded-Host`; Laravel ignora estas cabeceras cuando
el origen no pertenece a la lista confiable. Tras cambiar configuración:

```bash
php artisan optimize:clear
php artisan config:cache
```

Verificar las seis pestañas de Comisiones, cambio de mes, exportaciones y los
formularios de preparación/aprobación/reapertura. Ninguna URL renderizada debe
comenzar por `http://informes.app.hrmotor.com`. Si Laravel sigue detectando
HTTP, comprobar en el proxy la presencia de `X-Forwarded-Proto: https`; no
compensarlo con `forceScheme` ni reemplazos de texto.

## Rollback y pendientes

- Preferir rollback de código si el esquema aditivo es compatible.
- Ante migración parcial, conservar evidencia y aplicar forward fix explícito.
- Rollback de esquema solo tras analizar la migración concreta y el backup;
  nunca `migrate:rollback --step=N`.
- `2026_08_11_130000` deja de ser reversible cuando `operational_alerts`
  contiene datos; su `down()` elimina la tabla. Los índices solo se retiran por
  nombre tras analizar carga.
- Confirmar propietario/tráfico de consumidores antes de retirar legacy o bajar
  límites; medir los 11 índices añadidos a tablas existentes en una copia
  MariaDB 10.6; confirmar cuota de logs.
- Definir en cPanel límites de proceso y alerta de cron colgado. Los callbacks
  detectan el código de salida, pero no sustituyen un heartbeat externo.
- No existe readiness profunda; diseñarla solo con dependencias y permisos
  acordados, sin exponer detalles.

## OPS-1 — Verificación y recuperación operacional (2026-10-15)

Antes de habilitar el panel, comprobar el worker ACTUAL; una validación histórica
del worker default no certifica la cola nueva. Catálogo completo y exclusiones:
[OPERATIONS.md](ai/OPERATIONS.md). Solo admin activo puede ejecutar desde el panel;
Dirección no obtiene esta capacidad. Se mantienen los comandos/horarios anteriores.

### Despliegue previsto (no ejecutado por Codex)

1. Publicar código y public/build construido y revisado. Instalar Composer desde
   lockfile; no actualizar dependencias en producción.
2. Aplicar únicamente migraciones pendientes, revisando antes migrate:status.
   OPS-1 añade 2026_10_15_120000_create_operational_task_runs; no transforma datos
   funcionales. Mantener pausa controlada de cron/workers durante la publicación.
3. Regenerar caché de configuración según el procedimiento del entorno.
4. Provisionar y supervisar un worker dedicado, sin sustituir el default:

~~~bash
php artisan queue:work operations --queue=operations --timeout=14400 --tries=1 --sleep=3
~~~

   operations es conexión database con retry_after 14700 s. El timeout del Job es
   14400 s y un solo intento. El supervisor debe permitir terminación ordenada
   superior a 14400 s; comprobar soporte real de timeouts/señales en el host.
   Cron y Queue deben compartir BD de aplicación; validar DB_QUEUE_CONNECTION y
   DB_QUEUE_TABLE existentes sin imprimir sus credenciales. No usar sync/deferred.
5. Reiniciar los workers con el mecanismo de despliegue existente y reactivar
   cron una vez aplicadas las tablas. No ejecutar sincronizaciones de prueba.

### Checks concretos sin sincronizar ni enviar correos

~~~bash
php artisan migrate:status
php artisan schedule:list --timezone=Europe/Madrid
php artisan queue:failed
~~~

Comprobar en el supervisor que el proceso dedicado está activo con los argumentos
anteriores y que el default sigue funcionando. Comprobar que cron invoca
schedule:run cada minuto con el PHP, usuario, ruta y entorno correctos. schedule:list
demuestra definición, no que cron haya ejecutado. Revisar el ledger tras dos
intervalos del monitor; no ejecutar schedule:run manualmente como un “check”,
porque podría ejecutar integraciones y correo reales.

Consultas de solo lectura (sin payloads ni contenido de failed_jobs):

~~~sql
SELECT task_key, active_run_id, last_run_id, last_success_id, last_failure_id
FROM operational_task_states ORDER BY task_key;

SELECT id, task_key, origin, status, queued_at, started_at, finished_at, exit_code
FROM operational_task_runs ORDER BY id DESC LIMIT 50;

SELECT queue, COUNT(*) AS pending, MIN(created_at) AS oldest
FROM jobs GROUP BY queue;

SELECT task_key, status, MAX(finished_at) AS last_finish
FROM operational_task_runs
WHERE task_key = 'operations-health' GROUP BY task_key, status;
~~~

Certificar cron observando nuevos éxitos de operations-health cada cinco minutos
y tareas diarias en sus ventanas reales. Certificar Queue mediante una operación
ordinaria autorizada por admin: el POST vuelve con En cola antes del inicio y el
mismo run pasa a running y terminal con actor humano. Esta prueba productiva la
realiza el operador autorizado; Codex no la ejecuta. No usar queue:retry sobre
runs expirados o terminales; crear una nueva solicitud desde el panel.

### Uso y estados

Abrir /informes/alertas-operativas con admin. Leer tarea, cron Madrid, margen,
último inicio/éxito/fallo, duración, origen y próxima ocurrencia. Marcar la
confirmación específica antes de Ejecutar. Actualizar estado manualmente; no hay
polling agresivo. El navegador no espera al comando. El historial está paginado.

Nunca ejecutado no significa Correcto. Una solicitud en cola >15 min requiere
revisar el worker; el Job caducado no ejecuta cuando vuelve el worker.
Running fuera de ventana o unknown conserva la reserva. Fallos y retrasos usan
alertas deduplicadas; un éxito vigente resuelve la condición stale. Una omisión
por configuración o lock no publica éxito. Consultar el ledger del dataset para
readiness: el ledger operacional solo certifica ejecución del contrato.

Si el propio cron se detiene no habrá nuevas evaluaciones: integrar supervisión
externa del último éxito operations-health. /up solo acredita liveness HTTP.

### Locks y tareas stale

1. Identificar active_run_id, origen, estado y tiempos sin inspeccionar payloads.
2. Comprobar procesos del scheduler y worker y su terminación real. No asumir
   que una ventana vencida equivale a un proceso muerto.
3. Si sigue vivo, esperar o aplicar el procedimiento de parada controlada del host.
4. Solo tras verificar que terminó y registrar intervención en el sistema de
   operaciones, liberar por CLI (no disponible por HTTP):

~~~bash
php artisan operations:release ID_DEL_RUN --confirm-stopped --reason=process_terminated --reference=OPS-123
~~~

La liberación deja fin fallido auditable y no resuelve stale como si hubiera
éxito. La intervención se identifica como CLI en el resumen; la identidad del
operador se registra externamente, no se inventa actor Sistema. No borrar filas,
no ejecutar cache:clear o schedule:clear-cache para saltarse una reserva.
Los locks internos son distintos; no borrarlos mientras pueda existir proceso.
Las ejecuciones ordinarias por CLI deben usar operations:run CLAVE para respetar
la misma exclusión y auditoría; un comando directo queda fuera de ese contrato.

### Rollback

Parar el consumo de operations y pausar cron según el procedimiento operativo;
comprobar que no quede un proceso largo antes de volver a código anterior.
Conservar ambas tablas aditivas y su evidencia. No ejecutar down ni rollback
genérico: eliminarían la auditoría. No reactivar jobs operations con código antiguo;
volver primero a una versión compatible y decidir su cancelación de forma
auditada. El worker default no se retira. No se requiere cambio de .env en este
lote; sí provisión explícita del worker y publicación de configuración/código.

Pendiente de certificación externa: procesos reales, BD productiva/InnoDB,
timeout/señales, cron, servicio supervisor y retención a escala. No se ha
desplegado, hecho push, ejecutado sincronización, backfill o enviado email.

## Dependencias verificadas en OPS-1

El audit inicial detectó un advisory de symfony/http-foundation y seis advisories
en herramientas npm de desarrollo. El lockfile fija http-foundation 8.1.9,
compatible con Laravel y con requisito PHP >=8.4.1; comprobar esa versión antes
de instalar en producción (el CLI local verificado usa PHP 8.4.21). El lockfile
npm corrige las versiones compatibles y los assets se han reconstruido con Vite
8.3.4. No se modificaron las fuentes de los demás dashboards. No ejecutar
composer update o npm audit fix en producción; desplegar los lockfiles revisados.

## Correcciones OPS-1 tras revisión 41c1e7a

Aplicar en despliegue la migración aditiva 2026_10_15_130000_extend_operational_observation_and_release_audit
antes de reactivar código/worker nuevos. No se ha aplicado en producción desde
Codex. Conservar sus columnas y auditoría durante un rollback; no ejecutar down.
No cambian horarios, opciones de sincronización ni cola operations.

La activación nueva empieza a exigir la primera ocurrencia cron futura más su
margen. Nunca ejecutado durante ese arranque no equivale a Correcto. Los estados
preexistentes conservan created_at como primera observación; no reciben un margen
nuevo. No reiniciar el historial ni rellenarlo con éxitos ficticios.

Para supervisión externa usar operations:check-health y capturar su código de
salida: 0 sin incidencias exigibles, 5 incidencias detectadas, 1 fallo técnico de
la evaluación. Para supervisar además la evidencia del propio monitor, consultar
last_completed_id de operations-health, cuyo run puede ser success o unhealthy:
ambos demuestran evaluación terminada; solo success demuestra evaluación sin
incidencias. operations:run retorna 3 para omisión iniciada, 4 para bloqueo o
terminación incierta y 2 para clave inválida. Nunca tratar esos retornos como
sincronización correcta. Clasificar 3/4 como diferimiento y 5 como señal de salud;
no crear alertas técnicas recursivas por esos resultados esperados. Si todo cron
se detiene, el checker debe invocarse desde un supervisor independiente; un
schedule:list correcto o un schedule:run con salida global 0 no acredita éxito.

La liberación ahora requiere motivo cerrado y rechaza running aún dentro de
ventana. La confirmación debe estar respaldada por comprobación externa de muerte
del proceso y sus hijos en todos los hosts; el reloj por sí solo no basta. El
principal registrado es el UID efectivo, si posix_geteuid está disponible; no es
identidad humana. En Windows se registra unverified_cli/null. Usar auditoría OS
autenticada y referencia OPS-NNN para atribuir una intervención humana. No pasar
nombres, emails, secretos o --actor. No hay endpoint web de liberación.

Consulta de auditoría, sin cuerpos ni datos personales:

~~~sql
SELECT run_id, task_key, actor_type, actor_identifier, reason, reference, released_at
FROM operational_task_releases ORDER BY id DESC LIMIT 25;
~~~

El composer.lock definitivo no cambia en esta corrección. Requiere PHP >=8.4.1;
composer check-platform-reqs --lock --no-dev pasa con el CLI local 8.4.21. El
composer.json conserva su restricción general histórica ^8.3, que por sí sola no
acredita compatibilidad con ese lock. Antes de desplegar verificar PHP y extensiones
por separado en web, CLI y worker, usando sus binarios/entornos reales y el mismo
check-platform-reqs. Un PHP 8.3 o 8.4.0 no es compatible con el lock. El operador
ha confirmado PHP 8.4.21 para web, CLI y worker (2026-10-15), compatible con el
mínimo del lock. No se han inspeccionado esos procesos ni sus extensiones desde
este entorno; esa comprobación sigue siendo obligatoria.

## OPS-1 en producción — evidencia comunicada el 19/10/2026

Esta sección actualiza las notas históricas anteriores sobre cPanel, provisión
pendiente y ausencia de pruebas del worker. La evidencia procede del operador;
Codex no accedió al host ni ejecutó comandos productivos. Los ejemplos siguientes
son procedimientos para operación autorizada, no acciones realizadas en este lote.

### Verificado por el operador

Host infra-01, proyecto aplicación app-informes, imagen local app-informes-app,
base Laravel app_informes en laravel-informes-db, red externa existente
app-informes_informes-network. Código compartido por bind mount de
/srv/production/laravel/app-informes a /var/www. Contenedores:
laravel-informes-app, laravel-informes-queue-worker, laravel-informes-db y
laravel-informes-ops-worker.

El worker default se conserva sin cambios:
php artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90
(incluye restablecimiento de contraseñas). OPS utiliza un proyecto Compose
independiente informes-ops, archivo /srv/production/laravel/ops-worker/compose.yaml,
contenedor laravel-informes-ops-worker, con:

~~~text
php artisan queue:work operations --queue=operations --timeout=14400 --tries=1 --sleep=3
~~~

Conexión Laravel operations, driver database, tabla jobs, cola operations,
retry_after=14700. Reinicio unless-stopped. Se verificó proceso PHP activo,
Running=true, Restarting=false, ExitCode=0, RestartCount=0. No publica HTTP;
usa la imagen y red existentes, el bind mount indicado, parada ordenada y
rotación de logs. No constan aquí valores exactos de stop_grace_period o de
rotación: contrastarlos con el archivo instalado antes de intervenir.

Prueba funcional comunicada: run 1622, salesforce-sync-delegation-managers,
manual, success/0; queued 2026-10-19 08:27:03, started 08:27:05, finished 08:27:06.
Demuestra consumo real de operations y auditoría; no demuestra reinicio del host
ni ejecución durante cuatro horas. Fechas del ledger en UTC según contrato de
la aplicación; conservar el timestamp original al contrastar evidencias.

### Cierre del incidente F2/F5 del 15/10/2026

La ausencia de bootstrap local hizo no evaluables Intereses y su métrica del
Resumen. El operador ejecutó la carga completa F2 y bootstrap F5. Son hechos
históricos, NO instrucciones de repetición para este correctivo:

| Evidencia | Resultado comunicado |
|---|---|
| F2 run 4281 | completed; 283932 intereses, 196640 activos, 87292 eliminados; cutoff 2026-10-15 14:45:15 UTC; sin errores declarados |
| F5 run 1 | completed; fuente F2 4281; 15129 actividades, 15114 relaciones resueltas, 15 no resueltas; errores 0 |
| Pipeline run operacional 28 | salesforce-sync-interest-reporting, success/0, 2026-10-15 15:05:02–15:05:15 |
| Fuentes posteriores | F2 4283 completed, F5 2 completed, IDs/cutoffs alineados |

El usuario confirmó que ambos dashboards cargaban resultados. No se reejecutan
cargas completas ni snapshots en este lote. Se mantiene el pipeline serial F2/F5.

### Investigación pendiente del incidente Interests F2 #5729

El operador comunicó que #5729 falló tras registrar 268.627 inserciones y
753 actualizaciones, con cuatro conflictos de origen de migración contra
propietarios locales. Esos contadores no identifican todas las filas afectadas
ni sus valores anteriores. El backup localizado del 27/10/2026 contiene cero
bytes descomprimidos y no es restaurable; no consta otra copia anterior
verificada. El operador comunicó después una copia completa del estado actual,
con gzip y checksum correctos y 105 tablas; no se ha probado su restauración y
la copia es posterior al fallo #5729. No acredita el estado anterior ni sirve
por sí sola para inicializar una generación certificada. El run completado
#5722 no demuestra que la tabla viva conserve su estado. No hay base
certificada para esta recuperación hasta verificar una.

La investigación debe realizarla personal autorizado, con evidencias
restringidas y resultados compartibles agregados sin identificadores sensibles:

1. Comprobar el estado efectivo del scheduler en el momento del despliegue;
   se comunicó que fue detenido manualmente, pero ese estado puede cambiar.
2. Preservar los runs, errores y una copia verificable del estado actual para
   investigación, sin modificar #5729 ni sus asociaciones.
3. Inventariar mediante lecturas los registros y versiones afectados en la
   medida que permitan las evidencias disponibles. Si aparece otra copia,
   comprobar su integridad, cobertura y restauración en aislamiento antes de
   usarla como referencia; cotejar también F5 y las dependencias del informe.
4. Contrastar individualmente los cuatro conflictos y sus propietarios con
   evidencia de origen en un canal restringido. Registrar la decisión y su
   autorización; no liberar ni reasignar orígenes por inferencia.
5. Evaluar distribución temporal de modificaciones y evidencia disponible para
   explicar el volumen consultado; el contador por sí solo no prueba una
   actualización masiva legítima.

La recuperación inicial aislada desde el estado actual dispone del procedimiento
descrito en [generaciones F2/F5](salesforce-interest-generations.md). Su uso
operativo requiere pruebas y revisión de cobertura, borrados, unicidad,
relaciones y alineación F5 antes de publicar. Una extracción actual no
garantiza reproducir una fotografía histórica. La investigación no
autoriza sincronizaciones reales, restauraciones ni reactivación del scheduler.

### Provisión y recuperación exclusiva de OPS

Las etiquetas Docker referían /srv/production/laravel/app-informes/docker-compose.production.yml,
pero ese archivo no estaba presente. No recrearlo, sustituirlo ni ejecutar un
Compose supuesto para app-informes. No utilizar comandos down globales.

1. Recuperar el YAML OPS exacto desde el host o un respaldo autorizado; no se
   incluye un supuesto YAML instalado en este repositorio. Revisarlo privadamente,
   sin copiar secretos a tickets, terminales compartidos o Git.
2. Comprobar imagen local, red externa y bind mount. Si falta la red, imagen,
   archivo o configuración de conexión, detenerse: no crear sustitutos por intuición.
3. Para reconstruir SOLO el worker a partir de un archivo nuevo aprobado, conservar
   proyecto informes-ops, nombre de contenedor, imagen, working directory /var/www,
   bind mount, red externa y comando indicados. No añadir puertos o dependencias
   que levanten/recreen DB, app o worker default. Heredar configuración por el
   mecanismo seguro revisado en el host, sin incrustar secretos en el YAML.
4. Aprobar parada ordenada acorde con una tarea de hasta 14400 s y revisar la
   rotación de logs y política unless-stopped. No se conoce aquí su valor instalado.
   No lanzar una segunda instancia mientras un proceso anterior pueda seguir vivo.
5. Arrancar exclusivamente el proyecto OPS con su archivo validado. El servicio
   exacto dentro del YAML debe comprobarse, no inferirse del nombre del contenedor.

Checks sin mostrar variables de entorno ni payloads (ejecutar solo con autorización):

~~~sh
docker image inspect app-informes-app --format '{{.Id}}'
docker network inspect app-informes_informes-network --format '{{.Name}}'
docker compose -p informes-ops -f /srv/production/laravel/ops-worker/compose.yaml config --services
docker compose -p informes-ops -f /srv/production/laravel/ops-worker/compose.yaml ps
docker inspect laravel-informes-ops-worker --format '{{.State.Running}} {{.State.Restarting}} {{.State.ExitCode}} {{.RestartCount}}'
docker top laravel-informes-ops-worker -eo pid,etime,args
~~~

Usar config --services, no volcar docker inspect/config completos: pueden incluir
secretos. Los argumentos del worker conocidos no contienen credenciales; no
compartir procesos ajenos. Para arrancar o parar SOLO OPS, después de comprobar
que el archivo define exclusivamente ese worker:

~~~sh
docker compose -p informes-ops -f /srv/production/laravel/ops-worker/compose.yaml up -d --no-deps --no-build
docker compose -p informes-ops -f /srv/production/laravel/ops-worker/compose.yaml stop
~~~

No ejecutar stop mientras exista trabajo vivo sin ventana y plan aprobados.
Tras caída, unless-stopped permite recuperar el contenedor cuando Docker vuelve,
pero no acredita terminación de hijos ni reanuda de forma segura un run unknown.
Primero comprobar procesos, reserva y jobs. No usar queue:retry en masa. Un job
operations tiene un intento; un worker muerto puede dejar reserva persistente.
El timeout 14400 es inferior a retry_after 14700; esta separación no demuestra
que un proceso o sus hijos hayan muerto. Mantener reservas inciertas hasta
verificación externa; operations:release exige --confirm-stopped y motivo cerrado.
No borrar jobs, reservas o locks para obtener estado verde.

Revisar logs acotados localmente (por ejemplo docker logs --since 30m --tail 100
laravel-informes-ops-worker), sin publicarlos sin sanitizar. Logs legacy de
integraciones pueden contener detalles externos; nunca subir el stdout completo,
.env, docker inspect completo o failed_jobs.exception/payload a tickets.

Consultas acotadas con acceso administrativo a la BD, sin secretos:

~~~sql
SELECT queue, COUNT(*) AS pending FROM jobs WHERE queue IN ('operations','default') GROUP BY queue;
SELECT id, queue, attempts, reserved_at, available_at, created_at FROM jobs
 WHERE queue='operations' ORDER BY id DESC LIMIT 20;
SELECT id, connection, queue, failed_at FROM failed_jobs
 WHERE queue='operations' ORDER BY id DESC LIMIT 20;
SELECT s.task_key, s.active_run_id, r.status, r.queued_at, r.started_at, r.finished_at
 FROM operational_task_states s LEFT JOIN operational_task_runs r ON r.id=s.active_run_id
 WHERE s.active_run_id IS NOT NULL LIMIT 22;
~~~

### Implementado por el correctivo del monitor (pendiente de desplegar)

La programación del monitor añade únicamente --scheduler al wrapper operations:run.
El comando de salud y el wrapper directo siguen devolviendo 5 ante incidencias.
En modo scheduler solo unhealthy/5 con inicio, fin, last_completed_id coincidente
y reserva liberada se traduce a salida de proceso 0. El ledger conserva unhealthy/5,
no actualiza last_success_id ni elimina alertas. Laravel deja de reportar un fallo
por esa evaluación técnicamente completada. Las otras 21 tareas no usan la opción;
se rechaza para cualquier clave distinta de operations-health.

Errores, códigos inesperados, catálogo/BD no disponibles, persistencia fallida,
reconciliación incompleta, ejecuciones no iniciadas y reservas perdidas conservan
salida no cero. 3/4 no se normalizan. Los fallos al finalizar ya no quedan atrapados
como si el run persistido fuera suficiente para acreditar toda la evaluación.
La comprobación extra por PK solo afecta al monitor programado; no escanea historia.

Alertas: scheduled_task_failure/scheduler es el contrato histórico; los fallos
actuales usan operational_task_failure/operations. Un éxito posterior de esa misma
tarea resuelve ambos tipos por fingerprint, conservando filas y resolución.
La ausencia de evidencia fresca abre operational_task_stale; para el monitor,
operational_monitor_stale. Un heartbeat unhealthy válido recupera técnicamente al
monitor, nunca a las integraciones. Fallo, omisión, queued o despliegue no resuelven
fallos de integración. No multiplicar chequeos completos por cada tarea recuperada.

### Validación posterior al despliegue y pendientes

No hay migraciones ni cambios de configuración de colas. Desplegar el código por
el procedimiento humano aprobado y recargar procesos que mantengan código antiguo
solo en ventana controlada. No usar queue:restart global indiscriminado: puede
afectar al worker default. No se autoriza aquí ninguna actuación en el host.

- Comprobar schedule:list --timezone=Europe/Madrid: 22 tareas, mismos cron/mutex;
  solo operations-health incorpora --scheduler. Comprobar cron externo cada minuto.
- Esperar una ocurrencia natural: comprobar ledger unhealthy/5 cuando existan
  incidencias, fin y reserva liberada. En logs/eventos del scheduler no debe aparecer
  fallo de ese monitor por código 5; sí deben seguir visibles los fallos técnicos.
- Supervisión externa mantiene operations:check-health: 5 incidencias, 1 fallo
  técnico, 0 sin incidencias exigibles. La ausencia total de cron requiere supervisor
  independiente y alerta por heartbeat; un schedule:run global 0 no prueba salud.
- Consultar alertas y últimos runs con los checks de diagnóstico siguientes; no
  resolver manualmente, reintentar jobs antiguos o ejecutar sincronizaciones para
  forzar verde. La prueba manual 1622 ya aporta evidencia de consumo de cola.
- Pendiente certificar reinicio real del host, tarea de cuatro horas, señales y
  parada ordenada bajo carga, valores efectivos de logging/grace del YAML y recuperación
  de huérfanos multihost. Nada de ello queda acreditado por Running=true.
- Rollback: volver al código previo conservando ledgers y configuración OPS;
  volverá el ruido de código 5, pero no borrar auditoría ni modificar default.

### Diagnóstico de las tres integraciones — evidencia histórica, no estado actual

| Tarea / hora Madrid | Comando y dataset | Evidencia comunicada (timestamp original) |
|---|---|---|
| seo-sync-ga4-organic / 05:45 | seo:sync-ga4-organic --days=120; seo_ga4_organic_conversions | scheduled_task_failure high, 2026-10-15 03:45:13 |
| seo-sync-search-console / 05:15 | seo:sync-search-console --days=120; seo_search_console | scheduled_task_failure high, 2026-10-15 03:15:17 |
| salesforce-sync-tasaciones / 01:00 | salesforce:sync-tasaciones --days=120; salesforce_tasaciones | scheduled_task_failure high, 2026-10-14 23:00:19 |

No hay acceso productivo en este encargo: no se ha consultado su estado actual.
El horario coincide con cron en Madrid y timestamps UTC en octubre, pero el
operador debe contrastar zona/origen del registro. No se infiere fallo vigente,
recuperación ni causa OAuth de esos timestamps. Ninguna alerta real se modifica.

Rutas reales inspeccionadas:

- SyncSeoGa4OrganicCommand -> Ga4OrganicConversionSyncService -> GoogleAnalyticsClient
  -> GoogleOAuthTokenProvider. Config services.google_analytics y seo_analytics.
  configured exige valores presentes y property_id numérico, no demuestra acceso.
  Si falta configuración emite SKIPPED/0: el wrapper lo registra skipped, sin
  cerrar fallos ni acreditar éxito. Además de OAuth, puede fallar acceso a Admin/Data
  API, property no verificable, timezone inválido, falta de web stream o Key Events,
  red, cuotas, formato de respuesta o persistencia. No se atribuye un error actual
  a ninguna de estas hipótesis sin el último run y clase/código sanitizados.
- SyncSeoSearchConsoleCommand -> SearchConsoleSyncService -> SearchConsoleClient
  -> GoogleOAuthTokenProvider. Config services.google_search_console y seo_analytics;
  falta de configuración también omite. Puede fallar acceso exacto a la property,
  HTTP/red/cuotas, datos rows inválidos o confirmación de un cutoff finalizado,
  además de OAuth. Su calendario fuente usa search_console_timezone; no cambiarlo
  para acomodar el horario Madrid del scheduler.
- SalesforceSyncTasacionesCommand -> CallCenterCommissions/Sync/SalesforceTasacionSyncService
  -> SalesforceClient/SalesforceAuthService. Config salesforce. Consulta por bloques
  de 31 días y perfiles de relaciones/campos con fallback; el cliente pagina y
  reautentica una vez tras 401. Posibles fallos de OAuth, permisos/campos, consulta,
  paginación, timeout o escritura local; una respuesta con cero filas es completed,
  no se interpreta por sí sola como fallo. No cambiar campos, periodos o fórmulas
  sin evidencia. El error de consultas ya se reduce a HTTP/tipo en SalesforceClient;
  el comando legacy imprime getMessage() sin sanitización adicional. No recoger ni
  publicar su stdout bruto, que el ledger operacional descarta; hardening general
  de CLI legacy queda fuera de este correctivo y sigue siendo riesgo residual.

Los comandos crean report_sync_runs con running, completan con cutoff/completed_at
o fallan con error_message sanitizado por ReportSyncRunService. Un fallo previo al
inicio del run de fuente puede tener solo evidencia operacional. El wrapper guarda
salida/estado y mensaje constante; no retiene stdout ni payload. El panel no consulta
Google/Salesforce. No se encontró un defecto reproducible específico que explique
las tres alertas con la evidencia aportada; las pruebas existentes simulan errores
HTTP/datos y se incluyen en la validación local, no certifican servicio externo.

Checks propuestos para el operador autorizado: obtener alerta actual y punteros
operacionales por claves, después fuente reciente por dataset/id. Existen índices
operacionales task/state y task/status/id, y de fuentes dataset/source/status/completed_at.
No seleccionar stats, payloads, SOQL ni errores crudos. Consultas de una fuente a
la vez usando su dataset y estado, con índice y límite:

~~~sql
SELECT id, type, source, state, first_detected_at, last_detected_at, resolved_at, occurrences
 FROM operational_alerts WHERE technical_identifier IN
 ('seo-sync-ga4-organic','seo-sync-search-console','salesforce-sync-tasaciones')
 ORDER BY id DESC LIMIT 20;
SELECT s.task_key, r.id, r.status, r.exit_code, r.started_at, r.finished_at,
 s.last_success_id, s.last_failure_id, s.active_run_id
 FROM operational_task_states s LEFT JOIN operational_task_runs r ON r.id=s.last_run_id
 WHERE s.task_key IN ('seo-sync-ga4-organic','seo-sync-search-console','salesforce-sync-tasaciones');
SELECT id, dataset, status, started_at, completed_at, source_cutoff_at
 FROM report_sync_runs WHERE dataset='seo_ga4_organic_conversions'
 AND source='google_analytics' AND status='failed'
 ORDER BY completed_at DESC LIMIT 5;
~~~

Repetir la última consulta para cada dataset y status completed, usando source google_search_console para Search Console y salesforce para Tasaciones. El operador debe
extraer localmente solo categoría/HTTP/tipo permitido del error de esos IDs y
correlacionar con el run operacional/horario, omitiendo tokens y datos personales.
Para cerrar diagnóstico faltan: estado actual, último fallo y éxito verificables,
cutoff/cobertura y código sanitizado reciente (o confirmación de ausencia de run).
Un report_sync_run completed aislado o un despliegue no resuelve automáticamente
la alerta histórica: la ejecución ordinaria auditada de la misma tarea debe acreditar
recuperación. Solo un nuevo éxito del wrapper resuelve los dos tipos de fallo;
un nuevo monitor unhealthy no los resuelve. Stale exige además evidencia fresca
según la cadencia. No forzar runs ni resolver por SQL para conseguir pantalla verde.

Observación reproducida en la revisión final de Tasaciones: invalidFieldFromMessage
intenta extraer un campo del mensaje de excepción. SalesforceClient reduce las
respuestas a HTTP/tipo mediante IntegrationErrorSanitizer::remoteFailure, perdiendo
ese nombre. Con respuesta sintética INVALID_FIELD, el mensaje seguro conserva
HTTP 400/tipo INVALID_FIELD y el parser devuelve null. Por tanto la retirada de
campos inválidos dentro de un perfil no dispone de ese detalle en este recorrido;
puede fallar aunque el adaptador sepa retirar campos cuando recibe un mensaje bruto.
No se restaura el payload ni se modifica el contrato de sincronización en este lote.
Pendiente específico: si el error reciente real es INVALID_FIELD, reproducir con
cliente HTTP fake y diseñar metadatos tipados/permitidos para el fallback, sin
exponer respuestas crudas. No se atribuye la alerta histórica a esta limitación
sin evidencia actual de tipo/campo permitido del servidor.

## Fase B Campañas — activación separada del despliegue (2026-10-23)

La nueva tarea `campaigns-sync-current-status` pertenece al catálogo central, con cron `37 */6 * * *` en Europe/Madrid (00:37, 06:37, 12:37 y 18:37). Se evita el minuto de las tareas económicas principales; esto no garantiza ausencia de carga concurrente. Ventana operacional y lock: 240 minutos. No hay cron paralelo ni llamadas de proveedor desde HTTP.

**Estado inicial obligatorio:** `CAMPAIGN_CURRENT_STATUS_ENABLED=false` (valor por defecto en `config/campaign_current_status.php`). Scheduler y ejecución manual no reservan ni ejecutan la tarea; el monitor omite tareas deshabilitadas. Una reserva en cola se vuelve a validar antes de iniciar. Los permisos manuales siguen limitados a Administración activa mediante el mecanismo existente. No se introduce endpoint público.

Despliegue humano futuro, sin ejecución realizada por esta entrega:

1. Revisar el diff/migración y plan operativo; mantener la variable deshabilitada. Aplicar únicamente la migración aditiva `2026_10_23_120000_create_campaign_current_statuses_table.php` bajo el procedimiento habitual autorizado **antes de servir el nuevo código web**, ya que las consultas de estado requieren la tabla. Revisar previamente el conjunto de migraciones pendientes: no ejecutar otras implícitamente por este runbook.
2. Publicar código, bundles Vite y `public/build/manifest.json` del mismo release juntos. Compilar con `npm run build` en desarrollo/CI; no instalar Node en PHP de producción. Actualizar caché de configuración y reiniciar workers según el proceso vigente; no reconstruir datasets ni cierres.
3. Con la tarea aún deshabilitada, verificar permisos, endpoints, CSV y asset referenciado; Estado no disponible es esperado hasta una consulta válida. Confirmar que no se ha iniciado ninguna nueva sincronización.
4. La activación es una operación separada y expresamente autorizada: revisar credenciales existentes, cuentas, límites, retención de `report_sync_runs`, conectividad y capacidad. El operador configura `CAMPAIGN_CURRENT_STATUS_ENABLED=true`, actualiza caché de configuración y procesos de larga duración. No añadir comandos de sincronización a hooks de despliegue.
5. Solo tras autorizar la primera prueba, el operador puede ejecutar `php artisan operations:run campaigns-sync-current-status` o utilizar la acción operacional existente con permisos/confirmación. No invocar directamente `campaigns:sync-current-status` ni fabricar `--operational-run`. Supervisar resultado agregado y runs por cuenta; un fallo parcial hace fallar la tarea aunque otras cuentas completen. La primera activación permite también el siguiente horario del scheduler: elegir su momento deliberadamente.

Las lecturas tienen paginación acotada, máximo diez cuentas por plataforma, cien páginas y 50.000 campañas por cuenta. Una página admite dos intentos de 30 s; se comprueba presupuesto antes de la siguiente página tras 540 s. OAuth Google tiene timeout adicional de hasta 60 s. No considerar este presupuesto un timeout duro global. Errores y estadísticas persistidas son genéricos, sin tokens ni payloads completos. Inventario incompleto no renueva filas de la cuenta y una ausencia no implica baja.

Rollback de código: deshabilitar primero la tarea, refrescar configuración y detener nuevas reservas; esperar o gestionar bajo protocolo las ejecuciones en curso antes de cambiar release. Restaurar código/manifest/assets previos juntos y reiniciar workers según el procedimiento habitual. **Conservar la tabla operativa y auditoría**: no ejecutar rollback de migraciones generales, borrar tablas históricas ni regenerar snapshots. La migración es técnicamente reversible, pero su `down()` solo elimina la tabla nueva y no forma parte del rollback de código recomendado. El release anterior ignora los campos nuevos.

Pendientes de validación humana: permisos y cobertura reales de Meta/Google, carga con inventario real, QA de tooltips/zonas horarias/números, y monitorización tras una primera lectura autorizada. Las pruebas de proveedores usan respuestas simuladas. La integración limpia añade MariaDB 11.4.13 portátil local aislado para migración, unicidad/índice, upserts concurrentes, UTC/frescura y reversibilidad; no constituye una validación de capacidad ni una prueba de cuentas reales.


### Guardas y alcance de la validación MariaDB de Fase B

`tests/Integration/CampaignCurrentStatusMariaDbTest.php` es opt-in mediante `CAMPAIGN_MARIADB_TEST=1` y exige entorno testing, driver mariadb, host 127.0.0.1 y nombre de base con prefijo `hrmotor_phase_b_test_`. El principal creó una instancia portátil temporal fuera del repositorio, con base vacía, sin leer ni cambiar .env, Docker o bases productivas. No ejecutar esta prueba sobre una base compartida: incluye reversión y recreación de la tabla operativa y limpieza de sus fixtures. La configuración phpunit.xml incluye solo Unit y Feature: Integration está fuera de la suite ordinaria y requiere invocar explícitamente su archivo, además del flag y las guardas. No ejecutar todas las migraciones por accidente en otra base.

El índice único cubre VARCHAR(16/30/30) plataforma/cuenta/campaña; los IDs solo admiten dígitos y las plataformas son códigos controlados, por lo que la colación heredada no introduce equivalencias de mayúsculas en la identidad admitida. El índice `sync_source` y la FK nullable mantienen auditoría y lectura por lotes. EXPLAIN de identidad y dos procesos de upsert se verifican en la base aislada; los bloqueos operacionales siguen siendo la barrera normal de sincronización. Los cuatro campos temporales nuevos (last_observed_at, last_verified_at, created_at y updated_at) usan DATETIME en UTC para que cambiar time_zone de sesión no desplace la evidencia temporal. No se cambia la zona de servidores productivos.

Límite de esta evidencia MariaDB: la batería amplia Dashboard reproduce 19 errores también en main 89282a3 (fechas con offset en DATETIME legacy y longitud de IDs de fixtures). Los focales B y la migración aislada pasan; esto no convierte toda la suite MariaDB en correcta ni permite extrapolar capacidad o compatibilidad de datos productivos. La revisión de esos casos corresponde a trabajo separado, sin cambios de históricos ni activación en esta entrega.

## Fase C — diagnóstico y saneamiento controlado de campañas

La publicación de Fase C cambia las **entidades campaña** expuestas por JSON,
CSV, rankings, filtros y snapshots nuevos a Meta/Google. No requiere migración,
reconstrucción, limpieza física ni sincronización para ese contrato. Desplegar
PHP y `public/build/manifest.json` junto con los assets Vite del mismo build;
comprobar con permisos normales que el catálogo excluye Salesforce y que los
KPI/CSV de atribuciones aún incluyen intereses legítimos. El filtro visible
«Estado» sigue siendo histórico. El rollback restaura código y assets del
release previo; no revierte ni reescribe tablas económicas.

### Diagnóstico de «Estado no disponible» — solo lectura

La tabla operativa esperada es `campaign_current_statuses`. Antes de atribuir
una causa a Meta/Google, un operador autorizado debe comprobar, sin publicar
IDs, respuestas ni secretos: existencia de la tabla y recuento por plataforma;
valor **efectivo** de `campaign_current_status.enabled` y su caché de
configuración; últimas ejecuciones de `operational_task_runs` para
`campaigns-sync-current-status`; registros `report_sync_runs` del dataset
`campaign_current_status` por plataforma/cuenta con `status` y fecha; y
coincidencia canónica de plataforma, cuenta y Campaign ID entre métricas
publicitarias y estados. Comparar además `last_verified_at` en UTC con el
instante de consulta (umbral 24 h), códigos reconocidos, timestamps futuros,
errores parciales por cuenta e identidades ambiguas en filas agregadas. Las
consultas deben ser agregadas o limitadas y de solo lectura; no mostrar
identificadores de cuenta ni texto bruto de errores en informes compartidos.
La ausencia de filas con la tarea deshabilitada es compatible con el diseño;
no demuestra un defecto del frontend. El estado histórico no sirve de respaldo.

La configuración predeterminada es `CAMPAIGN_CURRENT_STATUS_ENABLED=false`.
Una primera lectura requiere autorización **separada**: verificar credenciales
y cuentas en el gestor autorizado sin imprimirlas; confirmar migración, permisos,
cuotas y conectividad; aprobar un run manual por el catálogo operacional ya
existente; activar temporalmente la tarea y refrescar configuración/procesos;
ejecutar `php artisan operations:run campaigns-sync-current-status` bajo la
reserva y permisos existentes; revisar resultados y fallos parciales por
plataforma/cuenta, filas válidas y frescura; decidir posteriormente, con otra
autorización, si permanece activa la cadencia de seis horas. No añadir la orden
a hooks de despliegue ni llamar clientes durante HTTP. No se ha realizado esa
verificación en producción en esta entrega.

### Limpieza histórica propuesta, no autorizada

No existe una tabla física independiente de catálogo de campañas. Las filas
`platform=salesforce` de `campaign_attributions` y
`campaign_lead_attributions` sostienen intereses, oportunidades y KPIs: **no
borrarlas**. `campaign_report_snapshots.campaigns` antiguos pueden incluir
entidades Salesforce; son evidencia histórica y no se reescriben. Las
clasificaciones operativas previas en `campaign_operational_classifications`
requieren inventario y revisión individual. El [plan de saneamiento](ai/CAMPAIGN_SALESFORCE_CLEANUP_PROPOSAL.md)
define recuentos de solo lectura y la puerta de aprobación. No hay comando de
`--apply` autorizado ni se ha ejecutado borrado; una segunda ejecución del
despliegue solo genera snapshots nuevos conforme al contrato actual.

### Lectura del diagnóstico de sincronización histórica de Campañas (2026-10-26)

El panel administrativo distingue ahora la **última ejecución correcta registrada** de `campaigns-sync-meta` y `campaigns-sync-google` en `operational_task_states`/`operational_task_runs` de la **última fila de métricas escrita** (`MAX(synced_at)` en `campaign_platform_daily_metrics`). Una ejecución correcta sin filas nuevas puede conservar antiguo el segundo valor; no reemplazarlo por la hora del run. Se muestra además el estado del último intento, aunque haya fallado después de una ejecución correcta, y la fecha operacional se marca «Antigua» si supera 36 horas. Sin run de éxito acreditado se muestra «Sin ejecución verificada». Son campos de diagnóstico aditivos; no corresponden a la tarea separada `campaigns-sync-current-status` ni modifican su umbral de frescura de 24 horas.

La lectura del run ocurre localmente, con permiso administrativo y fuera de la caché económica. El panel vuelve a solicitarla en cada apertura, sin caché HTTP. Una ejecución directa ajena al catálogo podría no aparecer; la falta de run no acredita que nunca se ejecutó el comando. Para investigar un desfase de Google, cotejar en el monitor los últimos intentos y su resultado con la fecha de la última métrica y con los logs operacionales autorizados; no ejecutar una sincronización solo para conseguir una fecha reciente. Esta iteración no consultó cuentas productivas ni cambió horarios, activación, credenciales o tareas programadas.

## Correctivo de clientes Meta/Google para estado actual y Google económico (2026-10-27)

La evidencia productiva comunicada identifica dos fallos distintos: Meta rechaza con HTTP 400/código 100/subcódigo 1815001 el parámetro de consulta `effective_status` en el inventario, pero responde HTTP 200 al omitirlo y permite solicitar `effective_status` como campo. Google acepta OAuth, pero la URL configurada `v22` devuelve `UNSUPPORTED_VERSION`; una GAQL de atributos en `v25` respondió HTTP 200. El código elimina solo el parámetro Meta, mantiene `fields=id,account_id,name,status,effective_status` y cambia a `v25` el predeterminado de `config/services.php` y de ambos puntos de entrada de `GoogleAdsClient` (`googleAds:search` de estados y `googleAds:searchStream` de métricas/identificadores). No cambian las conversiones `costMicros`, fórmulas ni datos históricos ya persistidos. La compatibilidad de `searchStream` económico con datos de cuentas reales queda pendiente: solo se comprobó mediante respuestas simuladas. No interpretar una prueba aislada de GAQL de atributos como verificación de toda la sincronización económica.

**Despliegue sin activar estados:** publicar el release aprobado y mantener `CAMPAIGN_CURRENT_STATUS_ENABLED=false`; no añadir sincronizaciones a hooks. Antes de publicar, el operador debe comprobar de forma segura el valor efectivo de `services.google_ads.api_version` en la configuración Laravel del entorno, sin imprimir credenciales. Si producción define `GOOGLE_ADS_API_VERSION=v22`, esa configuración explícita prevalece sobre el nuevo predeterminado: cambiarla a `v25` requiere el procedimiento operacional autorizado de configuración. Después de ese cambio, reconstruir la caché de configuración (`php artisan config:cache` según el procedimiento habitual) y reiniciar los procesos persistentes que conserven la configuración antigua. Confirmar que el valor efectivo es `v25` antes de interpretar nuevos resultados. No modificar `.env` productivo desde Codex ni registrar su contenido.

La sincronización económica Google existente puede volver a ejecutarse por su programación vigente tras el despliegue; revisar ventana, rangos y responsables antes del release porque `GoogleCampaignSyncService::sync()` reemplaza las métricas del intervalo autorizado por cuenta dentro de una transacción. El correctivo no autoriza reprocesar meses cerrados, hacer backfill ni modificar cierres. Supervisar `campaigns-sync-google`: un comando puede salir con código 0 y comunicar «No se ha podido actualizar»; `OperationalTaskOutput` marca entonces el run como `failed` y conserva el código de salida para diagnóstico, sin guardar el texto ni tokens. Contrastar el estado operacional con la última métrica persistida antes de afirmar recuperación económica. `UNSUPPORTED_VERSION` en un test simulado produce advertencia sanitizada y ninguna escritura; no se ha ejecutado el proveedor real en esta tarea.

La primera sincronización de **estados actuales** sigue siendo una decisión separada: validar cuentas/credenciales y permisos mediante el procedimiento existente de Fase B, autorizar la operación, habilitar temporalmente la tarea solo bajo control operacional, ejecutar `php artisan operations:run campaigns-sync-current-status`, revisar `operational_task_runs` y `report_sync_runs` por cuenta y comprobar frescura <24 h antes de presentar estados. La ejecución previa ID 4728 falló en ambas cuentas según la evidencia comunicada y no dejó estados verificados; desplegar este código por sí solo no hará aparecer estados actuales. No activar el scheduler de forma permanente sin autorización posterior. No realizar llamadas Meta/Google desde el informe HTTP.

Rollback: restaurar el release anterior y su configuración efectiva coordinadamente; una reversión de código con `GOOGLE_ADS_API_VERSION=v25` explícito mantiene `v25`, mientras que volver a `v22` puede reproducir `UNSUPPORTED_VERSION`. Evaluar la reversión con el responsable operacional antes de cambiar la versión explícita. Refrescar caché de configuración y procesos tras el rollback; conservar tablas de estados, métricas, runs y cierres sin borrados ni reconstrucciones.

### Corrección posterior de GAQL económico Google Ads v25 (2026-10-27)

Tras el cambio de versión anterior, la evidencia productiva comunicada
distinguió un `searchStream` mínimo HTTP 200 de la consulta económica completa
HTTP 400 (`INVALID_ARGUMENT / UNRECOGNIZED_FIELD`). Los campos rechazados eran
`campaign.start_date` y `campaign.end_date`; el cliente solicita ahora
`campaign.start_date_time` y `campaign.end_date_time`. Las fechas civiles se
validan sin convertir zona horaria y se guardan en las columnas DATE existentes;
un fin no informado queda `NULL`. La respuesta HTTP 200 con estructura
incompleta se rechaza antes de reemplazar métricas; una fecha inválida aborta
la transacción de la cuenta. Los errores externos siguen comunicándose de
forma genérica, sin tokens ni payload completo.

Para un despliegue autorizado, publicar el código del release y comprobar que
el valor efectivo `services.google_ads.api_version` es `v25` sin mostrar
secretos. No hay migración, variable nueva, asset ni reconstrucción obligatoria.
No lanzar sincronización como parte del despliegue: la tarea económica vigente
podría ejecutarse después según su programación existente. Acordar previamente
la ventana y el intervalo abierto permitido; no incluir períodos cerrados ni
reconstruir cierres. Tras una ejecución **autorizada**, revisar el resultado
`campaigns-sync-google` y `report_sync_runs`, las advertencias sanitizadas y
una comparación por cuenta/día de inversión, impresiones, clics y conversiones
frente a la fuente y al estado anterior. Una prueba simulada no acredita éxito
productivo. Una respuesta válida sin filas conserva la semántica existente de
reemplazo vacío para el intervalo solicitado: comprobar el alcance antes de
autorizarla.

Rollback de código: restaurar el release anterior conservando métricas,
identificadores, runs, snapshots y cierres; no borrar datos ni ejecutar
backfill. Ese rollback recuperaría el GAQL con campos rechazados en v25, por
lo que la sincronización económica Google volvería a fallar hasta una solución
compatible. Cualquier dato escrito por una ejecución productiva posterior no
se revierte automáticamente al volver el código; requiere evaluación separada
del responsable económico.

## Campañas — protección de cierres y recuperación abierta (2026-10-27)

`CampaignOpenInvestmentWindow` usa los meses `closed` de
`campaign_investment_closures` como límite de escritura. La solicitud
programada de 120 días se acota al primer día posterior al último cierre
anterior al fin solicitado; no autoriza reescribir meses cerrados. Los
comandos manuales Meta/Google aplican el mismo límite, informan el intervalo
efectivo `[inicio, fin)` y registran estadísticas del intento en
`report_sync_runs`. Un intervalo totalmente cerrado se omite sin petición al
proveedor; `report_sync_runs` conserva `skipped` y motivo `closed_period`.
El builder de atribución solo lee históricos en `--dry-run`; su
modo de escritura rechaza un intervalo cerrado y acota uno mixto. Cierre,
reapertura y escrituras económicas comparten bloqueo asesor MariaDB/MySQL;
el comienzo se revalida dentro de la transacción antes de reemplazar filas.
El refresco puede leer snapshots aprobados, pero este cambio no los regenera.

Los cierres **no consecutivos** requieren atención especial: el rango
ordinario que abarque un cierre posterior comienza después del último mes
`closed`, aunque un mes anterior esté reabierto. Procesar un mes reabierto
anterior exige autorización económica para un **rango aislado** que termine
antes del cierre siguiente; no usar `--days=120` esperando que lo incluya.
La reapertura no dispara una reconstrucción por sí misma. La prueba aislada
MariaDB de dos procesos comprueba la serialización del escritor y el cierre;
no sustituye una validación de concurrencia/carga productiva.

### Linaje de atribución y publicación

El control `CampaignPublicationReadiness` lee el último `report_sync_runs`
de Meta (`campaign_platform_daily_metrics/meta`), Google
(`campaign_platform_daily_metrics/google_ads`), F2
(`salesforce_interests/salesforce`) y Opportunities
(`salesforce_opportunities/salesforce`). Cada uno debe estar `completed` y
tener cutoff; el snapshot directo Opportunity–Interest también debe estar
completo y ser posterior a la sync de Opportunities. Una ejecución manual
completa cuenta igual que una programada; un intento posterior fallido o
incompleto impide certificar la fuente. Los runs Meta/Google deben cubrir el
tramo abierto del rango solicitado; el día corriente puede ser parcial, pero
no se presume cobertura futura. Para las plataformas se cotejan
también intentos operacionales posteriores fallidos. No usar solo la fecha
de la última fila como prueba de éxito.

El builder automático del catálogo usa `--require-ready`. Antes de escribir
exige fuentes completas; registra en `report_sync_runs` un run
`campaign_attributions/campaigns` con IDs de fuentes y rango efectivo. Si
desde el último build solo hubo F2 u Opportunity sin cambios materiales y
el rango sigue cubierto, emite `SKIPPED:` y la operación queda omitida, no
fallida. Una nueva referencia del snapshot directo invalida la atribución
cuando cambia su `reference_fingerprint`, aunque cambie únicamente el
contenido de las relaciones. Estadísticas ausentes o un run intermedio
fallido se tratan conservadoramente como cambio. Los manuales sin
`--require-ready` conservan la opción de reconstruir, pero quedan
`unverified` si falta o cambia evidencia y **no** acreditan publicación.

`reports:refresh-campaigns --store` exige atribución vigente del mismo linaje
y cobertura del rango económico abierto. Vuelve a comprobar las fuentes
antes de guardar y persiste juntos `campaign_report_snapshots` y el run
`campaign_report_snapshots/campaigns`, con `snapshot_id`,
`attribution_run_id` y `source_ids`. Omite una nueva publicación si el contexto
predeterminado ya tiene snapshot vigente. `filters_hash` es SHA-256 de los
filtros normalizados por el dataset, sin fechas; la cobertura temporal se
verifica por separado. Una ejecución fallida o un snapshot sin
run de publicación coherente deja `dataset_cutoff_at=NULL`; el informe
muestra «Actualización del informe no verificada» y un motivo genérico en
`dataset_freshness_reason`. Cuando la publicación sigue válida, «Datos
actualizados» muestra el cutoff mínimo de las fuentes verificadas, no la
hora de renderizado. El snapshot publicado y la atribución deben cubrir el
período consultado y la huella debe coincidir con los filtros actuales; un
contexto filtrado distinto, un snapshot antiguo con otra huella o un rango
fuera de cobertura no reciben la marca de frescura.
En un período totalmente cerrado la marca operativa queda no verificable,
sin impedir la lectura del snapshot económico aprobado. La caché económica versiona los runs relevantes para
no reciclar esa etiqueta tras un cambio de estado. No se introducen llamadas
a Salesforce, Meta o Google durante HTTP.

### Reconciliación horaria de la publicación

`campaigns-reconcile-publication` se programa a las `09:20` y luego cada hora
hasta las `23:20` en `Europe/Madrid`. Evalúa las fuentes Meta, Google, F2,
Opportunities y el snapshot directo; después, la cobertura de la atribución y
la publicación del contexto predeterminado. En cada pasada reserva y ejecuta
**una sola** dependencia pendiente mediante `OperationalTaskService`, usando
la misma clave, exclusión persistente y auditoría de la tarea hija ordinaria.
Los horarios ordinarios de esas fuentes, atribución y refresco permanecen
iguales; el reconciliador solo añade oportunidades condicionadas de reintento.
El propio reconciliador también usa reserva persistente y no admite disparo
manual desde la interfaz operacional. No hay cron paralelo ni nuevas llamadas
de proveedor durante las peticiones HTTP.

Antes de reservar una tarea hija cuenta sus `operational_task_runs` de origen
`scheduler`, no omitidos, desde el inicio del día civil de Madrid: el máximo
son tres intentos diarios **por tarea**, incluyendo el run ordinario. Una
reserva ya activa, un límite agotado o ausencia de tramo económico abierto se
registran como omisión, sin forzar escrituras; un fallo de la hija se registra
en su propio run y el reconciliador devuelve fallo para supervisión. La pasada
siguiente reevalúa el linaje. No asumir que todas las dependencias se recuperan
en una hora ni que quedarán publicadas antes de las 23:20; si persiste la
incidencia, revisar los runs y autorizar la actuación manual por el proceso
existente. La reconciliación puede volver a llamar a proveedores dentro de
los límites del catálogo: revisar carga, cuotas y cierres antes de habilitar
el scheduler en producción. Tras las comprobaciones y la autorización para
reactivarlo, confirmar que el nuevo task figure habilitado en el catálogo y
que cada pasada genere a lo sumo una reserva hija.

La purga transversal vigente retiene runs `completed` aproximadamente un
mes y `failed`/`unverified` durante 14 días; no se amplía aquí. La pérdida
de un run exigido por el linaje debe degradar la frescura o forzar una
reconstrucción conservadora, nunca sustituirse por fechas inferidas. Si se
investiga una anomalía, preservar los registros de auditoría mediante el
proceso autorizado antes de su vencimiento; no bloquear ni alterar la purga
productiva desde el informe.

Antes de reactivar tareas, comprobar **en lectura** que septiembre de 2026 y
todos los meses anteriores que la organización considera cerrados tienen
fila `closed` en `campaign_investment_closures`. La guarda depende de ese
inventario persistido: una ausencia o reapertura autorizada cambia el límite.
Revisar también procesos ya reservados: el despliegue no detiene un worker
antiguo sin la guarda. Mantener el scheduler detenido hasta cerrar la revisión
económica y operativa.

Despliegue y recuperación, solo tras autorización humana:

1. Guardar recuentos y huellas de solo lectura de cierres, snapshots, métricas
   y atribuciones cerradas; retener los resultados sensibles en el canal
   autorizado, no en tickets compartidos.
2. Publicar PHP, JS, bundle Vite y manifest del mismo release y reiniciar
   procesos persistentes. No hay migración ni variable nueva. No incluir una
   sincronización en los hooks de despliegue.
3. Con el scheduler aún parado, comprobar lecturas, permisos, últimos runs
   Meta, Google, F2, Opportunities, snapshot directo, atribución y publicación.
   Contrastar sus IDs, cutoff, rango y resultado; `MAX(synced_at)` reciente no
   acredita un run completo.
4. Autorizar por separado la recuperación **solo del período abierto**.
   Confirmar límites civiles Europe/Madrid y fechas efectivas reportadas;
   ejecutar por el mecanismo operacional existente, comparar inversión,
   impresiones, clics y conversiones por cuenta/día con el estado anterior,
   reconstruir atribuciones abiertas solo si cambió evidencia material o
   falta cobertura, y publicar el snapshot. Una respuesta vacía válida
   necesita contraste de alcance antes de aceptarse como cero.
5. Repetir huellas y KPI históricos cerrados antes de mantener el scheduler
   activo; observar fallos parciales, advertencias y dependencias pendientes.

Rollback: detener nuevas reservas, gestionar jobs en curso y restaurar código
y assets previos como unidad. **Mantener inactivos los escritores económicos
con el release anterior**, que carece de la guarda. No revertir cierres,
snapshots ni datos históricos. Una escritura autorizada de octubre no se
deshace al restaurar código. Si el bloqueo asesor supera su timeout, la
operación falla antes que escribir sin protección. Las pruebas sintéticas
SQLite no equivalen a validar contención real MariaDB ni datos productivos.

### Diagnóstico de Google Ads Venta con inversión y cero resultados

La captura comunicada no fija con certeza período ni filtros y no demuestra
que el cero sea erróneo. Usar credenciales **solo lectura**, parámetros
vinculados y el mismo período/filtros de la API. No consultar Salesforce ni
ejecutar sincronizaciones para este diagnóstico; conservar IDs y datos
comerciales solo en un anexo de acceso restringido.

1. Guardar los parámetros de `/informes/campanas/data/summary` (intervalo,
   `google_ads`, Venta, subcontexto y demás filtros) y los agregados devueltos.
   La tarjeta Google no equivale necesariamente a todo octubre.
2. Agrupar `campaign_platform_daily_metrics` del intervalo por
   `(account_id,campaign_id)` con `platform='google_ads'`; contar filas,
   sumar `spend` y obtener mínimo/máximo `metric_date`. Comparar pares y
   nombres con `campaign_platform_identifiers`; revisar el último run Google
   y si los identificadores o fechas faltan.
3. Convertir el intervalo Europe/Madrid a límites UTC. Contar Interests
   activos en `salesforce_interests` por `functional_created_at` y presencia
   de `utm_id`, `utm_content` y `utm_campaign`, sin devolver valores ni PII.
   Una igualdad literal UTM/ID solo es pista: el builder aplica normalización,
   precedencia y rechazo de ambigüedad. Su `--dry-run`, bajo acceso autorizado,
   da recuentos de métodos y `excluded_by_reason` sin escribir; no copiar
   muestras de Interest ID a tickets compartidos.
4. Contar en `campaign_attributions` por plataforma/cuenta/Campaign ID los
   `interest_id`, `opportunity_id`, estados de matching y flags de venta;
   cotejar `campaign_lead_attributions` y la relación
   `opportunity_relationship_status`. Distinguir: sin Interest, Salesforce-only,
   exclusión, ambigüedad, Opportunity ausente o Opportunity sin Venta.
5. Repetir con la clasificación Venta y los filtros efectivos de la petición.
   Localizar si la pérdida ocurre en ingestión, matching, clasificación,
   agregado, caché o presentación. Solo corregir código cuando exista un
   registro válido perdido de forma reproducible; no ampliar matching ni
   alterar una fórmula para fabricar resultados.

Para investigar específicamente **1–27 de octubre de 2026** en Madrid, fijar
el rango semiabierto `[2026-09-30 22:00:00,
2026-10-27 23:00:00)` en UTC: comprende desde las 00:00 del día 1 hasta el
final civil del día 27, incluido el cambio de horario del 25. Vincular estos
límites como `:inicio_utc` y `:fin_utc`; no usar un desplazamiento UTC fijo
para ambos extremos. Registrar en un canal restringido los filtros exactos
de la petición (`context=venta`, estado histórico, cuenta, plataforma,
campaña y otros seleccionados) y las fechas de los últimos runs completos o
fallidos de Google, F2, Opportunities, atribución y publicación. Una etiqueta
de «Datos actualizados» neutral o una sync incompleta impide certificar el
cero como dato productivo vigente.

Las siguientes consultas devuelven **solo agregados por plataforma y tipo**,
sin IDs ni datos personales. La primera usa el hecho de presentación
`campaign_lead_attributions`, que sí guarda `campaign_type`; la segunda usa
`campaign_attributions` legacy y obtiene el tipo únicamente de una fila
correspondiente de la primera tabla (legacy no tiene columna `campaign_type`).
El emparejamiento de diagnóstico por `interest_id`, plataforma y Campaign ID
no sustituye la conciliación ni deduplicación del dataset:

```sql
SELECT platform, campaign_type,
       COUNT(*) AS filas,
       COUNT(DISTINCT interest_id) AS intereses,
       COUNT(DISTINCT CASE WHEN is_ambiguous = 0 AND interest_is_deleted = 0
            THEN interest_id END) AS intereses_no_ambiguos_activos,
       COUNT(DISTINCT CASE WHEN has_sale = 1 THEN opportunity_id END) AS oportunidades_marcadas_venta
FROM campaign_lead_attributions
WHERE platform IN ('google_ads', 'meta')
  AND interest_functional_created_at >= :inicio_utc
  AND interest_functional_created_at < :fin_utc
GROUP BY platform, campaign_type;

SELECT ca.platform, COALESCE(cla.campaign_type, 'sin_tipo_en_cla') AS tipo_diagnostico,
       COUNT(DISTINCT ca.id) AS atribuciones_legacy,
       COUNT(DISTINCT ca.interest_id) AS intereses_legacy,
       COUNT(DISTINCT CASE WHEN ca.has_sale = 1 THEN ca.opportunity_id END) AS oportunidades_marcadas_venta
FROM campaign_attributions AS ca
LEFT JOIN campaign_lead_attributions AS cla
  ON cla.interest_id = ca.interest_id
 AND cla.platform = ca.platform AND cla.campaign_id = ca.campaign_id
WHERE ca.platform IN ('google_ads', 'meta')
  AND ca.interest_functional_created_at >= :inicio_utc
  AND ca.interest_functional_created_at < :fin_utc
GROUP BY ca.platform, COALESCE(cla.campaign_type, 'sin_tipo_en_cla');

SELECT platform, COUNT(*) AS campanas_con_tipos_mixtos
FROM (
    SELECT platform, campaign_id, campaign_name
    FROM campaign_lead_attributions
    WHERE platform IN ('google_ads', 'meta')
      AND interest_functional_created_at >= :inicio_utc
      AND interest_functional_created_at < :fin_utc
      AND interest_is_deleted = 0 AND is_ambiguous = 0
    GROUP BY platform, campaign_id, campaign_name
    HAVING COUNT(DISTINCT campaign_type) > 1
) AS mixtas
GROUP BY platform;
```

Contrastar esos recuentos con la respuesta autorizada de summary, detalle y
CSV bajo **los mismos filtros**, primero `Todos` y después `Venta`, y con el
run de atribución que cubre el rango. Un cero Google con gasto puede ser
legítimo, deberse a ausencia de Interest/matching o a filtros; si aparece una
campaña mixta y las atribuciones Venta se pierden solo en comparativa/detalle,
el defecto sintético corregido es candidato. No extrapolar ese mecanismo al
caso productivo sin la conciliación de filas agregadas y el linaje de sync.

Ejemplos de consultas preparadas con `:cuenta` y `:campana` vinculados como
**cadenas**, límites diarios `:inicio_dia`/`:fin_dia` y UTC
`:inicio_utc`/`:fin_utc`:

```sql
SELECT COUNT(*) AS filas, SUM(spend) AS inversion,
       MIN(metric_date) AS primer_dia, MAX(metric_date) AS ultimo_dia
FROM campaign_platform_daily_metrics
WHERE platform = 'google_ads' AND account_id = :cuenta
  AND campaign_id = :campana
  AND metric_date >= :inicio_dia AND metric_date < :fin_dia;

SELECT COUNT(*) AS intereses_activos,
       SUM(CASE WHEN NULLIF(TRIM(utm_id), '') IS NOT NULL THEN 1 ELSE 0 END) AS con_utm_id,
       SUM(CASE WHEN NULLIF(TRIM(utm_content), '') IS NOT NULL THEN 1 ELSE 0 END) AS con_utm_content,
       SUM(CASE WHEN NULLIF(TRIM(utm_campaign), '') IS NOT NULL THEN 1 ELSE 0 END) AS con_utm_campaign
FROM salesforce_interests
WHERE functional_created_at >= :inicio_utc
  AND functional_created_at < :fin_utc AND is_deleted = 0;

SELECT COUNT(DISTINCT interest_id) AS intereses_atribuidos,
       COUNT(DISTINCT opportunity_id) AS oportunidades_enlazadas,
       SUM(CASE WHEN has_sale = 1 THEN 1 ELSE 0 END) AS filas_con_venta
FROM campaign_attributions
WHERE platform = 'google_ads' AND account_id = :cuenta
  AND campaign_id = :campana
  AND interest_functional_created_at >= :inicio_utc
  AND interest_functional_created_at < :fin_utc;
```

Estos recuentos no replican la deduplicación, los universos ni los filtros del
dataset: solo ubican un posible tramo de pérdida y se contrastan con builder
y API. No se declara correcto ni incorrecto el cero observado hasta completar
esa comparación; el hallazgo tampoco autoriza reabrir un período cerrado.

## PR #92 — continuación por generaciones (2026-10-28)

La fase anterior de 503 y transacciones por bloque queda complementada por
[generaciones F2/F5](salesforce-interest-generations.md). Ese documento define
el nuevo esquema, la CLI de inicialización con evidencia, el aislamiento,
publicación, recuperación de fallos, despliegue y rollback. Los pasos históricos
que suponen bootstrap sobre la tabla viva no deben utilizarse con esta versión.
No se ha realizado ninguna acción productiva. La copia posterior a #5729,
comunicada como íntegra en gzip/checksum, sigue sin restauración ensayada y no
representa una base anterior certificada. Inventario de filas afectadas y causa
del volumen extraordinario pendientes. Confirmar estado real del scheduler en
la intervención; no reactivarlo por la mera presencia de este cambio.

# Operaciones de producción

## Alcance e inventario

Runbook para PHP 8.4, MariaDB 10.6 y cPanel. Producción no dispone de Node/npm.
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

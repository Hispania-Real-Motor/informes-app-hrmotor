# Operación auditable OPS-1

Implementación local del 2026-10-15. No acredita despliegue ni estado de cron o
workers productivos. Base e7073d3affa43b7d6a5295292667993b7a164493.

## Contrato

El catálogo de servidor es la única fuente de comandos, argumentos, cadencias,
márgenes, riesgo y permiso manual. La request solo acepta task_key, confirmed y
CSRF. Admin activo se comprueba al solicitar y al consumir. El controller nunca
ejecuta Artisan. Rate limit: 10 requests/minuto y cooldown persistente de 60 s
por tarea; una reserva activa impide otra instancia independientemente del cooldown.

El scheduler usa operations:run y conserva cada withoutOverlapping anterior.
La reserva de BD se obtiene en transacción con lockForUpdate sobre la PK de tarea,
antes de encolar o ejecutar. Enqueued/running/unknown retienen esa reserva.
No se utiliza el mismo lock de caché de los comandos internos: Stock,
pipeline Interest, F5 y snapshot directo conservan sus protecciones propias.
Los comandos CLI directos quedan fuera del contrato de reserva común; usar
siempre el wrapper operacional para operación ordinaria. No lanzar comandos
directos ni liberar mutex sin comprobar procesos reales.

Queue operations usa driver database fijo, cola operations, timeout 14400 s,
retry_after 14700 s y un intento. Configuración del worker separada de default.
La infraestructura actualmente activa no puede deducirse del repositorio.

## Evidencia y límites

El ledger almacena tarea, origen, actor numérico o null, correlación UUID, intento,
argumentos fijos no sensibles, queued/started/finished, estado, exit code y duración.
No retiene stdout, excepciones crudas, respuestas externas, secretos ni PII.
Los mensajes de error son constantes. OperationalTaskOutput descarta el texto y
solo observa marcadores explícitos de omisión SEO/Ads y fallos parciales Ads;
sus contratos están cubiertos por tests. Si cambia ese vocabulario CLI deberá
adaptarse el detector. Stock comunica omisión por lock mediante exit 2, sin
alterar su firma. El éxito de un comando no reemplaza la readiness del dataset.

Las consultas del panel leen punteros por PK y paginan 25 runs. Los punteros
al último éxito/fallo persisten aunque se depure historial. Retención: 90 días
para terminales no referenciados, excluyendo tareas con alertas abiertas.
Activos, queued, running y unknown nunca se depuran. Un actor eliminado conserva
su ID histórico; el worker no ejecuta si ese usuario ya no existe.

Sin historia: Nunca ejecutado. En cola: reserva entregada sin inicio.
Ejecutándose: inicio acreditado. Correcto: fin correcto verificable y vigente.
Fallido: salida no cero, excepción o fallo parcial conocido. Omitido: no acredita
éxito. Retrasado: sin éxito que cubra la última ocurrencia exigible.
Desconocido/retrasado: cola >15 min, running superior a ventana o worker interrumpido.
No hay recuperación inventada: solo un éxito vigente resuelve stale.

La cadencia usa CronExpression en Europe/Madrid, incluida transición DST.
La última ocurrencia exigible es la anterior a ahora menos el margen explícito.
El inicio del último éxito debe ser posterior o igual a esa ocurrencia.
Los márgenes diarios derivan de los mutex operativos existentes; no son SLA
certificados. Cada 15 min admite 10 min; horario, 30 min; monitor, 10 min.
Un monitor dentro del scheduler no puede alertar por sí solo si todo cron se
detiene: hace falta supervisión externa del heartbeat del monitor.

## Reseñas comerciales

02:20 Europe/Madrid, --months=2, withoutOverlapping(120). Entre Meta/Google
(01:30/01:45) y Stock (03:30), sin cambiar las otras ventanas. No garantiza
ausencia de carga concurrente si una tarea anterior se alarga; evita otra
coincidencia deliberada con el bloque Salesforce de 03:30–07:35.
Dos meses móviles cubren holgadamente el mes natural anterior; dataset
commercial_reviews intacto. Readiness sigue exigiendo run completed, completed_at,
cobertura mensual y frescura closure_source_max_age_hours (48 h por defecto).

## Catálogo final

Cron de cinco campos en Europe/Madrid. Todos usan exclusión persistente por tarea.
Riesgo moderado para las 18 operaciones manuales, alto para las reservadas a
scheduler (incluido el monitor interno, por tratarse de infraestructura).
Las ventanas son umbrales de observación; no liberan un proceso en curso.

| Clave | Comando y argumentos fijos | Cron | Ventana / margen (min) | Manual |
|---|---|---|---|---|
| reports-prune-transversal-data | reports:prune-transversal-data  | 30 0 * * * | 30 / 30 | No |
| stock-sync-daily | stock:sync-daily --sales-days=14 --logistics-days=30 | 30 3 * * * | 120 / 120 | No |
| salesforce-sync-delegation-managers | salesforce:sync-delegation-managers --from=2026-07-01 | 15 4 * * * | 60 / 60 | Sí |
| salesforce-sync-monthly-commercial | salesforce:sync-monthly-commercial --days=2 | */15 * * * * | 30 / 10 | Sí |
| salesforce-sync-interest-reporting | salesforce:sync-interest-reporting  | 5 * * * * | 30 / 30 | Sí |
| salesforce-sync-tasaciones | salesforce:sync-tasaciones --days=120 | 0 1 * * * | 180 / 180 | Sí |
| campaigns-sync-meta | campaigns:sync-meta --days=120 | 30 1 * * * | 180 / 180 | Sí |
| campaigns-sync-google | campaigns:sync-google --days=120 | 45 1 * * * | 180 / 180 | Sí |
| campaigns-build-attribution | campaigns:build-attribution --days=120 | 30 8 * * * | 240 / 240 | Sí |
| reports-refresh-campaigns | reports:refresh-campaigns --days=120 --store | 0 9 * * * | 120 / 120 | Sí |
| salesforce-sync-opportunities | salesforce:sync-opportunities --days=2 --modified | 10 7 * * * | 180 / 180 | Sí |
| salesforce-sync-opportunity-interest-direct | salesforce:sync-opportunity-interest-direct --reason=Snapshot diario ROT-3 posterior al sync de Opportunities | 35 7 * * * | 180 / 180 | Sí |
| salesforce-sync-calls | salesforce:sync-calls --days=7 | 45 4 * * * | 60 / 60 | Sí |
| seo-sync-search-console | seo:sync-search-console --days=120 | 15 5 * * * | 120 / 120 | Sí |
| seo-sync-salesforce-organic | seo:sync-salesforce-organic --days=120 | 30 5 * * * | 120 / 120 | Sí |
| seo-sync-ga4-organic | seo:sync-ga4-organic --days=120 | 45 5 * * * | 120 / 120 | Sí |
| seo-sync-technical-health | seo:sync-technical-health  | 0 6 * * * | 120 / 120 | Sí |
| seo-build-analytical-snapshots | seo:build-analytical-snapshots  | 15 6 * * * | 120 / 120 | Sí |
| seo-evaluate-analytical-snapshots | seo:evaluate-analytical-snapshots  | 30 6 * * * | 120 / 120 | Sí |
| seo-send-executive-daily-email | seo:send-executive-daily-email  | 0 8 * * * | 30 / 30 | No |
| salesforce-sync-commercial-reviews | salesforce:sync-commercial-reviews --months=2 | 20 2 * * * | 120 / 120 | Sí |
| operations-health | operations:check-health  | */5 * * * * | 5 / 10 | No |

## Alcance de la verificación

Los tests locales usan SQLite, caché/colas sintéticas o tabla jobs local sin
worker, y clientes externos mockeados. Verifican exclusión por reserva y entregas
duplicadas/intercaladas. Incluyen dos peticiones HTTP en procesos independientes,
con barrera común y SQLite temporal: solo una crea Job/run en cola. No certifican
contención en MySQL/MariaDB ni supervisor, cron o workers productivos. Esa validación queda como
check de despliegue. No se realizó inspección visual en navegador con datos reales.
El render Blade y sus componentes se verifican por tests; móvil/teclado conservan
los patrones del Design System y requieren validación visual en el entorno de QA.

## Notas finales de contrato

Las fechas persistidas siguen UTC (timezone de la aplicación); calendario del
scheduler, cálculo de ocurrencias y presentación del panel usan Europe/Madrid.
Un error de acuse de Queue no libera una ejecución que ya figure running.
Las solicitudes encoladas caducadas conservan reserva hasta consumo/reconciliación
manual segura; no se libera por TTL un proceso de estado incierto.

Se endurecieron los warnings de los servicios Ads detectados en la revisión:
omiten excepciones externas y cuentas al escribir logs o mensajes operacionales.
Esto no cambia métricas ni atribución. El ledger no pretende reemplazar una
auditoría exhaustiva de todos los logs legacy de integración; Stock conserva su
manejo previo de excepciones fuera del nuevo ledger, cuyo canal operativo
descarta stdout. Revisar ese logging legacy en un lote específico de hardening.

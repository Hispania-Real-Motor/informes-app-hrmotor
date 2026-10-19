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
Activos, queued, running y unknown nunca se depuran. El puntero last_completed_id
preserva también la última evaluación unhealthy del monitor. Un actor eliminado conserva
su ID histórico; el worker no ejecuta si ese usuario ya no existe.

Sin historia: Nunca ejecutado. En cola: reserva entregada sin inicio.
Ejecutándose: inicio acreditado. Correcto: fin correcto verificable y vigente.
Fallido: salida no cero, excepción o fallo parcial conocido. Omitido: no acredita
éxito. Retrasado: sin éxito que cubra la última ocurrencia exigible.
Desconocido/retrasado: cola >15 min, running superior a ventana o worker interrumpido.
No hay recuperación inventada: solo un éxito vigente resuelve stale de una tarea.
En el monitor se distinguen heartbeat técnico y salud de las tareas observadas.

La cadencia usa CronExpression en Europe/Madrid, incluida transición DST.
La última ocurrencia exigible es la anterior a ahora menos el margen explícito.
El inicio del último éxito debe ser posterior o igual a esa ocurrencia, siempre
que ya sea exigible según la política de arranque definida más abajo.
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

## Correcciones de revisión 41c1e7a (2026-10-15)

Base revisada: 41c1e7abf8b60d5e95f4af31287d5820a5b5f21a. Catálogo, horarios,
argumentos, 18 acciones manuales y cola operations se mantienen.

### Matriz de códigos de salida

| Código | operations:check-health | operations:run (sin --scheduler) | operations:release |
|---:|---|---|---|
| 0 | Evaluación completa sin incidencias actualmente exigibles | Solo run success con inicio, fin y salida 0 | Liberación auditada; el run queda failed, nunca success |
| 1 | Fallo técnico al evaluar; mensaje constante | Run failed o fallo técnico del wrapper | Fallo técnico; transacción revierte si no se pudo auditar |
| 2 | No utilizado | Clave inválida | ID, motivo, referencia o confirmación inválidos |
| 3 | No utilizado | Comando realmente iniciado pero omitido por su contrato/lock/configuración | No utilizado |
| 4 | No utilizado | Reserva incompatible, queued/running/unknown o evidencia incompleta | Reserva no liberable o no huérfana según política |
| 5 | Evaluación completa con incidencias | Solo el monitor: run unhealthy con inicio, fin y salida 5 | No utilizado |

El exit_code persistido sigue siendo el resultado del comando subyacente; el
wrapper normaliza su propio retorno según el estado y la evidencia. Un comando
ordinario que devuelva 5 sigue siendo failed/retorno 1; solo operations-health
conoce el contrato de evaluación con incidencias. Un skipped por reserva conserva
started_at y exit_code null: no se inventa ejecución del comando.

Los retornos 3 y 4 son diferimientos esperados, no éxitos ni fallos técnicos.
No abren por sí mismos operational_task_failure: la cadencia y el último éxito
vigente determinan cuándo el diferimiento se convierte en retraso. Un skipped
legítimo tampoco publica éxito nuevo; si aún existe éxito vigente, la salud puede
seguir sin incidencias exigibles hasta vencer su ventana. El scheduler continúa
con las demás tareas aunque el proceso hijo devuelva un código no cero. Si el
mutex externo de Laravel impide incluso arrancar el wrapper, no se inventan run
ni retorno; la falta de evidencia se evalúa por cadencia.

### Monitor sin recursión

Un run unhealthy acredita que el monitor terminó su evaluación, pero nunca se
escribe en last_success_id ni last_failure_id. last_completed_id permite consultar
por PK ese heartbeat técnico. Puede resolver un fallo técnico previo del monitor,
porque evaluar con incidencias demuestra recuperación técnica, no recuperación
de las tareas observadas. El panel lo representa como Incidencias de salud.

Durante su propia ejecución running vigente, el monitor no se diagnostica como
fallido. Al finalizar se reconcilia solo su propia frescura; no vuelve a evaluar
las otras 21 tareas. Los éxitos ordinarios reconcilian solo su tarea. La evaluación
completa queda en la cadencia de cinco minutos. Los fallos técnicos del monitor
usan operational_task_failure; su falta de heartbeat usa operational_monitor_stale;
las tareas retrasadas usan operational_task_stale. No se registran excepciones
crudas. El checker externo puede detectar un scheduler totalmente detenido con
código 5, pero debe ejecutarse desde supervisión independiente: cron no puede
vigilar su propia ausencia sin un observador externo.

### Arranque inicial explícito

La migración 2026_10_15_130000 registra monitoring_started_at una sola vez y
siembra las claves del catálogo sin crear runs. En estados existentes conserva
la primera observación created_at; no reinicia su margen ni descarta fallos previos.
El puntero last_completed_id se inicializa únicamente desde los punteros existentes,
sin escanear histórico. No se alteran ni fabrican ejecuciones de sincronización.

Para una tarea nueva sin historia, la primera ocurrencia exigible es la primera
fecha cron estrictamente futura a monitoring_started_at, más grace_minutes. Antes
sigue Nunca ejecutado, con due null: un retorno global 0 significa ausencia de
incidencias exigibles, no prueba de ejecución de esa tarea. Después abre alerta si
no hay éxito verificable que cubra la ocurrencia. Un fallo explícito o una cola
caducada se detecta inmediatamente, incluso durante este arranque. Reiniciar
workers/cron o consultar el panel no reinicia el inicio de observación. Si falta
la metadata de observación no se concede margen implícito: se considera falta de
evidencia. No borrar/resembrar estados para silenciar alertas. El calendario sigue
Europe/Madrid y se prueba en los dos cambios DST.

### Liberación de reservas y atribución

La liberación sigue exclusivamente en CLI de operación, fuera de la allowlist
HTTP. El acceso se controla por el sistema operativo y los permisos del despliegue;
no es un login Laravel ni una ampliación de permisos de Dirección. Exige verificación
externa previa de terminación, --confirm-stopped y --reason cerrado. Motivos:
worker_stopped (origen manual), scheduler_stopped (origen scheduler), queue_expired
(solo queued) o process_terminated. La referencia opcional tiene forma OPS-123,
con 1–12 dígitos: sin nombres, email, mensajes libres, tokens o trazas.

No se libera running dentro de su ventana. queued debe superar 15 minutos;
running debe superar su ventana; unknown conserva la exigencia de confirmación
externa. Superar el plazo no demuestra que el proceso murió: el operador debe
verificar/parar los procesos en todos los hosts relevantes antes de confirmar.
Sin ese conocimiento, mantener la reserva. No hay liberación automática por TTL.

La misma transacción escribe operational_task_releases y finaliza el run como
failed. Si falla la auditoría, no se libera. Se conserva ID/correlación del run,
tarea, momento UTC, motivo y referencia. posix_geteuid aporta un principal OS
verificable (UID efectivo), nunca identidad humana ni ReportUser. Si no existe
esa API, como en este Windows local, actor_type es unverified_cli y actor_identifier
null. No se aceptan --actor ni variables USER/SUDO_USER como prueba. La atribución
humana requiere auditoría externa autenticada del host/ticket; no se inventa.
Los eventos de liberación se conservan separados de la retención de runs y no se
borran automáticamente en este lote. El solicitante original del run se preserva.

Laravel emite ScheduledTaskFailed y registra su excepción genérica para cualquier
código hijo no cero, incluidos 3/4/5; continúa con las demás tareas. El proyecto no
tiene listeners ni callbacks que transformen ese evento en OperationalAlert.
La matriz anterior se aplica también a observadores de esos eventos/logs:
3/4 son diferimientos y 5 es una evaluación con incidencias, no fallo técnico.
En el contrato directo se mantienen retornos no cero. La adaptación explícita del monitor programado se describe en la corrección del 19/10/2026.

Una alerta previa no se resuelve por el margen inicial ni por un reintento en
cola. La evaluación consulta los tipos operacionales abiertos por las claves del
catálogo usando el índice task/state; la recuperación exige evidencia real.
El fixture de concurrencia SQLite usa transacciones IMMEDIATE, porque SQLite no
tiene bloqueos por fila. No cambia la configuración MariaDB/InnoDB productiva;
la concurrencia en ese motor sigue requiriendo certificación del despliegue.

## Corrección del scheduler, 2026-10-19

Supersede exclusivamente el tratamiento de 5 por el proceso programado descrito
arriba: routes/console.php añade --scheduler solo a operations-health. El wrapper
exige unhealthy/5 con inicio/fin, last_completed_id del run y reserva liberada antes
de devolver 0 al scheduler. El ledger permanece unhealthy/5 y el checker directo
(y wrapper sin flag) mantienen 5. Omisión/bloqueo/fallo no se normalizan; el flag se
rechaza para las otras 21 claves. Fallos de finalización/reconciliación se propagan.
No cambia OperationalTaskExitCode, cron, argumentos de integraciones ni Queue.
Ver operaciones-produccion.md: evidencia productiva F2/F5 y worker comunicada por
el operador, diagnóstico histórico de las tres integraciones y validación pendiente.

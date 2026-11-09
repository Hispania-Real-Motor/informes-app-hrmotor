# Generaciones Salesforce Interests F2/F5 — PR #92

Estado: implementación en revisión, sin autorización de despliegue. Contraste documental: base `edf2d97b6e5624936ed5cfd12fa790889fea3df2` y diff de esta iteración. Las reglas siguientes sustituyen las referencias históricas a la tabla viva y al «último run» como fuente certificada.

## Almacenamiento y publicación

La migración `2026_10_28_120000_create_salesforce_interest_generations.php` añade cinco tablas. `salesforce_interest_generations` registra base, F2, F5, corte, estado, validación y fechas. `salesforce_interest_publication` contiene un único puntero y watermark. `salesforce_interest_versions` conserva valores y payload inmutables; `salesforce_interest_members` relaciona generación, Salesforce ID, versión y origen. `salesforce_interest_conflicts` conserva la cuarentena. Los contadores de extracción permanecen en los runs; el manifiesto resume filas y borrados.

`InterestGenerationStore::begin()` copia únicamente pertenencias, en bloques de 1.000; comparte versiones existentes y crea versiones nuevas para deltas. No duplica todos los payloads cada hora, pero el índice de pertenencia sí crece con el dataset completo por candidata. La tabla histórica `salesforce_interests` no se importa ni certifica. El persistor conserva un camino legacy para compatibilidad; el sincronizador F2 llama explícitamente a `forGeneration()`.

Estados: `preparing` → `syncing` → `validating` → `certified` → `published`; `conflict` bloquea certificación y `failed` conserva una candidata fallida. Una interrupción abrupta puede dejar `preparing`, `syncing` o `validating`; ninguno es publicable. No se reparan estados históricos. Cada bloque bloquea la candidata y es transaccional. Solo `syncing` admite cambios. La clave primaria de pertenencia garantiza un Salesforce ID por generación; el índice único generación/origen garantiza unicidad con vacíos normalizados a null. No se usa upsert ambiguo por origen.

`publish()` bloquea primero el puntero y después la candidata en una transacción breve, exige base todavía vigente, certificación, ausencia de conflictos y F2/F5 completos con el mismo corte y run enlazado. Cambia juntos puntero, watermark y estado. Una publicación repetida del mismo candidato es idempotente; dos candidatas de una base no pueden ganar ambas. El lock de caché reduce solapes, pero la barrera de integridad está en la base de datos. Las generaciones previamente publicadas siguen disponibles e inmutables.

## Extracción, conflictos y límites de certificación

F2 conserva `SystemModstamp`, solape configurable y páginas; usa como watermark el corte de la generación publicada. Por defecto el corte retrasa dos minutos y se redondea al minuto. Activos y borrados se consultan separadamente; el incremental añade `getDeleted` y exige cobertura desde el inicio solapado hasta el corte. Un tombstone afecta solo a la candidata. Una reactivación observada con SystemModstamp posterior al borrado prevalece. Páginas sin total, `done`, continuación válida o recuento final completo fallan.

F5 recibe una candidata explícita; resuelve `Task/Event.WhatId` solo contra sus filas y conserva sus detalles por `activity_run_id`. No consulta el último F2 global. F2 completo por sí solo no mueve la publicación. La extracción actual con límites temporales no equivale a una fotografía histórica exacta: actualizaciones concurrentes y registros purgados requieren conciliación independiente. `queryAll` no reconstruye registros purgados y la disponibilidad del log de borrados es limitada. Falta de cobertura impide publicar.

La cuarentena distingue `duplicate_in_block`, `duplicate_between_blocks`, `base_owner_conflict`, `owner_change` y `retry_conflict`. Este último detecta contenidos distintos del mismo ID dentro del bloque; no debe interpretarse como una comparación universal de todas las repeticiones entre páginas. El fingerprint evita duplicar la misma evidencia al reintentar. Evidencia cifrada mediante `Crypt`; IDs y campos de revisión residen en almacenamiento restringido, nunca en mensajes públicos. No existe interfaz ni resolución automática. La implementación bloquea cualquier conflicto registrado, incluso si se cambiara solo su estado de revisión: la recuperación requiere evidencia, decisión autorizada y una nueva candidata limpia. Un origen de migración no demuestra la relación comercial actual.

## Lecturas y contratos

`InterestGenerationReader` se registra como scoped y fija una generación publicada durante cada petición/job/exportación. Sus consultas unen pertenencias y versiones; `f2()` y `f5()` salen de la misma generación. Interests obtiene `generation_id` en summary, lo envía en `X-Interest-Generation` para AJAX y en `interest_generation` para CSV. Un token inválido o inexistente produce el 503 controlado; recargar comienza otra carga coherente. No hay eliminación automática de generaciones. La fecha visible es el corte certificado; `generation_stale` usa 7.200 segundos por defecto.

Lectores adaptados: dashboard y auditorías Interests, métrica ejecutiva y prechequeo mensual, atribución y auditoría de Reservas/Ventas y Rendimiento comercial, builder/readiness/dashboard de Campañas, clasificación y reproceso de Llamadas, proyección orgánica y estado SEO, dependencias y reconciliaciones de Interests/Opportunities. Esto fija la fuente Interest; no certifica conjuntamente otras fuentes, cierres económicos o materializaciones dependientes. El token entre varias peticiones está implementado específicamente en Interests; verificar los restantes contratos de caché y pantallas compuestas mediante sus regresiones sigue siendo un requisito de revisión.

La materialización SEO diaria conserva ahora `interest_generation_id` además del
run y cutoff F2. Las filas antiguas sin ese campo no se consideran evidencia de
una generación certificada; una reconstrucción SEO debe ejecutarse de nuevo
desde un lector publicado antes de usarla para una publicación dependiente.

## Primera generación e incidente #5729

El backup posterior al incidente comunicado por el operador tiene gzip y checksum correctos (437 MB comprimidos, 105 tablas); su restauración no está probada. No es evidencia de un estado anterior ni fuente inicial certificable. No se ha accedido a él en desarrollo. El inventario exacto de filas insertadas/modificadas por #5729 y sus valores anteriores sigue pendiente; los contadores del run no bastan para reconstruirlo. Los 270.000 cambios pueden deberse a actualización masiva, pero no se ha demostrado la causa ni consultado Salesforce durante esta tarea.

La CLI `salesforce:interest-generation` no tiene endpoint web. Solo un operador autorizado, después de superar los bloqueos de pruebas/rendimiento y con una intervención aprobada, podrá ejecutar estos pasos; no se han ejecutado extracciones reales:

1. `prepare-initial --allow-salesforce-read --reason="motivo operativo revisado"` crea candidata vacía, extrae F2 y F5 y devuelve manifiesto. No publica. No importa la tabla viva ni el backup.
2. `manifest --generation=<candidata>` devuelve conteos, corte y SHA-256 sin IDs de Salesforce. Conciliar independientemente identificadores, conteos, unicidad, cobertura, borrados, relaciones y F5. La firma prueba correspondencia del manifiesto con la candidata, no veracidad de las comprobaciones humanas.
3. Preparar archivo JSON local restringido con `source: isolated_salesforce_reconstruction`, `manifest_sha256` y el objeto `manifest` completo devuelto por la CLI (filas, borrados y hash), además de `f5.run_id` y `f5.cutoff_at` exactos. Añadir `external` obtenido de una extracción independiente: `source` identificable, `identifiers_sha256`, `rows`, `deleted`, `relationships_sha256` y `coverage_sha256`. El servicio compara los contadores y la huella de identificadores contra la candidata; rechaza fuentes `candidate_manifest` o `legacy_table`. `checks` con `identifiers`, `counts`, `deletions`, `relationships`, `coverage`, `f5` representan la aprobación humana de evidencias externas, pero no sustituyen sus huellas verificables. No incluir PII. La CLI limita el archivo a 65.536 bytes. Conservar informe de revisión y aprobación fuera de logs públicos.
   La evidencia remota se obtiene, sin escrituras, con `php artisan salesforce:interest-generation external-evidence --generation=<id>`: consulta por páginas `Interes__c` activas/borradas y `Task`/`Event` relacionados hasta el corte almacenado, ordena las claves antes de calcular las huellas y solo imprime contadores y SHA-256. La salida no contiene IDs ni payloads. El operador debe guardar el JSON en una ruta restringida y contrastar su cobertura; el comando no certifica ni publica.
   `salesforce_identity_state_sha256` se calcula incrementalmente como `salesforce_id|is_deleted` sobre registros creados hasta el corte y ordenados por `Id`; no reutiliza `content_hash` local. Los registros cuyo `SystemModstamp` supera el corte incrementan `unverifiable`. La salida declara `certifiable: false` y `publish_initial_compatible: false` porque una lectura posterior no puede demostrar el contenido histórico del cutoff. No debe presentarse como autorización automática de publicación.
4. `publish-initial --generation=<candidata> --evidence=<archivo-revisado> --approve-publication` certifica y publica solo si pasan las comprobaciones. No cambia el scheduler. Si la cobertura no es demostrable, no aprobar: el informe permanece explícitamente indisponible.

Una vez publicada una base, `salesforce:sync-interest-reporting` ejecuta F2/F5 y publicación incremental. Los comandos F2 o F5 por separado solo construyen candidata; F5 exige `--generation` y motivo. Ante fallo, conservar evidencia y volver a construir desde la base publicada. No hay comando que reanude una candidata fallida ni que libere un origen. Si una base quedó superada por otra publicación, reconstruir desde la nueva base. No editar #5729, no certificar #5722 como fotografía de la tabla actual, no borrar propietarios ni colisiones.

## Despliegue, retención y rollback

Antes de despliegue: completar regresiones, carga representativa y restauración ensayada de una copia autorizada en un procedimiento separado; verificar versiones de PHP/MariaDB, espacio, índices, redo/binlog, APP_KEY y permisos del operador. Confirmar estado real del scheduler; la última observación era detenido. Coordinar pausa de escritores y reinicio de workers para evitar procesos antiguos escribiendo legacy. Aplicar migración y assets coherentes solo con autorización de despliegue. El primer arranque sin puntero devuelve 503 y no adopta datos vivos. Validar lectores, permisos y CSV después de la primera publicación; reactivar tareas requiere aprobación aparte.

No hay limpieza automática de versiones, candidatas, conflictos o detalles F5. `PruneTransversalDataCommand` preserva la tabla legacy y los runs F2 referenciados. Dimensionar retención de pertenencias, versiones compartidas, evidencias y F5; antes de cualquier limpieza futura inventariar punteros, exportaciones y snapshots dependientes. No implementar ni ejecutar borrado por antigüedad sin ese análisis.

Rollback de aplicación conserva todas las tablas y evidencias. `down()` rechaza quitar el esquema si existe cualquier generación. No usar rollback de migración con datos ni repuntar manualmente a una base histórica. El código previo podría volver a leer la tabla legacy parcialmente persistida: no habilitarlo como recuperación de disponibilidad; mantener informes/ejecuciones dependientes indisponibles hasta solución verificada. No confundir éxito de migración con certificación.

## Verificación y bloqueos

Pruebas nuevas: `InterestGenerationTest` cubre aislamiento, conflictos, reintentos, borrados/reactivación, publicación competitiva, lector fijado, F5 fallido, ausencia de base y evidencia inicial. F2/F5/dashboard y JS incorporan fixtures de generaciones y tokens. `Tests/Support/InterestGenerationFixture` es exclusivamente sintética y no constituye un procedimiento productivo. La carrera MariaDB de dos procesos y el benchmark están en `tests/performance/`.

Resultados finales y métricas reproducibles: [pruebas de rendimiento](../tests/performance/README.md) y [handoff](ai/HANDOFF.md). Los ensayos de 270.000 modificaciones con payload sintético de 2 KB detectaron latencia p95 de lectura concurrente superior a 11 segundos. No acreditan SLO ni viabilidad horaria; faltan distribución y volumen reales, impacto F5 completo y regresiones integrales. Esta implementación no está declarada apta para producción.

### Evidencia ausente y regresiones de consumidores

El lector traduce ausencia del run F2/F5 referenciado a indisponibilidad
controlada. Readiness de Campañas convierte esa ausencia en su contrato blocked;
no introduce una fuente alternativa. Las fixtures compartidas publican
exclusivamente generaciones sintéticas y simulan el comienzo de otro job para
renovar el lector scoped. Un intento nuevo, incluso fallido, no invalida la
generación ya fijada. Estas fixtures no son un mecanismo operacional ni una
excepción a la inmutabilidad productiva.

### Apropiación de candidata por F2

`SalesforceInterestSyncService` registra la asociación del run dentro del manejo
de errores. Si otro extractor intenta apropiarse de una candidata ya ligada,
su propio run queda fallido y no invalida la candidata del propietario. Solo un
extractor cuya asociación haya tenido éxito puede marcar su candidata fallida.
`test_second_extractor_cannot_take_over_candidate_or_leave_its_run_running`
comprueba rechazo, conservación del propietario/estado y ausencia de llamadas
remotas. No permite reanudar ni reasignar una candidata ajena.
## Recuperación inicial desde estado actual

## Inventario mensual provisional independiente

La CLI `salesforce:interest-generation` ofrece `monthly-prepare`, `monthly-run`,
`monthly-status` y `monthly-cancel` para reconstruir un único mes funcional en
`Europe/Madrid` dentro de un journal aislado. El inventario usa `queryAll`,
paginación keyset por `Id`, lotes de 50 a 1.000 (500 por defecto), checkpoints
transaccionales y lease fenced; no consulta `getDeleted`, no copia la semilla,
no escribe generaciones, no modifica publicación y no habilita lectores. Incluye
soft deletes observables, pero no puede demostrar hard deletes históricos ni
una fotografía de cierre: su evidencia declara expresamente F5 bloqueado y
certificación histórica indisponible. Un operador debe proporcionar autorización
explícita de lectura Salesforce y motivo; cancelar conserva filas y evidencia.
Tras F2, una segunda lectura remota también paginada por `Id` contrasta el
inventario y conserva solo contadores de ausencias y cambios de contenido. Su
cursor se checkpointa, y el lease se renueva antes, durante y después de cada
lectura; una pérdida de lease no puede confirmar filas, progreso ni evidencia.
Un fallo conserva fase y cursor confirmados para reanudar. Cualquier diferencia
mantiene el resultado provisional no verificado.
La evidencia mensual persiste solo ID técnico, fechas funcionales/modificación,
estado de soft delete y un hash canónico; no conserva el payload Salesforce.
El hash excluye payload y tiempos técnicos de sincronización/creación/actualización
para evitar diferencias artificiales, pero conserva los campos funcionales.
Ante fallo, `monthly-status` expone exclusivamente `diagnostic.stage`, categoría
y metadatos permitidos (tipo de excepción, páginas e instante; SQLSTATE/código
de driver solo para base de datos). Nunca expone mensajes, SOQL, bindings, IDs
ni payloads. La reanudación con un lease nuevo limpia el diagnóstico activo.
Las etapas permitidas distinguen consulta, mapeo, validación, hash, persistencia
y checkpoint, además de sus equivalentes de verificación; las categorías no
atribuyen una causa de base de datos a un error de contenido.

Cuando no existe una generación publicada, una generación histórica en estado
`validating` puede utilizarse únicamente como semilla técnica, nunca como
evidencia de certificación. El operador autorizado puede ejecutar
`salesforce:interest-generation recover-initial` con una generación fuente
explícita, autorización de lectura y un motivo operativo.

El procedimiento copia solamente las memberships de la fuente y reutiliza sus
versiones inmutables. Antes de crear la candidata, `getDeleted` debe acreditar
cobertura desde el corte de la semilla hasta el corte solicitado para F2/F5
(`ahora UTC - 2 minutos`, redondeado al minuto). Si `latestDateCovered` es
anterior, la operación falla sin crear candidata ni emitir lecturas F2/F5; no
rebaja silenciosamente el watermark al valor devuelto. Un journal existente
solo puede reanudarse si su watermark era ese corte solicitado al prepararlo;
los journals heredados con un watermark anterior permanecen sin certificar y
requieren cancelar/recrear la recuperación bajo procedimiento autorizado. F2 lee `queryAll` por lotes acotados (por defecto 500,
mínimo 50, máximo 1.000), ordenados por `Id` y con cursor `Id > último Id`
confirmado; no usa fecha funcional como cobertura global. El mismo mapeo F2
compara hash de contenido y solo escribe versiones para altas o diferencias.
Cada lote persiste en transacciones pequeñas; el checkpoint se confirma después
del lote. Una caída antes del checkpoint repite el lote: la persistencia de
versiones/memberships es idempotente y el cursor nunca lo salta. Un ID inválido,
fuera de orden o que no progresa bloquea la recuperación. El lease se renueva
antes y después de la lectura F2 y mientras se aplican los bloques de
`getDeleted`; perderlo cerca la ejecución anterior y evita que ésta confirme o
marque fallida una recuperación tomada por otro propietario. Los hard-deletes
requieren cobertura válida de `getDeleted`; una fila local ausente sin esa
evidencia hace fallar la candidata. La reconciliación tiene un máximo de tres
pasadas y no publica ni modifica la generación fuente, el scheduler ni los
workers.

F5 se reconstruye en un run nuevo contra la candidata F2 reconciliada; no se
reutiliza el run histórico de la semilla. El paso que completa el run F5 y lo
asocia a la candidata (`finishF5`) es atómico y se ejecuta bajo una guardia
fenced del lease: un trabajador que lo haya perdido no puede completar ni
asociar ese run. Si F5 se interrumpe antes de esa asociación, la candidata
conserva F2 y el run parcial queda como evidencia sin adjuntarse; la reanudación
crea un run F5 vacío nuevo. Si la caída ocurre después de la asociación pero
antes de certificar, el journal queda `associated_unverified`: la reanudación
reutiliza exclusivamente ese run ya completo para recalcular manifiesto y
verificación, y no lo acredita hasta certificar. No se acredita ni reutiliza un
F5 parcial. La evidencia `initial_current_recovery`
guarda generación fuente y recuperada, watermark, runs F2/F5, manifiesto,
contadores finales, cobertura de borrados y número de pasadas. Solo una
generación certificada mediante esa evidencia puede llegar posteriormente a
`publish-initial`, que sigue siendo una acción independiente y requiere
aprobación explícita. `recover-initial` emite la evidencia completa como JSON;
debe conservarse en un archivo local protegido y revisarse antes de utilizarla
con `--evidence`. La publicación comprueba que el contenido revisado coincide
con la evidencia certificada y que el manifiesto y alineación siguen siendo
válidos; rechaza evidencia alterada o de otra candidata. El puntero se verifica
en la publicación. El JSON contiene identificadores técnicos de generaciones y
runs, por lo que debe tratarse como evidencia operativa restringida.

La migración `2026_11_04_120000_create_salesforce_interest_initial_recoveries.php`
añade el journal exclusivo de recuperación inicial: generación semilla/candidata,
run F2, fase, cursor, lote, pasadas, lease/heartbeat, corte, cobertura de
borrados, contadores, hashes de configuración y motivo, error y tiempos. No
almacena payloads de Salesforce ni añade una fuente certificada alternativa.
La recuperación tiene exclusión de escritor mediante lease persistente de 15
minutos y verificación de propietario en cada checkpoint. El heartbeat se
renueva también durante F5 (lectura de Task/Event, persistencia, manifiesto y
verificación) y el cierre F2/F5 se protege con el mismo fencing transaccional.
`recover-initial`
reanuda automáticamente solo una ejecución no finalizada con la misma semilla,
motivo y configuración; un proceso con heartbeat vigente no puede ser tomado.
`recover-resume` solo acepta una recuperación `prepared`, `failed` o `running`
con lease caducado y el mismo motivo; no repara generaciones históricas.
`recover-cancel` únicamente cancela una ejecución no activa y no borra la
candidata, versiones, runs ni evidencia. Los fallos de cursor, orden,
conflicto o verificación permanecen cerrados; no se usan como autorización de
reanudar o publicar.

Operación permitida exclusivamente por CLI y con autorización explícita de
lectura Salesforce:

```text
php artisan salesforce:interest-generation recover-initial --source-generation=<semilla> --batch-size=500 --allow-salesforce-read --reason="motivo operativo revisado"
php artisan salesforce:interest-generation recover-status --recovery-id=<id>
php artisan salesforce:interest-generation recover-resume --recovery-id=<id> --allow-salesforce-read --reason="mismo motivo operativo revisado"
php artisan salesforce:interest-generation recover-cancel --recovery-id=<id>
```

`recover-status` es de solo lectura: informa estado/fase, pasada actual y máxima,
contadores persistidos, estado F5 (`pending`, `materializing`, `associated_unverified`,
`failed`, `verified` o `certified`), progreso de verificación, último checkpoint,
caducidad calculada del lease, cobertura de borrados y motivo de fallo, sin
payloads, tokens ni IDs Salesforce. El porcentaje solo es `100` tras completar
todas las fases; antes se omite porque Salesforce no ofrece un total certificable
para la recuperación progresiva. Los reintentos de lectura remota están acotados
a tres por lote con espera corta; una vez agotados se conserva el checkpoint
anterior. Para sobrevivir a una caída entre la asociación F5 y el checkpoint,
el estado consultado deriva `associated_unverified` de una candidata todavía
`validating` con F5 asociado y deriva `certified` si la generación ya lo está;
no infiere que exista verificación o publicación.

`InterestGenerationTest` incluye casos de publicación con evidencia coincidente,
rechazo de evidencia alterada o ajena, ausencia de aprobación y cambio del
puntero. Estas pruebas no sustituyen la revisión operativa ni validan datos
reales de Salesforce.

Las consultas de recuperación F2 y F5 leen el estado actual sin limitar
`SystemModstamp` al watermark. La verificación F2 hace un merge remoto/local
por `Id`, también por lotes y con checkpoint: un corte reanuda desde el último
rango comparado en vez de repetir una exploración completa. La cola local de
identidades se recorre por clave y con heartbeat; discrepancias pueden abrir
otra pasada de reconciliación hasta el máximo configurado, tras el cual el flujo
falla cerrado. Ese watermark no demuestra una fotografía histórica ni
simultaneidad exacta entre F2 y F5. El resultado y la cobertura deben revisarse
antes del uso operativo; las pruebas simuladas no certifican Salesforce ni
producción.

Antes de crear la candidata, `recover-initial` consulta de forma read-only
`getDeleted` desde el cutoff de la semilla hasta el corte solicitado (UTC,
`now - 2 min`). El cutoff efectivo es el mínimo entre ese valor solicitado y
`latestDateCovered`, normalizado de forma conservadora al minuto. La cobertura
histórica debe alcanzar el cutoff de la semilla y el corte efectivo debe avanzar
respecto a ella. La aplicación posterior vuelve a consultar `getDeleted` y
exige cobertura hasta el cutoff efectivo; una regresión falla cerrada. Así, el
watermark queda limitado por la cobertura de borrados comunicada por Salesforce;
no acredita por sí solo la integridad de toda la replicación ni una fotografía
histórica exacta. La evidencia registra el watermark efectivo; el journal
persistente conserva además la cobertura y configuración que fijaron la
ejecución.

### Diario de recuperación por período

La migración `2026_11_03_120000_create_salesforce_interest_recovery_periods.php`
añade un diario separado de la publicación global. `period-prepare` crea, para
una semilla inicial íntegra y un rango semiabierto en `Europe/Madrid`, un
registro con contadores y checkpoint; `period-run` requiere autorización
explícita de lectura Salesforce y continúa desde el último `Id` confirmado;
`period-status` solo consulta el estado persistido. Los IDs observados y las
diferencias de hash se guardan por período, por lo que un reinicio no duplica
contadores ya registrados. `period-run` admite estados `prepared` o `failed`;
una interrupción que deje el diario en `running` se reanuda únicamente con
`--resume` cuando el heartbeat persistido tiene al menos quince minutos, bajo
un bloqueo transaccional. No hay recuperación automática ni dos escritores
activos. Esta operación no crea una generación, no modifica
memberships/versiones, no usa la tabla legacy y no publica.

La consulta SOQL se restringe a la definición funcional vigente y compara cada
fila con la versión de la semilla. Registra filas idénticas, remotas nuevas,
cambios de contenido y filas de la semilla que ya no aparecen. Al completarse
declara `reconciled_unverified`: la evidencia histórica de entradas/salidas y
F5 sigue siendo insuficiente, así que no existe transición a `certified` ni
publicación parcial.

Antes de la lectura de activos, el diario consulta `getDeleted` desde el cutoff
de la semilla. Un ID de la semilla que aparezca en el log se marca como
`deleted_verified`; si vuelve a aparecer activo se clasifica como `reactivated`.
El log registra sus fronteras de cobertura, pero una retención incompleta o una
ausencia no incluida en él permanece explícitamente sin verificar. El lease del
diario cambia en cada ejecución: un worker anterior comprueba ese token antes
de persistir cada página y no puede continuar después de una reanudación.

### Inventarios mensuales aislados F2/F5

La recuperación mensual es un diario administrativo de estado actual, separado
de `salesforce_interest_generations`, versiones, memberships y de cualquier
puntero publicado. La F2 mensual conserva un inventario por período funcional
semiabierto en `Europe/Madrid`; la F5 mensual sólo puede prepararse desde una
F2 terminada. Las ejecuciones nuevas usan bloques acotados de `WhatId` tomados
del inventario F2 de origen: no filtran por fecha de creación de la actividad,
por lo que una actividad posterior del mismo Interest sigue perteneciendo al
inventario mensual. Cada bloque conserva por separado el último Interest
confirmado y el cursor `Id` dentro del bloque; los dos checkpoints se confirman
con las filas bajo el mismo lease fenced. Esto evita recorrer todas las Tasks y
Events de Salesforce para un período y evita omisiones al cambiar de bloque.
Los journals anteriores permanecen como `legacy_global_v1`: no se reinterpretan
ni se reanudan con la estrategia nueva.

La F5 se opera exclusivamente por CLI administrativa, sin endpoint HTTP:

```text
php artisan interests:monthly-f5 prepare --monthly-recovery=<f2> --reason="motivo operativo revisado"
php artisan interests:monthly-f5 run --run=<id> --reason="mismo motivo" --confirm-salesforce
php artisan interests:monthly-f5 status --run=<id>
php artisan interests:monthly-f5 manifest --run=<id>
php artisan interests:monthly-f5 cancel --run=<id>
```

La evidencia F5 fija el identificador, número de filas y hash de la F2 de
origen al prepararse; después verifica remotamente Task y Event por páginas y
registra sólo contadores de ausencias/cambios y un manifiesto de hashes. Una
F5 completada significa únicamente que terminó este inventario operativo: no
equivale a verificación de la F2, certificación histórica ni publicación. En
particular, si la evidencia de F2 tiene `verification.verified = false`, la F5
permanece provisional aunque sus comprobaciones no declaren discrepancias. La
ausencia de `getDeleted`, historial as-of o evidencia independiente mantiene
`historical_certification: unavailable`; los borrados duros siguen sin poder
inferirse del estado actual.

Las filas F5 se guardan en tablas mensuales propias, incluidas marcas de
eliminación y fechas técnicas necesarias para el hash, pero no alimentan
`salesforce_interest_activities` ni los lectores de generación. No crean una
generación global, no mueven el puntero, no habilitan KPI, comparativas, CSV,
auditorías ni el informe oficial. Fallos y estado exponen sólo diagnóstico
permitido (fase, categoría, tipo de excepción, páginas e instante), sin
payloads, SOQL, tokens ni identificadores remotos.

La reconciliación local se ejecuta también por CLI y no escribe los journals:

```text
php artisan interests:monthly-reconcile --recovery=<f2> --materialization=<id> --f5-run=<id>
```

Por defecto sólo emite agregados, huellas y límites de observabilidad. La
emisión acotada de identificadores Salesforce exige confirmación explícita de
operador. Los IDs de discrepancias F5 no se reconstruyen cuando el journal sólo
conservó su contador; de igual modo, un hash F2 histórico no permite atribuir
un cambio a un campo sin valores previos retenidos. Los tres diarios deben
pertenecer a la misma F2; la reconciliación no los escribe, no repara ausencias
ni eleva una verificación a certificación. Un hard delete sigue sin probarse por
la ausencia de `queryAll`.

### Lector administrativo provisional de octubre de 2026

`/informes/leads/provisional/october-2026` es una pantalla separada de todos
los lectores oficiales. Requiere administrador autenticado y permanece 404 por
defecto mientras `SALESFORCE_OCTOBER_2026_PROVISIONAL_ENABLED` sea falso. Al
habilitarse debe devolver `no-store, private`; cualquier journal inexistente,
en fase distinta de `completed`, período distinto de octubre de 2026 en
`Europe/Madrid`, relación F2/F2M/F5 no coincidente o manifiesto/hash que no
coincida falla cerrado con 503. No hay fallback a la tabla legacy, a otra
generación, ni a datos Salesforce remotos.

La lectura usa exclusivamente la F2, su materialización F2M y el run F5 con
ID interno fijo `1`, unidos a la misma F2. No acepta parámetros ni configuración
que redirijan esa fuente. La evidencia fuente se valida conforme al contrato
que cada journal persistió: F2M exige la secuencia ordenada
`Id|content_hash|is_deleted`, mientras F5 exige `Id:is_deleted`. Ambas huellas
se calculan en una sola lectura ordenada de F2; no son intercambiables. Expone
sólo cobertura agregada: tamaño
F2, materializados, contenido coincidente, activos aptos, ausencias, cambios de
contenido, soft deletes observados y disponibilidad de identidad canónica. Los
agregados por delegación incluyen exclusivamente filas activas cuyo contenido
sigue coincidiendo con F2; no muestra IDs Salesforce, payloads ni detalle de
discrepancias. F2/F2M/F5 terminados o verificados no acreditan una fotografía
histórica ni `historical_certification`, que continúa `unavailable`.

La Fase A de esa vista calcula al validar, no al servir HTTP, la clasificación
de procedencia sobre exactamente el mismo universo F2M activo y coincidente.
Usa `LeadDelegationNormalizer` para agregar delegación, grupo y zona; no deriva
ninguno de esos valores de owner, comercial, cuenta o Lead. Conserva totales de
clasificado, sin clasificar, vacío y no reconocido; el total incluido debe
coincidir con `eligible_active_matching`. Los valores no reconocidos sólo dejan
una huella SHA-256 estable y su frecuencia, nunca el valor fuente, IDs ni
payloads. Esa huella permite agrupar revisión de calidad, no constituye
anonimización ni certificación histórica.

Una consulta SOQL de estado actual comunicada durante la Fase A contó 34.212
Interests (21.238 con `IN_Delegacion_Procedencia__c` vacío y 12.974 informado;
`General` apareció literalmente en 1.394 de los informados). Es observación
actual de calidad, no journal ni snapshot de octubre: no reemplaza el universo
F2M válido de 33.811 ni acredita historia. Por ello `General` no recibe alias;
si aparece en F2M se conserva sólo como `raw_literal_general`, subcontador de
procedencia no reconocida, sin delegación, grupo o zona asignados.

La Fase B añade al mismo snapshot distribuciones de estado, tipo, fuente, medio
y canal, calculadas exclusivamente durante la validación sobre F2M activo con
contenido coincidente. Cada dimensión reconcilia con `eligible_active_matching`;
los textos vacíos se agrupan como `Sin clasificar` y el tipo se normaliza con
`LeadRecordTypeNormalizer`. HTTP no consulta los journals ni reejecuta estas
reglas: sólo entrega la evidencia cuya revisión sigue vigente.

No son KPI comerciales certificados. La atribución a comercial y su delegación
requieren instantánea histórica de `SalesforceUser`, inexistente en F2M; los
KPI de actividad requieren estado de relación F5 y certificación histórica,
también ausentes. Por ello permanecen indisponibles, igual que Dirección,
comparativas y los consumidores oficiales; no cambia la generación publicada.

La pantalla puede declarar el resumen de Interests disponible **con límites de
cobertura** sólo dentro de ese contexto administrativo. Dirección, KPI y
comparativas, Reservas/Ventas y Rendimiento comercial, Campañas, Llamadas y SEO
siguen indisponibles desde esta fuente; no hay CSV, auditoría, caché compartida,
puntero de publicación ni cambio de generación. El contrato fijo no admite
configuración de IDs: el único control de despliegue es el flag, que debe
permanecer apagado salvo habilitación administrativa autorizada.

La validación completa no se ejecuta desde HTTP. Un administrador debe lanzar
de forma explícita y local:

```text
php artisan reports:validate-october-2026-provisional --confirm
```

El comando no consulta Salesforce: comprueba estados, período, vínculos y
manifiestos F2/F2M/F5 de los IDs fijos, calcula agregados y persiste el snapshot
de cobertura junto con huellas, revisión de integridad y métricas de duración.
Las métricas sirven para auditar el coste local de cada fase; no son benchmark
productivo ni evidencia de certificación. La migración aditiva crea el snapshot
de validación y una revisión monótona. No hay triggers `FOR EACH ROW`: las rutas
Laravel autorizadas que escriben en F2, F2M o F5 invalidan esa evidencia e
incrementan la revisión dentro de la misma transacción y por lote. Por tanto,
una escritura SQL directa queda fuera del contrato: no debe modificar esos
journals sin una revocación operativa explícita y posterior validación.

HTTP sólo lee el snapshot cuya revisión coincide antes y después de la petición;
no vuelve a escanear las tablas de filas. Si no existe validación, si quedó
obsoleta por un cambio instrumentado de journal o si la revisión cambia durante
la lectura, responde 503. Vínculos y manifiestos se vuelven a comprobar en la
validación administrativa, antes de persistir un snapshot nuevo. Tras cualquier
cambio debe repetirse el comando autorizado. Este control reduce trabajo por
petición y rechaza carreras en las rutas autorizadas, pero no certifica historia,
no prueba hard deletes ni altera el aislamiento frente a lectores publicados. No se ha
validado aquí el comportamiento en MariaDB/CI ni medido rendimiento. La PR
incorpora el job de GitHub Actions `october-provisional-mariadb`, previsto para
ejecutar ese test focal en MariaDB 11, reaplicar la migración y confirmar que no
crea triggers por fila, también con volumen representativo sintético. El job es
evidencia pendiente hasta que la CI de la PR termine; las métricas del comando
son mediciones de sus fases, no un benchmark ni una garantía de rendimiento.

### Vista provisional administrativa

`/informes/leads/provisional/september-october-2026` es distinta de los
endpoints oficiales: solo admite administradores autenticados y está apagada
por defecto mediante `SALESFORCE_INTEREST_PROVISIONAL_PREVIEW_ENABLED`. Cuando
se habilita, la semilla configurada debe seguir siendo una generación inicial
íntegra, no publicada y en `validating`. La pantalla solo emite agregados de
septiembre/octubre de 2026, responde con `no-store, private` y advierte de la
falta de certificación. No ofrece exportación, token de generación, caché
compartida ni integración con KPIs, cierres, comisiones u otros consumidores.
El total cuenta todas las filas de la semilla en cada período, incluidos los
eliminados; estos se cuentan adicionalmente en su propia columna. La consulta
del diario, en cambio, compara solo Interests no eliminados en la semilla y en
Salesforce. Una ausencia en la comparación no demuestra un borrado.

La vista calcula sus períodos con agregaciones SQL (`COUNT(*)` y conteo de
eliminados) y aliases internos que no usan palabras reservadas. No selecciona
versiones completas; conserva los mismos totales, límites Europe/Madrid,
certificación y aislamiento de los consumidores oficiales.

### Fases C/D: alcance provisional y bloqueo oficial

La vista administrativa actual solo muestra agregados mensuales de conteo y
borrados de la semilla. La distribución por fuentes, canales o estados no se
amplía mientras no estén acreditadas su definición final y la cobertura de la
semilla no certificada. Esos agregados no publican una generación ni convierten
el informe oficial en disponible.

Según el estado operativo comunicado, el puntero publicado es nulo, la
generación 1 está en `validating` y no existen diarios por período. El estado
`reconciled_unverified` descrito antes es un posible resultado del código, no un
diario productivo existente. Falta evidencia histórica suficiente para F2 y F5,
incluidas la cobertura de borrados y las relaciones. Esos datos operativos no
se han comprobado desde esta revisión documental; deben contrastarse antes de
cualquier decisión. No se ha establecido una recuperación o certificación
nueva por añadir esta sección.

## Recuperación progresiva por período: bloqueo de certificación

No existe todavía una vía certificable para publicar únicamente septiembre y
octubre de 2026. La fecha funcional de un Interest se deriva de
`IN_Fecha_Creacion_Origen__c` y, si falta, de `CreatedDate`; no es un campo de
control de cambios. El cliente Salesforce disponible solo expone una consulta
del estado actual (`query`/`queryAll`) y el log de borrados `getDeleted`. No
expone historial de esos campos, Change Data Capture retenido ni una extracción
as-of para identificar un Interest que entró o salió posteriormente de un
período funcional.

Por tanto, un SOQL filtrado por la fecha funcional actual no puede probar que
contenga los registros que antes pertenecían al período, ni que excluya los que
han entrado después. Filtrar por `SystemModstamp` tampoco resuelve el problema:
la observación operativa indica que todos los Interests de la semilla aparecen
modificados desde octubre de 2026. Usarlo como delta haría que el candidato
elegible fuera de nuevo el universo completo y seguiría sin aportar los valores
históricos previos al cambio.

No se debe añadir una publicación parcial ni un puntero de cobertura hasta
disponer, para los dos meses, de una fuente independiente con retención
suficiente: Field Audit Trail/historial de los campos funcionales, CDC
conservado desde el cutoff, o un snapshot verificable con identidad, contenido,
borrados y relaciones F5. Esa fuente debe permitir contrastar tanto las
entradas como las salidas del rango y cubrir Task/Event. `getDeleted` por sí
solo solo justifica purgados dentro de su ventana de retención. Sin ella, el
procedimiento correcto es fallar cerrado y mantener el 503 para intervalos no
certificados; no se presentarán KPIs, comparativas ni exportaciones parciales
como consistentes.

## Materialización funcional F2 mensual aislada

`interests:monthly-f2-materialization` añade un journal separado para convertir
las identidades ya congeladas en `salesforce_interest_monthly_recovery_rows` en
campos funcionales estructurados. `prepare` exige un F2 mensual completado y un
motivo; `run` exige además `--confirm-salesforce`, procesa bloques de 50 a 500
IDs con `queryAll` y una consulta `Id IN (...)` acotada. `status`, `manifest` y
`cancel` no hacen lecturas remotas, y no hay ejecución automática al desplegar.

Las tablas `salesforce_interest_monthly_materializations` y
`salesforce_interest_monthly_materialized_rows` no escriben generaciones,
memberships, versiones, puntero de publicación ni los journals F2/F5. Cada fila
conserva la referencia a su fila F2, ambas huellas SHA-256 y los campos del
contrato actual de `SalesforceInterestSyncService::mapRecord`: identidades
Lead/Account/persona, origen de migración, fechas, owner, estado/tipo,
fuente/medio/canal/delegación, UTM, vehículos, oportunidad inversa y soft
delete observable. No conserva `raw_payload`.

La huella usa exactamente la normalización mensual F2: excluye payload y tiempos
técnicos y conserva los campos funcionales. Una presencia con hash distinto
cuenta solo como cambio; una fila no devuelta cuenta solo como ausencia; un ID
inesperado o duplicado no entra en la pertenencia congelada. Filas y checkpoint
se confirman en la misma transacción bajo lease fenced. El manifiesto declara
cobertura, cambios, ausencias, inesperados, duplicados, soft deletes y si la
evidencia F2 fuente permanece igual.

`completed` es una finalización técnica, no certificación ni publicación.
Hard deletes siguen sin observarse y cualquier discrepancia deja
`verification.verified` en falso. Los lectores e informes oficiales continúan
usando exclusivamente una generación F2/F5 publicada.

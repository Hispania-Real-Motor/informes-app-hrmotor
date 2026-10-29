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

Cuando no existe una generación publicada, una generación histórica en estado
`validating` puede utilizarse únicamente como semilla técnica, nunca como
evidencia de certificación. El operador autorizado puede ejecutar
`salesforce:interest-generation recover-initial` con una generación fuente
explícita, autorización de lectura y un motivo operativo.

El procedimiento copia solamente las memberships de la fuente y reutiliza sus
versiones inmutables. Después lee el estado actual de Salesforce por páginas,
lo transforma con el mismo mapeo F2 y compara el hash de contenido existente.
Solo se escriben versiones para altas o diferencias. Los hard-deletes requieren
cobertura válida de `getDeleted`; una fila local ausente sin esa evidencia hace
fallar la candidata. La reconciliación tiene un máximo de tres pasadas y no
publica ni modifica la generación fuente, el scheduler ni los workers.

F5 se reconstruye en un run nuevo contra la candidata F2 reconciliada; no se
reutiliza el run histórico de la semilla. La evidencia `initial_current_recovery`
guarda generación fuente y recuperada, watermark, runs F2/F5, manifiesto,
contadores finales, cobertura de borrados y número de pasadas. Solo una
generación certificada mediante esa evidencia puede llegar posteriormente a
`publish`, que sigue siendo una acción independiente y bloqueada.

Las consultas de recuperación F2 y F5 leen el estado actual sin limitar
`SystemModstamp` al watermark. La verificación compara lecturas sucesivas;
ese watermark no demuestra una fotografía histórica ni simultaneidad exacta
entre F2 y F5. El resultado y la cobertura deben revisarse antes del uso
operativo; las pruebas simuladas no certifican Salesforce ni producción.

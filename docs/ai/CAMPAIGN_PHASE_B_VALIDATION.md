# Fase B de Campañas — revisión, evidencia y despliegue

> La sección Integración limpia es el estado de esta entrega. El resto se conserva como historial de la rama original: sus bases, resultados intermedios y limitaciones anteriores no sustituyen la validación actual.

Docs-Impact: updated
Docs-Matrix-Impact: updated
Docs-Outside-Paths: ["app/Services/Operations/OperationalTaskCatalog.php", "app/Services/Operations/OperationalTaskHealthService.php", "app/Services/Operations/OperationalTaskService.php", "routes/console.php"]
Docs-Outside-Reason: Estas rutas coordinan catálogo, habilitación, reservas y supervisión del motor operacional central; el nuevo inventario de Campañas y su presentación se documentan mediante las rutas campaigns mapeadas. Los trabajos previos mantienen sus contratos.
Docs-Outside-Evidence: app/Services/Operations/OperationalTaskService.php

## Integración limpia sobre main tras PR #85

Base `89282a32b79060fca671adee4bc49741c954308f`, rama `feat/campaign-current-status-ready`, worktree aislado. Grafo contrastado por el principal: los commits exclusivos `29ab39f`, `ff9e005`, `59e822b` se trasladan con `cherry-pick -x` como `b8f0a20`, `5ff5a43`, `2fdc3a7`. No se traslada `2f3791e`: Fase A y la reconciliación de su matriz ya están en main. Se conserva la matriz general, los 23 campos, seis informes, pestañas de Comisiones, fichas pendientes y el registro focal de Fase A. La rama B original y sus commits permanecen intactos.

La única corrección funcional adicional de integración es DATETIME UTC para last_observed_at/last_verified_at y created_at/updated_at de la tabla nueva, evitando conversión implícita de zona de sesión MariaDB. Se añade una prueba opt-in de integración; no cambian fórmulas, filtros, contratos económicos, sincronizadores históricos o fuentes Salesforce. La tarea sigue deshabilitada y las APIs publicitarias se simulan.

Resultados comunicados hasta esta revisión documental:

- `DashboardMetricPercentStyleTest`: correcto aislado (1 prueba/6 aserciones) y en runner paralelo de dos workers (1/6). El fallo previo era `WorkerCrashedException`, causado por límite de ejecución de 120 s en Container durante reutilización de worker, sin fallo de aserción de formato. Servicios legacy reactivan set_time_limit(120); no se modifican ni se elimina la prueba.
- Primer intento completo PHP serial terminó: **1458 pruebas/13447 aserciones, 0 errores y 2 fallos**, 1269,583 s. Fallan únicamente umbrales de rendimiento: StockRecommendationCandidatePaginationTest::test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion mide 33,468168 s frente a <20 s; CallsEndpointDoesNotTimeoutTest::test_summary_responde_con_volumen_razonable mide 5,683645 s frente a <5 s. DashboardMetricPercentStyleTest pasa. Estos archivos y servicios Stock/Calls son idénticos a main. Hubo otras tareas concurrentes durante el intento, pero no se atribuye causalidad sin aislamiento. Tras detener MariaDB y sin cambiar umbrales, ambas clases aisladas pasan: **3 pruebas/16 aserciones en 16,258 s**. La segunda ejecución completa serial, sin DB, builds ni tests concurrentes, **terminó correctamente: 1458 pruebas/13447 aserciones, 0 errores, 0 fallos y 0 omitidas; exit 0; 494,710877 s (8 min 14,71 s)**. Evidencia JUnit externa: phaseb-final-full-suite.xml. No se deduce causalidad definitiva del primer intento solo a partir de esta repetición. Runner CLI externo PHP -n y subscriber de pruebas restablecen límite ilimitado entre pruebas; no se cambian umbrales, tests ni aplicación para conseguir verde.
- MariaDB 11.4.13 real, portátil local, instancia/base nuevas aisladas: migraciones completas correctas. Integración final **4 pruebas/22 aserciones correctas**, incluido EXPLAIN del índice único, upserts concurrentes y created_at UTC tras SET time_zone=+02:00. Batería focal B **67 pruebas/844 aserciones correctas**: estados 25/160, operaciones 41/664 y regresión económica B 1/20. Las cuatro Integration se ejecutan por separado, no se suman a la suite general configurada.
- JavaScript: 20/20. Build Vite correcto (20,38 s), bundle `campaigns-dashboard-DisEWXqJ.js` idéntico y manifest conjunto. Se conserva CSS compartido `app-DYzMdch_` y bundles previos: no hay cambio CSS fuente ni de otros informes.
- Pint focal aplicado. Pruebas documentales ejecutadas por el agente documental: 37 en 57,455 s, correctas con una omitida por symlink Windows (36 correctas). Enlaces locales de los siete documentos modificados comprobados; matriz original de main preservada literalmente como prefijo del documento ampliado; git diff --check correcto. Validador integral y resultados definitivos: pendientes del cierre del principal sobre el commit final.
- Experimento focal MariaDB de 117 pruebas: estados 25/160 y operaciones 41/664 correctos; Dashboard tuvo 20 errores SQL, sin fallos de aserción. Incluyen ISO8601 con offset en DATETIME legacy interest_sync_cutoff_at e IDs sintéticos de más de 18 caracteres. La fixture nueva B se acorta a sf-current-status (17), sin alterar datos económicos. Comparación baseline terminada: main 89282a3, en otro worktree y otra base MariaDB nueva, ejecutó 50 pruebas de Dashboard/326 aserciones: 31 correctas y 19 errores, sin fallos de aserción. Coinciden los 19 nombres de prueba, SQLSTATE y columnas tras normalizar únicamente el nombre del esquema: 8 errores 22007 por ISO8601+offset en interest_sync_cutoff_at y 11 errores 22001 por IDs sintéticos interest_id/salesforce_id de más de 18 caracteres. El vigésimo error del experimento B era la fixture nueva ya corregida y validada. La comparación sanitizada externa no contiene SQL, datos ni secretos. Esta batería amplia MariaDB no es verde; los 19 casos de main requieren revisión separada de compatibilidad/fixtures, sin inferir fallos en datos productivos.

La regresión económica compara KPIs, filas legacy, CSV y seis tablas protegidas con fixtures. La regresión B pasó sobre MariaDB con 20 aserciones; la ejecución general SQLite se registra al cierre: no certifica datos ni cuentas productivas. No se realiza despliegue, activación, migración productiva ni llamada real a proveedores.

### Diagnóstico del límite de ejecución y prueba porcentual

El error observado fue un worker abortado por tiempo, no un desacuerdo de valores esperado/real. La [documentación oficial de PHP set_time_limit](https://www.php.net/manual/en/function.set-time-limit.php) indica que 0 elimina el límite, que una nueva llamada reinicia el contador y que en Windows se mide tiempo real. Los servicios legacy pueden restablecer 120 s dentro de un worker reutilizado; por ello el runner serial externo restablece 0 al preparar y terminar cada prueba. No se modifica aplicación, php.ini ni el test para ocultar el error. El test porcentual pasa aisladamente y en dos workers; esto no demuestra que toda la suite paralela carezca de otros fallos. Un primer comando paralelo mal escapado con comillas literales alrededor de -n fue un error de invocación, corregido antes del resultado paralelo correcto.

La prueba MariaDB se invoca explícitamente como `php vendor/bin/phpunit tests/Integration/CampaignCurrentStatusMariaDbTest.php` con `CAMPAIGN_MARIADB_TEST=1` y configuración de proceso dirigida a una base nueva local con prefijo `hrmotor_phase_b_test_`, entorno testing y driver mariadb. El principal usa runner PHP limpio equivalente por compatibilidad del entorno Windows. No se publican contraseñas ni instrucciones dirigidas a bases existentes. `phpunit.xml` no incluye Integration en las suites ordinarias.

Comparación Git de integración: `git diff 59e822b -- app resources config routes` vacío, comprobado por el agente documental. Respecto a B original, cambios adicionales de código acotados a cuatro tipos temporales en la migración nueva, fixture B de interés de 17 caracteres y nueva prueba Integration; no se cambian servicios económicos. PHP -l de 15 archivos correcto comunicado por el principal; Pint --test final y PHP -l de los 15 archivos correctos. El contraste baseline MariaDB ha terminado con los 19 errores preexistentes reproducidos; la repetición completa serial SQLite pasó con 1458 pruebas/13447 aserciones, sin errores ni fallos.

### Identidad de la fila económica legacy: límite y protección posterior

`CampaignDashboardDatasetService::metricRows()` agrupa métricas incluyendo cuenta, pero `mergeRows()` usa `rowKey()`, que para `platform_campaign` omite account_id deliberadamente para unir métricas con atribuciones sin cuenta. Si dos cuentas comparten plataforma, Campaign ID y nombre normalizado, la fila económica legacy puede combinarse y conservar la cuenta de su fila inicial. Fase B no modifica esta regla, sus filtros ni su universo económico. En el cierre 46f283f, el DTO verificaba exclusivamente la tupla plataforma/cuenta/ID que informaba la fila; esa limitación se protege en el ajuste posterior descrito a continuación. No representa automáticamente el estado de varias cuentas potencialmente combinadas. No se infiere identidad por nombre. La persistencia de estados sí distingue cuentas con Campaign IDs iguales, como cubren las pruebas. Antes de activar, revisar la correspondencia de identidades del informe y esta condición legacy; no se afirma que se haya corregido la agrupación por cuenta ni que exista el caso en producción.

### Resultado general definitivo de esta integración

La segunda suite completa configurada (Unit y Feature, SQLite) pasa: **1458 pruebas, 13447 aserciones, cero errores/fallos/omitidas**, exit 0 en 494,710877 s. El primer intento fallido se conserva arriba; no se borran ni relajan tests. En el JUnit final, la duración total del caso Stock es 14,812110 s (3 aserciones), Calls 2,699829 s (3) y DashboardMetricPercentStyleTest 1,521623 s (6). Son duraciones de los casos completos, no las mediciones internas de cálculo/petición usadas por sus umbrales. Las cuatro pruebas MariaDB Integration permanecen separadas de esta suite configurada.

La validación amplia Dashboard/MariaDB conserva la limitación de 19 errores reproducidos también en main; el éxito SQLite no la elimina. Pint --test y sintaxis finales correctos (15 archivos); pruebas documentales finales 37, con 36 correctas y una omitida por symlink Windows. El validador integral se ejecuta sobre el commit final, sin activar la tarea.

### Archivos exclusivos de esta integración frente a main

- `app/Console/Commands/SyncCampaignCurrentStatus.php`
- `app/Services/Campaigns/CampaignCurrentStatusService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/GoogleAdsClient.php`
- `app/Services/Campaigns/MetaAdsClient.php`
- `app/Services/Operations/OperationalTaskCatalog.php`
- `app/Services/Operations/OperationalTaskHealthService.php`
- `app/Services/Operations/OperationalTaskService.php`
- `config/campaign_current_status.php`
- `database/migrations/2026_10_23_120000_create_campaign_current_statuses_table.php`
- `docs/ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md`
- `docs/ai/CAMPAIGN_PHASE_B_VALIDATION.md`
- `docs/ai/DECISIONS.md`
- `docs/ai/HANDOFF.md`
- `docs/ai/PROJECT_CONTEXT.md`
- `docs/decisiones-negocio-pendientes.md`
- `docs/guia-mantenimiento-documental.md`
- `docs/informe-campanas.md`
- `docs/matriz-trazabilidad-kpi.md`
- `docs/operaciones-produccion.md`
- `public/build/assets/campaigns-dashboard-DisEWXqJ.js`
- `public/build/assets/campaigns-dashboard-DlRi4wRD.js`
- `public/build/manifest.json`
- `resources/js/reports/campaign-presentation.js`
- `resources/js/reports/campaigns-dashboard.js`
- `routes/console.php`
- `scripts/check_documentation_integrity.py`
- `tests/Concerns/AssertsOperationalSchedule.php`
- `tests/Feature/CampaignCurrentStatusTest.php`
- `tests/Feature/CampaignDashboardTest.php`
- `tests/Integration/CampaignCurrentStatusMariaDbTest.php`
- `tests/documentation/test_check_documentation_integrity.py`
- `tests/js/campaign-presentation.test.mjs`

### Comandos de validación documental de la entrega actual

```text
python -B scripts/check_documentation_integrity.py --base 89282a32b79060fca671adee4bc49741c954308f --head <SHA_FINAL_COMPLETO> --pr-body-file docs/ai/CAMPAIGN_PHASE_B_VALIDATION.md
python -B -m unittest discover -s tests/documentation -p test_*.py
git diff --check
```

## Historial de validación de la rama original

## Base y alcance

Rama `feat/campaign-current-status-and-number-format`, main verificado `e314c8d48e8deb091206bd8a3aadb3dff910929e`. Fase A no estaba fusionada; se incorpora su dependencia `6fe686ad5dda65664af1defb20164dcbc41d2345` en `2f3791e`. El diff completo contra main incluye ambas fases; el diff B parte de 2f3791e. No se fusiona ni despliega.

Causa raíz: los estados de métricas diarias no acreditaban vigencia y el agrupamiento implícito de es-ES omitía cuatro cifras. La corrección separa inventario operativo y formato de los valores económicos. Una tabla adicional evita reutilizar históricos o identificadores de atribución; auditoría existente evita duplicar ejecuciones. Contrato, índice y frescura: [informe](../informe-campanas.md). Operación deshabilitada y rollout/rollback: [runbook](../operaciones-produccion.md).

## Evidencia sintética comunicada por el agente principal

- `CampaignCurrentStatusTest`: 25 pruebas, 157 aserciones correctas.
- Pruebas existentes de CampaignDashboard, comandos/sincronización de intereses legacy y operaciones/monitor/review: 167 pruebas, 2279 aserciones correctas.
- Nueva regresión de integración económica: una prueba, 20 aserciones correctas. Construye cierre sintético aislado antes de comparar; no crea ni modifica cierres productivos.
- `node --test tests/js/campaign-presentation.test.mjs`: 20/20 correctas, incluidos renderizadores y formateadores reales.
- Suite general: pendiente de terminar. Build, sintaxis y Pint correctos; evidencia final detallada más abajo.

La regresión `test_current_status_sync_changes_only_additive_metadata_outside_economic_cache_and_csv` compara KPIs idénticos, filas idénticas tras retirar únicamente `current_status`, CSV exacto y serializaciones de seis tablas: métricas, ambas atribuciones, cierres, snapshots y eventos. Tras sincronización simulada, un estado histórico Activa coexiste con actual Pausada en el mismo payload económico cacheado. Tras 24h, la proyección es stale sin nuevas llamadas externas. Esta evidencia local no es auditoría de producción ni prueba con MariaDB real.

## Formato y límites de verificación

Pruebas reales cubren 999/1000/1234/9999/10000/1234567, moneda decimal/negativa, porcentaje y multiplicador, null, ejes, revisión, ordenación y no alteración de ID largo. Detalle mantiene — y tooltips específicos. La revisión documental contrasta los archivos nuevos además del diff tracked; no se consultan bases reales ni Salesforce ni APIs de cuentas.

Los tests simulados no acreditan cobertura/permisos de cuentas reales, tiempos de inventarios grandes ni QA visual real. Debe verificarse en una primera operación futura autorizada la cobertura de campañas sin gasto y eliminadas, errores de cuenta y supervisión. No se ejecuta migración sobre base existente ni se activa scheduler. Retención de report_sync_runs y capacidad operacional deben revisarse antes de activar; la falta de auditoría suficiente degrada conservadoramente la vigencia.

## Comprobación documental final

Ejecutar tras el commit final (HEAD debe contener código y documentos):

```text
python -B scripts/check_documentation_integrity.py --base e314c8d48e8deb091206bd8a3aadb3dff910929e --head <SHA_FINAL_COMPLETO> --pr-body-file docs/ai/CAMPAIGN_PHASE_B_VALIDATION.md
python -B -m unittest discover -s tests/documentation -p test_*.py
git diff --check
```

No publicar solo manifest o fuentes. Comprobar que el bundle señalado existe y servir ambos desde el mismo release; mantener assets anteriores para páginas cacheadas. El rollback recomendado conserva tabla operativa/auditoría, deshabilita nuevas tareas y restaura el release completo sin operaciones históricas.

Actualización de evidencia frontend:19/19 pruebas JS correctas tras añadir expiración en página abierta. Un temporizador único hasta el siguiente vencimiento y visibilitychange actualizan solo texto/title de celdas; no se consulta red ni se reconstruyen métricas. Resultados finales PHP/build/suite general: consultar cierre en HANDOFF.


## Cierre de verificaciones focalizadas

- PHP final de estados y dashboard: 75/75 pruebas, 693 aserciones (24 estados + 51 dashboard), tras los ajustes finales de metadata y consultas acotadas.
- JavaScript final: 19/19, incluido vencimiento de celdas en página abierta.
- Pint focal final: passed. Sintaxis PHP de los archivos modificados y node --check de ambos módulos: correctas.
- npm run build final: correcto; public/build/assets/campaigns-dashboard-DlRi4wRD.js (60,68 kB) y manifest verificados conjuntamente. Se conservan los bundles versionados anteriores para páginas cacheadas y rollback.
- Suite documental: 36 ejecutadas, 35 correctas y una omitida por privilegio Windows de symlink. Suite PHP general: pendiente de terminar al registrar este cierre focal.
- Vite emitió aviso de deprecación Node DEP0205 sobre module.register; el build terminó correctamente, sin nuevas dependencias.

Comandos ejecutados: node --test tests/js/campaign-presentation.test.mjs; node --check de ambos módulos; npm run build; PHP vendor/bin/pint sobre los PHP modificados y después --test; PHP -l de los PHP modificados; Python -m unittest discover -s tests/documentation -v; git diff --check. PHPUnit focal se ejecutó directamente mediante PHP -d auto_prepend_file= -r, autoload y PHPUnit\TextUI\Application, sobre tests/Feature/CampaignCurrentStatusTest.php y tests/Feature/CampaignDashboardTest.php. La batería existente y general requieren runner Pest (hay tests Pest además de PHPUnit); se lanzó con PHP -d auto_prepend_file= -r cargando vendor/pestphp/pest/bin/pest y --compact. SQLite :memory:, CACHE_STORE=array y APP_KEY sintética limitada al proceso; sin datos ni credenciales de producción.

Se corrigió el aislamiento de fakes HTTP en las pruebas nuevas: cada escenario debe reemplazar Factory para no conservar callbacks del escenario anterior. Un primer intento de la batería existente registró una comparación de segundo inestable en test_release_audits_verified_os_principal_or_explicitly_unknown_actor_atomically; la repetición de los 167 tests existentes pasó sin modificar ese test. Invocar PHPUnit sobre toda la suite fue rechazado porque los tests Pest necesitan su runner; se usó Pest para la suite general. Estos intentos no se presentan como ejecuciones correctas.

## Revisión incremental de frescura posterior a 29ab39f

Se exige que el último run sea `completed`, que ambas referencias de ejecución sean no nulas y coincidan, además de estado reconocido y edad <24h. La desaparición de auditoría por retención (FK nullOnDelete) o un run marcado fallido con el mismo ID producen stale, nunca fresh. El cambio solo endurece la proyección operativa; no altera el contrato económico ni CSV. La regresión `test_missing_or_failed_audit_cannot_confirm_freshness_even_with_a_recent_timestamp` reproduce ambos casos.

Resultados focales finales comunicados:76/76 PHP (25 estados +51 dashboard),696 aserciones; JS19/19; build final correcto, `campaigns-dashboard-DlRi4wRD.js` (60,68 kB), manifest con destinos existentes y contenido de agrupamiento/expiración comprobado. Validador integral pasó sobre29ab39f (34 rutas); debe repetirse tras commit de este endurecimiento. Suite general: primer intento interrumpido por límite120s local reactivado por servicios; repetición en proceso con subscriber de pruebas aislado que restaura límite0 antes de cada test, sin modificar aplicación ni php.ini. No se declara aún éxito de la suite completa.

Último ajuste B2 contrastado: cellHtml reutiliza texto numérico formateado en el title cuando el tooltip de origen era numérico; conserva los motivos de costes/estado y el escape HTML. Prueba de tabla real incluye1.234 y divisor cero, sin modificar el objeto original. JS20/20 correctas comunicadas por el agente principal; Pint --test y PHP -l del endurecimiento final correctos. El build se vuelve a generar tras este ajuste: el hash DlRi4wRD anterior es evidencia de una iteración previa, no la referencia final; consultar manifest y cierre HANDOFF. Suite general aún en proceso.


## Asset vigente y ejecución general final

El último build completo termina correctamente en 24,86s y el manifest referencia assets/campaigns-dashboard-DisEWXqJ.js (60,73kB). Se verifica que todos los archivos referenciados por el manifest existen y que el bundle incluye current_status, agrupación explícita y caducidad de celdas. Los bundles anteriores se conservan sin cambios. Este hash sustituye como asset vigente a la iteración DlRi4wRD citada antes.

Pint --test y PHP -l tras el endurecimiento de auditoría: correctos. PHP76/76 (696 aserciones) y JS20/20 son las baterías focales finales. No existen fallos focales pendientes.

El primer intento general se interrumpió por Maximum execution time of120seconds. Otros servicios existentes invocan set_time_limit(120), por lo que cambiar solo el límite inicial no impide que se reactive. Se reinicia la suite general sobre el PHP final con max_execution_time=0 y un subscriber CLI de PHPUnit Test\Prepared que restablece el límite antes de cada prueba. No se modifica php.ini, código de otros informes ni políticas de producción. El intento previo fue detenido deliberadamente antes de iniciar este runner final; no se declara completo. El resultado final se registra al terminar.


## Relación completa de archivos contra main verificado

Incluye la dependencia de Fase A. El diff exclusivo de B usa 2f3791e como base.

- `app/Console/Commands/SyncCampaignCurrentStatus.php`
- `app/Services/Campaigns/CampaignCurrentStatusService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/GoogleAdsClient.php`
- `app/Services/Campaigns/MetaAdsClient.php`
- `app/Services/Operations/OperationalTaskCatalog.php`
- `app/Services/Operations/OperationalTaskHealthService.php`
- `app/Services/Operations/OperationalTaskService.php`
- `config/campaign_current_status.php`
- `database/migrations/2026_10_23_120000_create_campaign_current_statuses_table.php`
- `docs/ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md`
- `docs/ai/CAMPAIGN_PHASE_A_REVIEW.md`
- `docs/ai/CAMPAIGN_PHASE_B_VALIDATION.md`
- `docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md`
- `docs/ai/DECISIONS.md`
- `docs/ai/HANDOFF.md`
- `docs/ai/PROJECT_CONTEXT.md`
- `docs/decisiones-negocio-pendientes.md`
- `docs/guia-mantenimiento-documental.md`
- `docs/informe-campanas.md`
- `docs/matriz-trazabilidad-kpi.md`
- `docs/operaciones-produccion.md`
- `public/build/assets/campaigns-dashboard-C4UL5FZw.js`
- `public/build/assets/campaigns-dashboard-DisEWXqJ.js`
- `public/build/assets/campaigns-dashboard-DlRi4wRD.js`
- `public/build/manifest.json`
- `resources/js/reports/campaign-presentation.js`
- `resources/js/reports/campaigns-dashboard.js`
- `routes/console.php`
- `scripts/check_documentation_integrity.py`
- `tests/Concerns/AssertsOperationalSchedule.php`
- `tests/Feature/CampaignCurrentStatusTest.php`
- `tests/Feature/CampaignDashboardTest.php`
- `tests/documentation/test_check_documentation_integrity.py`
- `tests/js/campaign-presentation.test.mjs`

## Contrato final de atributos operativos

Meta solicita `effective_status` como parámetro de colección JSON con seis códigos contemplados, sin filtering ni fechas. Evidencia oficial: [SDK AdAccount.get_campaigns](https://github.com/facebook/facebook-python-business-sdk/blob/main/facebook_business/adobjects/adaccount.py); regresión HTTP valida la forma y los códigos archivados/eliminados. Ausencia de status o Metaeffective_status invalida la cuenta; Google comprueba campaign.status antes de normalizar. Null explícito es observación no verificable y no renueva último válido, conservando la diferencia entre origen incompleto y código sin evidencia.

Asset definitivo comunicado: `campaigns-dashboard-DisEWXqJ.js`60,73kB, manifest conjunto; JS20/20. PHP76/698 corresponde a ejecución anterior al último assert específico del SDK: registrar el resultado final real al cierre, sin extrapolar. Sin sincronización ni migración real.

## Cierre final de entrega (prevalece sobre iteraciones anteriores)

- PHP estados + dashboard: **76 pruebas, 699 aserciones correctas**, sobre el código final, incluido el parámetro Meta `effective_status`.
- Batería existente de campañas y operaciones: **167 pruebas, 2279 aserciones correctas**. Hay solapamiento con dashboard; no se suman ambas cifras como pruebas únicas.
- JavaScript: **20/20**. Documentación: **35 correctas y 1 omitida** por privilegios de symlink Windows.
- Build Vite correcto y manifest conjunto: `campaigns-dashboard-DisEWXqJ.js`. Sintaxis y Pint focal final correctos; `git diff --check` correcto.
- La suite PHP general **no se completó**. Primer intento: fatal `Maximum execution time of 120 seconds exceeded`. Repetición con subscriber CLI: salida final vacía y código 1, sin diagnóstico concluyente. Repetición Pest `--parallel --processes=4 --compact`: `WorkerCrashedException` en `DashboardMetricPercentStyleTest.php`, fatal del mismo límite120s en `Illuminate/Container/Container.php:1541`. Otros servicios existentes reactivan el límite; no se modifican otros informes para evitarlo. No se afirma éxito global ni ausencia de regresiones fuera de las baterías ejecutadas.
- Integridad demostrada únicamente en fixtures SQLite y HTTP simulado: KPIs, CSV y seis tablas históricas idénticos antes/después. No se han consultado ni alterado datos productivos. Pendiente repetir suite completa en CI con recursos/límites adecuados y QA real autorizado de proveedores/MariaDB.

Comando general final: PHP con `-d auto_prepend_file= -d max_execution_time=0 vendor/pestphp/pest/bin/pest --parallel --processes=4 --compact`, con APP_KEY sintética limitada al proceso y configuración de pruebas SQLite. No se introduce configuración de producción ni se activa la tarea. La salida se revisó desde un archivo temporal porque el runner local no transmitía progreso.

El índice existente `report_sync_runs_lookup_idx(dataset, source, status, completed_at)` cubre el prefijo de consulta de auditoría; la nueva tabla añade unique por identidad, índice de source y FK de ejecución. Validar planes/capacidad en MariaDB antes de activar. Migración aditiva pendiente de despliegue autorizado; rollback de código conserva la tabla operativa y toda la historia económica.

## Protección posterior de identidad — base 46f283f4e721c18a6d120e8182ee089433870a6a

El comportamiento vigente neutraliza el estado cuando confluyen varias cuentas en la misma fila económica. La marca se calcula durante `mergeRows()` con cuentas métricas normalizadas; una contribución desconocida junto con una conocida es ambigua. No se incluyen como cuentas adicionales atribuciones Salesforce sin métricas. `decorate()` evita el lookup de filas ambiguas y devuelve unavailable con `identity_ambiguous:true` y `reason:ambiguous_account_identity`, sin identificadores de cuenta, códigos o fechas operativas. El tooltip específico explica el motivo. No se cambia rowKey ni la conciliación. Si el filtro histórico selecciona una sola cuenta, se conserva su economía y su estado inequívoco. Payload v6 evita cache previo sin marca; exportRows elimina metadata y mantiene CSV.

Evidencia sintética: dos cuentas con mismo Campaign ID/nombre normalizado, inversión 120/230 y estados ACTIVE/PAUSED, inversión combinada 350, estado neutral, KPIs/CSV/métricas persistidas idénticos antes/después; filtrado de cuenta conserva 230/Pausada. IDs iguales con nombres distintos no se fusionan; fila sin cuenta no acredita estado. Prueba del decorador verifica ausencia total de consultas si todas las filas son ambiguas.

Resultados comunicados por el principal para este ajuste: focales PHP **79 pruebas/735 aserciones correctas**; JavaScript **21/21 correctas**; build repetido correcto **10,12 s**, bundle `campaigns-dashboard-Bo45id63.js` con manifest conjunto; sintaxis JS/UTF8 correctas. Primer build falló por codificación UTF8 de la nueva línea del tooltip, corregida antes de repetir. Suite PHP completa de esta nueva revisión **en curso**; el resultado 1458/13447 previo corresponde al cierre anterior, no acredita aún este ajuste. No se han ejecutado nuevas pruebas MariaDB para esta protección. El principal incorporará el resultado definitivo y el validador sobre commit final. No hay migración nueva ni activación, consultas reales o cambios económicos.


### Validación general de la protección de identidad — intentos registrados

Primer intento completo: 1461 pruebas/13483 aserciones, 0 errores, 1 fallo, 0 omitidas, 637,456082 s. Único fallo: StockRecommendationCandidatePaginationTest::test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion, cálculo 23,0175765 s frente al umbral <20. Repetición aislada de la clase: 2 pruebas/13 aserciones, 0 errores y 1 fallo, 33,478901 s; mismo caso, cálculo 25,3288557 s. No se modifica Stock ni el test, no se eliminan pruebas ni se relajan umbrales. La segunda ejecución completa está en curso y sus resultados se registrarán al terminar. Estos fallos no acreditan una regresión económica ni se presentan como éxito global.

Comandos: PHP CLI con extensiones existentes y runner serial de pruebas, --compact --filter='CampaignCurrentStatusTest|CampaignDashboardTest' (79/735); suite general sin --filter, --log-junit para cada ejecución; Stock aislado con --filter=StockRecommendationCandidatePaginationTest; node --test tests/js/campaign-presentation.test.mjs (21/21); npm run build (repetición correcta), Pint --test y PHP -l en los cuatro PHP modificados, node --check de ambos módulos, git diff --check. Solo bases sintéticas de pruebas, sin proveedores reales. El runner CLI evita límites de tiempo heredados en Windows; no cambia archivos ni configuración de aplicación.


### Resultado definitivo de esta corrección

Segunda suite general completa serial: **1461 pruebas, 13483 aserciones, 0 errores, 0 fallos, 0 omitidas**, exit 0, 380,208754 s. JUnit externo identity-final-full-suite.xml. Incluye campañas, atribuciones, cierres y el caso Stock antes fallido, sin modificar umbrales ni eliminar pruebas. Los intentos anteriores quedan registrados como variabilidad local observada; no se afirma una causa demostrada. Código PHP y pruebas congelados durante ambas ejecuciones. Focales 79/735 y JS21/21 correctos; Pint, PHP -l, node --check y diffcheck correctos. Revisión documental delegada finalizada y revisada: 36 correctas/1 omitida, 33 enlaces existentes. No se ejecutaron nuevas pruebas MariaDB ni operaciones productivas; ninguna migración nueva.

Diff de esta tarea contra 46f283f: dos servicios PHP, helper de presentación, tres archivos de pruebas, cinco documentos, bundle Bo45id63 y manifest (13 archivos). Solo cambia metadato/tooltip; rowKey, sumas, fórmulas, filtros y CSV económico se conservan. Despliegue futuro: publicar código y assets/manifest juntos; payload v6 omite cachés v5 sin marca automáticamente. Sin migraciones adicionales ni activación. Rollback: restaurar release 46f283f con su bundle/manifest; conserva todos los datos, pero vuelve a la limitación de identidad anterior y requiere revisión antes de activar sincronización. No hubo push, merge, despliegue ni sincronización real.

# Fase B de Campañas — revisión, evidencia y despliegue

> Registro incremental: las cifras y hashes intermedios se conservan como historial. El cierre final al pie prevalece para el resultado de entrega.

Docs-Impact: updated
Docs-Matrix-Impact: updated
Docs-Outside-Paths: ["app/Services/Operations/OperationalTaskCatalog.php", "app/Services/Operations/OperationalTaskHealthService.php", "app/Services/Operations/OperationalTaskService.php", "routes/console.php"]
Docs-Outside-Reason: Estas rutas coordinan catálogo, habilitación, reservas y supervisión del motor operacional central; el nuevo inventario de Campañas y su presentación se documentan mediante las rutas campaigns mapeadas. Los trabajos previos mantienen sus contratos.
Docs-Outside-Evidence: app/Services/Operations/OperationalTaskService.php

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

# Fase B de Campañas — revisión, evidencia y despliegue

Docs-Impact: updated
Docs-Matrix-Impact: updated
Docs-Outside-Paths: ["app/Services/Operations/OperationalTaskCatalog.php", "app/Services/Operations/OperationalTaskHealthService.php", "app/Services/Operations/OperationalTaskService.php", "routes/console.php"]
Docs-Outside-Reason: Estas rutas coordinan catálogo, habilitación, reservas y supervisión del motor operacional central; el nuevo inventario de Campañas y su presentación se documentan mediante las rutas campaigns mapeadas. Los trabajos previos mantienen sus contratos.
Docs-Outside-Evidence: app/Services/Operations/OperationalTaskService.php

## Base y alcance

Rama `feat/campaign-current-status-and-number-format`, main verificado `e314c8d48e8deb091206bd8a3aadb3dff910929e`. Fase A no estaba fusionada; se incorpora su dependencia `6fe686ad5dda65664af1defb20164dcbc41d2345` en `2f3791e`. El diff completo contra main incluye ambas fases; el diff B parte de 2f3791e. No se fusiona ni despliega.

Causa raíz: los estados de métricas diarias no acreditaban vigencia y el agrupamiento implícito de es-ES omitía cuatro cifras. La corrección separa inventario operativo y formato de los valores económicos. Una tabla adicional evita reutilizar históricos o identificadores de atribución; auditoría existente evita duplicar ejecuciones. Contrato, índice y frescura: [informe](../informe-campanas.md). Operación deshabilitada y rollout/rollback: [runbook](../operaciones-produccion.md).

## Evidencia sintética comunicada por el agente principal

- `CampaignCurrentStatusTest`: 24 pruebas, 154 aserciones correctas.
- Pruebas existentes de CampaignDashboard, comandos/sincronización de intereses legacy y operaciones/monitor/review: 167 pruebas, 2279 aserciones correctas.
- Nueva regresión de integración económica: una prueba, 20 aserciones correctas. Construye cierre sintético aislado antes de comparar; no crea ni modifica cierres productivos.
- `node --test tests/js/campaign-presentation.test.mjs`: 18/18 correctas, incluidos renderizadores y formateadores reales.
- Suite general, build, sintaxis y Pint: resultados finales pendientes de comunicación al cierre; no se declaran superados aquí hasta su registro final en HANDOFF.

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

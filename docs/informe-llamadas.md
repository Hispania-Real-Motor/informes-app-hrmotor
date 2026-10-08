# Informe de Llamadas

Actualizado: 2026-10-08.

## Universo

- Fuente: Salesforce `Task` con `Type = Call`.
- El dashboard incluye únicamente llamadas con `CallObject`, es decir, llamadas
  con información de centralita asociada.
- Las Tasks de llamada sin `CallObject` se conservan para conciliación con
  motivo `missing_call_object`, pero no suman en los KPIs operativos.
- Fecha pivote: `Task.CreatedDate`.
- Tablas: `salesforce_calls`, `salesforce_interests`, `salesforce_users`,
  `call_agent_mappings` y `salesforce_call_classification_history`.

## Clasificación vigente

Versión de reglas: `2026-10-08.1`.

- Atendida: resultado normalizado `ANSWERED` o valor válido extraído de
  `Respondido por`, salvo una regla más fuerte de no atención.
- `ABANDONED`: siempre perdida, nunca atendida y nunca desbordada, aunque la
  descripción contenga otros valores.
- No atendida: resto de clasificaciones no atendidas.
- Origen directo: llamada de centralita/directa sin portal operativo.
- Origen portal: llamada asociada a un portal.
- Desbordada: llamada de portal atendida por Contact Center o Atención al
  Cliente, respetando portal y opción de teclado. `ABANDONED` queda excluida de
  esta regla.
- Las llamadas operativas sin equipo aparecen como `Sin equipo`; la suma de
  equipos debe reconciliar con el total correspondiente.
- El perfil Salesforce exacto `Pruebas comunidad comercial` queda fuera de todos
  los KPI y desgloses operativos con motivo `excluded_test_profile`, pero sus
  Tasks permanecen auditables. `missing_call_object` es el motivo estructural
  primario cuando concurren ambas condiciones.
- Equipo, zona y delegación son dimensiones independientes: Atención al
  Cliente, Contact Center y Tasadores no se generan como geografías ficticias.

El parser extrae de `Description` resultado, `Respondido por`, agente, cola,
opción de teclado, duración y demás valores brutos. Esos valores se conservan
en la auditoría junto con la interpretación final.

## Atribución CRM ROT-2

- `Task` continúa siendo el hecho contado. La única relación CRM autorizada es
  la coincidencia exacta local `Task.WhatId = salesforce_interests.salesforce_id`.
  `WhoId` se conserva como evidencia bruta, pero no atribuye Interest ni fuente.
- `Portales__c = null` continúa siendo `commercial_direct`. Un valor Task
  reconocido continúa siendo autoritativo aunque el Interest indique otra
  fuente. Solo cuando Task está informado pero queda `Sin clasificar` se usa
  `salesforce_interests.source` como fallback, con el mismo normalizador de
  portales. No existe cascada por Lead, `original_source`, medium, channel ni
  procedencia.
- Un Interest eliminado conserva la relación histórica y puede aportar su
  fuente; no excluye la llamada. Si `WhatId` no resuelve localmente, no se
  infiere otra relación ni se consulta Salesforce. Una clasificación histórica
  visible de una llamada existente puede conservarse de forma explícita como
  `historical_preserved`, sin presentarse como evidencia Interest actual.
- Owner y `origin_delegation` del Interest no cambian agente, equipo,
  delegación o zona operativos de la llamada.
- El runtime no depende de FOUNDATION-5: la relación procede del `WhatId` ya
  persistido en `salesforce_calls` y el enrichment se realiza por lotes contra
  la réplica local de Interests.

## Duración

Regla definitiva vigente:

- llamada directa: `max(duración base - 5, 0)`;
- portal: `max(duración base - 10, 0)`.

La duración base usa `CallDurationInSeconds` y la descripción como fallback. No
debe modificarse esta regla sin contrastar una muestra de Salesforce y
centralita; sigue siendo una decisión funcional pendiente.

## Equipos y perfil de pruebas

La clasificación usa usuario operativo, aliases explícitos y perfiles. Las
identidades de sistema no se mezclan con los equipos operativos.

`Pruebas comunidad comercial` no se excluye automáticamente. Debe auditarse:

```bash
php artisan reports:audit-calls-profile --profile="Pruebas comunidad comercial" --from=2026-07-01 --to=2026-07-31
```

El resultado debe revisarse por User ID, nombre, volumen, origen, equipo y
ejemplos antes de aprobar una exclusión o reasignación.

## Versionado e histórico

Cada llamada conserva clasificación, versión, fecha, valores brutos y motivo de
inclusión/exclusión. La sincronización guarda la clasificación anterior cuando
cambia un campo funcional, tanto si Salesforce modifica la Task como si cambia
la evidencia local exacta de Interest. Una reejecución idéntica no genera
histórico.

Un cambio de parser no reprocesa históricos automáticamente. El reproceso es
manual, exige período explícito y `--dry-run` o motivo:

```bash
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --reason="Motivo aprobado y documentado"
```

En este comando `--to` es inclusivo. Antes de un reproceso real se recomienda
exportar la auditoría y revisar el resumen del modo simulación.

## Auditoría y operación

- CSV: `/informes/llamadas/export/audit.csv`.
- Diagnóstico: `php artisan reports:debug-calls`.
- Sincronización operativa automática: diariamente a las 04:45
  `Europe/Madrid`, `php artisan salesforce:sync-calls --days=7`, monitorizada
  como `salesforce-sync-calls` y protegida con `withoutOverlapping(60)`.
- Sincronización manual histórica o de diagnóstico, cuando exista una ventana
  expresamente revisada: `php artisan salesforce:sync-calls --days=120`.

El CSV contiene exactamente una fila por cada `Task.Id` del universo bruto
conciliado en el período y ámbito solicitados. Incluye tanto las Tasks que
participan en KPI como las excluidas; las que carecen de `CallObject` permanecen
con motivo `missing_call_object`. La exportación se escribe mediante cursor, sin
materializar el conjunto completo en memoria y sin consultas por fila.

Contiene inclusión/exclusión, resultado bruto e interpretado, duraciones,
segundos descontados, portal, equipo, usuario, valores del parser, regla,
versión y corte. También expone `WhatId`, coincidencia exacta, ID y lifecycle
del Interest, source de fallback y procedencia efectiva Task/Interest/histórica/
sin clasificar, sin payload ni PII del Interest. Los valores estructurados de
`classification_raw_values` se
serializan como JSON UTF-8 estable, sin escapar Unicode ni barras y sustituyendo
secuencias UTF-8 inválidas. `null` se exporta vacío y un array vacío como `[]`.

El desglose visible de atendidas se construye desde las mismas filas por equipo
que el total. Si existen llamadas operativas atendidas sin equipo canónico, se
muestra la fila `Sin equipo` cuando su valor es mayor que cero; por tanto, la
suma de los equipos visibles reconcilia con el total de atendidas.

Archivos principales:

- `app/Services/Reports/Calls/SalesforceCallSyncService.php`;
- `app/Services/Reports/Calls/CallDescriptionParser.php`;
- `app/Services/Reports/Calls/CallClassificationRules.php`;
- `app/Services/Reports/Calls/CallInterestPortalResolver.php`;
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`;
- `app/Console/Commands/ReprocessCallsClassificationCommand.php`.

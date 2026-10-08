# Informe de Interests — contrato ROT-1

> La ruta `/informes/leads`, el permiso `leads` y varias claves técnicas
> `leads_*` se conservan por compatibilidad. Desde ROT-1 el hecho funcional del
> informe es Salesforce `Interes__c`, no `Lead`.

Actualizado: 2026-10-08.

## Fuentes y persistencia vigentes

El informe usa exclusivamente estas fuentes locales:

- `salesforce_interests`: réplica read-only de `Interes__c` (FOUNDATION-2);
- `salesforce_interest_activity_runs` y `salesforce_interest_activities`:
  evidencia FOUNDATION-5 de Task/Event relacionados directamente mediante
  `WhatId → Interes__c`;
- `salesforce_users`: elegibilidad y dimensión comercial del owner;
- `report_sync_runs`: estado, run y cutoff F2 auditables.

El render HTTP no consulta Salesforce. No recupera atributos desde Lead,
Account o Contact y no utiliza PII como fallback.

## Período funcional

- La fecha del hecho es `salesforce_interests.functional_created_at`.
- La persistencia es UTC.
- Los períodos visibles y calendarios de negocio se interpretan en
  `Europe/Madrid`; sus límites se convierten explícitamente a UTC antes de
  consultar la columna persistida.
- Los cutoffs se publican con instante y offset inequívocos.

## Estado y lifecycle

Los estados funcionales proceden de `salesforce_interests.status`: Convertido,
Descartado y Potencial mantienen la semántica vigente de KPI. Un estado no
reconocido permanece auditable y no se fuerza a otro estado.

`salesforce_interests.is_deleted = true` excluye el Interest del KPI activo. El
registro sigue disponible en conciliación con `salesforce_deleted_at` y
`deletion_detection_source`; nunca se presenta como incluido en el dataset
activo.

## Tipo

El tipo procede exclusivamente de `salesforce_interests.type`.
`LeadRecordTypeNormalizer` mantiene la normalización compatible para filtros y
KPIs (`tasacion`, `venta`, `venta_con_cambio`), pero ROT-1 no recupera
RecordType ni otro atributo desde Lead.

## Fuente, medio y canal

Los campos vigentes son:

- Fuente: `source`;
- fuente original: `original_source`;
- medio: `medium`;
- canal: `channel`.

`portal` continúa únicamente como alias técnico compatible. El informe ROT-1
no aplica heurísticas basadas en `Portal_Text__c`, `Fuente_Nuevo__c`,
`LEA_SEL_Fuente_Origen__c` o campos Lead equivalentes.

## Comercial y procedencia

- El comercial candidato es el owner del Interest.
- Su elegibilidad se determina con `salesforce_users`: usuario activo y perfil
  `Compra/Venta` o `Comerciales Partner Community`.
- La delegación y zona comercial proceden de la dimensión del owner elegible.
- La procedencia del Interest es `origin_delegation` y se normaliza con
  `LeadDelegationNormalizer` por compatibilidad técnica.

No se usa persona que trabajó el Lead, propietario al descarte ni fallback de
delegación Lead.

## Actividad directa FOUNDATION-5

Solo cuenta actividad:

- del snapshot F5 `completed` ligado al mismo ID y cutoff F2;
- con `relationship_status = resolved`;
- no eliminada;
- relacionada directamente por `WhatId → Interes__c`.

Para Task, `ActivityDate` determina el día funcional en `Europe/Madrid` y
`CreatedDate` solo desempata Tasks del mismo `ActivityDate`; nunca mueve una
Task a otro día. Una Task sin `ActivityDate` cuenta como evidencia existente,
pero no demuestra recencia mediante una fecha inventada. Para Event,
`StartDateTime` es el instante funcional. Los timestamps técnicos de sync no
sustituyen esas fechas.

Potencial sin trabajar significa potencial no asignado técnicamente y sin
actividad directa reciente en los tres días anteriores al corte. Gestionado es
convertido, descartado o potencial con actividad directa reciente.

## Exposición

ROT-1 no ofrece filtro funcional de Exposición porque Interest no dispone de
una dimensión canónica equivalente. No se infiere desde Fuente ni desde Lead.

## Auditoría y permisos

- JSON KPI: `/informes/leads/data/kpi-audit`;
- CSV KPI: `/informes/leads/export/kpi-audit.csv`;
- CSV conciliación: `/informes/leads/export/reconciliation-audit.csv`;
- inspección puntual: `/informes/leads/data/lead-audit?ids[]=...`, máximo 200
  IDs por compatibilidad de ruta.

La auditoría es Interest-centric y no expone nombre de cliente, teléfono,
móvil ni email. Conserva F2/F5, lifecycle, tipo, fuente, medio, canal,
procedencia, owner/comercial, actividad directa y motivos de inclusión o
exclusión. El scope autorizado se aplica antes de devolver filas. Los CSV se
emiten mediante cursor y chunks.

## Operación

El pipeline ROT-1 es:

```bash
php artisan salesforce:sync-interest-reporting
```

Antes del cutover productivo requiere un bootstrap F2 completo y un F5 alineado
con el mismo run/cutoff. Después, el comando ejecuta el flujo incremental F2 →
F5 con lock y está programado cada hora. El dashboard operativo exige F2/F5
alineados; la métrica ejecutiva técnica `leads`, que solo cuenta Interests
activos, depende únicamente de un F2 estable.

## Referencia legacy — consumidores todavía no rotados

Campañas y otros consumidores expresamente fuera de ROT-1 continúan usando
`SalesforceLeadDashboardDatasetService`, `salesforce_leads`,
`salesforce_activities` y `salesforce_lead_activity_summaries` bajo sus reglas
Lead históricas. Esa semántica no aplica al informe `/informes/leads`, a Monthly
Commercial ni a la métrica ejecutiva rotada. ROT-1 no modifica Campañas,
Llamadas ni Reservas/Ventas.

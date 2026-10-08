# Informe de Leads

> Desde ROT-1 la ruta y el permiso conservan el nombre histórico `leads`, pero
> el hecho funcional del informe es `salesforce_interests`.

Solo se incluyen Interests activos y el período usa `functional_created_at`.
Estado, tipo, fuente, medio, canal, procedencia y owner proceden del Interest sin
fallback Lead. Solo cuentan actividades FOUNDATION-5 `resolved` y no eliminadas
de un snapshot alineado con F2. Task usa `ActivityDate`; Event usa
`StartDateTime`. Las claves KPI históricas continúan por compatibilidad y
Campañas conserva el universo Lead. La auditoría no recupera PII desde Lead,
Account o Contact.

Actualizado: 2026-10-08.

## Fuente, persistencia y período

- Fuente: Salesforce `Lead`, `Task`, `Event` y `User`.
- Tablas principales: `salesforce_leads`, `salesforce_activities`,
  `salesforce_lead_activity_summaries`, `salesforce_users` y
  `report_sync_runs`.
- El período del dashboard se basa en `Lead.CreatedDate`.
- El dashboard consulta una fotografía local; no consulta Salesforce durante el
  render.
- El corte publicado procede de la última sincronización completada. Se muestran
  sincronización de Leads y actividades, generación, rango, zona horaria y corte
  del dataset.

`salesforce:sync-monthly-commercial` acepta ventana móvil con `--days` o rango
explícito `--from/--to`; `--to` es exclusivo. La consulta incremental incluye
registros creados en el rango o modificados mediante `LastModifiedDate`, por lo
que actualiza también Leads antiguos cuyo estado, propietario, tipo o portal
haya cambiado.

## Tipo de Lead

`LeadRecordTypeNormalizer` es el mapping único utilizado en sincronización,
dataset, filtros y exportaciones. Aplica `trim`, minúsculas, eliminación de
tildes, compactación de espacios y aliases controlados.

| Valor funcional | Clave canónica |
|---|---|
| Tasación | `tasacion` |
| Venta | `venta` |
| Venta con cambio | `venta_con_cambio` |
| Lead | `venta` |
| Ayvens | `venta` |

El filtro funcional `Venta` incluye Venta, Venta con cambio, Lead y Ayvens para
todo el histórico. Durante el despliegue también reconoce temporalmente las
claves materializadas heredadas `lead` y `ayvens`, hasta ejecutar el reproceso
local idempotente. No existe fecha de transición.

## Canal y portal

El canal se resuelve con `Medio_Nuevo__c`:

- valor normalizado `Llamada` → `Llamada`;
- cualquier otro valor → `Formulario`.

Prioridad del portal:

| Canal | Prioridad |
|---|---|
| Llamada | `Fuente_Nuevo__c` → `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Sin clasificar` |
| Formulario | `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c` → `Sin clasificar` |

Se conservan el portal final, el campo que lo resolvió y los valores brutos. Por
esta regla, `Coches.net Coche Nuevo` puede proceder de `Fuente_Nuevo__c` en una
llamada aunque `Portal_Text__c` sea `Coches.net`.

## Estado, comercial efectivo y delegaciones

Estados del dashboard:

- `Convertido` → convertido;
- `Descartado` → descartado;
- `Potencial` → potencial;
- cualquier valor no reconocido permanece auditable y no se fuerza a otro
  estado.

Prioridad del comercial efectivo:

1. Convertido: persona que trabajó el Lead; fallback propietario actual.
2. Descartado: propietario al descartarse; después persona que lo trabajó;
   finalmente propietario actual.
3. Resto: propietario actual.

Un comercial es elegible solo si el usuario está activo y su perfil Salesforce
es `Compra/Venta` o `Comerciales Partner Community`. La ausencia de delegación
no lo convierte en no elegible.

Se distinguen dos ejes:

- Delegación del Lead: campos de delegación encargada del propio Lead.
- Delegación comercial: delegación del usuario comercial efectivo.

Los KPIs de calidad son independientes:

- `Sin comercial elegible`;
- `Sin delegación comercial`;
- `Sin clasificar` para la delegación del Lead.

Los registros válidos de estas categorías siguen sumando en el KPI general.

La delegación efectiva del Lead prioriza, en este orden,
`Delegacion_Encargada_Bueno__c` y `Delegacion_Encargada__c`. El API Name del
tercer campo funcional “Delegación” no ha podido verificarse en el repositorio:
no se infiere un API Name nuevo ni se atribuye a `Delegacion_Encargada_Text__c`
la condición de campo funcional aprobado. El fallback histórico ya persistido
en ese campo se conserva después de los dos campos confirmados para no degradar
datos existentes. Si todos están vacíos, el Lead queda `Sin clasificar`, salvo
Exposición, que puede usar la delegación disponible del owner/persona que lo
trabajó. Ese fallback no se aplica a Venta, Tasación, Branding ni Otros.

## Actividad y KPIs

- Potencial sin trabajar: potencial no asignado técnicamente y sin actividad o
  sin actividad en los tres días anteriores al corte.
- Sin asignar: potencial cuyo propietario es una identidad técnica configurada.
- Gestionado: convertido, descartado o potencial con actividad reciente.
- Exposición se determina por el portal final `Exposición`.
- El modo `Sin Exposición` excluye esos registros; el modo normal los incluye.

Al cambiar filtros se cancela la petición anterior, se vacían los resultados
obsoletos y solo se pinta la respuesta que corresponde al estado actual.

## Eliminados y fusionados

La sincronización usa consultas que permiten detectar eliminados y fusiones:

- `is_deleted = true` excluye el Lead de todos los KPIs activos;
- `MasterRecordId` conserva la relación con el registro maestro de una fusión;
- la reconciliación de ausentes cubre hard deletes;
- el ID antiguo no se borra de la auditoría;
- se conservan fuente y fecha de detección de la eliminación.

## Auditoría y permisos

- JSON KPI: `/informes/leads/data/kpi-audit`.
- CSV KPI: `/informes/leads/export/kpi-audit.csv`.
- CSV conciliación: `/informes/leads/export/reconciliation-audit.csv`.
- Inspección puntual: `/informes/leads/data/lead-audit?ids[]=...`, máximo 200
  IDs.

La exportación incluye Lead ID, fechas, corte, estado, propietarios, comercial
efectivo, elegibilidad, delegaciones, portal bruto/final, tipo bruto/normalizado,
eliminación/fusión y motivos de inclusión o exclusión. Los endpoints aplican el
mismo período, filtros y ámbito del usuario que la pantalla.

## Operación

```bash
php artisan salesforce:sync-monthly-commercial --days=2
php artisan salesforce:sync-monthly-commercial --from=2026-07-01 --to=2026-08-01
php artisan salesforce:backfill-lead-audit-metadata --dry-run
php artisan reports:reprocess-lead-record-types --dry-run
```

`reports:reprocess-lead-record-types` actualiza únicamente
`salesforce_leads.record_type_normalized`, trabaja por lotes y es idempotente.
Su dry-run informa examinados, cambios, conversiones Lead/Ayvens a Venta,
período y dependencias derivadas. No reconstruye Campañas automáticamente;
`campaign_salesforce_leads` y sus atribuciones deben planificarse aparte.

El backfill local queda marcado como `legacy_local_backfill`: completa campos
técnicos, pero no demuestra un corte real de Salesforce. Para una conciliación
formal debe hacerse una sincronización con rango explícito.

Archivos principales:

- `app/Services/Reports/Leads/LeadRecordTypeNormalizer.php`;
- `app/Services/Reports/Leads/LeadPortalResolver.php`;
- `app/Services/Reports/Leads/LeadDelegationNormalizer.php`;
- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`;
- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyLeadsSyncService.php`.

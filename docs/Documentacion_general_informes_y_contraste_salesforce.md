# Documentación general de informes y contraste con Salesforce

Versión: 2026-09-22
Proyecto: `informes-app-hrmotor`

## 1. Propósito y criterio de verdad

Este documento describe el funcionamiento vigente de los informes:

- Leads
- Reservas / Ventas
- Llamadas
- Campañas
- Comisiones comerciales
- Stock

La fuente de verdad de esta versión es el código actual del proyecto, incluidas las
migraciones y pruebas presentes en el árbol de trabajo. Los documentos históricos se
han usado como contexto, pero no prevalecen sobre la implementación.

Para cualquier contraste hay que fijar antes:

1. zona horaria y límites del período;
2. objeto y fecha pivote;
3. filtros funcionales;
4. nivel de deduplicación;
5. si el KPI es del período o una foto global;
6. si la cifra procede directamente de Salesforce o de una transformación local.

Las consultas de este documento son plantillas SOQL. Sustituir los valores
`2026-06-01` y `2026-07-01` por el intervalo que se quiera auditar. Los límites
superiores son exclusivos.

## 2. Arquitectura común

### 2.1 Fuentes externas

- Salesforce REST API:
  - `Lead`
  - `Task`
  - `Event`
  - `User`
  - `Opportunity`
  - `Product2`
  - `Resena__c`
  - `Tasacion__c`
  - `Logistica__c`
- Meta Marketing API.
- Google Ads API.
- endpoint interno de reseñas de delegaciones.
- CSV/XLSX de capacidades de stock.
- XLSX de penalizaciones por cancelación de financiación.

### 2.2 Persistencia local

La aplicación no consulta Salesforce al renderizar cada pantalla. Primero sincroniza
objetos a tablas locales y los dashboards agregan esas tablas. Esto permite cache,
normalización, auditoría y conservación histórica.

Principales tablas por dominio:

| Dominio | Tablas locales principales |
|---|---|
| Leads | `salesforce_leads`, `salesforce_activities`, `salesforce_lead_activity_summaries`, `salesforce_users` |
| Reservas / Ventas | `salesforce_opportunities`, `salesforce_opportunity_stage_transitions`, `commercial_delegation_snapshots`, `commercial_performance_monthly_targets`, `leads_raw` como fallback de portal |
| Llamadas | `salesforce_calls`, `salesforce_call_classification_history`, `salesforce_users`, `call_agent_mappings` |
| Campañas | `campaign_platform_daily_metrics`, `campaign_platform_identifiers`, `campaign_lead_attributions`, `campaign_unresolved_attributions`, `campaign_operational_classifications`, `campaign_salesforce_leads`, `salesforce_opportunities` |
| Comisiones | `salesforce_opportunities`, `salesforce_users`, `salesforce_reviews`, `salesforce_tasaciones`, `commercial_commission_month_settings`, cierres, snapshots, ajustes y tablas de penalizaciones |
| Stock | `salesforce_vehicles`, `stock_catalog_values`, `stock_catalog_aliases`, `stock_delegations`, `stock_daily_snapshots`, `salesforce_sale_snapshots`, `salesforce_logistics`, `stock_availability_alerts` |

### 2.3 Fechas pivote

| Informe / bloque | Fecha pivote |
|---|---|
| Leads | `Lead.CreatedDate` |
| Reservas / Ventas | legacy: criterio seleccionable de cohorte; Rendimiento comercial: fecha propia por hito y mes natural |
| Llamadas | `Task.CreatedDate` |
| Campañas | creación del lead para resultados; fecha publicitaria para inversión |
| Comisiones comerciales | `Opportunity.Fecha_firma_contrato__c` y mes cerrado seleccionado |
| Stock actual | foto vigente de `Product2` |
| Ventas de Stock | `Opportunity.Fecha_firma_contrato__c` congelada en snapshot |
| Histórico de Stock | `stock_daily_snapshots.snapshot_date` |

### 2.4 Estado funcional implantado

| Informe | Regla vigente que no debe volver a tratarse como pendiente |
|---|---|
| Leads | Venta = Venta + Venta con cambio; Lead/Ayvens fuera. Calidad comercial y no clasificados siguen dentro del total. Eliminados y fusionados no suman en activos. |
| Reservas / Ventas | Las pestañas legacy conservan cohorte única. Rendimiento comercial usa actividad mensual por hito, objetivo histórico y cancelación solo desde OpportunityHistory. |
| Llamadas | Solo `CallObject`; `ABANDONED` es perdida y nunca desborde; histórico versionado y reproceso manual. |
| Campañas | First touch único; Salesforce-only separado de pago; pruebas excluidas solo por clasificación explícita por ID. |
| Comisiones | Mes único, actual provisional, cierre persistente con snapshot, reapertura y libro de ajustes. |
| Stock | Todo Disponible se evalúa; 60/90 son prioridad; Top 3 teórico y plan conjunto sin sobreasignar plazas. |

Las decisiones todavía abiertas están exclusivamente en
`docs/decisiones-negocio-pendientes.md`.

## 3. Leads

### 3.1 Objetos y campos consultados

`Lead`:

- identidad y fechas: `Id`, `Name`, `CreatedDate`, `LastActivityDate`,
  `Fecha_Asignacion__c`;
- estado y tipo: `Status`, `RecordType.Name`;
- responsables: `OwnerId`, `Owner.Name`, `Persona_que_trabaj__c`,
  `Persona_que_trabaj__r.Name`, `Propietario_cuando_se_descarto__c`,
  `Propietario_cuando_se_descarto__r.Name`;
- procedencia: `LEA_SEL_Fuente_Origen__c`, `LEA_SEL_Medio_Origen__c`,
  `Medio_Nuevo__c`, `Fuente_Nuevo__c`, `Portal_Text__c`,
  `Remitente_Lead__c`;
- delegación: `Delegacion_Encargada_Text__c`,
  `Delegacion_Encargada__c`, `Delegacion_Encargada_Bueno__c`;
- campaña y vehículo: `Campa_a_Adquirida__c`, `Id_Adquirido__c`,
  `Contenido_Adquirido__c`, `LEA_BUS_Vehiculo_de_interes__c`;
- contacto y conversión: `Phone`, `MobilePhone`, `Email`, `IsConverted`,
  `ConvertedDate`, `ConvertedAccountId`, `ConvertedContactId`,
  `ConvertedOpportunityId`;
- Contact Center: `Captador_de_cita__c`, `Captador_de_cita__r.Name`,
  `Fecha_captador__c`, `Cita_llamada__c`, `Cita_Tienda__c`,
  `Acudi_a_la_cita__c`, `Comercial_que_atiende_en_tienda__c`,
  `Comercial_que_atiende_en_tienda__r.Name`,
  `Estado_del_candidato_formula__c`.

`Task` y `Event` aportan la actividad del lead. `User` aporta perfil, delegación,
estado, email y marca de tasador.

### 3.2 Query base de Leads

```sql
SELECT
    Id, Name, CreatedDate, LastActivityDate, Status, RecordType.Name,
    OwnerId, Owner.Name,
    Persona_que_trabaj__c, Persona_que_trabaj__r.Name,
    Propietario_cuando_se_descarto__c,
    Propietario_cuando_se_descarto__r.Name,
    Fecha_Asignacion__c,
    LEA_SEL_Fuente_Origen__c, LEA_SEL_Medio_Origen__c,
    Medio_Nuevo__c, Fuente_Nuevo__c, Portal_Text__c,
    Remitente_Lead__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c,
    Campa_a_Adquirida__c, Id_Adquirido__c, Contenido_Adquirido__c,
    LEA_BUS_Vehiculo_de_interes__c,
    Phone, MobilePhone, Email,
    IsConverted, ConvertedDate, ConvertedAccountId,
    ConvertedContactId, ConvertedOpportunityId,
    Captador_de_cita__c, Captador_de_cita__r.Name,
    Fecha_captador__c, Cita_llamada__c, Cita_Tienda__c,
    Acudi_a_la_cita__c,
    Comercial_que_atiende_en_tienda__c,
    Comercial_que_atiende_en_tienda__r.Name,
    Estado_del_candidato_formula__c
FROM Lead
WHERE IsDeleted = false
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate, Id
```

El sincronizador ampliado usa esta condición para no perder citas del Contact
Center creadas sobre leads antiguos:

```sql
AND (
    (CreatedDate >= 2026-06-01T00:00:00Z
     AND CreatedDate < 2026-07-01T00:00:00Z)
    OR
    (Fecha_captador__c >= 2026-06-01
     AND Fecha_captador__c < 2026-07-01)
)
```

Esta ampliación solo afecta a la extracción. El dashboard de Leads sigue filtrando
por `salesforce_leads.created_date`, es decir, por `Lead.CreatedDate`.

### 3.3 Queries de actividad

```sql
SELECT
    Id, WhoId, OwnerId, Owner.Name, CreatedById, CreatedBy.Name,
    CreatedDate, ActivityDate, Subject, Type, Status
FROM Task
WHERE WhoId IN (
    SELECT Id FROM Lead
    WHERE IsDeleted = false
      AND CreatedDate >= 2026-06-01T00:00:00Z
      AND CreatedDate < 2026-07-01T00:00:00Z
)
AND CreatedDate >= 2026-06-01T00:00:00Z
AND CreatedDate < 2026-07-01T00:00:00Z
```

```sql
SELECT
    Id, WhoId, OwnerId, Owner.Name, CreatedById, CreatedBy.Name,
    CreatedDate, ActivityDate, Subject, Type
FROM Event
WHERE WhoId IN (
    SELECT Id FROM Lead
    WHERE IsDeleted = false
      AND CreatedDate >= 2026-06-01T00:00:00Z
      AND CreatedDate < 2026-07-01T00:00:00Z
)
AND CreatedDate >= 2026-06-01T00:00:00Z
AND CreatedDate < 2026-07-01T00:00:00Z
```

### 3.4 Lógicas y cálculos

- Canal:
  - `Medio_Nuevo__c = Llamada` normalizado → `Llamada`;
  - cualquier otro valor → `Formulario`.
- Portal:
  - llamada: `Fuente_Nuevo__c` → `Portal_Text__c` →
    `LEA_SEL_Fuente_Origen__c` → `Sin clasificar`;
  - formulario: `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` →
    `Fuente_Nuevo__c` → `Sin clasificar`.
- Delegación del lead:
  `Delegacion_Encargada_Text__c` → `Delegacion_Encargada__c` →
  `Delegacion_Encargada_Bueno__c`; después se aplica el catálogo de alias de
  `LeadDelegationNormalizer`.
- Comercial responsable:
  - convertido: persona que trabajó → owner;
  - descartado: propietario al descarte → persona que trabajó → owner;
  - resto: owner.
- Solo se consideran comerciales activos con perfiles `Compra/Venta` o
  `Comerciales Partner Community`.
- `Convertido`: `Status = Convertido`.
- `Descartado`: `Status = Descartado`.
- `Potencial`: `Status = Potencial`.
- `Lead sin asignar`: potencial cuyo owner es una identidad técnica configurada.
- `Potencial sin trabajar`: potencial, no sin asignar, y sin actividad o sin
  actividad en los tres días anteriores al fin del período.
- `Gestionado`: convertido, descartado o potencial con actividad en esos tres días.
- Los no clasificados sí se incluyen en los KPIs generales.
- `Sin comercial elegible`, `Sin delegación comercial` y `Sin clasificar` son
  KPIs independientes de calidad y no retiran registros válidos del total.
- Porcentajes generales: `métrica / leads_totales * 100`.
- En filtro `Venta` se incluyen únicamente tipos `Venta` y `Venta con cambio`;
  `Lead` y `Ayvens` quedan fuera por decisión funcional cerrada.
- Eliminados y fusionados no suman en KPIs activos. Se conservan `Lead.Id`,
  `MasterRecordId`, fecha y fuente de detección en la conciliación.
- Exposición:
  - `Con`: todos;
  - `Sin`: excluye portal `Exposición`;
  - la propiedad `is_exposicion` se obtiene del portal resuelto.

### 3.5 Filtros, salidas y auditoría

Filtros: período, fechas personalizadas, portal, delegación del lead, tipo de lead,
delegación comercial, zona, comercial y modo de exposición.

Endpoints:

- `/informes/leads/data/resumen`
- `/informes/leads/data/kpis`
- `/informes/leads/data/portales`
- `/informes/leads/data/delegaciones`
- `/informes/leads/data/comerciales`
- `/informes/leads/data/comparativa`
- `/informes/leads/data/calidad-dato`
- `/informes/leads/data/kpi-audit`
- `/informes/leads/export/kpi-audit.csv`
- `/informes/leads/export/reconciliation-audit.csv`
- `/informes/leads/data/lead-audit?ids[]=...`

Comando de sincronización:

```bash
php artisan salesforce:sync-monthly-commercial --days=120 --debug-soql
```

Código fuente principal:

- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyLeadsSyncService.php`
- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyActivitiesSyncService.php`
- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`
- `app/Services/Reports/Leads/LeadDelegationNormalizer.php`

## 4. Reservas / Ventas

### 4.1 Objetos y fuentes

Objeto pivote: `Opportunity`. Se consultan también `Account`, `Owner`, `RecordType`
y relaciones a `Product2`. Para resolver procedencia, el email se consulta con
la política vigente y el teléfono usa claves normalizadas indexadas en
`salesforce_leads` para descubrir IDs. Los candidatos telefónicos se reconsultan
por ID en Salesforce vivo antes de resolver. `leads_raw` permanece únicamente
como fallback acotado de email.

Campos funcionales principales:

- fechas: `CreatedDate`, `CloseDate`, `OPO_FEC_Fecha_de_reserva__c`,
  `Fecha_firma_contrato__c`;
- clasificación: `StageName`, `RecordType.Name`;
- flags: `OPO_CAS_Reserva__c`,
  `OPO_CAS_Contrato_CV_firmado__c`;
- responsable: `OwnerId`, `Owner.Name`, `Owner.IsActive`,
  `Owner.USR_SEL_Delegacion__c`;
- cliente: `AccountId`, `Account.Name`, `Account.Phone`,
  `Account.PersonEmail`, `Account.AC_C_EMA_email__c`;
- procedencia: `Opportunity.Portal__c`, `Opportunity.Fuente_de_Origen__c` y
  `Lead.Fuente_origen__c` con fallback legacy del Lead;
- importes: `Amount`, `OPO_FOR_Importe_total__c`,
  `OPO_FOR_Importe_vehiculo_venta__c`,
  `OPO_FOR_Importe_vehiculo_a_cambio__c`;
- campos de comisiones y vehículo descritos en la sección 7.

### 4.2 Query base de sincronización y contraste

```sql
SELECT
    Id, Name, CreatedDate, CloseDate, Amount,
    OPO_FOR_Importe_total__c,
    StageName, RecordType.Name,
    OwnerId, Owner.Name, Owner.IsActive, Owner.USR_SEL_Delegacion__c,
    AccountId, Account.Name, Account.Phone, Account.PersonEmail,
    Portal__c, Fuente_de_Origen__c,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Tienda_de_entrega__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c
FROM Opportunity
WHERE IsDeleted = false
  AND (
      (CreatedDate >= 2026-06-01T00:00:00Z
       AND CreatedDate < 2026-07-01T00:00:00Z)
      OR
      (OPO_FEC_Fecha_de_reserva__c >= 2026-06-01
       AND OPO_FEC_Fecha_de_reserva__c < 2026-07-01)
      OR
      (Fecha_firma_contrato__c >= 2026-06-01
       AND Fecha_firma_contrato__c < 2026-07-01)
  )
ORDER BY CreatedDate, Id
```

Query para CV firmados:

```sql
SELECT
    Id, Name, StageName, RecordType.Name, OwnerId, Owner.Name,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c, Tienda_de_entrega__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01
  AND Fecha_firma_contrato__c < 2026-07-01
  AND StageName != 'Cerrada Perdida'
  AND RecordType.Name IN ('Venta', 'Cambio', 'Tasacion')
ORDER BY Fecha_firma_contrato__c, Id
```

Query de reservas vivas actuales, sin fecha:

```sql
SELECT
    Id, Name, StageName, RecordType.Name,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Reserva__c = true
  AND OPO_CAS_Contrato_CV_firmado__c = false
  AND StageName != 'Cerrada Perdida'
ORDER BY OPO_FEC_Fecha_de_reserva__c, Id
```

### 4.3 Lógicas y cálculos

- Criterio temporal seleccionable:
  - `created_date` → `Opportunity.CreatedDate`;
  - `reservation_date` → `OPO_FEC_Fecha_de_reserva__c`;
  - `cv_signed_date` → `Fecha_firma_contrato__c`.
- El criterio seleccionado define una única cohorte. Los eventos posteriores de
  esas Opportunities se miden sobre la misma cohorte. La única excepción
  aditiva es `Reservas totales del período`, que representa eventos producidos
  en cada rango y siempre usa `reservation_date`, sin cambiar el criterio
  temporal de los KPI existentes.
- Tipo `Venta` del dashboard = `RecordType.Name IN (Venta, Cambio)`.
- Tipo `Tasación` acepta el valor local `Tasacion`.
- `Oportunidades totales`: todas las filas del tipo y período seleccionados.
- `Reservas vivas del universo seleccionado`: reserva true, CV false y etapa
  distinta de `Cerrada Perdida`, dentro del criterio temporal seleccionado.
- `Reservas vivas actuales (todas las fechas)`: la misma regla de estado sin
  filtro temporal. Los filtros de tipo siguen aplicando; los filtros de
  comercial/delegación/zona se aplican después de decorar la fila.
- `Reservas totales del período`: reserva true y fecha de reserva dentro del
  rango, con independencia de que su estado actual sea vivo, `Cerrada Perdida`
  o CV firmado. Excluye reservas sin fecha, usa la deduplicación común por
  vehículo + `reservation_date` con fallback a Opportunity y se calcula por
  separado para el período actual y el comparado.
- `Caída`: etapa `Cerrada Perdida`.
- `CV firmado`: flag true y etapa distinta de `Cerrada Perdida`.
- Porcentajes del resumen: KPI / oportunidades totales.
- En tablas por comercial, delegación o portal se separan conversión de fila
  (`métrica / oportunidades de la fila`) y participación de columna
  (`métrica de la fila / total de la métrica`).
- Una reserva o firma repetida para el mismo vehículo y fecha cuenta una vez.
  La identidad usa Product2 y fallback de matrícula. Los conflictos de owner,
  tienda, delegación, zona o portal se muestran como `Incidencia de datos` y no
  se adjudican arbitrariamente en los desgloses.
- Resumen Dirección muestra las tres magnitudes con etiquetas diferenciadas y
  Comparativa básica añade `Reservas totales del período` como valor absoluto,
  sin porcentaje ficticio. Su auditoría JSON/CSV usa `reservation_date` y la
  misma deduplicación del KPI.
- Delegación y zona salen del owner y se normalizan con el mismo catálogo de Leads.
- Resolución de portal, por prioridad:
  1. `Opportunity.Portal__c` si es concluyente;
  2. Lead relacionado por email/teléfono: `Fuente_origen__c` si está informado;
     si falta, `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` →
     `Fuente_Nuevo__c`, manteniendo la validación legacy;
  3. `Opportunity.Fuente_de_Origen__c` si es útil;
  4. fallback `Exposición`;
  5. fallback `Web`;
  6. `Sin clasificar`.

Un valor nuevo no vacío es autoritativo aunque no se normalice a un portal
oficial: en ese caso el resultado es `Sin clasificar` con fuente `lead` y no se
continúa por los fallbacks posteriores. La consulta auxiliar mantiene
`CreatedDate DESC` y desempata por `Lead.Id` ascendente. La fotografía local
nunca aporta procedencia: solo IDs de candidatos telefónicos activos.

### 4.4 Auditoría

- `/informes/reservas-ventas/data/kpi-audit`
- `/informes/reservas-ventas/export/kpi-audit.csv`
- `php artisan reports:debug-reservas-ventas --unclassified-portals`
- `php artisan reports:reprocess-opportunity-portals`

La auditoría conserva todas las Opportunities de un grupo duplicado e indica
grupo, tamaño, fila contabilizada, IDs afectados, campos contradictorios y
estado del desglose.

Sincronización:

```bash
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01 --debug-soql
```

Código fuente:

- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`
- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`
- `app/Services/Reports/ReservasVentas/OpportunityPortalNormalizer.php`

### 4.5 Rendimiento comercial mensual

`Rendimiento comercial` no cambia las reglas anteriores: constituye un dataset
local separado. Administrador dispone de lectura global, auditoría y edición del
objetivo mensual; Director dispone de lectura global, auditoría y objetivo de
solo lectura; Area Manager dispone de lectura exclusivamente de su zona y
objetivo de solo lectura, sin auditoría. El scope de Area Manager se fuerza en
servidor, los parámetros HTTP no pueden ampliarlo y la ausencia de una zona
configurada devuelve 403. Delegation Manager, Viewer, Commercial, Marketing,
Financial, Stock Only, Commission Auditor y los demás roles no obtienen acceso.

El dataset usa Lead por `Fecha_Asignacion__c`, Opportunity por `CreatedDate`,
reserva por fecha de reserva, venta por fecha de firma y cancelación por la
transición persistida de `OpportunityHistory`. Los ratios son de actividad,
admiten más del 100 % y devuelven N/A con denominador cero. El objetivo es único
por mes y se aplica individualmente a todos los comerciales evaluables; solo
Administrador puede modificarlo.

La investigación Salesforce de solo lectura verificó que
`Delegacion_del_propietario__c` es una fórmula de la delegación actual. Al no
existir `UserFieldHistory` ni tracking útil del campo, la aplicación conserva
intervalos observados desde la implantación y no reconstruye meses previos. La
fila mensual se agrega siempre por Salesforce User ID; huecos o cambios internos
invalidan solo delegación/media/ranking, nunca duplican persona u objetivo. Los
comerciales con cobertura mensual estable se inicializan aunque tengan cero.

`OpportunityFieldHistory` no contenía filas. `OpportunityHistory` sí ofreció
secuencias de Stage y timestamps; se persisten pasos desde una etapa previa
distinta hacia `Cerrada Perdida` con estado de calidad. Solo cuentan si
`reservation_date <= transitioned_at`. Los intervalos de consulta completados
se materializan; el mes actual llega solo al cutoff diario certificado y los
meses cerrados exigen cobertura completa. Un hueco o una Opportunity candidata
ausente localmente deja el intervalo no certificable y devuelve cancelaciones
N/A, con la incidencia persistida para auditoría.
No se usa `CloseDate` ni `LastModifiedDate` como fecha funcional. Este último
solo descubre modificaciones antiguas en el proceso incremental diario.

Endpoints:

- `GET /informes/reservas-ventas/data/commercial-performance`
- `GET /informes/reservas-ventas/data/commercial-performance/audit`
- `PUT /informes/reservas-ventas/data/commercial-performance/target`

Operación:

```bash
php artisan salesforce:sync-opportunities --days=2 --modified
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
```

El segundo comando es solo un ejemplo de lote inicial acotado. Antes se revisa
`migrate:status`, se aprueba el conjunto completo de migraciones pendientes y se
miden filas, duración y llamadas; después se amplían rangos contiguos. La
retención de Salesforce limita cuánto historial anterior puede certificarse.
El rango debe comenzar antes del mes de cancelación para materializar reservas
anteriores; se revisan dependencias `opportunity_not_local` y se repite cualquier
intervalo afectado antes de certificarlo.

## 5. Llamadas

### 5.1 Objetos y campos

Objeto pivote: `Task`:

- `Id`, `Subject`, `Description`, `Type`, `Status`, `Priority`;
- `ActivityDate`, `CreatedDate`, `LastModifiedDate`;
- `OwnerId`, `Owner.Name`, `Owner.Profile.Name`;
- `WhoId`, `WhatId`, `CallObject`;
- `CallDurationInSeconds`, `CallType`, `Portales__c`.

También se consulta `User` para perfil/delegación y `Lead` cuando `WhoId` empieza
por `00Q`, para recuperar portal.

### 5.2 Query base

```sql
SELECT
    Id, Subject, Description, Type, Status, Priority,
    ActivityDate, CreatedDate, LastModifiedDate,
    OwnerId, Owner.Name, Owner.Profile.Name,
    WhoId, WhatId, CallObject,
    CallDurationInSeconds, CallType, Portales__c
FROM Task
WHERE IsDeleted = false
  AND Type = 'Call'
  AND CallObject != null
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate DESC
```

Query auxiliar de leads:

```sql
SELECT
    Id, Name, CreatedDate, Portal_Text__c,
    LEA_SEL_Fuente_Origen__c, Fuente_Nuevo__c, Medio_Nuevo__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE Id IN ('00Q...')
```

### 5.3 Parsing, clasificación y KPIs

De `Task.Description` se extraen: resultado, tipo, teléfonos, comercial destino,
código/nombre de agente, cola, opción de teclado, duración, UID/PUID e inicio/fin.

- Atendida: `Resultado = ANSWERED`; también se infiere atendida si existe
  “Respondido por” válido, salvo una regla más fuerte de no atención.
- No atendida: cualquier otra clasificación.
- `ABANDONED` siempre se trata como perdida y nunca como desborde.
- Dirección:
  - contiene `inbound` o `entrante` → entrante;
  - contiene `outbound` o `saliente` → saliente;
  - resto → desconocida.
- Duración base: `CallDurationInSeconds`; fallback a duración parseada.
- Duración ajustada:
  - llamada directa comercial: `max(duración - 5, 0)`;
  - portal: `max(duración - 10, 0)`.
- Origen efectivo:
  - `Portales__c` nulo, “Llamada directa” o centralita → `commercial_direct`;
  - resto → `portal`.
- Desborde:
  - origen portal;
  - atendida;
  - equipo `Contact Center` o `Atención al Cliente`;
  - portal distinto de Web/Google Maps, o en esos portales opción de teclado
    nula, vacía, 1 o 2.
- Equipos: comercial, atención al cliente, contact center y tasadores. Hay alias
  forzados y se excluyen identidades de sistema/administrador.
- Las llamadas operativas sin equipo se muestran como `Sin equipo` y forman
  parte de la reconciliación del total.
- `Total llamadas`: `COUNT(*)`.
- `Atendidas` y `No atendidas`: reglas anteriores, excluyendo `ABANDONED` de
  atendidas.
- `Tiempo medio conversación`: promedio de `adjusted_duration_seconds` solo en
  atendidas.
- `Ratio atención`: atendidas / total.
- `Ratio desborde`: desbordes / denominador elegible de portal atendido.

Filtros: período, dirección, estado, origen, portal, equipo, delegación, zona y
usuario operativo.

La clasificación conserva versión, fecha, valores brutos e historial. Un cambio
del parser no reprocesa históricos automáticamente. Auditoría y operación:

```bash
php artisan salesforce:sync-calls --days=120 --debug-soql
php artisan reports:debug-calls
php artisan reports:reprocess-calls-classification --from=2026-06-01 --to=2026-06-30 --dry-run
php artisan reports:reprocess-calls-classification --from=2026-06-01 --to=2026-06-30 --reason="Motivo aprobado"
```

- CSV: `/informes/llamadas/export/audit.csv`.
- Perfil no excluido automáticamente:
  `php artisan reports:audit-calls-profile --profile="Pruebas comunidad comercial" --from=2026-06-01 --to=2026-06-30`.

Código fuente:

- `app/Services/Reports/Calls/SalesforceCallSyncService.php`
- `app/Services/Reports/Calls/CallDescriptionParser.php`
- `app/Services/Reports/Calls/CallClassificationRules.php`
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`

## 6. Campañas

### 6.1 Fuentes

- Salesforce `Lead` y `Opportunity`.
- `campaign_platform_daily_metrics`:
  - Google Ads: coste, impresiones, clics, conversiones y estado;
  - Meta Ads: gasto, impresiones, clics, leads y estado efectivo.
- `campaign_lead_attributions`: resultado local del cruce.
- `campaign_type_mappings`: overrides editables de clasificación.
- `campaign_platform_identifiers`: inventario de IDs publicitarios.
- `campaign_operational_classifications`: clasificación persistente `real`,
  `test` o `pending_review` por plataforma/cuenta/ID.
- `campaign_unresolved_attributions`: candidatos ambiguos no adjudicados.

La inversión se filtra por `metric_date`. Leads y resultados se filtran por
`Lead.CreatedDate`; reservas, ventas y compras pueden ocurrir después.
La opción legacy `--window` no tiene efecto.

### 6.2 Query de leads de campaña

```sql
SELECT
    Id, CreatedDate, Name, Status, OwnerId, Owner.Name,
    Phone, MobilePhone, Email,
    IsConverted, ConvertedDate, ConvertedAccountId,
    ConvertedContactId, ConvertedOpportunityId,
    LEA_SEL_Fuente_Origen__c, LEA_SEL_Medio_Origen__c,
    Campa_a_Adquirida__c, Id_Adquirido__c,
    Contenido_Adquirido__c, LEA_BUS_Vehiculo_de_interes__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE IsDeleted = false
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND (
      Campa_a_Adquirida__c != null
      OR Id_Adquirido__c != null
      OR Contenido_Adquirido__c != null
      OR LEA_SEL_Fuente_Origen__c != null
      OR LEA_SEL_Medio_Origen__c != null
  )
ORDER BY CreatedDate, Id
```

### 6.3 Query de oportunidades atribuibles

Para un contraste reproducible se recomienda exportar primero
`ConvertedOpportunityId` y `ConvertedAccountId` de la query anterior y consultar:

```sql
SELECT
    Id, Name, CreatedDate, StageName, RecordType.Name,
    AccountId, Account.Phone, Account.PersonEmail,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c
FROM Opportunity
WHERE Id IN ('006...')
   OR AccountId IN ('001...')
ORDER BY CreatedDate, Id
```

El resultado final no se reproduce contando todas las oportunidades del mes:
solo entran las enlazadas a los leads del período.

### 6.4 Atribución

Precedencia first touch:

1. relación explícita del Lead convertido con Opportunity;
2. identificadores publicitarios inequívocos: anuncio, adset/ad group y campaña;
3. campaña original inequívoca del Lead;
4. primera campaña inequívoca conocida de la cuenta;
5. Salesforce-only;
6. ambiguo o sin atribuir.

Una Opportunity se reclama una sola vez. Varias campañas con la misma
precedencia sin señal concluyente generan una atribución no resuelta; no se
elige la primera ni se duplica. La traza conserva candidatos, método, confianza,
primer contacto, IDs, ambigüedad y versión.

Tipos de campaña:

   - tasación por mapping o nombre de tasación;
   - venta por nombre;
   - exposición por visita a tienda/PMax;
   - branding por YouTube, vídeo, shorts o display;
   - otros para catálogo/instant forms y resto;
   - exposición, branding y otros son subcategorías de `Venta`.

El tipo de campaña y el RecordType real del Lead son ejes de filtro separados.
Una campaña solo queda fuera de KPIs ejecutivos por ser prueba cuando existe una
clasificación persistente `test`; el nombre por sí solo no excluye.

### 6.5 Resultados y fórmulas

- `Leads Salesforce`: leads distintos.
- `Oportunidades`: oportunidades distintas con match.
- `Reservas`: flag reserva.
- `Venta`:
  - CV firmado;
  - fecha de firma informada;
  - no cerrada perdida;
  - tipo Venta; Cambio cuenta como venta solo si tiene importe positivo.
- `Compra`: contrato firmado válido y tipo Tasación.
- Una campaña fuente de tasación no recibe ventas; una fuente de venta no recibe
  compras.
- Importe vendido: primer valor positivo de
  `OPO_FOR_Importe_total__c`; `Amount` y otros campos compatibles son fallback.
- Importe de compra: valor absoluto de `OPO_FOR_Importe_total__c`.
- `CTR = clicks / impressions`.
- `CPC = spend / clicks`.
- `Coste por lead/oportunidad/reserva/venta/compra = spend / resultado`.
- `ROAS = sale_amount / spend`.
- `ROI estimado = (sale_amount - spend) / spend`.
- Ratios de funnel:
  lead→oportunidad, oportunidad→reserva, reserva→venta, lead→venta,
  lead→compra y oportunidad→compra.

La revisión ejecutiva prioriza: fallo de medición/cero Leads, mayor inversión sin
resultado, coste fuera de benchmark con muestra suficiente y caída del funnel.
Dentro de cada nivel se ordena por inversión. Salesforce-only cuenta en la
actividad Salesforce, pero no en rendimiento de pago sin inversión asociada.

### 6.6 Sincronización y auditoría

```bash
php artisan salesforce:sync-campaign-leads --from=2026-06-01 --to=2026-07-01 --fresh --debug-soql
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01
php artisan campaigns:sync-meta --from=2026-06-01 --to=2026-07-01
php artisan campaigns:sync-google --from=2026-06-01 --to=2026-07-01
php artisan campaigns:build-attribution --from=2026-06-01 --to=2026-07-01
php artisan reports:refresh-campaigns --from=2026-06-01 --store
```

Auditoría:

- `/informes/campanas/data/kpi-audit`
- `/informes/campanas/export/kpi-audit.csv`
- `/informes/campanas/export/campaigns.csv`
- `/informes/campanas/export/attributions.csv`
- `php artisan campaigns:debug-attribution`
- `php artisan campaigns:debug-google-spend`

Código fuente:

- `app/Services/Campaigns/CampaignLeadSyncService.php`
- `app/Services/Campaigns/CampaignAttributionBuilderService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/CampaignTypeResolver.php`
- `app/Services/Campaigns/CampaignPerformanceClassifier.php`

## 7. Comisiones comerciales

Esta sección resume las fuentes y universos. La especificación económica completa
está en `Calculo_comisiones_comerciales.txt`.

### 7.1 Objetos consultados

- `Opportunity`: operaciones, importes, productos, propietarios, tiendas,
  financiación y contratos.
- `User`: identidad, email, fecha de creación, perfil, delegación, activo y
  `Comision_Tasador__c`. `CreatedDate` se materializa en
  `salesforce_users.created_date` como dato fundacional; no participa todavía
  en un KPI, filtro, universo o cálculo económico.
- `Resena__c`: reseñas por oportunidad/comercial.
- `Tasacion__c`: negociaciones German.
- Endpoint interno de reseñas: bonus/penalización de delegaciones.
- XLSX: cancelaciones de financiación.

Campos de `Opportunity` usados, además de los de Reservas/Ventas:

- `Entrega_Compartida__c`, `Entrega_Compartida__r.Name`;
- `Tienda_de_entrega__c`, `Delegacion_del_propietario__c`;
- `Garant_a_Total__c`, `Beneficio_financiacion_comercial__c`,
  `Importe_financiado__c`, `Gestion_de_venta__c`,
  `OPO_DIV_Descuento__c`;
- `Comisi_n_Financiera__c`, `OPO_DIV_Descuento_financiera__c`,
  `Inter_s_elegido__c`, `zona_financiera__c`,
  `Tipo_de_registro_oportunidad__c`;
- `Captador__c`, `Comisi_n_Captador__c`,
  `Captador_de_cita__c`, `Fecha_captador__c`;
- relaciones del vehículo de interés: precios, matrícula, entrada, procedencia,
  comprador, Plan Auto Plus y CAE.

### 7.2 Query base de comerciales

```sql
SELECT
    Id, Name, StageName, RecordType.Name,
    OwnerId, Owner.Name, Owner.IsActive,
    Owner.USR_SEL_Delegacion__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Gestion_de_venta__c,
    Entrega_Compartida__c, Entrega_Compartida__r.Name,
    Tienda_de_entrega__c, Delegacion_del_propietario__c,
    OPO_DIV_Descuento__c, Garant_a_Total__c,
    Beneficio_financiacion_comercial__c, Importe_financiado__c,
    OPO_FOR_Importe_total__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01
  AND Fecha_firma_contrato__c < 2026-07-01
  AND StageName != 'Cerrada Perdida'
  AND RecordType.Name IN ('Venta', 'Cambio', 'Tasacion')
  AND Owner.IsActive = true
  AND (Gestion_de_venta__c = false OR Gestion_de_venta__c = null)
ORDER BY Owner.Name, Fecha_firma_contrato__c, Id
```

Usuarios:

```sql
SELECT
    Id, Name, Email, CreatedDate, Profile.Name, USR_SEL_Delegacion__c,
    Comision_Tasador__c, IsActive
FROM User
WHERE IsActive = true
  AND (
      Profile.Name = 'Comerciales Partner Community'
      OR Profile.Name = 'Compra/Venta'
      OR Comision_Tasador__c = true
  )
```

Reseñas:

```sql
SELECT
    Id, CreatedDate, OwnerId, Owner.Name,
    RES_BUS_Oportunidad__c, RES_BUS_Oportunidad__r.Name,
    RES_BUS_Oportunidad__r.OwnerId,
    RES_BUS_Oportunidad__r.Owner.Name,
    RES_BUS_Oportunidad__r.RecordType.Name,
    RES_BUS_Oportunidad__r.Fecha_firma_contrato__c
FROM Resena__c
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
```

### 7.3 Universos internos

`CommissionMonthResolver` mantiene el mismo mes en las seis pestañas, cabecera,
detalles y exportaciones. El mes actual se permite y es siempre Provisional.

- Comerciales:
  query base anterior; owner activo y gestión de venta excluida.
- Delegaciones, entregas:
  CV firmado, mes, no cerrada perdida, Venta/Cambio o nombre Facilitea; no
  filtra owner activo ni gestión de venta; asignación exclusivamente por
  `Tienda_de_entrega__c`.
- Delegaciones, rentabilidad total:
  fecha de firma en mes y etapa distinta de `Cerrada ganada` y
  `Cerrada perdida`; no exige CV ni limita Record Type; asignación por tienda.
- Ratios financieros de Delegaciones:
  conjunto de entregas, agrupado por
  `Delegacion_del_propietario__c`, con fallback al owner histórico/local.
- Area Managers:
  entregas Venta/Cambio y compras Tasación/Cambio con CV firmado, mes y no
  cerrada perdida; agrupa por delegación actual del owner. Facilitea con owner
  no operativo cae a tienda.
- Financieros:
  firma en mes, no cerrada perdida, tipo fórmula Venta/Cambio, zona financiera
  válida. El responsable se resuelve por una clave estable de zona, nunca por el
  OwnerId comercial. Irene agrega Alicante/Paterna y Nuria Sedaví/Castellón; su
  regla 0,50 % sustituye los tres bloques desde junio de 2026. La explicación
  completa de campos, SOQL, tramos y front está en
  `docs/informe-comisiones-financieras.md`.
  El detalle usa primero `Owner.USR_SEL_Delegacion__c` y después
  `Delegacion_del_propietario__c` como fallback. Una zona explícita desconocida
  con comisión/descuento bloquea la integridad y la exportación; nunca se asigna
  por aproximación.
- Call Center:
  oportunidades firmadas del rango; Tasación, Venta y Cambio con captador y sin
  gestión de venta; German desde `Tasacion__c`; Facilitea por regla específica.
- Contact Center:
  citas del mes en Leads; oportunidades hasta el día 10 del mes siguiente;
  ventas firmadas dentro del mes.

Cada pestaña puede tener un universo diferente y expone el puente
`base - exclusiones + inclusiones = total`. La conciliación interna se muestra
solo a Administrador/IT; IDs y motivos permanecen en exportaciones autorizadas.

Reseñas mantiene la fórmula actual: reseñas creadas en el mes por `OwnerId`
divididas entre operaciones elegibles. Puede superar 100 % porque no es una
relación uno-a-uno. No se limita a una reseña por Opportunity sin nueva decisión
funcional.

### 7.4 Cierres, snapshots y ajustes

- Estados: provisional, pendiente de aprobación, definitivo y reabierto.
- Solo Dirección y Administrador/IT preparan, aprueban o reabren.
- El definitivo exige mes natural terminado, cinco componentes confirmados y
  ausencia de incidencias relevantes.
- Los cierres se separan por `Comerciales`, `Delegaciones` y `Área Manager`; cada
  snapshot conserva únicamente el universo y detalle de su bloque. Call Center,
  Contact Center y Financieros siguen siendo operativos/provisionales y no se
  congelan en esos cierres.
- Salesforce no sobrescribe un definitivo. Una corrección se registra como
  ajuste del siguiente mes abierto o exige reapertura manual con motivo.
- El libro de ajustes conserva operación, mes original/aplicación, importe,
  motivo, usuario, fecha y estado.

### 7.5 Auditoría y comandos

- vista `/informes/comisiones-comerciales`;
- XLSX `/informes/comisiones-comerciales/export/comisiones.xlsx`;
- CSV de entregas de delegación:
  `/informes/comisiones-comerciales/export/delegation-deliveries.csv`;
- CSV de Call Center sin captador:
  `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`;
- API Basic Auth:
  `/api/comisiones_comercial?salesforce_id={ID}&month={YYYY-MM}`. `month` es
  opcional; la fila completa procede de `commercials.summary_rows`, usa el
  snapshot para cierres definitivos aunque cambie después la elegibilidad del
  usuario, devuelve `row=null` solo para un ID exacto, activo y elegible sin fila
  real, y responde `503` si el dataset vivo no está calculable;
- configuración mensual: `/informes/configuracion-comisiones`;
- penalizaciones: `/informes/penalizaciones-financiacion`.
- reseñas: `/informes/comisiones-comerciales/export/reviews-audit.csv`.

El Auditor de comisiones puede cargar Penalizaciones financieras, pero no
gestiona usuarios, fórmulas ni cierres. Los ámbitos se aplican en servidor por
Salesforce User ID, delegación o zona según el rol.

```bash
php artisan salesforce:sync-opportunities --from=2026-08-01 --to=2026-09-01
php artisan salesforce:sync-commercial-reviews --all
php artisan salesforce:sync-tasaciones --all
```

Código fuente:

- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`
- `app/Services/Reports/CallCenterCommissions/CallCenterCommissionDashboardService.php`
- `app/Services/Reports/ContactCenterCommissions/ContactCenterCommissionDashboardService.php`
- `app/Services/Reports/AreaManagerCommissions/AreaManagerCommissionDashboardService.php`
- `app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php`
- `app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php`
- `app/Services/Reports/CommercialCommissions/CommissionMonthResolver.php`
- `app/Services/Reports/CommercialCommissions/CommercialCommissionClosureService.php`

Guía funcional específica:

- `docs/informe-comisiones-financieras.md`

## 8. Stock

### 8.1 Fuentes y propósito

Stock cruza:

1. inventario vigente de `Product2`;
2. ventas firmadas de `Opportunity`;
3. logística de `Logistica__c`;
4. capacidades por tienda importadas desde CSV/XLSX;
5. fotografías diarias y snapshots económicos de venta.

El dashboard no calcula ventas históricas leyendo el Product2 actual: cuando detecta
una nueva venta crea `salesforce_sale_snapshots` y conserva los importes y atributos
de ese momento. El contenido económico queda congelado; el estado de validez del
snapshot sí se reconcilia posteriormente contra el estado actual de Opportunity.

### 8.2 Query de inventario

```sql
SELECT
    Id, Name, StockKeepingUnit,
    PRO_TEX_Matricula__c,
    PRO_SEL_Marca__c, PRO_TEX_Modelo__c, PRO_TEX_Version__c,
    Segmento__c, PRO_SEL_Combustible__c, PRO_SEL_Carroceria__c,
    PRO_NUM_Kilometraje__c, PRO_SEL_Estado__c,
    PRO_BUS_Delegacion__c, PRO_BUS_Delegacion__r.Name,
    PRO_DIV_Precio_de_compra__c,
    PRO_DIV_Precio_de_venta__c,
    PRO_DIV_Precio_venta_financiado__c,
    Solo_financiado__c,
    PRO_FEC_Fecha_entrada__c,
    Comprador_oportunidad__c, Comprador_oportunidad__r.Name,
    Procedencia_de_compra__c
FROM Product2
WHERE PRO_SEL_Estado__c IN ('Disponible', 'Reservado', 'Bloqueado')
ORDER BY PRO_BUS_Delegacion__r.Name, PRO_FEC_Fecha_entrada__c, Id
```

Cada sincronización marca primero como fuera de stock todos los vehículos locales
que estaban activos y vuelve a activar únicamente los IDs devueltos por esta query.

Precio efectivo de stock:

- si `Solo_financiado__c = true`, se usa
  `PRO_DIV_Precio_venta_financiado__c` y, si falta, el precio normal;
- en cualquier otro caso se usa `PRO_DIV_Precio_de_venta__c`;
- se conservan además `normal_sale_price`, `financed_sale_price` y
  `only_financed` para que la elección sea auditable.

### 8.3 Query de ventas firmadas

```sql
SELECT
    Id, Name, StageName, LastModifiedDate, RecordType.Name,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Tienda_de_entrega__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Marca__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Modelo__c,
    OPP_BUS_Vehiculo_de_interes__r.Segmento__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Combustible__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Carroceria__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_NUM_Kilometraje__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_venta_financiado__c,
    OPP_BUS_Vehiculo_de_interes__r.Solo_financiado__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c,
    OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name,
    OPO_BUS_Vehiculo_a_tasar__c,
    OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c,
    OPO_FOR_Importe_vehiculo_venta__c,
    OPO_FOR_Importe_vehiculo_a_cambio__c,
    Gestion_de_venta__c,
    Costes_de_gestion__c,
    Costes_de_Logistica_Incluido__c,
    OPO_DIV_Coste_Traslado__c,
    Garant_a_Total__c, OPO_DIV_Descuento__c,
    OPO_DIV_Descuento_financiera__c,
    Descuento_Logistica__c, OPO_FOR_Importe_total__c
FROM Opportunity
WHERE IsDeleted = false
  AND RecordType.Name IN ('Venta', 'Cambio')
  AND (
      (
          OPO_CAS_Contrato_CV_firmado__c = true
          AND Fecha_firma_contrato__c >= 2026-06-01
          AND Fecha_firma_contrato__c < 2026-07-01
      )
      OR LastModifiedDate >= 2026-06-01T00:00:00Z
  )
```

La rama de `LastModifiedDate` no tiene límite superior en la implementación. Sirve
para volver a leer cambios posteriores —por ejemplo, una operación que pasa a
`Cerrada perdida`— y reconciliar snapshots ya creados. Esto significa que la query
puede devolver operaciones sin contrato vigente o fuera del intervalo de firma;
no se cuentan automáticamente como venta.

Precio de venta capturado:

1. `OPO_FOR_Importe_vehiculo_venta__c` si está informado;
2. precio efectivo del Product2 relacionado;
3. para un vehículo `Solo_financiado__c`, el efectivo es el financiado con
   fallback al normal.

Validez de snapshots:

- una nueva venta se crea con `pending_validation`;
- es válida únicamente si el contrato continúa firmado, el tipo es Venta/Cambio,
  la fase no es `Cerrada perdida` y existe vehículo de interés;
- motivos posibles: `contract_not_signed`, `invalid_record_type`, `closed_lost`
  y `missing_vehicle_interest`;
- si hay más de una venta base-válida para el mismo Product2, gana la firma más
  reciente y las anteriores quedan `duplicate_not_selected`, enlazadas con la
  Opportunity elegida;
- si varias comparten exactamente la fecha de firma más reciente, todas quedan
  `duplicate_ambiguous`, no suman y requieren revisión; `LastModifiedDate` no
  desempata;
- se actualizan `current_stage_name`, `is_valid`, `validity_checked_at`,
  `invalidated_at` e `invalid_reason`, pero no se reescriben los importes
  congelados;
- si falta la Opportunity local, el snapshot queda sin comprobar en esa pasada.

Solo los snapshots con `is_valid = true` participan en KPIs, ratios, rankings,
recomendaciones y en la detección de vehículos entregados que aún figuran en
stock. Los inválidos se conservan como trazabilidad económica y de calidad.

### 8.4 Query logística

```sql
SELECT
    Id, Name, LastModifiedDate,
    LOG_BUS_Vehiculo_a_transportar__c,
    LOG_BUS_Vehiculo_a_transportar__r.Name,
    LOG_BUS_Delegacion_Origen__c,
    LOG_BUS_Delegacion_Origen__r.Name,
    LOG_BUS_Delegacion_Destino__c,
    LOG_BUS_Delegacion_Destino__r.Name,
    LOG_SEL_Estado__c,
    LOG_FEC_Fecha_de_transporte__c,
    LOG_FEC_Fecha_recepcion__c,
    Fecha_en_destino__c
FROM Logistica__c
WHERE IsDeleted = false
  AND LastModifiedDate >= 2026-06-01T00:00:00Z
ORDER BY LastModifiedDate, Id
```

Actualmente Logística se sincroniza y conserva para trazabilidad; los KPIs
principales del dashboard se calculan con stock, ventas y capacidades.

### 8.5 KPIs y fórmulas

Stock actual:

- total = vehículos `is_in_stock = true`;
- disponible/reservado/bloqueado por `PRO_SEL_Estado__c`;
- valor de compra = suma de precios de compra;
- valor de venta = suma de precios de venta;
- margen potencial = valor venta − valor compra;
- precio medio = suma / unidades;
- antigüedad = hoy − fecha de entrada;
- tramos de antigüedad excluyentes: 0–59, 60–89, 90–119, 120–180 y más de
  180 días;
- los vehículos sin fecha se presentan en `Sin fecha de entrada`; la suma de los
  cinco tramos y este grupo debe coincidir con el stock total.

Ventas:

- universo: snapshots con `is_valid = true` y `signed_date` dentro del rango;
- rotación = fecha firma − fecha entrada, solo si entrada ≤ firma;
- margen bruto mostrado = precio contractual de venta − precio de compra;
- el detalle económico conserva cambio, gestión, logística, traslado, garantía,
  Plan Auto Plus, CAE, descuentos e importe total, pero la vista unificada no
  muestra por ahora las operaciones individuales.

Capacidad:

- capacidad libre = plazas totales − stock total;
- ocupación = stock total / plazas totales × 100;
- ventas/stock por delegación = ventas del rango / stock actual.

Ratio ventas/stock general:

- si el histórico diario cubre al menos el 80% de los días:
  `ventas / promedio diario de disponibles`;
- si no, fallback aproximado:
  `ventas / disponibles actuales`;
- el payload marca si el resultado es aproximado.
- el KPI general de ventas/stock no se muestra en Resumen, aunque el dato se
  conserva como contexto analítico en las tablas.

Histórico:

- cobertura = días fotografiados / días esperados;
- suficiente si cobertura ≥ 80%;
- el gráfico de líneas diario presenta tres series separadas: Disponible,
  Reservado y Bloqueado.

Rankings:

- dimensiones: marca, modelo, segmento, combustible, tramo de precio, carrocería,
  procedencia, kilometraje y antigüedad/rotación;
- rendimiento = ventas / stock actual;
- los valores equivalentes se agrupan con una clave en minúsculas, ASCII, sin
  diferencias de puntuación/separadores ni espacios repetidos;
- se excluyen de distribuciones y rankings los vehículos con términos no
  operativos en marca/modelo/segmento/combustible/carrocería/procedencia;
- si no hay stock, el rendimiento queda nulo; si existen ventas se muestra
  `Demanda sin stock` en vez de convertir el número de ventas en un ratio falso.
- el orden principal es exclusivamente por unidades vendidas; se puede consultar
  más vendidos, menos vendidos o todos los valores;
- las antiguas pestañas Delegaciones, Ventas y Rankings están unificadas. Pulsar
  el nombre de una delegación aplica el filtro a stock, ventas y rankings.

### 8.6 Motor de recomendaciones

Usa únicamente ventas válidas y operativas de los últimos 120 días. Para contexto
y capacidad utiliza Disponible, Reservado y Bloqueado; los candidatos son todos
los Product2 operativos en estado Disponible. Los 60/90 días solo determinan
urgencia y no excluyen candidatos.

Puntuación de destino:

```text
score =
  ventas_modelo * 9
  + ventas_marca * 2,5
  + ventas_segmento * 1,6
  + ventas_combustible * 1,2
  + ventas_tramo_precio * 1,8
  - stock_mismo_modelo * 15
  - stock_antiguo_mismo_modelo * 10
  - stock_similar * 1
  + bonus_rotacion_rapida
  - 30 si no existe histórico comparable
```

`bonus_rotacion_rapida = max(0, (120 - min(rotación_media, 120)) / 120) * 12`.

El orden previo compara ventas de marca, modelo y combustible, después
similitud de kilometraje y finalmente score. La capacidad no suma puntos al
ranking teórico; se usa para determinar ejecutabilidad y construir el plan.

Se devuelven los tres mejores destinos teóricos. El ranking ordena primero marca,
modelo y combustible, después similitud de kilometraje y score. Matriculación no
participa hasta confirmar el API Name exacto en Salesforce. Un destino participa
en el ranking si:

- es una delegación comercial;
- tiene una capacidad total configurada positiva; puede estar completo y en ese
  caso se muestra como no ejecutable, con exceso y plazas a liberar;
- no es la delegación actual del vehículo;
- no está en `stock.excluded_destination_keys`; actualmente se excluyen las
  variantes configuradas de Dos Hermanas.

Después del ranking se construye un plan conjunto que consume capacidad virtual
y no sobreasigna plazas. No guarda reservas persistentes, órdenes logísticas ni
cadenas de movimientos entre tiendas.

El stock no operativo sigue ocupando capacidad, pero no aporta coincidencias de
modelo/similitud. Un vehículo queda:

- `review` desde 60 días;
- `priority` desde 90 días;
- `priority` con 3 o más unidades del mismo modelo en la tienda;
- `priority` si el mejor destino supera al actual en 40 puntos.

Los tramos de precio son bloques de 5.000 EUR. Stock similar significa misma
combinación de segmento, combustible y tramo de precio.

Normalización y volumen:

- el catálogo canónico procede de picklists activos de Salesforce sincronizados
  con `stock:sync-salesforce-catalog`; aliases locales solo apuntan a valores
  oficiales activos y cada vehículo conserva bruto, normalizado, canónico y
  regla;
- la firma interna del score de recomendaciones normaliza mayúsculas, acentos y
  espacios, pero conserva los demás separadores;
- términos excluidos por defecto: `prueba`, `test`, `formacion` y
  `fuera de stock`;
- primero se calculan y ordenan todos los candidatos y después se pagina;
- el cálculo global conserva solo destino y puntuación; las explicaciones y el
  perfil completo se materializan después para las 150 filas de la página;
- página de recomendaciones: 150 candidatos;
- pestaña Vehículos: máximo 250 filas de detalle;
- las recomendaciones se cachean en memoria por firma normalizada del vehículo
  durante la construcción del dataset.

### 8.7 Capacidades, alertas y calidad

- Capacidades:
  - importación CSV/XLSX;
  - se localiza cabecera `Plazas totales`;
  - si un nombre se repite se conserva la mayor capacidad;
  - se normalizan alias de delegación.
- No comerciales: mantenimiento, pendiente de entrar, fuera de stock y clave de
  prueba configurada.
- Alerta:
  - solo delegaciones comerciales con capacidad;
  - cero vehículos `Disponible` abre alerta;
  - crea `Task` prioritaria en Salesforce y envía email a `STOCK_ALERT_EMAIL`;
  - cuando vuelve a haber disponibles, resuelve la alerta y completa la Task.
- Alerta visual del Resumen:
  - muestra delegaciones por encima del 100% o por debajo del 80% de ocupación;
  - usa el stock completo, independientemente de los filtros analíticos;
  - no sustituye el ciclo automático de Task/email por cero disponibles.
- Calidad:
  - stock sin entrada/delegación/marca/modelo/segmento/combustible;
  - entregados válidos que aún aparecen en stock;
  - tiendas comerciales sin zona;
  - snapshots sin firma/tienda/entrada/precio;
  - fecha de firma sin contrato, firmado en Cerrada perdida y fase inesperada;
  - ventas no seleccionadas o empates ambiguos para el mismo vehículo;
  - fecha de entrada futura y tienda sin capacidad válida;
  - variantes duplicadas de catálogo y vehículos con valores no operativos.

El XLSX de calidad contiene 20 hojas: las 12 comprobaciones anteriores de stock y
snapshot, más Firma sin contrato, Firmados cerrada perdida, Vehículos venta
duplicada, Fases inesperadas, Entradas futuras, Tiendas sin capacidad, Catálogos
duplicados y Valores no operativos. Las fases firmadas esperadas son `Contrato` y
`Cerrada ganada`.

Para evitar que la carga común supere el límite HTTP, estos controles se ejecutan
solo en Resumen. Los recuentos de nulos se agrupan con agregaciones condicionales;
Capacidades no carga el dataset analítico y las ventas de Resumen/Delegaciones se
leen únicamente con las columnas necesarias y sin hidratación Eloquent completa.

Exportación:

- `/informes/stock/exportar/calidad-dato.xlsx`

### 8.8 Operación

```bash
php artisan stock:sync-salesforce-catalog
php artisan stock:import-capacities ruta/capacidades.xlsx
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Programación vigente:

```text
03:30 Europe/Madrid
stock:sync-daily --sales-days=14 --logistics-days=30
sin solapamiento durante 120 minutos
```

Orden relevante dentro de `stock:sync-daily`:

1. sincroniza Product2, salvo `--skip-vehicles`;
2. sincroniza oportunidades recientes y modificadas, salvo
   `--skip-opportunities`;
3. crea snapshots nuevos;
4. reconcilia siempre la validez de todos los snapshots con Opportunity;
5. sincroniza logística, fotografía el stock y evalúa alertas según sus flags.

La reconciliación de validez se ejecuta aunque se use `--skip-opportunities`; en
ese caso trabaja con el estado local ya disponible.

Código fuente:

- `app/Services/Reports/Stock/SalesforceVehicleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSignedSaleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSaleSnapshotService.php`
- `app/Services/Reports/Stock/SalesforceLogisticsSyncService.php`
- `app/Services/Reports/Stock/StockDailySnapshotService.php`
- `app/Services/Reports/Stock/StockDashboardDatasetService.php`
- `app/Services/Reports/Stock/StockRecommendationService.php`
- `app/Services/Reports/Stock/StockCatalogNormalizer.php`
- `app/Services/Reports/Stock/StockSaleValidityService.php`
- `app/Services/Reports/Stock/StockAvailabilityAlertService.php`
- `config/stock.php`
- `database/migrations/2026_07_31_090000_add_sale_validity_and_financed_stock_price.php`

## 9. Método de conciliación recomendado

1. Guardar captura de filtros, período y rol.
2. Identificar la fecha pivote exacta.
3. Ejecutar primero `COUNT()` o la query base.
4. Exportar IDs, no solo totales.
5. Comparar con el endpoint/CSV auditable cuando exista.
6. Revisar normalizadores, alias y filtros locales.
7. Confirmar que la sincronización cubre todo el intervalo.
8. En Campañas, conciliar en orden:
   lead → campaña → cuenta/oportunidad → reserva → venta/compra → importe.
9. En Comisiones, conciliar cada pestaña por separado: sus universos no son
   intercambiables.
10. En Stock, distinguir siempre inventario vivo, snapshot diario y snapshot de
    venta.

## 10. Seguridad documental

La documentación solo debe nombrar variables de entorno. No debe incluir tokens,
contraseñas, secretos de Basic Auth ni credenciales de Salesforce, Meta, Google o
del endpoint interno.

Durante esta revisión se ha detectado que `config/services.php` aún contiene
valores fallback sensibles para el endpoint interno de reseñas. Deben retirarse,
rotarse y proporcionarse únicamente mediante `INTERNAL_REVIEWS_ENDPOINT`,
`INTERNAL_REVIEWS_USER` e `INTERNAL_REVIEWS_PASSWORD`. Este hallazgo no se
resuelve copiando los valores a otro documento.

### 9.1 Conciliación de Campañas tras Fase 6

La cohorte debe conciliarse primero con las señales legacy, porque los UTM
nuevos no amplían el universo. Dentro de esa misma lista de Lead IDs se comparan
después los cinco valores efectivos nuevo → legacy y `matched_source_field`.
`source_acquired`/`medium_acquired` proceden de Fuente/Medio adquiridos, no de
los campos generales `LEA_SEL_*`. Meta Direct Form mantiene su gate legacy y
solo migra la clasificación efectiva posterior a la admisión.

La ejecución implementada es local y por chunks, sin consultas por Lead ni
llamadas externas añadidas. Durante la implementación no se realizó backfill ni
validación externa de Google o Meta. El refactor técnico quedó posteriormente
desplegado; las validaciones externas de `utm_id__c` y la medición UTM-only
continúan pendientes y no alteran el contrato actual.

### 9.2 Backfill histórico de atribución de Leads (Fase 7A)

La herramienta `salesforce:backfill-lead-attribution-fields` parte siempre de
IDs ya persistidos en las dos tablas locales de Leads y consulta Salesforce por
lotes de esos IDs. Requiere un rango explícito de `created_date` local y
exactamente uno de `--dry-run` o `--apply`; la escritura exige además un motivo
operativo. `--limit` y `--after-salesforce-id` permiten ensayos acotados y
reanudar en orden estable.

El modo simulación no escribe filas, histórico ni cachés. El modo apply actualiza
exclusivamente los campos de atribución aprobados mediante operaciones UPDATE,
fusiona solo esas claves en `raw_payload`, registra before/after por fila y
confirma cada lote en una transacción independiente. Los IDs ausentes en
Salesforce no se limpian ni se marcan eliminados. Los UTM-only se cuentan para
conciliación, pero nunca se insertan en el universo legacy de Campañas.

La infraestructura está preparada; el histórico todavía **NO ha sido
modificado** y su ejecución requiere una aprobación operativa posterior.

### 9.3 Reproceso histórico de portales de Opportunities (Fase 7B)

`reports:reprocess-opportunity-portals` opera únicamente sobre filas locales de
`salesforce_opportunities` dentro de un rango `created_date` `[--from, --to)`.
Exige exactamente uno de `--dry-run` o `--apply`; apply requiere motivo, usa
mutex de seis horas, lotes de 100 y cursor local exclusivo `--after-id`.

La resolución reutiliza sin cambios la precedencia de Fase 5. Salesforce se
consulta solo en lectura y únicamente sobre Lead para el matching por lotes;
no existe consulta `FROM Opportunity` ni escritura remota. Antes de cada UPDATE,
la fila se relee con `lockForUpdate()` y se valida una huella de todos los inputs
de resolución. Un cambio concurrente fuerza una nueva consulta Lead, con máximo
de tres intentos y sin HTTP bajo locks.

Cada lote confirma conjuntamente los seis campos funcionales permitidos y su
before/after en `salesforce_opportunity_portal_reprocess_history`. No guarda PII
ni `raw_payload`, no inserta o elimina Opportunities y solo incrementa la versión
de caché si hubo cambios confirmados. La herramienta está preparada; no se ha
ejecutado el reproceso histórico ni un dry-run productivo.

### 9.4 Lifecycle/presencia de Opportunities y cierre técnico

El refactor de procedencia, canal, medio, delegación, UTM y atribución de
Opportunities está implementado y desplegado. Mantiene la autoridad nuevo →
legacy de cada módulo y no amplía sus universos funcionales.

El lifecycle se desplegó con PR #38 y se corrigió con PR #40 para consumir el
API Name real `SystemModstamp`. `query_all_deleted` identifica exclusivamente
un borrado confirmado y queda fuera de los consumidores dependientes de la
Opportunity. `presence_reconciliation_missing` solo significa que una consulta
explícita por ID no devolvió la fila y continúa reportable. Solo el sync canónico
completo puede reactivar una Opportunity retirada.

La reconciliación productiva del 8 de septiembre de 2026 terminó con 40.718
activas, tres borrados confirmados, tres ausencias diagnósticas y cero cambios
pendientes en el dry-run final. `SystemModstamp` se guarda en
`salesforce_deleted_at` como evidencia temporal técnica; no sustituye a
`Opportunity.LastModifiedDate` ni constituye una fecha contractual de borrado.
La prueba sintética sandbox no pudo ejecutarse, pero la evidencia productiva
confirmó consulta, persistencia e idempotencia.

Este cierre no acredita los históricos de Fase 7A o Fase 7B: ambas herramientas
están disponibles, pero sus ejecuciones continúan pendientes según la evidencia
versionada disponible.

## Cambio transversal de fuente local Interest — PR #92

Desde esta implementación en revisión, `salesforce_interests` es almacenamiento
legacy conservado; las lecturas adaptadas se resuelven por generación publicada
sobre `salesforce_interest_versions` y `salesforce_interest_members`. La identidad
externa sigue siendo `Interes__c.Id`; los aliases Leads no cambian el objeto fuente.
El run F2 aislado no constituye publicación: se requieren F5 alineado, validación
y puntero transaccional. Las descripciones históricas de «último F2»/tabla viva
quedan sustituidas por [el contrato de generaciones](salesforce-interest-generations.md).
Se revisan Interests, Reservas/Ventas, Campañas, Llamadas, Monthly Commercial y
SEO en sus puntos de dependencia. No se modifican fórmulas económicas de
Comisiones/Stock ni se certifican snapshots ajenos. Las auditorías KPI completas
siguen pendientes en la matriz; el aislamiento no demuestra por sí solo sus
fórmulas, ni consistencia temporal de otras fuentes.

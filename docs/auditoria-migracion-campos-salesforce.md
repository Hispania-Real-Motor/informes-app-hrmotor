# Auditoría preparatoria de procedencia y atribución Salesforce

Actualizado: 2026-09-08.

## Fase 3: clasificación efectiva de Leads

- La clasificación del informe de Leads compone `Fuente_origen__c`,
  `Canal__c`, `Medio_origen__c` y `Delegacion_procedencia__c` mediante
  `SalesforceLeadFieldResolver`: únicamente null, vacío o whitespace permite
  aplicar el fallback legacy. Placeholders no vacíos permanecen válidos y los
  conflictos siguen en `field_resolution`.
- `LeadPortalResolver` no cambia: continúa siendo la autoridad exclusiva de la
  prioridad legacy de fuente y del canal binario legacy. La composición vive en
  `LeadClassificationResolver`, usada por sync, dashboard y backfill local.
- El dashboard recalcula desde los raw locales; una materialización
  `resolved_*` antigua no puede ocultar los campos nuevos persistidos. Las
  auditorías de Lead añaden raws, efectivos y trazas por fuente, canal, medio y
  delegación sin eliminar contratos existentes.
- No cambian SOQL WHERE, IDs del universo, reglas de actividad/gestión,
  comercial efectivo, Calls, Reservas/Ventas ni el universo o atribución de
  Campañas. No se ejecuta backfill ni se escribe en Salesforce.
- La delegación visible puede provenir de `Delegacion_procedencia__c`, pero la
  autorización por delegación conserva la cadena legacy y el fallback de
  Exposición previo a esta fase. La dimensión de acceso se calcula solo en
  memoria y no se expone como un nuevo contrato público.

## Fase 4: procedencia de Llamadas

- El universo de Tasks no cambia. Cuando `Task.Portales__c` entra en el fallback
  de Lead ya existente, `Lead.Fuente_origen__c` informado gana para el portal
  visible; null, vacío y whitespace conservan exactamente el orden legacy de
  Llamadas: `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
- La resolución conserva dos salidas: la legacy operacional alimenta origen,
  duración y overflow; la efectiva alimenta únicamente `portal_resolved` y su
  traza. Un valor nuevo no normalizable no vuelve a recurrir a legacy.
- Sync consulta solo el campo adicional imprescindible y el reproceso carga
  Leads locales por chunk, sin Salesforce ni consultas por llamada. No se ha
  ejecutado reproceso ni sincronización real.

## Fase 5: procedencia de Reservas/Ventas

- `Opportunity.Portal__c` concluyente conserva prioridad absoluta. Cuando debe
  consultarse el Lead relacionado, `Fuente_origen__c` informado gana y solo
  null, vacío o whitespace activa el fallback congelado
  `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
- `SalesforceLeadFieldResolver` decide nuevo → legacy. Un placeholder no vacío
  selecciona el Lead de forma autoritativa y, si no es normalizable, persiste
  `Sin clasificar` con fuente `lead`, sin continuar a la fuente de Opportunity.
- Matching, orden `CreatedDate DESC`, chunks, SOQL base y universo de
  Opportunities permanecen intactos. El fallback `leads_raw` reconstruye el
  nuevo API Name desde `raw_payload`, sin columnas ni consultas adicionales.
- `portal_resolution_debug` añade raw nuevo, legacy y campo legacy ganador,
  efectivo, campo efectivo, fallback y conflicto. No incorpora PII nueva.

## Alcance y línea base

Este documento congela el comportamiento observado antes de migrar procedencia,
canal, medio, delegación de entrada y UTM. No define la regla futura ni valida
API Names contra la metadata de Salesforce.

- Rama auditada: `main`.
- Commit auditado: `4a4037a1036f0ce32800e003abdb571289cd10a1`.
- Estado inicial: limpio; `origin/main` apuntaba al mismo commit.
- Referencia solicitada: `a6b7ef768d0c261e18508e902b846abfffc12707`.
  Ese objeto no está disponible en el repositorio local, por lo que Git no pudo
  calcular ahead/behind ni merge-base. No se hizo fetch, reset ni checkout; el
  estado real actual se tomó como fuente de verdad.
- No se consultó metadata de Salesforce ni se ejecutó ninguna escritura remota.

## Hallazgos ejecutivos

1. `salesforce_leads.fuente_origen` significa actualmente
   `Lead.LEA_SEL_Fuente_Origen__c`; no significa `Lead.Fuente_origen__c`.
   `medio_origen` tiene la misma colisión con
   `LEA_SEL_Medio_Origen__c` frente a `Medio_origen__c`.
2. `LeadPortalResolver` deriva el canal solo de `Medio_Nuevo__c`. Una llamada
   prioriza `Fuente_Nuevo__c`; cualquier otro canal se trata como Formulario y
   prioriza `Portal_Text__c`.
3. Campañas sincroniza el universo SOQL mediante cinco campos legacy, pero el
   attribution builder no considera automáticamente candidato todo lo
   sincronizado: requiere campaña válida o la regla vigente de formulario
   directo Meta.
4. `CampaignLeadSyncService` escribe el mismo Lead en
   `campaign_salesforce_leads` y, si existe, en `salesforce_leads`. La segunda
   escritura es parcial y puede reemplazar por `null` columnas de procedencia o
   contacto obtenidas por el sincronizador mensual.
5. Históricamente `Medio_origen__c` se usó en una integración aislada de SEO
   para contar Leads orgánicos. ROT-5 conserva esa tabla como histórico, pero la
   métrica vigente usa `Interes__c.IN_Medio_Origen__c`, ya persistido en
   `salesforce_interests.medium`; no alimenta ni reinterpreta
   `salesforce_leads.medio_origen`.
6. Hay lógicas legacy paralelas con prioridades diferentes: el dashboard
   Salesforce usa `LeadPortalResolver`; la canalización histórica
   `leads_raw`/`leads_normalized` usa `LeadNormalizationService`; Llamadas y
   Reservas/Ventas aplican sus propios fallbacks de portal del Lead.

## Matriz técnica

`Impacta universo` indica si cambiar el uso puede alterar qué registros entran,
no solo su etiqueta.

| Campo/API | Tipo de uso | Archivo / contexto | Persistencia local | Informe afectado | Impacta universo | Riesgo | Cambio futuro |
|---|---|---|---|---|---|---|---|
| `Medio_Nuevo__c` | SELECT, mapper, resolución de canal | `SalesforceMonthlyLeadsSyncService::leadSoql/persistPages`, `SalesforceLeadMapper`, `SalesforceLeadCsvMapper`, `LeadPortalResolver::channel` | `salesforce_leads.medio_nuevo`, `leads_raw.medio_nuevo`, `salesforce_leads.resolved_channel` | Leads, Comercial mensual | No en sync general; sí en clasificaciones/filtros posteriores | Alto | Mantener como fallback hasta aprobar autoridad de `Canal__c` |
| `Medio_Nuevo__c` | SELECT auxiliar | `SalesforceCallSyncService::relatedLeadMatches` | No se persiste desde la consulta auxiliar | Llamadas | No, el universo es Task | Medio | Decidir si la consulta auxiliar necesita canal nuevo; no ampliar universo |
| `Fuente_Nuevo__c` | SELECT, mapper, resolución | `SalesforceMonthlyLeadsSyncService`, `SalesforceLeadMapper`, `SalesforceLeadCsvMapper`, `LeadPortalResolver::resolve` | `salesforce_leads.fuente_nuevo`, `leads_raw.fuente_nuevo`, `resolved_portal`, `portal_resolution_source` | Leads, Comercial mensual | No | Alto | Añadir fuente nueva solo tras definir prioridad y validez |
| `Fuente_Nuevo__c` | fallback de portal relacionado | `SalesforceCallSyncService::leadPortal`, `SalesforceOpportunitySyncService::leadPortal/queryLeads` | `salesforce_calls.portal_resolved`; `salesforce_opportunities.portal_resolved` | Llamadas, Reservas/Ventas | No: Tasks/Oportunidades no cambian | Alto | Cambiar únicamente la clasificación, preservando consultas base |
| `Portal_Text__c` | SELECT, portal primario de formulario | `SalesforceMonthlyLeadsSyncService`, `LeadPortalResolver`, `SalesforceLeadCsvMapper` | `salesforce_leads.portal_text`, `leads_raw.portal`, `resolved_portal` | Leads, Comercial mensual | No | Alto | Conservar como fallback explícito |
| `Portal_Text__c` | portal primario del Lead relacionado | `SalesforceCallSyncService::leadPortal`, `SalesforceOpportunitySyncService::leadPortal` | portal resuelto de Call/Oportunidad | Llamadas, Reservas/Ventas | No | Alto | Proteger prioridad y trazabilidad del campo ganador |
| `Portal__c` | mapper de importación API legacy | `SalesforceLeadMapper::map` | `leads_raw.portal` | Canalización legacy de Leads | No | Medio | No confundir con `Portal_Text__c` |
| `Portal__c` | SELECT y clasificación propia de Opportunity | `SalesforceOpportunitySyncService::buildOpportunitySoql/resolvePortal` | `salesforce_opportunities.portal_original`, `portal_resolved`, `portal_resolution_source` | Reservas/Ventas, Campañas al enlazar oportunidades | No en universo | Alto | No reemplazar por campo de Lead; mantener precedencia de Opportunity |
| `LEA_SEL_Fuente_Origen__c` | SELECT, mapper, fallback de portal | sync mensual, mappers, `LeadPortalResolver`, consultas auxiliares | `salesforce_leads.fuente_origen`, `leads_raw.lea_sel_fuente_origen` | Leads, Llamadas, Reservas/Ventas | No salvo Campañas | Crítico | La columna local es legacy; no reutilizar para `Fuente_origen__c` |
| `LEA_SEL_Fuente_Origen__c` | OR de filtro, SELECT, criterio PHP | `CampaignLeadSyncService::leadSoql/countMappedRecord`; `SalesforceMonthlyLeadsSyncService` en `campaignOnly` | ambas tablas de Lead | Campañas | Sí | Crítico | El cambio futuro del OR requiere decisión consciente y pruebas de conteo |
| `LEA_SEL_Medio_Origen__c` | SELECT, mapper, exposición | sync mensual, mappers, `MonthlyCommercialLeadEnricher` | `salesforce_leads.medio_origen`, `leads_raw.lea_sel_medio_origen` | Leads, Comercial mensual | No salvo Campañas | Crítico | No reutilizar columna para `Medio_origen__c` |
| `LEA_SEL_Medio_Origen__c` | OR de filtro, SELECT, criterio PHP | `CampaignLeadSyncService`; sync mensual `campaignOnly` | ambas tablas de Lead | Campañas | Sí | Crítico | Preservar universo hasta aprobar equivalencia/fallback |
| `Remitente_Lead__c` | SELECT, mapper y delegación de formulario legacy | sync mensual, mappers, `LeadNormalizationService::resolveFormDelegation` | `salesforce_leads.remitente_lead`, `leads_raw.remitente_lead` | Leads legacy / calidad | Puede alterar agrupación, no sync | Alto | No sustituir sin revisar mappings por portal/remitente |
| `Delegacion_Encargada_Text__c` | SELECT, mapper y fallback | sync mensual, mappers, sync Campañas | `delegacion_encargada_text` en ambas tablas | Leads, Comercial mensual, Campañas | No | Alto | Mantener su semántica de valor recibido/fallback |
| `Delegacion_Encargada_Text__c` | mapeo exacto por portal en llamadas legacy | `LeadNormalizationService::resolveCallDelegation` | `leads_normalized` | Canalización legacy de Leads | No | Alto | No equiparar automáticamente con procedencia |
| `Delegacion_Encargada_Bueno__c` | SELECT, mapper, prioridad 1 dashboard | sync mensual; `SalesforceLeadDashboardDatasetService::resolveLeadDelegation` | `salesforce_leads.delegacion_encargada_bueno` | Leads | No | Crítico | La prioridad futura está pendiente |
| `Delegacion_Encargada_Bueno__c` | fallback de formulario legacy | `LeadNormalizationService::resolveFallbackDelegation` | resultado normalizado legacy | Leads legacy | No | Alto | Conservar antes de `Delegacion_Encargada__c` |
| `Delegacion_Encargada__c` | SELECT, mapper, prioridad 2 dashboard | sync mensual y Campañas; dataset Leads | `salesforce_leads.delegacion_encargada`; `campaign_salesforce_leads.delegacion_encargada_id` | Leads, Campañas | No | Crítico | No cambiar prioridad sin decisión |
| `Delegacion__c` | mapper de Lead API legacy | `SalesforceLeadMapper::map`; `LeadNormalizationService` tercer fallback | `leads_raw.delegacion`; no se carga por CSV | Canalización legacy | No | Alto | API Name funcional no está verificado para el dashboard actual |
| `Owner.Delegacion__c` | delegación de owner en mapper legacy | `SalesforceLeadMapper::map` | `leads_raw.owner_delegation` | Leads legacy, Exposición | No | Alto | Solo Exposición permite este fallback |
| `Owner.USR_SEL_Delegacion__c` | SELECT de usuario/owner | sync mensual de Users, sync de Calls, sync de Opportunities | `salesforce_users.user_delegation`, `salesforce_opportunities.owner_delegation` | Leads (delegación comercial), Llamadas, Reservas/Ventas, Comisiones | No | Alto | Es delegación del comercial, no delegación de entrada |
| `Campa_a_Adquirida__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `campaign_acquired` en ambas tablas | Campañas y auditoría Leads | Sí en Campañas | Crítico | Definir equivalencia con `utm_campaign__c` antes de ampliar filtro |
| `Campa_a_Adquirida__c` | nombre y exclusión de campaña | `CampaignAttributionBuilderService::resolveCampaign` | atribuciones `campaign_acquired`, `source_campaign_name`, match trace | Campañas | Sí en candidatos del builder | Crítico | Mantener rule version y decidir conflicto nuevo/legacy |
| `Id_Adquirido__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `acquired_id` en ambas tablas | Campañas, auditoría Leads | Sí en sync Campañas | Crítico | No asumir que equivale a `utm_id__c` |
| `Id_Adquirido__c` | candidato de ID | attribution builder: ad, adset, ad group y campaign ID | `acquired_id`, `matched_source_field/value` | Campañas | No tras selección; cambia atribución | Crítico | Acordar significado y prioridad ID/nombre |
| `Contenido_Adquirido__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `content_acquired` en ambas tablas | Campañas, auditoría Leads | Sí en sync Campañas | Crítico | No asumir equivalencia con `utm_content__c` |
| `Contenido_Adquirido__c` | segundo candidato de ID | attribution builder | `content_acquired`, traza de match | Campañas | No tras selección; cambia atribución | Alto | Definir si es contenido, anuncio u otro identificador |
| `Fuente_Adquirida__c` | fallback de fuente adquirida | sync y attribution builder | `acquired_source_legacy`, `source_acquired` efectivo | Campañas | No | Alto | Fallback exclusivo de `utm_source__c`; no confundir con procedencia general |
| `Medio_Adquirido__c` | fallback de medio adquirido | sync y attribution builder | `acquired_medium_legacy`, `medium_acquired` efectivo | Campañas | No | Alto | Fallback exclusivo de `utm_medium__c` |
| `Fuente_origen__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Crítico | Verificar API Name; crear destino inequívoco en fase posterior |
| `Medio_origen__c` | histórico Lead previo a ROT-5 | servicio retirado; sin lectura runtime | `seo_salesforce_organic_daily_metrics.lead_count` histórico | SEO/Analytics histórico | No | Alto | No mezclar con la serie Interest vigente |
| `Interes__c.IN_Medio_Origen__c` | sync F2 canónico | `SalesforceInterestSyncService` → proyección local ROT-5 | `salesforce_interests.medium` → `seo_salesforce_organic_interest_daily_metrics.interest_count` | SEO/Analytics vigente | Sí, igualdad exacta `Orgánico` | Alto | F2 completed/estable; `functional_created_at` y límites Madrid→UTC |
| `Canal__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Alto | Pendiente definir autoridad y valores válidos |
| `Delegacion_procedencia__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Crítico | Pendiente prioridad, Exposición y aliases |
| `utm_campaign__c`, `utm_id__c`, `utm_source__c`, `utm_medium__c`, `utm_content__c` | SELECT y resolución efectiva tras gate legacy | ambos sync y attribution builder | columnas `utm_*_new`, `field_resolution`, atribuciones | Campañas | No en universo; sí en atribución | Crítico | Nuevo no vacío gana por dimensión; `utm_id__c` conserva la posición funcional del ID legacy |
| campos locales `source_acquired`, `medium_acquired` | proyección de atribución | `CampaignAttributionBuilderService::makeAttributionRow` | tablas de atribución | Campañas/export | No | Alto | Proceden de `utm_source/Fuente_Adquirida` y `utm_medium/Medio_Adquirido`; no de `LEA_SEL_*` |

## Flujos de datos actuales

### Leads Salesforce y Comercial mensual

1. `SalesforceMonthlyLeadsSyncService` consulta `Lead` por `CreatedDate`,
   `Fecha_captador__c` o `LastModifiedDate`. Es el universo general; los campos
   de procedencia no deciden la entrada.
2. Los campos legacy se mapean a `salesforce_leads`. En el mismo paso,
   `LeadPortalResolver` materializa `resolved_channel`, `resolved_portal` y
   `portal_resolution_source`.
3. El canal actual normaliza trim, mayúsculas y acentos: solo `llamada` produce
   `Llamada`; `null`, vacío, whitespace, Formulario y desconocidos producen
   `Formulario`.
4. Prioridad de portal: Llamada = `Fuente_Nuevo__c` → `Portal_Text__c` →
   `LEA_SEL_Fuente_Origen__c`; Formulario = `Portal_Text__c` →
   `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`. Los candidatos se recortan;
   vacío/whitespace no gana. Sin candidato: `Sin clasificar`, source `fallback`.
5. `SalesforceLeadDashboardDatasetService` reutiliza lo materializado y solo
   recalcula si falta alguno de los tres valores. No modifica el universo de
   Leads válidos por procedencia.
6. El CSV de auditoría de Leads expone portal bruto y resuelto, campo ganador,
   canal, delegaciones, `LEA_SEL_Fuente_Origen__c`, medio local y adquisición.
7. `MonthlyCommercialLeadEnricher` proyecta `fuente_original` desde
   `fuente_origen`, `medio` desde `medio_origen` y tanto `portal` como `fuente`
   desde `portal_text`. Su delegación usa únicamente
   `Delegacion_Encargada_Text__c` y una comparación con `master_delegations`.

Existe además una canalización histórica `SalesforceLeadMapper` / CSV →
`leads_raw` → `LeadNormalizationService` → `leads_normalized`. No comparte todas
las prioridades del dashboard actual: para Llamada, `resolvePortalOriginal`
usa solo `fuente_nuevo` o `Sin portal`; para Formulario usa `portal`, fuente
legacy y fuente nueva. Esta divergencia debe tratarse conscientemente.

### Delegación de Lead

- Dashboard Salesforce: `Delegacion_Encargada_Bueno__c` →
  `Delegacion_Encargada__c` → `delegacion_encargada_text` persistido legacy.
- Solo RecordType funcional Exposición, y únicamente si lo anterior está vacío,
  permite persona que trabajó → owner → usuario comercial efectivo.
- Otros tipos no usan owner como fallback de delegación del Lead.
- `LeadDelegationNormalizer` limpia mayúsculas, acentos, puntuación, guiones,
  barras y espacios; para emails elimina espacios. Después aplica aliases
  controlados y devuelve delegación, grupo y zona. Desconocido/null/vacío queda
  `Sin clasificar` / `Sin grupo`.
- La delegación comercial es otro eje: procede del usuario efectivo y de
  `USR_SEL_Delegacion__c`. No debe confundirse con delegación de entrada.
- Canalización legacy: llamadas usan mapping portal +
  `Delegacion_Encargada_Text__c`; formularios priorizan mapping portal +
  remitente + valor, luego Bueno → Encargada → `Delegacion__c`; Exposición
  puede usar owner.

### Campañas

`CampaignLeadSyncService`:

- WHERE actual: `IsDeleted = false`, rango semiabierto de `CreatedDate` y OR de
  `Campa_a_Adquirida__c`, `Id_Adquirido__c`, `Contenido_Adquirido__c`,
  `LEA_SEL_Fuente_Origen__c`, `LEA_SEL_Medio_Origen__c` no null.
- SELECT: identidad, contacto, conversión, esos cinco campos, vehículo y tres
  campos de delegación. No selecciona campos UTM candidatos, `Fuente_Adquirida`
  ni `Medio_Adquirido`.
- Mapeo: los cinco campos escriben `campaign_acquired`, `acquired_id`,
  `content_acquired`, `fuente_origen`, `medio_origen`.
- Aunque Salesforce devuelva una cadena vacía, el filtro PHP exige `filled` tras
  trim. Un registro sin ninguno de los cinco se cuenta en
  `without_acquisition` y no se guarda.
- Estadísticas: tabla, borrados, consultados, guardados, contador por cada uno de
  los cinco campos, sin adquisición, warnings y `dry_run`.
- `fresh`: elimina antes del sync solo filas de `campaign_salesforce_leads` cuyo
  `created_date` cae en el rango; no elimina `salesforce_leads`. No es
  transaccional con la consulta remota: un fallo posterior puede dejar vacío el
  tramo especializado.
- `dryRun`: consulta, mapea y cuenta, pero no elimina ni hace upsert. El comando
  prohíbe combinar explícitamente `--fresh` con `--dry-run`.
- Si la query filtrada lanza `RuntimeException`, reintenta una query por rango
  con el mismo SELECT y sin OR de adquisición; después aplica el mismo filtro
  PHP. El warning incluye el error de cliente ya sanitizado por la capa
  Salesforce.
- Persistencia: upsert por chunks, ordenado por Salesforce ID y con reintentos
  de deadlock. Escribe primero `campaign_salesforce_leads` y después, si existe,
  `salesforce_leads`; no hay transacción común entre ambas tablas.

`CampaignAttributionBuilderService`:

1. Prefiere `salesforce_leads` si hay cualquier fila en el rango; solo cae a
   `campaign_salesforce_leads` si la tabla general no tiene filas en ese rango.
   Por ello no mezcla ambas fuentes ni completa huecos por Lead.
2. Recupera campos vacíos desde `raw_payload` usando exclusivamente API Names
   legacy.
3. Considera candidato un Lead con `campaign_acquired` válido o que cumpla la
   regla vigente de formulario directo Meta. Un Lead sincronizado únicamente
   por fuente/medio o ID no es automáticamente candidato fuera de esa regla.
4. Prueba `acquired_id` y `content_acquired` contra ID de anuncio, adset,
   ad group y campaña; conflictos producen atribución ambigua. Si no gana un ID,
   prueba nombre de campaña exacto y flexible. Después conserva atribución solo
   Salesforce cuando aplica.
5. Persiste bruto, resultado, método, confianza, campo/valor ganador,
   candidatos, first touch y versión de regla. Los exports muestran esa traza.

#### Riesgo de doble escritura

- Los sincronizadores mensual y de Campañas pueden actualizar el mismo
  `salesforce_leads.salesforce_id` con ventanas y tiempos distintos. No existe
  versionado por `LastModifiedDate` ni compare-and-swap entre servicios.
- El sync de Campañas no selecciona RecordType, resolución de portal, campos de
  citas ni metadatos de borrado. El upsert no actualiza esas columnas, por lo que
  permanecen, pero sí actualiza nombre, estado, owner, contacto, conversión,
  procedencia, adquisición, delegación y `raw_payload`.
- Si su respuesta parcial omite fuente/medio/contacto/delegación, los mapea a
  `null` y puede borrar el valor general existente. El test de caracterización
  congela expresamente este comportamiento peligroso sin corregirlo.
- El `raw_payload` de `salesforce_leads` pasa a ser el payload parcial del sync
  de Campañas. Un attribution builder posterior puede perder campos disponibles
  en el payload mensual.
- Un fallo entre ambos upserts deja tablas divergentes. Carreras concurrentes
  son last-write-wins. `fresh` solo afecta la tabla especializada.

### Reservas/Ventas

El universo nace de Opportunity y sus fechas/reglas; no depende de Lead. El
portal se resuelve así: `Opportunity.Portal__c` conclusivo → Lead relacionado
por email/teléfono (más reciente y con fuente nueva informada o portal legacy válido) →
`Opportunity.Fuente_de_Origen__c` útil → fallbacks Exposición/Web → Sin
clasificar. En el Lead relacionado la prioridad es `Fuente_origen__c` y, solo
si falta, `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
Esta migración solo cambia `portal_resolved` y su traza, nunca las Opportunities
consultadas, reservas, contratos ni conteos.

Si la consulta de Leads no devuelve filas, existe fallback local a `leads_raw`.
La consulta Opportunity solo reintenta sin email de empresa cuando Salesforce
rechaza ese campo opcional; no es el fallback de query filtrada de Campañas.

### Llamadas

El universo nace de Task de llamada y `CallObject`; no depende del Lead. Primero
normaliza `Task.Portales__c`. Solo si estaba informado pero queda Sin clasificar
busca el Lead de `WhoId` y aplica `Portal_Text__c` → fuente legacy → fuente
nueva. La consulta auxiliar selecciona también canal y delegaciones de Lead,
pero la resolución observada solo consume el portal. Cambiar procedencia futura
no debe alterar las Tasks, estados, duraciones, atendidas o desbordamientos.

### SEO/Analytics

ROT-5 retira la consulta Lead directa y su resolver describe. La proyección SEO
vigente lee exclusivamente el F2 local estable, filtra
`salesforce_interests.medium = 'Orgánico'` y materializa conteos diarios en
`seo_salesforce_organic_interest_daily_metrics` mediante
`functional_created_at`. La tabla `seo_salesforce_organic_daily_metrics` se
conserva solo como histórico Lead; ninguna de las dos series sobrescribe
`salesforce_leads.medio_origen`.

### Otros consumidores y trazabilidad

- `salesforce_leads` alimenta Leads, Comercial mensual, Campañas, comisiones de
  Contact Center y auditorías. Cambiar columnas compartidas tiene radio amplio.
- `campaign_salesforce_leads` alimenta el attribution builder solo como fallback
  por rango y comandos de Campañas.
- Leads exporta bruto/resuelto/campo ganador. Campañas exporta valores adquiridos,
  resultado, IDs, método, campo y valor de match, ambigüedad y rule version.
- Calls y Opportunities persisten portal bruto, resuelto, source y debug/Lead ID,
  lo que permite comparar antes y después sin cambiar su universo.
- No se halló uso de estos campos en Stock. Las referencias “procedencia de
  compra” de vehículo pertenecen a otra semántica y quedan fuera de esta
  migración.

## Casos límite congelados o documentados

- `null`, vacío y whitespace no son candidatos válidos en resolvers/sync PHP.
- El canal solo reconoce Llamada tras trim, lowercase y eliminación de acentos;
  cualquier desconocido es Formulario.
- Los valores de portal se conservan recortados, sin normalizar mayúsculas ni
  aliases en `LeadPortalResolver`; los normalizadores posteriores sí pueden
  agruparlos.
- Campos legacy contradictorios: gana la prioridad específica del consumidor;
  no se detecta conflicto en Leads, Calls u Opportunities.
- Datos adquiridos parciales: cualquiera de los cinco campos introduce el Lead
  en el sync especializado, pero no garantiza candidatura de atribución.
- Lead convertido: se persisten flags e IDs; no cambia el criterio de sync por
  adquisición.
- Delegación desconocida conserva raw y queda Sin clasificar.
- La disponibilidad de campos opcionales en el sync mensual puede provocar
  reintento con un SELECT reducido. No valida campos candidatos nuevos.

## Riesgos

### CRÍTICO

- Colisión de nombres local/API en `fuente_origen` y `medio_origen`.
- Ampliar el OR de Campañas aumenta el universo, volumen, escrituras y conteos.
- Doble upsert no transaccional y last-write-wins sobre `salesforce_leads`, con
  posibilidad demostrada de reemplazar valores por `null`.
- Cambiar significado/prioridad de IDs puede reatribuir campañas históricas y
  ventas, alterando KPIs económicos sin cambiar Leads base.

### ALTO

- Prioridades divergentes entre dashboard, canalización legacy, Calls y
  Opportunities.
- Exposición tiene fallback de owner exclusivo y dependiente de RecordType.
- Añadir campos al SELECT puede fallar por API Name, permisos/FLS o tamaño de
  respuesta; los fallbacks actuales no son equivalentes entre servicios.
- Un backfill o reatribución masiva puede elevar tiempos, locks, deadlocks y
  escritura; debe diseñarse por lotes, reanudable y auditable.
- `fresh` borra antes de consultar y puede dejar un rango vacío tras fallo.

### MEDIO

- Alias, acentos, mayúsculas y valores desconocidos no se normalizan igual en
  todos los servicios.
- El builder elige una sola tabla por rango, por lo que una tabla general parcial
  puede ocultar Leads presentes solo en la especializada.
- Crecer SELECT, OR y volumen aumenta consumo de API, red, PHP y base de datos.

### BAJO

- Etiquetas de exports como “Medio origen” no explicitan que hoy es legacy;
  la traza técnica sí conserva el dato.
- Los API Names candidatos ausentes del código siguen sin verificar.

## Decisiones cerradas y validaciones técnicas pendientes

Dirección cerró la prioridad absoluta del campo nuevo informado, la validez
basada solo en null/vacío/whitespace, la resolución independiente, la prioridad
de delegación incluida Exposición, la política de conflictos y las cinco parejas
UTM. `utm_campaign__c` decide el nombre y `utm_id__c` permanece independiente.

Ya no son bloqueos de negocio. Siguen pendientes para fases posteriores la
semántica real de `utm_id__c` por plataforma, el análisis de casos Google/Meta,
la medición de Leads UTM-only fuera del universo legacy y el diseño/ejecución
controlada del backfill histórico.

## Pruebas de caracterización

- `LeadPortalResolverCharacterizationTest`: canal, normalización actual,
  prioridades, source, vacíos y fallback.
- `CampaignLeadSyncCharacterizationTest`: SELECT/WHERE legacy exactos, ausencia
  de candidatos nuevos, cinco formas de entrada, exclusión, whitespace,
  `dryRun`, fallback a rango y doble escritura parcial.
- `OpportunityPortalResolutionTest`: prioridad del Lead relacionado.
- `SalesforceCallSyncServiceTest`: SELECT auxiliar y prioridad del Lead cuando
  `Task.Portales__c` no clasifica.
- Cobertura previa reutilizada: normalización/prioridad de delegación, Exposición,
  sync mensual, attribution builder, Opportunities y Calls.

## Confirmación de no regresión de esta fase

- ¿Se modificó algún universo? NO.
- ¿Se modificó algún conteo? NO.
- ¿Se modificó alguna regla de negocio? NO.
- ¿Se modificó alguna prioridad de campos? NO.
- ¿Se integró algún campo Salesforce nuevo? NO.
- ¿Se modificó algún SOQL de producción para añadir campos nuevos? NO.
- ¿Se realizó algún backfill? NO.
- ¿Se modificó producción? NO.

## Implementación técnica de la fase 2 (2026-09-01)

### Metadata Lead validada en Salesforce (solo lectura)

Se utilizó `SalesforceClient::describe('Lead')` y una consulta `SELECT ... LIMIT
1` que solo informó del número de campos/filas, sin imprimir datos de Lead,
credenciales ni endpoints. Los 14 API Names existen y son consultables por la
integración actual.

| API Name | Label | Tipo | Longitud | Picklist | Nillable |
|---|---|---|---:|---|---|
| `Fuente_origen__c` | Fuente de Origen | string | 255 | Sí | Sí |
| `Medio_origen__c` | Medio de origen | string | 255 | Sí | Sí |
| `Canal__c` | Canal | string | 255 | Sí | Sí |
| `Delegacion_procedencia__c` | Delegación de Procedencia | string | 255 | Sí | Sí |
| `utm_campaign__c` | UTM Campaña | string | 70 | No | Sí |
| `utm_id__c` | UTM Id | string | 70 | No | Sí |
| `utm_source__c` | UTM Fuente | string | 70 | No | Sí |
| `utm_medium__c` | UTM Medio | string | 70 | No | Sí |
| `utm_content__c` | UTM Contenido | string | 70 | No | Sí |
| `Campa_a_Adquirida__c` | Campaña Adquirida | string | 255 | No | Sí |
| `Id_Adquirido__c` | Id Adquirido | string | 255 | No | Sí |
| `Fuente_Adquirida__c` | Fuente Adquirida | string | 255 | No | Sí |
| `Medio_Adquirido__c` | Medio Adquirido | string | 255 | No | Sí |
| `Contenido_Adquirido__c` | Contenido Adquirido | string | 255 | No | Sí |

Valores activos observados: Canal incluye `No identificado`, `Chat`, `Chatbot`,
`Email`, `Formulario`, `Llamada`, `Manual`, `Otro` y `Whatsapp`; Medio incluye
`No identificado`, `CPC`, `Orgánico` y `Referral`. Fuente y Delegación exponen
catálogos activos amplios. La aplicación conserva cualquier valor no vacío y no
replica esos catálogos como validación local.

### Mapeo local aditivo

Las columnas se añaden tanto a `salesforce_leads` como a
`campaign_salesforce_leads`, sin índices nuevos:

| Salesforce | Columna local | Semántica | Escritura autoritativa |
|---|---|---|---|
| `LEA_SEL_Fuente_Origen__c` | `fuente_origen` | Legacy, sin cambio | Sync general; tabla especializada de Campañas |
| `LEA_SEL_Medio_Origen__c` | `medio_origen` | Legacy, sin cambio | Sync general; tabla especializada de Campañas |
| `Fuente_origen__c` | `source_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Medio_origen__c` | `medium_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Canal__c` | `channel_new` | Nuevo raw | Sync general; tabla especializada |
| `Delegacion_procedencia__c` | `delegation_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Campa_a_Adquirida__c` | `campaign_acquired` | Legacy | Sync general y Campañas |
| `Id_Adquirido__c` | `acquired_id` | Legacy | Sync general y Campañas |
| `Fuente_Adquirida__c` | `acquired_source_legacy` | Legacy nuevo en almacenamiento | Sync general y Campañas |
| `Medio_Adquirido__c` | `acquired_medium_legacy` | Legacy nuevo en almacenamiento | Sync general y Campañas |
| `Contenido_Adquirido__c` | `content_acquired` | Legacy | Sync general y Campañas |
| `utm_campaign__c` | `utm_campaign_new` | Nuevo raw | Sync general y Campañas |
| `utm_id__c` | `utm_id_new` | Nuevo raw independiente | Sync general y Campañas |
| `utm_source__c` | `utm_source_new` | Nuevo raw | Sync general y Campañas |
| `utm_medium__c` | `utm_medium_new` | Nuevo raw | Sync general y Campañas |
| `utm_content__c` | `utm_content_new` | Nuevo raw | Sync general y Campañas |
| resolución estructurada | `field_resolution` | efectivo, campo, fallback, conflicto y raw por dimensión | Sync general; tabla especializada |

### Resolución y alcance funcional

`SalesforceLeadFieldResolver` aplica una única regla pura: trim vacío hace
fallback; cualquier otro valor nuevo gana. Compara los valores recortados para
marcar conflicto y conserva ambos raw. Fuente recibe como fallback el resultado
de `LeadPortalResolver`; canal recibe su canal legacy; delegación puede recibir
el resultado legacy contextual; medio y cada UTM usan su pareja aprobada.

En esta fase la resolución se materializa para auditoría, pero no sustituye los
campos resueltos consumidos por dashboards. No se migran Llamadas,
Reservas/Ventas, `CampaignAttributionBuilderService`, first-touch ni matching
Google/Meta. Por ello no cambian universos, clasificación visible ni conteos.

### Propiedad de datos y doble escritura

- `SalesforceMonthlyLeadsSyncService` es autoritativo sobre la fila general y
  puede reflejar valores informados, modificados o vaciados por Salesforce. Si
  falla el SELECT opcional, la query reducida omite todas las columnas nuevas y
  `field_resolution`, conservando lo ya almacenado.
- `CampaignLeadSyncService` es autoritativo sobre toda la fila de
  `campaign_salesforce_leads`. En una fila general existente solo puede aportar
  adquisición legacy y UTM no vacíos; no modifica identidad, contacto,
  conversión, procedencia, delegación ni `raw_payload`. Actualiza exclusivamente
  los cinco nodos UTM de `field_resolution` para que correspondan al raw final;
  conserva sin reconstruir `source`, `channel`, `medium` y `delegation`.
- La lectura de filas generales existentes se hace una vez por chunk y los
  upserts continúan siendo masivos. Las dos escrituras del chunk comparten una
  transacción corta, abierta después de la consulta remota, y conservan los
  reintentos de deadlock. El insert de un Lead general ausente permanece
  soportado y es seguro frente a una carrera mediante upsert.

El WHERE de Campañas conserva exactamente sus cinco condiciones legacy. Los
nuevos campos solo crecen el SELECT; un Lead con UTM nuevos pero sin ninguno de
los cinco criterios legacy continúa fuera del universo.

#### Corrección de coherencia UTM (2026-09-01)

La resolución UTM de una fila general existente se calcula después de aplicar
la política incoming no vacío → incoming; incoming vacío → valor existente. La
misma consulta masiva por chunk obtiene raw y `field_resolution`; no existe una
segunda lectura ni una query por Lead. Los cinco pares se recalculan mediante
`SalesforceLeadFieldResolver` y se mezclan sobre el JSON general existente.

Un JSON nulo, parcial o inválido no detiene el sync: se interpreta como una
estructura vacía cuando no es recuperable y se crean solo los cinco nodos UTM.
Raw y resolución se escriben juntos dentro de la transacción corta del chunk.
La prueba de caracterización cubre valores nuevos, preservados por whitespace,
fallbacks legacy, conflictos, cinco dimensiones mezcladas, JSON nulo/parcial/
inválido, `raw_payload`, campos generales e idempotencia.

## Fase 6: atribución efectiva de Campañas (2026-09-03)

El universo permanece gobernado por el WHERE y el gate PHP legacy. Solo después
de admitir un Lead, el builder resuelve de forma independiente `utm_campaign`,
`utm_id`, `utm_source`, `utm_medium` y `utm_content` mediante
`SalesforceLeadFieldResolver`; null, vacío o whitespace activan la pareja legacy
y cualquier otro valor nuevo bloquea ese fallback.

`source_acquired` y `medium_acquired` representan ahora correctamente
`utm_source__c`/`Fuente_Adquirida__c` y
`utm_medium__c`/`Medio_Adquirido__c`. `matched_source_field` conserva el API Name
ganador en los cruces por ID, contenido o nombre. Los diagnósticos agregan
`field_resolution_sources` por dimensión sin PII. La regla queda versionada como
`2026-09-03.1`.

La excepción Meta Direct Form sigue admitiendo exclusivamente por
`Portal_Text__c = Meta` y `LEA_SEL_Fuente_Origen__c = Facebook`; una vez dentro,
su clasificación efectiva usa `Fuente_origen__c` con fallback a ese origen
legacy. No hubo migración, backfill ni acceso real a Salesforce, Google o Meta.

## Fase 7A: herramienta controlada de backfill histórico (2026-09-03)

Se incorpora `salesforce:backfill-lead-attribution-fields` para consultar en
Salesforce, únicamente en lectura y por lotes, los IDs que ya existen en
`salesforce_leads` o `campaign_salesforce_leads`. El rango local
`[--from, --to)`, el modo exclusivo `--dry-run`/`--apply`, el motivo obligatorio
de escritura, `--limit` y el cursor `--after-salesforce-id` hacen la operación
acotada y reanudable. La consulta no incluye nombre, email ni teléfonos.

La escritura usa `UPDATE` sobre filas existentes, nunca `upsert` ni insert. Solo
materializa los once raw nuevos/adquiridos aprobados, `field_resolution` y, en
la tabla general, `resolved_channel`, `resolved_portal` y su campo ganador. Los
fallbacks proceden de los legacy locales y se resuelven con
`LeadClassificationResolver`/`SalesforceLeadFieldResolver`; no se refresca el
resto del histórico. `raw_payload` se fusiona únicamente con las claves
consultadas y conserva el resto.

Cada lote aplicado guarda before/after en
`salesforce_lead_attribution_backfill_history` dentro de la misma transacción.
El histórico solo contiene ID técnico, tabla, motivo, campos cambiados y sus
valores aprobados; para `raw_payload` registra exclusivamente el subconjunto de
atribución, nunca el payload completo. Las métricas incluyen conflictos,
fallbacks, placeholders, ausentes en Salesforce, cambios por tabla/campo,
cursor, duración, memoria y Leads UTM-only. Esta última medida es informativa y
no crea filas de Campañas.

La herramienta está preparada, pero el histórico todavía **NO ha sido
modificado**. No se ejecutaron backfill, `--apply`, sincronizadores reales ni
escrituras Salesforce, y no se reprocesaron Calls, Opportunities o Campañas.

#### Consistencia de identidad y concurrencia del backfill

La conciliación trata un ID de 15 caracteres y su representación de 18 como la
misma identidad mediante los primeros 15 caracteres, sin cambiar el casing ni
reescribir los IDs locales. El cursor continúa avanzando sobre la clave local
literal, por lo que ambas representaciones pueden coexistir sin perder filas.

Salesforce se consulta antes de la transacción. En apply, cada lote relee y
bloquea las filas locales en orden por `salesforce_id`; candidato,
`raw_payload`, resolución e histórico before/after se calculan después de ese
bloqueo. Un lock atómico de caché de seis horas impide dos apply concurrentes y
se libera siempre; dry-run no lo adquiere. No se ha ejecutado el backfill real
ni se ha realizado ninguna escritura Salesforce.

## Fase 7B: reproceso controlado de atribución de Opportunities (2026-09-03)

El comando existente `reports:reprocess-opportunity-portals` requiere ahora
rango local `[--from, --to)`, modo explícito, motivo para apply y admite
`--limit`/`--after-id`. Su universo son exclusivamente Opportunities locales ya
persistidas. Mantiene la resolución de Fase 5 y solo consulta Leads Salesforce
en lectura mediante el matching agrupado existente; las consultas Opportunity
y las escrituras Salesforce son cero.

Dry-run ejecuta la resolución sin UPDATE, histórico, cambio de `updated_at` ni
invalidación de caché. Apply usa mutex de seis horas, chunks de 100 y transacción
por lote. La consulta remota ocurre antes de la transacción; luego las filas se
releen ordenadas bajo `lockForUpdate()` y se compara una huella de portal,
fuente raw y datos locales de matching. Si cambia, se reconsulta Lead y se
reintenta hasta tres veces.

Solo pueden cambiar los seis campos de atribución aprobados. El before/after se
guarda atómicamente en `salesforce_opportunity_portal_reprocess_history` con
debug filtrado por whitelist, sin nombre, teléfono, email ni payload completo.
Un fallo detiene el chunk y conserva como cursor el último lote confirmado; si
ya hubo commits, la caché se invalida exactamente una vez. La herramienta está
preparada, pero no se ha ejecutado el histórico ni un dry-run productivo.

## Invariancia del matching Opportunity → Lead (2026-09-04)

La consulta agrupada de Reservas/Ventas ya no depende de los teléfonos brutos
aportados por otras Opportunities del lote. La clave numérica canónica vigente
se materializa en `salesforce_leads.phone_normalized` y
`mobile_phone_normalized`, con índices exactos individuales. Esa fotografía
local solo descubre IDs activos; el servicio vuelve a consultar los candidatos
por `Lead.Id` en Salesforce y la aceptación final conserva la igualdad
telefónica normalizada contra los datos vivos. Los emails sin respuesta remota
recurren individualmente al fallback local. Los teléfonos no usan `leads_raw`.

Para Leads recientes todavía ausentes del índice se mantiene únicamente como
fallback una búsqueda Salesforce agrupada mediante las siete variantes exactas
existentes. No se amplían formatos manualmente ni se usa `LIKE`. El sync mensual
y las inserciones generales del sync de Campañas mantienen las dos claves. El
histórico se materializa mediante el comando local, idempotente y chunked
`salesforce:backfill-lead-phone-normalization`; no se ejecuta desde migraciones
ni scheduler.

La prioridad continúa siendo Opportunity conclusiva → Lead → fuente de
Opportunity → Exposición → Web → Sin clasificar. Dentro del Lead se conserva
`Fuente_origen__c` → fallback legacy. El desempate entre Leads con el mismo
`CreatedDate` es `Lead.Id` ascendente. No cambian universo, conteos, campos raw
ni escrituras Salesforce.

## Lifecycle de Opportunities eliminadas o ausentes (2026-09-07)

`salesforce_opportunities` conserva físicamente su histórico y añade
`is_deleted`, `salesforce_deleted_at` y `deletion_detection_source`. El sync
canónico consulta por `queryAll` los `IsDeleted=true` modificados en la ventana,
usando `SystemModstamp`, y marca solo filas locales ya existentes. Únicamente el
mapper completo del sync canónico reactiva/limpia metadata cuando vuelve a
recibir una Opportunity activa. La fuente `query_all_deleted` representa un
borrado confirmado; `presence_reconciliation_missing` representa únicamente
ausencia en una comprobación explícita por ID y no presupone borrado, fusión,
purga ni pérdida de permisos, por lo que no sale del scope reportable.

Los lectores Eloquent aplican un scope activo central. Las consultas SQL
directas de Campañas filtran la misma metadata, y las transiciones de
Opportunity eliminadas se excluyen de Rendimiento y su auditoría. No cambia la
resolución Opportunity → Lead ni ningún campo de atribución.

Campañas conserva siempre la entidad Lead y sus dimensiones. Cuando la
Opportunity enlazada tiene borrado confirmado, solo se anulan oportunidad,
reserva, venta, compra e importes derivados. Stock carga Opportunities sin el
scope para distinguir ausencia local de borrado confirmado e invalida el
snapshot solo ante borrado confirmado. Si la Opportunity no existe en la réplica
local, conserva el estado previo del snapshot y lo contabiliza como `unchecked`;
el sync parcial de Stock no limpia metadata lifecycle ni reactiva filas.

La reconciliación histórica se prepara con
`salesforce:reconcile-opportunity-presence --dry-run`, en lotes de 100 y con
cursor local. Apply exige motivo, mutex y auditoría mínima por ejecución; no
inserta ni borra Opportunities y no escribe Salesforce. La reconciliación
completa de presencia ya se ejecutó en producción el 8 de septiembre de 2026.
Un apply que confirme borrados ejecuta después la
reconciliación local de validez de Stock, sin sincronizaciones ni snapshots
nuevos. La misma operación puede reintentarse de forma explícita con
`stock:reconcile-sale-validity` si falla después de persistir lifecycle.
`SystemModstamp` se almacena solo como evidencia técnica en
`salesforce_deleted_at`; nunca sobrescribe `salesforce_last_modified_at`.

## Cierre formal del refactor Salesforce (2026-09-08)

El refactor técnico de procedencia, canal, medio, delegación, UTM y atribución
de Opportunities queda cerrado, desplegado y validado. Las Fases 3–6 mantienen
la autoridad nuevo → legacy ya aprobada, con resolución independiente por campo
y placeholders no vacíos autoritativos. No se alteraron los universos
funcionales: cada informe conserva sus filtros y prioridades legacy, salvo las
exclusiones de lifecycle expresamente diseñadas para registros confirmados como
eliminados.

El bloque de lifecycle/presencia se desplegó mediante PR #38, merge
`8231929cbda7427de40a163ef79e98f1e94c4be1`. La validación productiva completa
encontró 40.718 Opportunities activas, tres borrados confirmados por
`query_all_deleted` y tres ausencias diagnósticas
`presence_reconciliation_missing`. El primer apply cambió seis filas y la
repetición en dry-run quedó en cero cambios pendientes.

PR #40, merge `fe758b0dd7623acff35eb051b17b2b5072ec70b2`, corrigió el
casing del API Name real `SystemModstamp`. El apply posterior materializó la
fecha técnica en los tres borrados confirmados y el dry-run final examinó
40.724 filas, con cero cambios, cero errores, cero concurrencias omitidas y cero
reactivaciones pendientes. Stock terminó conciliado con 13.625 snapshots
válidos, 326 inválidos, 66 duplicados y cero `unchecked`.

`SystemModstamp` se interpreta exclusivamente como timestamp técnico de la
modificación detectada y se guarda en `salesforce_deleted_at`; no es una fecha
funcional contractual de borrado ni sustituye a `LastModifiedDate`. La prueba
sintética en sandbox no pudo ejecutarse, pero la evidencia productiva confirmó
`queryAll`, el timestamp real, su persistencia y la idempotencia final.

Este cierre técnico no acredita operaciones históricas distintas. La
herramienta de Fase 7A está terminada, pero no existe evidencia de que se haya
ejecutado el backfill histórico de Leads. La herramienta de Fase 7B también está
terminada, pero no existe evidencia de dry-run o reproceso histórico de portales
de Opportunities. Ambas permanecen como operaciones controladas pendientes, no
como deuda de implementación.

# Informe de Campañas

Actualizado: 2026-10-09. URL: `/informes/campanas`.

## Contrato funcional ROT-4

La entidad CRM de adquisición es `Interes__c`, replicada localmente en
`salesforce_interests`. El informe solo incluye Interests activos del período
solicitado; usa `functional_created_at` persistido en UTC y convierte los
límites de negocio `Europe/Madrid` a UTC antes de consultar.

Google Ads y Meta Ads siguen aportando inversión, impresiones, clics y métricas
diarias. Opportunity continúa aportando reservas, ventas, compras e importes.
No se consulta Salesforce durante una petición HTTP ni durante el builder.

Los campos de adquisición proceden exclusivamente del Interest:

- `utm_campaign`, `utm_id`, `utm_source`, `utm_medium`, `utm_content` y
  `utm_term`;
- `source`, `original_source`, `medium` y `channel`;
- `status`, `type`, `origin_delegation` y owner actual;
- `sale_vehicle_salesforce_id` para Venta y
  `appraisal_vehicle_salesforce_id` para Tasación.

No existe fallback a Lead, Account o Contact para completar dimensiones del
Interest. Tampoco existe matching por nombre, email, teléfono o cualquier otra
PII.

## Persistencia y compatibilidad

Las tablas `campaign_attributions` y `campaign_lead_attributions` mantienen sus
nombres históricos, pero las nuevas filas ROT-4 usan columnas Interest
explícitas: identidad, fecha funcional, status, tipo, procedencia, owner,
lifecycle, run/cutoff F2 y estado de relación Opportunity. Las columnas Lead
legacy permanecen `NULL`; nunca almacenan un Interest ID disfrazado de Lead.

`leads_salesforce`, `lead_type`, `lead_status` y otras claves técnicas heredadas
se conservan temporalmente como aliases de compatibilidad. Su semántica ROT-4
es Interest. Las superficies visibles y los CSV muestran Interés/Intereses.

`campaign_salesforce_leads`, `CampaignLeadSyncService` y el comando
`salesforce:sync-campaign-leads` se conservan únicamente como infraestructura
legacy/de rollback. Ya no participan en el pipeline funcional ni están
programados.

## Matching de campañas

Se preservan los métodos auditables:

- `ad_id_match`;
- `adset_or_adgroup_id_match`;
- `campaign_id_match`;
- `campaign_name_exact_match`;
- `campaign_name_flexible_match`;
- `salesforce_only`;
- exclusión y ambigüedad.

La traza registra el campo Interest real ganador, su valor, candidatos, método,
confianza y versión de reglas. Las procedencias Interest sin UTM pueden quedar
como `salesforce_origin`: son evidencia diagnóstica, no una campaña de pago ni
un coste ficticio.

Meta Instant Forms solo se identifica cuando el nombre de campaña aporta la
evidencia explícita `instantforms`/Formulario Directo Meta. La antigua
inferencia Lead `Portal_Text__c = Meta` + Facebook no tiene equivalente
Interest demostrado y no se reproduce mediante una heurística nueva.

## Interest ↔ Opportunity y first touch

La relación CRM exacta reutiliza `OpportunityInterestAttributionService`. Solo
`both_match` autoriza la asociación exacta. `direct_only`, `inverse_only`,
`contradiction`, `inverse_shared`, `unresolved` y `no_reference` nunca eligen
un Interest por sí solos.

Cuando no existe relación exacta, el first touch histórico por Account se
mantiene separado:

1. compara únicamente `Interest.account_salesforce_id` con
   `Opportunity.account_id`;
2. aplica la precedencia de calidad de campaña existente;
3. ordena por `functional_created_at`;
4. deja ambiguos los candidatos incompatibles de igual precedencia;
5. persiste un método `account_first_touch`/equivalente y confianza inferior a
   la relación exacta.

Esta heurística no se presenta como relación CRM bidireccional y no utiliza PII.

## Estado, tipo, owner y lifecycle

- Estado visible: `SalesforceInterest.status`.
- Tipo visible: `SalesforceInterest.type`, normalizado mediante el contrato
  compartido de informes Interest.
- Comercial: owner actual del Interest en el último F2
  (`current_interest_owner_at_last_sync`), no owner histórico.
- Procedencia: `origin_delegation`, sin fallback Lead.
- Lifecycle: Interests eliminados quedan fuera del KPI activo.

`Tipo de campaña` y `Tipo del Interest` son filtros distintos. La clasificación
de campaña no reescribe el tipo CRM.

## Snapshot, caché y fallo seguro

El builder captura el último run F2 de `salesforce_interests/salesforce`, exige
`completed` y cutoff, y lo revalida antes de publicar. También captura y valida
el contexto Opportunity↔Interest directo/inverso. Si cualquiera cambia durante
la construcción, la transacción se revierte y no se mezcla evidencia.

La caché incluye IDs, estados, cutoffs, disponibilidad y razón del contexto de
atribución. Una misma fila que pasa `running → completed` produce una identidad
de caché distinta.

El procesamiento usa cursor PK y lotes; los lookups Opportunity e Interest son
bulk, sin N+1 ni funciones SQL sobre IDs Salesforce indexados.

## Métricas y auditoría

Interests, Opportunities y resultados se deduplican como entidades distintas.
Salesforce-only conserva trazabilidad sin presentar costes inexistentes como
cero. Las auditorías JSON/CSV publican identidad Interest, fecha funcional,
status, tipo, source/medium/UTM, campaña, método, confianza, candidatos,
Opportunity, relación, owner actual, lifecycle y F2; no incluyen PII.

Endpoints:

- JSON KPI: `/informes/campanas/data/kpi-audit`;
- CSV KPI: `/informes/campanas/export/kpi-audit.csv`;
- CSV campañas: `/informes/campanas/export/campaigns.csv`;
- CSV atribuciones: `/informes/campanas/export/attributions.csv`.

## Operación

El scheduler usa `Europe/Madrid`:

- Meta: 01:30;
- Google: 01:45;
- Opportunity legacy: 07:10;
- snapshot directo Opportunity→Interest: 07:35;
- atribución Campañas: 08:00;
- snapshot del informe: 08:15;
- F2/F5 canónicos continúan en su pipeline horario existente.

Comandos locales del pipeline ROT-4:

```bash
php artisan campaigns:sync-meta --days=120
php artisan campaigns:sync-google --days=120
php -d memory_limit=512M artisan campaigns:build-attribution --days=120
php artisan reports:refresh-campaigns --days=120 --store
```

`campaigns:build-attribution --dry-run` reutiliza el builder real, compara por
`interest_id` y no escribe ni invalida caché. Antes de habilitar ROT-4 en un
entorno debe aplicarse la migración aditiva, disponer de un F2 completo/estable,
un snapshot directo coherente y reconstruir el período bajo procedimiento
operacional revisado. Esta implementación no ejecuta backfill ni despliegue.

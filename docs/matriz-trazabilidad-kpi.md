# Matriz de trazabilidad de KPIs

Estado: **estructura pendiente de auditoría inicial**. Esta entrega no certifica
ningún KPI ni traslada reglas históricas sin comprobarlas con código, pruebas y
fuentes. Las fichas se incorporarán en una tarea posterior, una por KPI y
siempre asociadas a una pestaña o sección real.

## Contrato de ficha KPI

Cada ficha verificada debe contener estos campos, con referencias concretas y
valor `No aplica` justificado cuando corresponda:

1. Informe.
2. Pestaña o sección real.
3. Identificador estable del KPI.
4. Nombre visible del KPI.
5. Descripción y significado.
6. Fuente externa.
7. Objeto y campo API de Salesforce, si aplica.
8. Tabla y columna local, si aplica.
9. Sincronizador responsable.
10. Fecha pivote y zona horaria.
11. Universo de registros.
12. Filtros e inclusiones.
13. Exclusiones.
14. Deduplicación y clave de identidad.
15. Fórmula exacta explicada, sin copiar el archivo PHP.
16. Redondeos, umbrales y coeficientes.
17. Tratamiento de nulos y división por cero.
18. Configuración y vigencia de parámetros.
19. Archivo y método PHP responsables.
20. Tests que validan el cálculo.
21. Exportación o auditoría disponible.
22. Estado de verificación: pendiente, verificado o discrepancia.
23. Commit de referencia comprobado.

Un cambio funcional que altere KPI, fuente, universo, filtro, fórmula o
trazabilidad debe actualizar el documento del informe y esta matriz en el mismo
diff. Si todavía no existe ficha verificada, se registra la evidencia y el
impacto como pendiente; no se crea una ficha de 23 campos por conjetura. Si el
cambio documentado no afecta a ningún KPI, la descripción de la PR declara
`Docs-Matrix-Impact: unchanged` con razón técnica y ruta de evidencia revisable.
La matriz indexa la evidencia y no crea una segunda especificación
contradictoria. Una discrepancia conserva referencias a las dos evidencias y
queda pendiente de decisión humana.

## Índice de informes y secciones pendientes

### Intereses (ruta histórica Leads)

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [reglas vigentes de Intereses](reglas-negocio-leads.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Reservas / Ventas

Auditoría inicial pendiente. Pestañas y KPIs concretos por verificar contra la
interfaz actual. Referencias: [informe de Reservas / Ventas](informe-reservas-ventas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Llamadas

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Llamadas](informe-llamadas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Campañas

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Campañas](informe-campanas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

#### Registro focalizado: Detalle y Campañas a revisar

Base contrastada: 01d3aa8ad428ad413f184741d9a4f2314696f1fe más diff Fase A.

| Contrato afectado | Evidencia implementada | Impacto y estado de auditoría |
| --- | --- | --- |
| Universo del detalle | advertisingEligibility()/advertisingDetailRows(), resources/js/reports/campaign-presentation.js; renderCampaignTables(), campaigns-dashboard.js | Meta/Google, sin procedencias Salesforce sin gasto atribuible y spend finito > 0; solo presentación. No cambia API/CSV/atribución. Auditada la exclusión visual sintética; trazabilidad completa de adquisición pendiente. |
| Coste por compra | CampaignDashboardDatasetService::withRatios()/divide(); cost_per_purchase = spend / purchases | Fórmula existente intacta; denominador cero o null produce null; divide redondea a cuatro decimales. Solo selección y presentación monetaria cambian. Auditoría integral de compras/origen/fecha/deduplicación pendiente. |
| Resultados y coste por resultado | resultCountForRow()/costPerResultForRow(); columnDefinitions y columnAllowedInContext() JS | Se retiran solo del detalle/selector; permanecen fórmulas funcionales y datos de API/CSV y otros bloques. No equivale a compras. |
| Benchmark y top 8 de Campañas a revisar | CampaignDashboardDatasetService::reviewCampaigns() | Elegibilidad publicitaria antes de medias por tipo; promedio de costes por resultado positivos no nulos, cuatro decimales; Coste alto > 2 × media. Mismos motivos y prioridad; universo cambia solo aquí. |
| Estado publicitario | historicalStatusPresentation(); MetaCampaignSyncService, GoogleCampaignSyncService y clientes | Sigue histórico, no remoto vigente. Fase B pendiente de aprobación, sin nueva fuente implementada. |

##### Fuentes contrastadas y límites de esta revisión

MetaAdsClient consulta insights y campañas; MetaCampaignSyncService persiste
metric_date, spend, campaign_status y campaign_effective_status en métricas
diarias. GoogleAdsClient consulta campaign.status con segmentos/métricas;
GoogleCampaignSyncService persiste metric_date y campaign_status. El dataset
usa el período solicitado y snapshots aprobados cuando proceden; no se cambian
fechas pivote, zonas, identidades, reglas de atribución, first touch ni deduplicación.
La fuente CRM canónica documentada es Interest (aliases técnicos leads_salesforce
no significan Lead); no se ha hecho una auditoría nueva de los campos API CRM
ni se ha consultado Salesforce. La clasificación funcional no se deduce aquí del
nombre. Configuración y sincronizaciones permanecen intactas.

El costo visible conserva el valor backend; Intl.NumberFormat es-ES/EUR muestra
dos decimales y los inválidos del detalle usan — con motivo. No se convierte null
en cero. El conjunto recibido no se muta. exportRows() y los CSV conservan filas
ocultas del detalle. Pruebas: CampaignDashboardTest (integridad cerrada y
exportación, denominadores aprobados, benchmarks/top 8 publicitarios) y
campaign-presentation.test.mjs (elegibilidad, formato y migración de columnas).

Pendiente: fichas completas por KPI (fuente/campo API, tabla/columna, fecha
pivote, universo/deduplicación y configuración de extremo a extremo) y auditoría
de otros informes, fuera del alcance. Este registro no certifica esos contratos.
La propuesta de estado actual se detalla en
[CAMPAIGN_CURRENT_STATUS_PROPOSAL](ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md).
Reglas funcionales vigentes: [Informe de Campañas](informe-campanas.md).


### Comisiones

Auditoría inicial pendiente en cada una de las seis pestañas reales:

| Pestaña | Estado | Referencia funcional |
| --- | --- | --- |
| Comerciales | Pendiente | [Comisiones](informe-comisiones.md) y [cálculo comercial](Calculo_comisiones_comerciales.txt) |
| Delegaciones | Pendiente | [Comisiones](informe-comisiones.md) |
| Call Center | Pendiente | [Comisiones](informe-comisiones.md) |
| Contact Center | Pendiente | [Comisiones](informe-comisiones.md) |
| Área Manager | Pendiente | [Comisiones](informe-comisiones.md) |
| Financieros | Pendiente | [Comisiones](informe-comisiones.md) y [guía financiera](informe-comisiones-financieras.md) |

### Stock

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Stock](informe-stock.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

## Fichas verificadas

Ninguna todavía. La auditoría inicial de los seis informes es una tarea
posterior independiente. No se deben promover fichas a `verificado` sin
comprobar implementación, fuentes y tests.

## Decisiones y discrepancias

Consultar [decisiones de negocio pendientes](decisiones-negocio-pendientes.md).
Las discrepancias nuevas se registrarán aquí con evidencia y responsable de
resolución, sin alterar retrospectivamente reglas históricas.

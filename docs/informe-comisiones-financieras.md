# Guia de revision de comisiones financieras

## Alcance

La pestana `Financieros` calcula comisiones mensuales desde la replica local de
`Opportunity`. Salesforce es la fuente de verdad y la pantalla no realiza
llamadas remotas durante el render.

```text
/informes/comisiones-comerciales?tab=financials&month=YYYY-MM
```

El acceso sigue protegido en servidor por los permisos de Comisiones. El
diagnostico interno solo se muestra a Administrador/IT.

Un usuario con rol `commission_auditor` que sea el aprobador activo asignado al
scope `financials` puede revisar en la superficie de Auditoria el detalle
financiero completo y de solo lectura cuando selecciona ese scope: indicadores,
resumen por responsable, detalle por delegacion y trazabilidad por Opportunity.
La asignacion se comprueba en servidor y falla cerrada si pertenece a otro
usuario, esta inactiva, eliminada o es incoherente. No concede otros scopes,
configuracion, reapertura, acciones administrativas ni exportaciones. La vista
compartida fuerza la ocultacion del diagnostico y conciliacion internos.

La fuente visible coincide con la que se aprueba: snapshot candidato en
`pending_approval`, snapshot definitivo en `definitive` y calculo live en
`provisional` o `reopened`. La proyeccion reutiliza ese dashboard y no ejecuta
una segunda formula ni mezcla importes vivos con el snapshot persistido.

## Universo mensual

Una Opportunity entra en el universo si cumple:

1. `Fecha_firma_contrato__c >= primer dia del mes`.
2. `Fecha_firma_contrato__c < primer dia del mes siguiente`.
3. `StageName != Cerrada perdida`, sin distinguir mayusculas.
4. `Tipo_de_registro_oportunidad__c` es `Venta` o `Cambio`.
5. Si la formula de tipo es nula o vacia, se usa `RecordType.Name` como fallback.

No se mezclan `CreatedDate`, `CloseDate` ni otras fechas con la fecha de firma.
`General`, `Sin Zona` y zonas no reconocidas permanecen en el diagnostico del
universo, pero no se asignan a un responsable.

Consulta de contraste para julio de 2026:

```sql
SELECT
    Id, Name, StageName, RecordType.Name, OwnerId, Owner.Name,
    Owner.USR_SEL_Delegacion__c, Delegacion_del_propietario__c,
    Fecha_firma_contrato__c, Tipo_de_registro_oportunidad__c,
    zona_financiera__c, OPO_FOR_Importe_total__c,
    Importe_financiado__c, Comisi_n_Financiera__c,
    OPO_DIV_Descuento_financiera__c, Garant_a_Total__c,
    Inter_s_elegido__c
FROM Opportunity
WHERE IsDeleted = FALSE
  AND Fecha_firma_contrato__c >= 2026-07-01
  AND Fecha_firma_contrato__c < 2026-08-01
ORDER BY Id
```

Los filtros de tipo y etapa se aplican como se describe arriba. La metadata y la
lista interna de IDs del informe Salesforce de referencia no estuvieron
accesibles. La SOQL de contraste devolvio 680 IDs y sus sumatorios coinciden
exactamente con los publicados por el report de julio. No se anadio ningun filtro
para forzar esa coincidencia ni se afirma que los IDs internos del report se
hayan exportado.

## Campos materializados

| Concepto | Salesforce | Columna local |
|---|---|---|
| ID auditable | `Id` | `salesforce_id` |
| Fecha del periodo | `Fecha_firma_contrato__c` | `cv_signed_date` |
| Tipo formula | `Tipo_de_registro_oportunidad__c` | `opportunity_record_type_formula` |
| Tipo fallback | `RecordType.Name` | `record_type_name` |
| Etapa | `StageName` | `stage_name` |
| Delegacion | `Owner.USR_SEL_Delegacion__c` | `owner_delegation` |
| Delegacion reportada | `Delegacion_del_propietario__c` | `report_owner_delegation` |
| Zona financiera | `zona_financiera__c` | `financial_zone` |
| Importe total | `OPO_FOR_Importe_total__c` | `opo_for_importe_total` |
| Importe financiado | `Importe_financiado__c` | `importe_financiado` |
| Comision financiera | `Comisi_n_Financiera__c` | `financial_commission` |
| Descuento financiero | `OPO_DIV_Descuento_financiera__c` | `financial_discount` |
| Garantia | `Garant_a_Total__c` | `garantia_total` |
| Tipo de interes | `Inter_s_elegido__c` | `interest_rate` |

`financial_commission` se persiste sin sustituirla por beneficio comercial ni
por una formula derivada. Los nulos economicos se calculan como cero.

## Sincronizacion

```bash
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01 -vvv
```

No usar `--fresh`, `--all-history` ni una sincronizacion global para esta
conciliacion. El sincronizador materializa los datos en
`salesforce_opportunities`.

`Account.AC_C_EMA_email__c` es opcional para otros informes. Algunas
organizaciones Salesforce no lo exponen. Si Salesforce rechaza la primera SOQL,
el sincronizador reintenta una sola vez sin ese campo. Si el segundo intento
falla, propaga el error. Este fallback no elimina ningun campo financiero ni
modifica los filtros.

## Responsable y delegacion

La resolucion funcional es:

1. Normalizar `zona_financiera__c`.
2. Si esta vacia, resolver zona desde la delegacion del propietario.
3. Traducir la zona a una clave tecnica estable de responsable.
4. Mantener la delegacion normalizada como segundo nivel de agregacion.

| Clave | Responsable | Zona |
|---|---|---|
| `zona_carlos` | Carlos | Zona Carlos |
| `zona_cristina` | Cristina | Zona Cristina |
| `zona_irene` | Irene Simon | Zona Irene |
| `zona_nuria` | Nuria Moracho | Zona Nuria |

`Opportunity.OwnerId` no identifica al responsable financiero: identifica al
comercial propietario. Por ello no participa en la seleccion de la regla de
comision. Distintos owners de Alicante y Paterna se agregan bajo `zona_irene`;
Sedavi y Castellon se agregan bajo `zona_nuria`.

Fallback de delegaciones vigente:

- Cristina: Bilbao, Fontellas, Gijon, Pamplona, San Sebastian, Zaragoza,
  A Coruna, Valladolid, Badalona, Manresa, Girona, Lleida, Sant Boi,
  Llica de Valls, Barcelona, Elche, Alcoy y Villareal.
- Nuria: Sedavi y Castellon.
- Carlos: Alcala de Guadaira, Badajoz, Malaga, Malaga Centro, Palma, Sevilla,
  Torrejon de Ardoz, Rivas, Call Rivas, Alcobendas, Collado Villalba, Valencia,
  Murcia y Dos Hermanas.
- Irene: Alicante y Paterna.

No se asigna una zona desconocida por aproximacion.

Para el detalle por delegacion se usa primero
`Owner.USR_SEL_Delegacion__c`. Solo si ese valor esta vacio se usa
`Delegacion_del_propietario__c`. Esta prioridad es la implementacion vigente y
no se cambio durante la auditoria. Como no se obtuvo una exportacion ni la
metadata interna del report, no se afirma que esta agrupacion sea identica a la
dimension de delegacion usada por el report; debe contrastarse con una
exportacion real si se necesita certificar esa equivalencia.

Una zona explicita distinta de las cuatro conocidas no usa el fallback de
delegacion y nunca se asigna automaticamente. Se muestra en el diagnostico
administrativo con sus IDs e importes. Si contiene comision o descuento
financiero, `ready=false` bloquea cierres/exportaciones hasta resolverla.
`General` y `Sin Zona` siguen excluidas por diseno y no se consideran zonas
desconocidas.

## Formulas

### Carlos y Cristina

Conservan los tres bloques configurables del periodo:

```text
porcentaje financiado = importe financiado / importe total
comision neta = comision financiera - descuento financiero
bloque 1 = comision neta * incentivo financiado
```

Para el bloque 2 solo entran operaciones con interes informado y distinto de
`3,99%`, `4,99%` y `5,99%`:

```text
beneficio valido = comision financiera valida - descuento financiero valido
rentabilidad = beneficio valido / importe financiado valido
bloque 2 = beneficio valido * incentivo de rentabilidad
```

El interes solo afecta al bloque 2. La operacion continua en los bloques 1 y 3.

```text
porcentaje garantia = garantia / importe financiado
bloque 3 = garantia * incentivo de garantia
total = bloque 1 + bloque 2 + bloque 3
```

Los tramos se leen de la configuracion efectiva del mes. No se modificaron en
esta auditoria.

### Irene y Nuria

Desde junio de 2026 su regla sustituye completamente los tres bloques:

```text
comision neta = comision financiera - descuento financiero
total = comision neta * 0.005
```

`0.005` equivale a `0,50%`, no a `50%`. Los bloques 1, 2 y 3 quedan en cero y no
se suman al total especial. La configuracion usa las claves tecnicas de zona,
no nombres visibles ni Salesforce User IDs.

## Payload y UI

El servicio construye una sola coleccion y deriva de ella:

- `summary_rows`: total por responsable;
- `delegation_rows`: total por responsable y delegacion;
- `detail_rows`: Opportunities incluidas con su `Opportunity.Id`;
- `diagnostics`: universo, incluidos, excluidos y sumas economicas.

La UI separa Carlos/Cristina de Irene/Nuria. La tabla por delegacion permite
contrastar agregados y el detalle por Opportunity permanece plegado. El Blade
solo presenta valores ya calculados.

Por redondeo a centimos, la ultima delegacion de un responsable puede recibir un
ajuste maximo de centimos para garantizar:

```text
total responsable = suma de sus delegaciones
```

La suma de comision financiera no requiere ajuste y se conserva exactamente.
Cuando existe, `rounding_adjustment` se muestra en la tabla por delegacion y se
cuenta en el diagnostico administrativo; no modifica las bases economicas.

## Conciliacion de julio de 2026

Tras la sincronizacion acotada, la comparacion entre la SOQL de contraste y la
replica local por `Opportunity.Id` dio:

- SOQL de contraste Salesforce: 680 IDs.
- Replica local: los mismos 680 IDs.
- IDs ausentes localmente: 0.
- IDs extra localmente: 0.
- diferencias en `Comisi_n_Financiera__c`: 0.
- diferencias en `OPO_DIV_Descuento_financiera__c`: 0.

Los sumatorios coinciden exactamente con el report de referencia. No se pudo
verificar directamente su metadata ni exportar su lista interna de IDs.

| Metrica | Resultado |
|---|---:|
| Operaciones | 680 |
| Importe total | 12.085.921,06 EUR |
| Importe financiado | 5.064.691,00 EUR |
| Comision financiera | 718.638,40 EUR |
| Descuento financiero | 27.086,00 EUR |
| Garantia | 243.080,00 EUR |

Hay 29 operaciones `Sin Zona/General`. Se conservan en diagnostico y quedan fuera
de responsables. Su comision y descuento financiero suman cero, por lo que los
totales economicos incluidos siguen siendo los del universo.

| Responsable | Ops | Comision financiera | Descuento | Total final |
|---|---:|---:|---:|---:|
| Carlos | 259 | 318.593,94 | 5.177,00 | 4.791,84 |
| Cristina | 313 | 290.201,96 | 12.409,00 | 1.350,77 |
| Irene Simon | 54 | 79.035,84 | 8.500,00 | 352,68 |
| Nuria Moracho | 25 | 30.806,66 | 1.000,00 | 149,03 |

Irene se compone de Alicante (32) y Paterna (22). Nuria se compone de Castellon
(4) y Sedavi (21). La suma global final es `6.644,32 EUR` y coincide entre
responsables y delegaciones.

Detalle conciliado por delegacion (`Ops / comision financiera / descuento /
comision final`):

| Responsable | Delegacion | Ops | Comision financiera | Descuento | Comision final |
|---|---|---:|---:|---:|---:|
| Carlos | Alcala de Guadaira | 21 | 40.261,39 | 131,00 | 604,11 |
| Carlos | Alcobendas | 27 | 25.511,23 | 0,00 | 389,97 |
| Carlos | Collado Villalba | 10 | 19.786,39 | 0,00 | 292,80 |
| Carlos | Dos Hermanas | 3 | 2.850,00 | 0,00 | 43,69 |
| Carlos | Malaga | 22 | 21.645,12 | 0,00 | 343,85 |
| Carlos | Malaga Centro | 7 | 6.590,61 | 0,00 | 106,18 |
| Carlos | Murcia | 21 | 26.108,58 | 560,00 | 388,16 |
| Carlos | Palma | 33 | 38.848,00 | 0,00 | 616,80 |
| Carlos | Rivas | 27 | 33.170,00 | 3.430,00 | 445,93 |
| Carlos | Sevilla | 35 | 48.306,58 | 0,00 | 738,81 |
| Carlos | Torrejon de Ardoz | 32 | 36.342,34 | 166,00 | 546,97 |
| Carlos | Valencia | 21 | 19.173,70 | 890,00 | 274,57 |
| Cristina | A Coruna | 25 | 12.243,98 | 1.800,00 | 43,95 |
| Cristina | Alcoy | 13 | 15.280,17 | 0,00 | 76,40 |
| Cristina | Badalona | 7 | 7.262,86 | 0,00 | 36,31 |
| Cristina | Bilbao | 31 | 19.936,18 | 3.190,00 | 74,90 |
| Cristina | Elche | 8 | 8.653,94 | 0,00 | 41,59 |
| Cristina | Fontellas | 25 | 18.293,61 | 1.301,00 | 76,97 |
| Cristina | Gijon | 18 | 17.167,25 | 0,00 | 85,84 |
| Cristina | Girona | 3 | 1.467,20 | 0,00 | 7,34 |
| Cristina | Lleida | 10 | 11.340,60 | 0,00 | 56,70 |
| Cristina | Llica de Valls | 11 | 9.506,77 | 900,00 | 43,04 |
| Cristina | Manresa | 15 | 10.283,17 | 0,00 | 51,41 |
| Cristina | Pamplona | 27 | 14.123,58 | 1.790,00 | 61,14 |
| Cristina | San Sebastian | 12 | 3.364,00 | 900,00 | 12,32 |
| Cristina | Sant Boi | 38 | 66.495,16 | 1.548,00 | 324,74 |
| Cristina | Valladolid | 22 | 24.091,96 | 0,00 | 118,81 |
| Cristina | Villareal | 33 | 43.345,73 | 980,00 | 202,59 |
| Cristina | Zaragoza | 15 | 7.345,80 | 0,00 | 36,72 |
| Irene Simon | Alicante | 32 | 53.084,96 | 8.500,00 | 222,92 |
| Irene Simon | Paterna | 22 | 25.950,88 | 0,00 | 129,76 |
| Nuria Moracho | Castellon | 4 | 688,80 | 0,00 | 3,44 |
| Nuria Moracho | Sedavi | 21 | 30.117,86 | 1.000,00 | 145,59 |

## Causa raiz del descuadre previo

El dashboard anterior se apoyaba en una replica incompleta. La SOQL de
sincronizacion incluia un campo opcional de `Account` no accesible y Salesforce
respondia HTTP 400. El error se sanitizaba antes de llegar al fallback, por lo
que el reintento condicionado al nombre del campo nunca ocurria y la replica no
se refrescaba. La diferencia aproximada observada era `105.128,40 EUR`
(`718.638,40 - ~613.510`), pero el corte anterior de produccion no se conservo y
no es posible atribuir honestamente esa cifra a una lista exacta de IDs.

En local, al inicio de la auditoria faltaban los 680 IDs de julio. Tras el
resync, la comparacion por ID y campos economicos dio cero diferencias. El
segundo defecto era independiente: Irene/Nuria se seleccionaban por el owner
comercial, por lo que la regla especial no cubria sus delegaciones completas.

## Despliegue y verificacion

No hay migraciones ni variables de entorno nuevas.

```bash
php artisan optimize:clear
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01 -vvv
```

Validar despues que el diagnostico de julio muestre 680 operaciones y los
sumatorios anteriores. Si Salesforce cambia desde el corte auditado, comparar de
nuevo por `Opportunity.Id`; no hardcodear cifras historicas.

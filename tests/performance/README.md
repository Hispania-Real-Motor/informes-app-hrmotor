# Generaciones Interests: mediciones locales

## Entorno y límites

MariaDB 11.8 y PHP 8.4 en contenedores locales desechables sin puertos publicados ni acceso a producción. Datos sintéticos: 270.000 Interests, `raw_payload` de aproximadamente 2 KB por fila, 270.000 modificaciones por candidata. No se leyó ningún backup. Los scripts exigen `APP_ENV=testing`, conexión MySQL y la base `interests_generation_benchmark`.

Ejecutar `php tests/performance/interest-generations.php` sobre una base sintética vacía y migrada. El modo `read 120` mide lecturas simultáneas; `delta` añade otra candidata. `php tests/performance/interest-generation-publication-race.php` enfrenta dos procesos PHP contra el mismo puntero. No ejecutar estos scripts en bases compartidas o productivas.

## Resultados observados (28/10/2026)

| Medición | Primera ejecución | Segunda ejecución con índice de resumen |
| --- | ---: | ---: |
| Base sintética | 59,24 s | reutilizada |
| Copia de membresías | 8,04 s | 40,01 s |
| Aplicación de 270.000 modificaciones | 88,61 s | 388,08 s |
| Pico de memoria PHP | 32 MiB | 30 MiB |
| Lectura concurrente p50 | 7.965 ms | 9.085 ms |
| Lectura concurrente p95 | 12.863 ms | 11.615 ms |
| Esperas de bloqueo de fila InnoDB registradas | 0 | 0 |

La segunda ejecución coincidió con otras pruebas PHP y no constituye una comparación controlada. Una lectura aislada con el índice de resumen dio p50 964 ms y p95 1.296 ms (19 muestras). Las lecturas concurrentes reunieron solo 7 y 14 muestras: no establecen un SLO.

Tras 540.000 versiones: aproximadamente 1.463 MB de datos y 339 MB de índices de versiones; 44 MB de datos y 95 MB de índices de membresías. Tras 810.000 versiones: 2.110 MB y 407 MB, respectivamente; membresías 65 MB y 140 MB. Son tamaños físicos aproximados de InnoDB; sus estimaciones de filas no son conteos exactos. Redo acumulado: 1,79 GB y 2,75 GB; no son incrementos aislados ni medidas de binlog. Memoria del servidor muestreada: 342–367 MiB. No se midió el binlog del entorno productivo.

La carrera con dos conexiones independientes produjo exactamente `published` y `rejected`: un solo ganador. Ninguna candidata cambió el puntero durante su construcción.

## Decisión y bloqueo de producción

Se evita copiar los payloads completos cada hora: se copian referencias de membresía y se escriben únicamente versiones modificadas. La membresía sigue teniendo coste O(N) por generación. Si cambia toda la población, el coste de versiones sigue siendo proporcional al dataset completo.

**No queda demostrada la viabilidad de una frecuencia horaria.** La latencia concurrente observada requiere investigación y presupuesto explícito de I/O, almacenamiento y retención. Estas mediciones no representan el tamaño completo real, distribución de filtros, tamaño real de payloads ni concurrencia de usuarios. El último añadido de columna nullable de SystemModstamp requiere repetir el benchmark sobre la migración definitiva. Antes de producción se exige ensayo controlado representativo, análisis EXPLAIN de consumidores, p95/p99 acordados, métricas de redo/binlog y espacio libre. No hay limpieza automática de generaciones.

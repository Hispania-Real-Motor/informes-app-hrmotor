# Ensayo preliminar de copia de Interests

Estos dos scripts crean y modifican datos sintéticos exclusivamente en una
instancia MariaDB desechable, sin red ni volúmenes productivos. El primero crea
la base `synthetic_interests`, 270.000 filas y una copia completa; el segundo
modifica 270.000 filas de la copia en bloques de 1.000. No ejecutarlos en una
instancia compartida o de producción. La base debe estar vacía antes de empezar.

El esquema deliberadamente reducido usa un JSON sintético de 512 caracteres y
no reproduce todos los índices, campos, relaciones ni la distribución real. La
inserción de copia se realiza en una sola sentencia para medir un límite
preliminar; no es una propuesta de publicación. La prueba no demuestra que una
copia por hora sea viable ni cubre F2/F5, dashboard, concurrencia, replicación,
retención o interrupciones. Hay que completar esas mediciones sobre datos
representativos y consultas reales antes de aprobar el diseño.

En una instancia temporal sin red, suministrar los scripts por la entrada
estándar del cliente MariaDB: primero `interests-synthetic-benchmark.sql` y
después `interests-synthetic-update.sql`. Medir separadamente memoria, I/O,
latencia de lectura y bloqueos con las herramientas del entorno de pruebas.
No guardar credenciales reales ni datos de clientes en este directorio.

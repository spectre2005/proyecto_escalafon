# Catálogo de ubicaciones

`ubigeo_peru.csv` se generó desde el archivo `catalogo-ubigeo-peru.xlsx` proporcionado por el usuario. Contiene 1,893 distritos, 196 provincias y 25 departamentos.

La segunda hoja del archivo fuente indica que es una compilación de repositorios abiertos, no una descarga directa del INEI; también advierte que el código `180107` para San Antonio (Moquegua) fue asignado manualmente. El seeder valida que los códigos tengan seis dígitos, pero no los guarda: el esquema existente identifica las ubicaciones con UUID y no cuenta con una columna para el código UBIGEO. La aplicación usa los nombres para los desplegables.

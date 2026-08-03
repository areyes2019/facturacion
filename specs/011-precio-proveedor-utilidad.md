# Spec: Precio del proveedor y utilidad (precio de venta calculado por margen)

## Historia de usuario

Como usuario único del sistema de facturación, quiero registrar el precio que me cobra el proveedor
y el porcentaje de utilidad que quiero ganar, para que el sistema calcule solo el precio de venta de
cada artículo y me muestre cuánto dinero me queda de utilidad por pieza, sin tener que sacar la
cuenta a mano cada vez que un proveedor me cambia un precio o me mejora un descuento.

## Objetivo / Alcance

Ampliar el modelo de precios de `Articulo` ([006-gestion-articulos.md](006-gestion-articulos.md)) y
de `Catalogo` ([009-catalogos.md](009-catalogos.md)) para separar **costo** de **precio de venta**.

Hoy el usuario captura un solo precio (`precio_unitario_sin_iva`) y el descuento del catálogo se
aplica sobre él. Esta historia invierte el modelo: el usuario captura el **precio de lista del
proveedor** y un **porcentaje de utilidad**, y el precio de venta pasa a ser un valor **calculado**
por el sistema. La utilidad en pesos queda visible en el listado y en el formulario de artículos.

Se implementa sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum (ver
[001](001-inicio-proyecto.md), [002](002-login-auth.md)) y el design system de
[003](003-design-system-tailwind.md), siguiendo el patrón de 006/009.

**No** incluye reportes de rentabilidad ni ninguna modificación a
[Facturación](007-facturacion.md), [Cotizaciones](008-cotizaciones.md) o
[Tesorería](010-tesoreria.md).

### Cadena de cálculo

Con precio de lista $200.00, descuento de catálogo 10% y utilidad 25%:

```
precio_proveedor          (capturado)              $200.00
  ↓ × (1 − descuento / 100)                        descuento del catálogo (10%)
costo_con_descuento       (calculado, persistido)  $180.00
  ↓ ÷ (1 − utilidad_porcentaje / 100)              margen sobre venta (25%)
precio_unitario_sin_iva   (calculado, persistido)  $240.00
  ↓ × 1.16                                         IVA general (006)
precio_unitario_con_iva   (calculado al leer)      $278.40

utilidad = precio_unitario_sin_iva − costo_con_descuento = $60.00
```

El porcentaje se interpreta como **margen sobre la venta**, no como recargo sobre el costo: un 25%
significa "quiero que el 25% de lo que cobro sea ganancia", de ahí la división en vez de una
multiplicación.

## Backend (Laravel)

### Cambios sobre `Catalogo` (extiende 009)

- **Nueva columna `utilidad_porcentaje`**: decimal(5,2), **obligatoria**, con **valor por defecto de
  0** si no se especifica (mismo patrón que `descuento` en 009). Es el porcentaje de utilidad que
  heredan por defecto todos los artículos del catálogo.
- **Disparadores de recálculo en bloque**: al editar un catálogo, **tanto `descuento` como
  `utilidad_porcentaje`** disparan el recálculo masivo de sus artículos (009 solo lo disparaba con
  `descuento`). El recálculo sigue haciéndose con una actualización masiva vía query, no iterando
  artículo por artículo.
  - Un cambio de `descuento` mueve el precio de **todos** los artículos del catálogo, incluidos los
    que tienen porcentaje propio, porque cambia el costo del que parten.
  - Un cambio de `utilidad_porcentaje` mueve el precio **solo de los artículos que heredan** el
    porcentaje (los que tienen `utilidad_porcentaje` en `NULL`).
- **Nuevo endpoint de previsualización de impacto**:
  `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios?descuento=&utilidad_porcentaje=`
  — recibe los valores que el usuario está por guardar (ambos opcionales; los ausentes se toman de
  los valores actuales del catálogo) y responde `{ "articulos_afectados": <int> }` con el conteo
  **exacto** de artículos cuyo precio de venta cambiaría, aplicando la regla de arriba. Alimenta el
  diálogo de confirmación del frontend.

### Cambios sobre `Articulo` (extiende 006 y 009)

- **Nueva columna `precio_proveedor`**: decimal(10,2), **obligatoria**, mayor a 0, en pesos
  mexicanos (MXN), **sin IVA**. Es el **precio de lista** del proveedor, es decir, el precio *antes*
  de aplicar el descuento del catálogo.
- **Nueva columna `utilidad_porcentaje`**: decimal(5,2), **nullable**. `NULL` significa "hereda el
  porcentaje del catálogo"; un valor significa "este artículo usa su propio porcentaje". La herencia
  es **viva**: cambiar el porcentaje del catálogo mueve a todos sus artículos en `NULL` y respeta a
  los que tienen valor propio.
- **`precio_con_descuento` se renombra a `costo_con_descuento`** y cambia de significado: deja de
  ser "precio de venta con descuento" y pasa a ser el **costo real** del artículo. Se calcula como
  `redondeo2(precio_proveedor × (1 − catalogo.descuento / 100))` y **se sigue persistiendo**.
- **`precio_unitario_sin_iva` deja de ser un campo de entrada** y pasa a ser **calculado y
  persistido**: `techo2(costo_con_descuento ÷ (1 − utilidad_efectiva / 100))`, donde
  `utilidad_efectiva = articulo.utilidad_porcentaje ?? catalogo.utilidad_porcentaje`. La columna
  **no cambia de nombre ni de tipo**, para no tocar a Facturación ni a Cotizaciones, que la leen al
  precargar líneas.
- **`utilidad` (monto)**: `precio_unitario_sin_iva − costo_con_descuento`, siempre sin IVA. **No se
  persiste**: es una resta de dos columnas y se expone como atributo calculado en el Resource,
  igual que `precio_unitario_con_iva`.
- **`precio_unitario_con_iva`** no cambia: sigue siendo el accessor de 006 sobre
  `precio_unitario_sin_iva`, a la tasa general del 16%.

#### Redondeo

Dos redondeos distintos y deliberados:

- **`costo_con_descuento`**: redondeo matemático estándar a 2 decimales (`redondeo2`), igual que en
  009.
- **`precio_unitario_sin_iva`**: redondeo **hacia arriba** a 2 decimales (`techo2`), para que el
  precio de venta nunca quede por debajo del margen solicitado.

`techo2` **no** puede implementarse como un `ceil(v * 100) / 100` ingenuo. La división
`costo ÷ (1 − % / 100)` produce error de representación en punto flotante: con costo $210.00 y 30%
de utilidad el resultado correcto es exactamente $300.00, pero `210 / 0.7` da `300.00000000000006`,
que un techo ingenuo convertiría en $300.01. Se implementa **absorbiendo primero el error de
representación**: redondear a 4 decimales y aplicar el techo sobre ese valor
(`ceil(round(v, 4) * 100) / 100`), con la técnica equivalente del lado SQL para el recálculo masivo.

Es la misma familia de trampa que el bug de división entera en SQLite documentado en
[009](009-catalogos.md) (`descuento / 100` truncaba a cero), por lo que se cubre con una suite de
tests de casos frontera dedicada (ver "Tests").

#### Migración de esquema y de datos

En un solo cambio:

1. `catalogos` gana `utilidad_porcentaje` decimal(5,2) con default 0. Todos los catálogos existentes
   (incluido el "General" que generó 009) quedan en **0%**.
2. `articulos` gana `precio_proveedor` decimal(10,2) y `utilidad_porcentaje` decimal(5,2) nullable.
3. `articulos.precio_con_descuento` se renombra a `costo_con_descuento`.
4. Cada artículo existente toma su `precio_unitario_sin_iva` actual como `precio_proveedor` (es
   decir, su precio actual pasa a interpretarse como precio de lista del proveedor) y queda con
   `utilidad_porcentaje` en `NULL`.
5. Se recalcula la cadena completa hacia adelante para todos los artículos existentes. Con 0% de
   utilidad en los catálogos, su precio de venta queda igual a su costo con descuento; los
   porcentajes reales se capturan manualmente después de migrar.

Los artículos actualmente en base de datos son datos de ejemplo, por lo que ese recálculo no
representa una pérdida de información de negocio.

### Endpoints

Sin rutas nuevas de `Articulo`. Cambios sobre las existentes:

- `GET /api/v1/articulos` — gana parámetros de ordenación `?sort=` y `?direction=` (ver Frontend).
  `?sort=` acepta `costo_con_descuento`, `precio_unitario_sin_iva` y `utilidad`; `?direction=`
  acepta `asc` y `desc` (default `asc`). Ordenar por `utilidad` se traduce a un `ORDER BY` sobre la
  expresión `precio_unitario_sin_iva - costo_con_descuento`, ya que la utilidad no se persiste. Un
  `sort` no reconocido se ignora y se cae al orden por defecto actual.
- `POST` / `PUT /api/v1/articulos[/{id}]` — aceptan `precio_proveedor` (obligatorio) y
  `utilidad_porcentaje` (opcional); **dejan de aceptar** `precio_unitario_sin_iva`.
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` — mismas columnas nuevas del
  CSV (ver abajo), mismo formato de reporte por fila que 006/009.
- `GET /api/v1/articulos/exportar-csv` — mismas columnas nuevas del CSV.
- `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios` — nuevo, descrito arriba.

### Columnas CSV

Idénticas en importación y exportación (se mantiene el principio de 006: un CSV exportado, editado,
es directamente reimportable). `precio_unitario_sin_iva` **sale** de las columnas por ser un valor
calculado, y entran las dos nuevas:

```
nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje
```

- `precio_proveedor` es obligatorio en cada fila.
- `utilidad_porcentaje` es **opcional**: celda vacía significa "hereda el porcentaje del catálogo
  destino".
- Los valores calculados (costo con descuento, precio de venta, utilidad, precio con IVA) **no
  viajan** en el CSV en ninguna dirección.

### Validaciones (Form Requests)

- `precio_proveedor`: requerido, numérico, **mayor a 0**, máximo 2 decimales.
- `utilidad_porcentaje` en `Articulo`: **nullable**, numérico, **mayor o igual a 0 y estrictamente
  menor a 100**, máximo 2 decimales.
- `utilidad_porcentaje` en `Catalogo`: requerido (con default `0` si se omite en la petición),
  numérico, **mayor o igual a 0 y estrictamente menor a 100**, máximo 2 decimales.
- El límite superior es **estricto** (`< 100`, no `<= 100`) porque un 100% haría una división entre
  cero en `costo ÷ (1 − % / 100)`.
- Se permite 0% (vender exactamente a costo, sin ganancia). **No** se aceptan porcentajes negativos:
  la utilidad nunca puede ser negativa por captura.
- `precio_unitario_sin_iva`, `costo_con_descuento` y `utilidad` **no forman parte de las reglas de
  validación**: cualquier valor que un cliente envíe para ellos se **ignora en silencio**, mismo
  patrón que ya usa `precio_con_descuento` en 009.
- Fila de importación CSV: mismas reglas que el alta individual, aplicadas por fila.
- El cálculo de la cadena vive en el controlador (o en un servicio compartido por
  `store`/`update`/`importarCsv` y por el recálculo masivo de `Catalogo`), no en los Form Requests,
  siguiendo la decisión ya tomada en 009.

### `ArticuloResource`

Agrega `precio_proveedor`, `utilidad_porcentaje` (el propio del artículo, `null` si hereda),
`utilidad_porcentaje_efectivo` (el que se usó realmente para calcular) y `utilidad` (monto).
Renombra `precio_con_descuento` a `costo_con_descuento`. Conserva `precio_unitario_sin_iva` y
`precio_unitario_con_iva` sin cambios de nombre.

### Tests

- Suite dedicada de **casos frontera del redondeo**, corriendo tanto en **SQLite** (tests) como en
  **MySQL** (entorno real), dado que ya hubo dos bugs de este tipo en 009: valores que deben dar un
  resultado exacto (costo $210.00 al 30% → $300.00, no $300.01), 0% de utilidad, costos con muchos
  decimales, y descuentos que producen costos no redondos.
- Batería de la **cadena completa**: herencia del porcentaje desde el catálogo, sobrescritura por
  artículo, conservación del porcentaje propio al mover el artículo de catálogo, recálculo en bloque
  por cambio de `descuento`, recálculo en bloque por cambio de `utilidad_porcentaje` (que debe
  respetar a los artículos con porcentaje propio), y el endpoint de previsualización de impacto.
- `ArticuloFactory` deja de recibir `precio_unitario_sin_iva` y pasa a recibir `precio_proveedor` y
  `utilidad_porcentaje`, **derivando** el resto de la cadena.
- Los tests de [007](007-facturacion.md), [008](008-cotizaciones.md) y [009](009-catalogos.md) que
  crean artículos se reescriben para expresar su intención en términos del modelo nuevo (costo y
  margen) en vez de arrastrar el precio de venta capturado.

## Frontend (Vue 3)

### `/articulos` (listado)

- **Columnas**: nombre, modelo, catálogo, **costo con descuento**, **precio de venta** y **utilidad
  ($)**. El precio de lista del proveedor y el porcentaje quedan solo en el formulario, para no
  revivir el desborde de tabla corregido en 006 el 2026-08-03.
- **Ordenación por columna numérica**: costo con descuento, precio de venta y utilidad son
  ordenables (ascendente/descendente) haciendo clic en su encabezado, alimentando `?sort=` y
  `?direction=`. Las columnas de texto no son ordenables en esta historia.
- La celda de catálogo mantiene el truncado con elipsis y `title` de 006.
- El buscador `?search=` no cambia.

### `/articulos/crear` y `/articulos/:id/editar`

- Se captura **`precio_proveedor`** (`Input` numérico, obligatorio) en lugar del precio de venta.
- Se captura **`utilidad_porcentaje`** (`Input` numérico, opcional). Cuando está vacío, el campo
  muestra como *placeholder* el porcentaje heredado del catálogo seleccionado, dejando claro qué
  valor se va a aplicar.
- **`precio_unitario_sin_iva` deja de ser un campo editable**: pasa a ser un valor mostrado de solo
  lectura dentro del bloque de resumen.
- **Bloque de resumen de la cadena de cálculo**, siempre visible y actualizándose en vivo conforme
  se captura (y también al cambiar de catálogo, porque cambian descuento y porcentaje heredado):

  ```
  Precio de lista del proveedor      $200.00
  Descuento del catálogo (10%)      −$20.00
  Costo                              $180.00
  Utilidad (25%)                     +$60.00
  Precio de venta sin IVA            $240.00
  IVA (16%)                          +$38.40
  Precio de venta con IVA            $278.40
  ```

- Los mensajes de error de validación por campo siguen el patrón de 006 (`Input`/`Alert`),
  incluyendo el rango del porcentaje (0 a menos de 100) y el precio del proveedor mayor a 0.

### `/catalogos/crear` y `/catalogos/:id/editar`

- Nuevo campo **`utilidad_porcentaje`** (`Input` numérico, precargado en `0`, editable), junto al
  `descuento` ya existente.
- **Diálogo de confirmación antes de guardar** cuando cambia `descuento` o `utilidad_porcentaje` en
  una edición: antes de enviar el `PUT`, el formulario consulta
  `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios` con los valores nuevos y muestra el
  conteo exacto ("Se recalculará el precio de venta de N artículos"). Confirmar envía el `PUT`;
  cancelar regresa al formulario sin guardar. En el alta de un catálogo nuevo no aplica (no tiene
  artículos).
- `/catalogos` (listado) muestra el porcentaje de utilidad junto al descuento.

### Importar CSV

El modal de importación de `/articulos` no cambia de flujo (seleccionar catálogo destino + archivo,
con reporte de errores por fila); solo se actualiza el listado de columnas que muestra en su
descripción, respetando la regla de `Dialog` con contenido dinámico de
[003](003-design-system-tailwind.md) (bloque `<code>` propio con `overflow-x-auto`).

## Fuera de alcance

- **Reportes de rentabilidad** (cuánto gané/perdí hoy, esta semana, este mes; rentabilidad agregada
  por catálogo o proveedor). Queda como historia futura `012`. Responder "cuánto gané" requiere
  además que las líneas vendidas guarden el costo del momento, lo cual **no** se hace en esta
  historia.
- Cualquier modificación a [Facturación](007-facturacion.md), [Cotizaciones](008-cotizaciones.md) o
  [Tesorería](010-tesoreria.md). Las líneas de factura y cotización siguen guardando su propia copia
  de `precio_unitario` (desacoplada del catálogo) y **no** guardan costo ni utilidad.
- Mostrar la utilidad al armar una cotización o factura, incluso cuando se aplica un descuento por
  línea que erosiona el margen.
- **Campos personalizados definibles por el usuario** (un constructor de atributos dinámicos por
  giro de negocio). Los campos de esta historia son fijos para todos los artículos.
- **Modo manual de precio**: no existe una casilla que congele un precio de venta capturado a mano
  ignorando el porcentaje. El precio de venta siempre es calculado.
- **Utilidad negativa** (vender por debajo del costo): el porcentaje no admite valores negativos.
- **Historial** de cambios de precio, costo o porcentaje, ni registro de los valores previos a un
  recálculo masivo. `updated_at` es la única referencia temporal.
- **Multi-moneda y tipo de cambio**: todo en MXN. Un costo en dólares se captura ya convertido a
  pesos por el usuario.
- Cálculo del porcentaje a partir de un precio de venta objetivo (el sentido inverso al de esta
  historia).
- Márgenes mínimos obligatorios, umbrales de alerta o bloqueo de guardado por margen bajo.
- Descuentos variables por artículo dentro de un mismo catálogo (sigue vigente lo definido en 009:
  el descuento es uniforme por catálogo; lo que ahora sí varía por artículo es la **utilidad**).
- Ordenación por columnas de texto en `/articulos`, y ordenación en el resto de los listados de la
  app (Clientes, Proveedores, Catálogos, Facturas, Cotizaciones).
- Inventario/existencias, y por lo tanto utilidad total por unidades en stock.

## Criterios de aceptación

1. Un usuario autenticado puede crear un artículo capturando el precio de lista del proveedor
   (obligatorio, mayor a 0) y, opcionalmente, un porcentaje de utilidad propio; el precio de venta
   no se captura.
2. Capturar un precio del proveedor menor o igual a 0 muestra un error de validación y no permite
   guardar.
3. Capturar un porcentaje de utilidad negativo, igual a 100 o mayor a 100 muestra un error de
   validación y no permite guardar; 0 sí se acepta.
4. Al guardar un artículo, el sistema calcula y persiste el costo con descuento (precio de lista
   menos el descuento del catálogo) y el precio de venta sin IVA (costo dividido entre uno menos el
   porcentaje de utilidad), y muestra la utilidad en pesos como la diferencia entre ambos.
5. El precio de venta se redondea **hacia arriba** a 2 decimales; un costo de $175.00 con 25% de
   utilidad produce exactamente $233.34.
6. Un costo de $210.00 con 30% de utilidad produce exactamente **$300.00**, no $300.01 (caso
   frontera de punto flotante), tanto en SQLite como en MySQL.
7. Un artículo sin porcentaje propio hereda el del catálogo; el formulario muestra el valor heredado
   como referencia mientras el campo está vacío.
8. Cambiar el descuento de un catálogo recalcula el costo y el precio de venta de **todos** sus
   artículos, incluidos los que tienen porcentaje propio.
9. Cambiar el porcentaje de utilidad de un catálogo recalcula el precio de venta **solo** de los
   artículos que heredan el porcentaje; los que tienen porcentaje propio conservan su precio.
10. Antes de aplicar cualquiera de esos dos recálculos, el sistema muestra un diálogo de
    confirmación con el número **exacto** de artículos cuyo precio de venta va a cambiar; cancelar
    no guarda nada.
11. Mover un artículo que tiene porcentaje propio a otro catálogo conserva ese porcentaje propio y
    recalcula su precio con el descuento del catálogo destino.
12. Enviar `precio_unitario_sin_iva`, `costo_con_descuento` o `utilidad` en un `POST`/`PUT` de
    artículo no produce error, pero el valor enviado se ignora por completo.
13. El listado `/articulos` muestra nombre, modelo, catálogo, costo con descuento, precio de venta y
    utilidad en pesos, y permite ordenar ascendente y descendentemente por cada una de las tres
    columnas numéricas.
14. El formulario de artículo muestra en vivo la cadena completa de cálculo (precio de lista →
    descuento → costo → utilidad → precio de venta → IVA → precio final), actualizándose al cambiar
    cualquier campo capturado y también al cambiar de catálogo.
15. Importar un CSV con las columnas
    `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje` da
    de alta los artículos con su precio de venta ya calculado; las filas con la celda de porcentaje
    vacía heredan el del catálogo destino.
16. Exportar el listado genera un CSV con esas mismas 7 columnas, sin columnas calculadas, y ese
    archivo es directamente reimportable.
17. Tras la migración, los artículos existentes conservan su precio anterior como precio de lista
    del proveedor y quedan con porcentaje heredado; ningún artículo queda sin `precio_proveedor`.
18. Facturación y Cotizaciones siguen precargando líneas con el precio de venta del artículo, sin
    cambios de comportamiento respecto a 007/008.
19. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. La ampliación no crea una entidad nueva: agrega campos y cálculos a `Articulo` y `Catalogo`, y
   todo vive en las pantallas de Artículos y Catálogos que ya existen. No hay pantalla nueva de
   "Lista de precios": la lista de precios es el listado `/articulos`.
2. "Campos personalizados" significa **campos fijos nuevos** propios de un negocio de compra-venta
   (precio del proveedor y utilidad), **no** un motor de campos dinámicos definibles por el usuario.
3. El mecanismo de descuento por catálogo de 009 ya funciona; lo que falta es el precio del
   proveedor, la utilidad, y volver a mostrar el precio con descuento en el listado (se había
   quitado de la tabla en 006 el 2026-08-03 al corregir el desborde).
4. `precio_proveedor` es obligatorio y mayor a 0, sin IVA, en MXN, con 2 decimales. No se admite dar
   de alta un artículo sin conocer su costo.
5. `precio_proveedor` es el **precio de lista** del proveedor, *antes* del descuento del catálogo.
   El sistema deriva el costo real aplicándole ese descuento.
6. **(Redefinido)** `precio_unitario_sin_iva` deja de capturarse y pasa a **calcularse** a partir
   del costo con descuento y un porcentaje de utilidad. El dato que el usuario captura es el
   porcentaje; el precio es el resultado.
7. **(Redefinido)** El porcentaje de utilidad vive en el `Catalogo` como valor por defecto, y cada
   `Articulo` puede sobrescribirlo con el suyo propio. Si el artículo no define ninguno, hereda el
   del catálogo, y la herencia es viva.
8. **(Redefinido)** El porcentaje se interpreta como **margen sobre la venta**
   (`venta = costo ÷ (1 − % / 100)`), no como recargo sobre el costo. Con costo $100 y 25%, el
   precio de venta es $133.33, no $125.00.
9. El descuento del catálogo se aplica sobre el precio del proveedor, no sobre el precio de venta:
   es un beneficio de compra. El precio de venta se calcula sobre el costo ya rebajado, por lo que
   un mejor descuento se traduce en un precio de venta más bajo al mismo porcentaje de utilidad.
10. La columna `precio_con_descuento` de 009 cambia de significado (pasa a ser el costo con
    descuento) y por eso se **renombra a `costo_con_descuento`**. El rename está contenido: ningún
    archivo de Facturación ni de Cotizaciones la usa.
11. Los artículos existentes toman su `precio_unitario_sin_iva` actual como `precio_proveedor` y se
    recalcula toda la cadena hacia adelante; sus precios de venta cambiarán. Los datos actuales en
    base son de ejemplo, por lo que no hay pérdida real de información.
12. **(Redefinido)** El precio de venta se redondea **hacia arriba** a 2 decimales, para no quedar
    nunca por debajo del margen solicitado. El costo con descuento usa redondeo estándar a 2
    decimales, como en 009.
13. La utilidad en pesos es `precio de venta − costo con descuento`, por unidad y sin IVA: se mide
    contra lo que efectivamente pagas, no contra el precio de lista.
14. En pantalla se muestra el **porcentaje capturado** tal cual; no se muestra el porcentaje
    efectivo recalculado desde los montos redondeados ni el markup equivalente sobre costo.
15. Todos los valores derivados son de solo lectura y cualquier valor que se envíe para ellos se
    **ignora en silencio**, mismo patrón que `precio_con_descuento` en 009. No existe un "modo
    manual" que congele el precio de venta.
16. El porcentaje de utilidad va de 0 a menos de 100. Se permite vender exactamente a costo (0%);
    no se permiten porcentajes negativos, por lo que la utilidad nunca es negativa por captura.
17. **(Redefinido)** El recálculo en bloque se dispara con `descuento` **y** con
    `utilidad_porcentaje` del catálogo, y va **precedido de un diálogo de confirmación** que indica
    cuántos artículos van a cambiar de precio.
18. **(Redefinido)** El listado `/articulos` muestra nombre, modelo, catálogo, costo con descuento,
    precio de venta y utilidad en pesos. El precio de lista y el porcentaje quedan solo en el
    formulario, para no revivir el desborde de tabla corregido en 006.
19. El formulario de artículo muestra la **cadena de cálculo completa**, siempre visible y en vivo.
20. Al mover un artículo de catálogo, **conserva su porcentaje propio** si lo tiene; solo cambia su
    costo, porque cambia el descuento aplicable.
21. **(Redefinido)** El CSV sustituye `precio_unitario_sin_iva` por `precio_proveedor` y agrega
    `utilidad_porcentaje` **opcional** (celda vacía = hereda del catálogo destino). Importación y
    exportación usan exactamente las mismas 7 columnas, y los valores calculados no viajan en el
    archivo.
22. Facturación y Cotizaciones no se tocan en esta historia; no se guarda utilidad ni costo por
    documento emitido.
23. **(Redefinido)** No hay reportes en esta historia. Responder "¿cuánto gané/perdí hoy, esta
    semana, este mes?" y "¿de cuánto dinero puedo disponer?" queda como historia futura `012`,
    porque cruza Artículos, Cotizaciones, Facturas y Tesorería, y exige guardar el costo en cada
    línea vendida.
24. No hay historial de cambios de precio, costo ni porcentaje, ni posibilidad de revertir un
    recálculo masivo.
25. Todo en MXN; no hay moneda del proveedor ni tipo de cambio.
26. Los catálogos existentes arrancan con 0% de utilidad tras la migración (mismo patrón que el
    `descuento` con default 0 en 009); los porcentajes reales se capturan manualmente después.
27. **(Adición técnica)** Se persisten `costo_con_descuento` y `precio_unitario_sin_iva` en
    columnas; la utilidad en pesos se calcula al leer, por ser una resta de dos columnas. Esto
    mantiene funcionando sin cambios lo que 007/008 ya leen de la columna del precio de venta.
28. **(Adición técnica)** "Hereda el porcentaje del catálogo" se representa con
    `articulos.utilidad_porcentaje` **nullable**, donde `NULL` = hereda, sin columna booleana
    adicional.
29. **(Adición técnica)** El techo a 2 decimales absorbe primero el error de representación en punto
    flotante (`ceil(round(v, 4) * 100) / 100`), y se cubre con una suite de tests de casos frontera
    corriendo en SQLite y en MySQL, dado que 009 ya sufrió dos bugs de esta familia.
30. **(Adición técnica)** El conteo del diálogo de confirmación viene de un **endpoint de
    previsualización** (`GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios`) que recibe los
    valores por guardar y devuelve el conteo exacto, en vez de reusar el total de artículos del
    catálogo (que sería un número inflado cuando solo cambia el porcentaje).
31. **(Adición técnica)** `ArticuloFactory` pasa a recibir costo y porcentaje y deriva el resto de
    la cadena; los tests de 007/008/009 que crean artículos se reescriben en términos del modelo
    nuevo, y se agrega una batería nueva que cubre la cadena completa.
32. **(Adición técnica)** El listado `/articulos` gana ordenación por sus tres columnas numéricas
    (costo con descuento, precio de venta y utilidad) vía `?sort=` y `?direction=`; ordenar por
    utilidad se traduce a un `ORDER BY` sobre la expresión
    `precio_unitario_sin_iva - costo_con_descuento`, ya que no está persistida. No se extiende la
    ordenación al resto de los listados de la app.

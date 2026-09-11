# Calculadora de Joyas NCM — Plan de fases

> ### ⚠️ Antes de leer: `CLAUDE.md` manda sobre el modelo público/interno
>
> Este documento se escribió cuando la calculadora era **solo interna**. Después
> cambió el alcance: hoy hay **dos modos**, uno público (para SEO) y uno interno.
> Las fases 4 y 5 ya están reescritas para reflejarlo, pero si encuentras
> **cualquier** discrepancia entre este documento y `CLAUDE.md` sobre quién ve
> qué, **gana `CLAUDE.md`**.
>
> En concreto, y por si el texto viejo sobrevive en alguna copia: el endpoint
> AJAX **sí registra `wp_ajax_nopriv_ncm_calcular`, y eso es correcto.** No lo
> quites. La calculadora pública dejaría de funcionar. Lo que protege los datos
> no es la ausencia del registro `nopriv`, sino el **filtrado en servidor**
> descrito en la Fase 4.

**Objetivo:** replicar en WordPress, tal cual el Excel, la calculadora de joyas de
NCM. Tiene dos modos: una versión **pública** que solo devuelve el precio
`DESDE $X COP` (pensada para captar tráfico e indexarse) y una versión **interna**
que muestra el desglose completo para cotizar.

**Plugin autónomo:** trae su propio panel de configuración en el admin de
WordPress. NO depende de JetEngine ni de ningún otro plugin. Todos los precios,
catálogos y parámetros se editan desde ese panel y se guardan en la base de datos
de WP (`wp_options`). Al instalar, el plugin precarga los valores exactos del
Excel (sección "Datos semilla").

**Regla de oro:** los números deben coincidir con el Excel al peso. No cambiar la
lógica salvo donde este documento lo indique explícitamente (ver nota del metal en
la sección 0).

---

## 0. Lógica de cálculo (leer antes de programar)

El usuario elige 6 entradas y obtiene un precio. Flujo:

1. **Tipo de joya + Diseño → código de diseño** (ej. Pulsera + Bangle (Rígida) =
   `PU-BAN`). Si la combinación no existe → salida `REVISAR CONFIGURACIÓN`, no se
   calcula.
2. El código trae de la matriz de diseños: peso metal base (g), cantidad de gemas,
   ct por gema, mano de obra, extras.
3. **Componente gema** = `precio_por_1ct(gema, origen) × cant_gemas × ct_por_gema
   + ajuste_talla`.
4. **Componente metal** = `peso_base × factor_merma × precio_gramo(metal) ×
   factor_adicional(metal)`.
5. **Costo producción** = `componente_gema + componente_metal + mano_obra +
   extras`.
6. **Precio calculado** = `costo_produccion × (1 + margen_comercial)`.
7. **Precio final** = redondear hacia arriba al múltiplo de `redondeo_precio`.
   Mostrar `DESDE $X COP`.

> ⚠️ **Nota sobre el metal.** En el Excel original, las celdas puente del precio de
> metal/gramo y factor adicional (MASTER E11/E12/E13) están vacías, pero el
> desglose muestra el metal como `peso × merma × precio/g × factor`. Se replica el
> comportamiento que el Excel muestra (paso 4), tomando precio/g y factor del metal
> elegido desde la matriz de metales. Con los datos actuales los números coinciden
> exactamente con el Excel.

---

## Casos de prueba (tests de aceptación obligatorios)

**Caso A — Pulsera / Bangle (Rígida) / Natural / Diamante / Redonda / Oro blanco
(código `PU-BAN`):**

- Gema = `12.000.000 × 0 × 0 + 0` = **0**
- Metal = `15 × 1.1 × 650.000 × 1` = **10.725.000**
- Costo = `10.725.000` → `× 1.35` = `14.478.750` → **DESDE $14.480.000 COP**

**Caso B — Anillo / Solitario / Natural / Diamante / Redonda / Oro blanco (código
`AN-SOL`):**

- Gema = `12.000.000 × 1 × 1 + 0` = **12.000.000**
- Metal = `3.2 × 1.1 × 650.000 × 1` = **2.288.000**
- Costo = `14.288.000` → `× 1.35` = `19.288.800` → **DESDE $19.290.000 COP**

El código debe reproducir estos dos resultados exactos antes de avanzar al front.

---

## Datos semilla (copiar tal cual — valores exactos del Excel)

Guardar todo bajo la opción de WP `ncm_calc_config` (un solo array serializado).
Estructura en PHP:

```php
$seed = [
  'parametros' => [
    'margen_comercial'   => 0.35,   // fracción (35%)
    'factor_merma_metal' => 1.1,
    'redondeo_precio'    => 10000,
    'moneda'             => 'COP',
    'texto_nota'         => 'Valor estimado para la configuración seleccionada. El precio final puede variar según talla, dimensiones, características específicas de la gema, origen de la gema y personalizaciones adicionales.',
  ],

  // codigo, tipo, diseno, peso_metal_g, cant_gemas, ct_por_gema, mano_obra, extras
  'disenos' => [
    ['AN-SOL','Anillo','Solitario',       3.2, 1, 1,    0, 0],
    ['AN-TRI','Anillo','Trilogía',        3,   3, 0.5,  0, 0],
    ['AN-ETE','Anillo','Eternity',        4,   15,0.05, 0, 0],
    ['AN-COC','Anillo','Cocktail',        3.5, 1, 1,    0, 500000],
    ['AR-TSO','Aretes','Topos Solitario', 4,   2, 1,    0, 0],
    ['AR-TCO','Aretes','Topos Cocktail',  4,   2, 1,    0, 500000],
    ['AR-ETE','Aretes','Arete Eternity',  5,   10,0.3,  0, 0],
    ['AR-LIN','Aretes','Topos en Línea',  5,   6, 0.2,  0, 0],
    ['PU-TEN','Pulsera','Tennis',         16,  30,0.5,  0, 0],
    ['PU-ESC','Pulsera','Esclava',        18,  0, 0,    0, 0],
    ['PU-BAN','Pulsera','Bangle (Rígida)',15,  0, 0,    0, 0],
    ['DI-HAL','Dije','Halo',              3.7, 10,0.2,  0, 0],
    ['DI-SOL','Dije','Solitario',         3,   1, 1,    0, 0],
    ['DI-CRU','Dije','Cruz',              4,   5, 0.5,  0, 0],
    ['DI-INI','Dije','Iniciales',         4,   1, 0.05, 0, 0],
  ],

  // tipo_gema, precio_natural, precio_laboratorio  (precios por 1 ct)
  'gemas' => [
    ['Rubí',      700000,   160000],
    ['Zafiro',    400000,   160000],
    ['Esmeralda', 1000000,  268000],
    ['Diamante',  12000000, 150000],
  ],

  // talla, ajuste (COP), disponible
  'tallas' => [
    ['Redonda',   0, true],
    ['Cuadrada',  0, true],
    ['Gota',      0, true],
    ['Esmeralda', 0, true],
    ['Marquesa',  0, true],
  ],

  // metal, precio_gramo, factor_adicional, disponible
  'metales' => [
    ['Oro amarillo', 650000, 1, true],
    ['Oro blanco',   650000, 1, true],
    ['Oro rosado',   650000, 1, true],
    ['Plata',        250000, 1, true],
    ['Platino',      300000, 1, true],
  ],

  // Orígenes fijos (no editables)
  'origenes' => ['Natural','Laboratorio'],
];
```

Convertir cada fila a array asociativo con claves nombradas dentro de `NCM_Data`
para que el resto del código lea por nombre, no por índice.

> **Al día de hoy** las cuatro matrices tienen además una columna `imagen` (id de
> adjunto de la mediateca, `0` = sin imagen), que se usa para las tarjetas del
> formulario. No está en la semilla porque el Excel no la tiene: las filas
> sembradas arrancan sin imagen y se asignan desde el panel.

---

## Fases

1. **Esqueleto + datos** — estructura del plugin, `NCM_Data`, semilla en
   activación.
2. **Panel admin** — pantalla de configuración con pestañas y filas repetibles.
3. **Motor** — `NCM_Calculator`, cálculo puro y testeable fuera de WP.
4. **AJAX** — un endpoint para los dos modos, con el filtrado en el servidor.
5. **Shortcodes / front** — `[ncm_calculadora]` pública y
   `[ncm_calculadora_interna]` con desglose.
6. **Impresión** — `@media print` + botón imprimir/PDF (solo en la interna).
7. **Pruebas** — casos A y B más validaciones de configuración y de filtrado.
8. **Entrega** — README, instalación y notas de mantenimiento.

Recomendado: hacer Fase 1 y 3 primero y validar los dos casos de prueba antes de
construir el panel y el front.

---

## FASE 4 — AJAX: un endpoint, dos respuestas

**Meta:** el front pide cálculos sin recargar. Cualquiera puede pedir un precio;
solo quien tiene sesión recibe el desglose.

- Registrar la acción **en las dos rutas**: `wp_ajax_ncm_calcular` y
  `wp_ajax_nopriv_ncm_calcular`. Ambas apuntan al mismo handler.
- **Verificar el nonce siempre**, con sesión y sin ella.
- Sanitizar las 6 entradas y llamar al motor.
- **Decidir en el servidor** qué se devuelve, a partir de una sola línea:

  ```php
  $interno = is_user_logged_in() && current_user_can( self::CAP );
  ```

- La respuesta pública es una **allowlist**, no el desglose recortado:

  | Público | Interno |
  | --- | --- |
  | `modo`, `estado`, `entrada`, `precio_final`, `precio_final_formateado`, `moneda`, `texto_nota`, `html` | todo lo anterior **más** `codigo`, `gema`, `metal`, `mano_obra`, `extras`, `costo_produccion`, `margen_comercial`, `valor_margen`, `precio_calculado`, `redondeo_precio`, `desglose`, y un `html` con la tabla |

- El **HTML público se genera aparte** (`html_precio()`), no es el interno con
  cosas ocultas. Un dato tapado con CSS sigue siendo un dato entregado.
- El motivo técnico de un `REVISAR CONFIGURACIÓN` solo se le da a quien puede
  configurar el plugin.
- Catálogos para poblar el formulario: incrustados en la página
  (`ncmCalcData.catalogos`), sin segunda petición. Ahí no viaja nada sensible.

**Aceptación:**

- POST **sin sesión** con nonce válido → JSON con el precio correcto y **sin**
  `gema`, `metal`, `margen_comercial`, `costo_produccion`, `desglose` ni `codigo`;
  tampoco esas cifras dentro del `html`.
- POST **con sesión** → el desglose completo.
- Nonce inválido → 403 en ambos casos.

> **Nota histórica.** Hasta el cambio de alcance, esta fase decía "registrar solo
> `wp_ajax_ncm_calcular` (NO `nopriv`)". Ya no aplica: sin el registro `nopriv`
> la calculadora pública no puede calcular nada. La protección se movió del
> registro del hook al **contenido de la respuesta**.

---

## FASE 5 — Front: dos shortcodes

**Meta:** una calculadora pública indexable y una interna para cotizar.

### `[ncm_calculadora]` — pública

- Se pinta **a cualquiera, con o sin sesión**.
- Resultado: **solo** `DESDE $X COP`, un resumen de lo que el visitante eligió y
  la nota legal. Sin desglose, sin código de diseño, sin imprimir.
- **Contenido para SEO en el HTML inicial**, antes de cualquier interacción: el
  texto descriptivo (parámetro `texto_publico`, editable en el panel), un precio
  "desde" real calculado sobre todo el catálogo (`NCM_Calculator::precio_desde()`)
  y el nombre de cada opción del catálogo. La página no depende del JavaScript
  para tener algo que indexar.

### `[ncm_calculadora_interna]` — interna

- Exige `is_user_logged_in()` y la capacidad `NCM_Shortcode::CAP`. A un anónimo
  **no se le pinta ni el formulario**, solo un aviso.
- Muestra el desglose detallado completo y el botón de imprimir/PDF.

### Comunes a los dos

- Seis pasos: Tipo de joya → Diseño (cascada) → Origen → Gema → Talla → Metal.
- Cada opción es una **tarjeta con imagen** (o un monograma si esa fila aún no
  tiene imagen cargada en el panel). Por debajo son `input[type=radio]` con su
  `label`: accesibles por teclado y funcionales sin JavaScript.
- Manejar el estado `REVISAR CONFIGURACIÓN`.
- Encolar CSS/JS solo en las entradas que usan alguno de los dos shortcodes.
- Varias instancias pueden convivir en la misma página sin repetir ids.

**Aceptación:**

- Un anónimo ve y usa la pública, y obtiene el precio.
- Un anónimo **no** ve el formulario interno.
- Alguien con sesión ve el desglose en la interna.
- Una página sin shortcodes no carga el CSS ni el JS.

---

## Qué NO hacer (recordatorio)

- **No quitar `wp_ajax_nopriv_ncm_calcular`.** Rompe la calculadora pública.
- **No filtrar datos en el front.** Si un anónimo no debe verlo, no se envía.
- No agregar campos a la respuesta pública "porque son inofensivos": con el
  precio y un par de componentes se despeja el margen.

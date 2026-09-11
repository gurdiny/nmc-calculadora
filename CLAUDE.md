# CLAUDE.md — Calculadora de Joyas NCM (plugin WordPress)

Contexto permanente para trabajar en este proyecto. Léelo antes de tocar código.
El plan de trabajo por fases está en `FASES_Calculadora_NCM_WordPress.md` — síguelo
en orden.

---

## Qué es esto

Un **plugin de WordPress autónomo** que replica, **tal cual**, una calculadora de
cotización de joyas que hoy vive en un Excel. El usuario elige 6 opciones y
obtiene un precio "DESDE $X COP".

Tiene **dos modos**, y la diferencia entre ellos es de seguridad, no de estilo:

| | `[ncm_calculadora]` (pública) | `[ncm_calculadora_interna]` |
| --- | --- | --- |
| Quién la ve | cualquiera, sin sesión | solo con sesión iniciada |
| Para qué | captar tráfico, SEO | cotizar internamente |
| Qué devuelve | **solo** `DESDE $X COP` | desglose completo + imprimir/PDF |
| Sin sesión | funciona | no se pinta ni el formulario |

- **Autónomo:** sin JetEngine ni ninguna dependencia externa. Panel de config
  propio, datos en `wp_options` (clave `ncm_calc_config`).
- **Un solo endpoint AJAX** (`ncm_calcular`), registrado para `wp_ajax_` **y**
  `wp_ajax_nopriv_`, con nonce en ambos casos.

---

## Regla de oro nº 1: el filtrado es del servidor

**El desglose no puede salir del servidor hacia un anónimo.** Nunca se oculta
información en el front: `NCM_Shortcode::respuesta()` decide qué se envía a
partir de `is_user_logged_in()`, y esa es la única fuente de verdad.

- A una petición **sin sesión** se le manda el precio final, la selección que el
  propio visitante hizo y la nota legal. **Nada más:** ni componentes, ni costos,
  ni mano de obra, ni extras, ni margen, ni el precio previo al redondeo, ni el
  código de diseño, ni el motivo técnico de un `REVISAR CONFIGURACIÓN`.
- Eso incluye el **HTML**: el bloque que se devuelve a un anónimo no puede
  contener ninguna de esas cifras. Un dato escondido con CSS sigue siendo un dato
  filtrado.
- El **mensaje de WhatsApp** se arma en el servidor y entra en la misma regla:
  solo la selección del visitante y el precio final. Por eso no hay marcador de
  plantilla para el costo ni el margen, y no debe añadirse uno.
- Si agregas un campo al resultado del motor, **no** llega solo al público:
  hay que sumarlo a mano a la rama interna de `respuesta()`. La lista pública es
  una *allowlist*, y así debe seguir.
- `tests/test-respuesta.php` recorre la carga entera (JSON + HTML) buscando esas
  cifras en las 30 combinaciones de la matriz. Si tocas la respuesta, corre esa
  suite.

## Regla de oro nº 2: los números

**Los números deben coincidir con el Excel al peso.** No "mejores" ni
"optimices" la lógica de cálculo. Si un cambio hace que un resultado difiera del
Excel, es un bug, no una mejora. La única desviación permitida está documentada
(el cálculo del metal, ver abajo) y ya reproduce lo que el Excel muestra.

Antes de dar por buena cualquier versión del motor, corre los **dos casos de
prueba** y verifica los números exactos:

- Pulsera / Bangle (Rígida) / Natural / Diamante / Redonda / Oro blanco →
  **DESDE $14.480.000 COP**
- Anillo / Solitario / Natural / Diamante / Redonda / Oro blanco →
  **DESDE $19.290.000 COP**

Si no dan exacto, **detente y arréglalo** antes de avanzar de fase.

---

## La lógica de cálculo (resumen)

1. Tipo de joya + Diseño → código de diseño (ej. `PU-BAN`). Si no existe →
   `REVISAR CONFIGURACIÓN`.
2. componente_gema = precio_por_1ct(gema, origen) × cant_gemas × ct_por_gema +
   ajuste_talla
3. componente_metal = peso_base × factor_merma × precio_gramo × factor_adicional
4. costo = componente_gema + componente_metal + mano_obra + extras
5. precio = costo × (1 + margen) (margen = 0.35)
6. precio_final = ceil(precio / redondeo) × redondeo (redondeo = 10000)

**Metal (única desviación permitida):** en el Excel las celdas puente del metal
están vacías, pero el desglose _muestra_ el metal con la fórmula del paso 3. Se
replica lo que el Excel muestra. No lo "corrijas" de otra forma.

---

## Convenciones de código

- **Nada hardcodeado en el motor.** Todos los precios, catálogos y parámetros se
  leen de la config (`NCM_Data`). El motor no conoce ningún número del Excel
  directamente; los recibe.
- **Prefijo `ncm_` / `NCM_`** en funciones, clases, hooks, opciones y handles de
  assets, para no chocar con otros plugins.
- **Separación de capas:**
  - `NCM_Data` → acceso a la config (getters + búsquedas).
  - `NCM_Calculator` → cálculo puro, sin HTML, testeable fuera de WP.
  - Admin (panel), AJAX y shortcodes → capas separadas que usan las dos de arriba.
  - `NCM_Shortcode::respuesta()` / `respuesta_error()` → la frontera de permisos.
    Son públicas y puras a propósito, para poder testearlas sin WordPress.
- **Seguridad siempre:** nonces en cada formulario/AJAX, `current_user_can`
  donde corresponda, `sanitize_*` en toda entrada, `esc_*` en toda salida.
- **Dinero:** trabajar con números para calcular; formatear a `$#,##0` solo al
  mostrar. Margen guardado como fracción (0.35, no 35).
- **Idioma:** UI, etiquetas y comentarios en **español**. Nombres de código en
  inglés/estándar WP está bien.
- **Encoding:** UTF-8. Cuidado con tildes y `ñ` en los datos (Rubí, Trilogía,
  Bangle (Rígida), etc.) — deben conservarse exactos porque son llaves de
  búsqueda.

---

## SEO de la página pública

La calculadora pública se indexa, así que su HTML inicial **no puede estar
vacío**: `[ncm_calculadora]` imprime, antes de cualquier interacción, el texto
descriptivo (parámetro `texto_publico`, editable en el panel) y un precio
"desde" de referencia calculado sobre todo el catálogo
(`NCM_Calculator::precio_desde()`). Ese precio es real —la configuración más
barata posible—, no un número inventado.

## Estructura esperada del plugin

```
ncm-calculadora/
├── ncm-calculadora.php        # header + bootstrap
├── includes/
│   ├── class-ncm-data.php     # acceso a config
│   └── class-ncm-calculator.php  # motor de cálculo
├── admin/
│   └── class-ncm-admin.php    # panel de configuración
├── public/
│   └── class-ncm-shortcode.php   # shortcode + AJAX
├── assets/
│   ├── css/
│   └── js/
└── README.md
```

---

## Qué NO hacer

- No agregar dependencia de JetEngine ni de otros plugins.
- No filtrar datos en el front (`display:none`, borrar nodos en JS, etc.). Si algo
  no debe verlo un anónimo, **no se envía**.
- No agregar campos del resultado a la respuesta pública "porque son inofensivos":
  con el precio y un par de componentes se despeja el margen.
- No cambiar los valores semilla del Excel salvo que se pida explícitamente.
- No alterar la fórmula de cálculo para que "cuadre" un caso; si no cuadra, el
  error está en otra parte.
- No usar librerías PDF externas por ahora: la impresión es vía `@media print`.
- No hardcodear precios en el motor ni en el front.

---

## Datos y verificación

Los valores semilla exactos (15 diseños, 4 gemas, 5 tallas, 5 metales,
parámetros) están en `FASES_Calculadora_NCM_WordPress.md`, sección "Datos
semilla", listos para copiar. El archivo Excel original es la fuente de verdad;
ante cualquier duda de un valor, gana el Excel.

## Orden de trabajo

Sigue las fases del documento: 1 (esqueleto + datos) → 2 (panel admin) →
3 (motor) → 4 (AJAX) → 5 (shortcode/front) → 6 (impresión) → 7 (pruebas) →
8 (entrega). Recomendado: hacer Fase 1 y 3 primero y validar los dos casos de
prueba antes de construir el panel y el front.

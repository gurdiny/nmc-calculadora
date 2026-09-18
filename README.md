# Calculadora de Joyas NCM

Plugin de WordPress que replica, tal cual, la calculadora de cotización de joyas
que NCM tenía en Excel. Tiene **dos modos**:

| | `[ncm_calculadora]` | `[ncm_calculadora_interna]` |
| --- | --- | --- |
| **Quién la ve** | cualquier visitante, sin sesión | solo usuarios con sesión |
| **Para qué** | captar tráfico e indexarse | cotizar internamente |
| **Qué muestra** | únicamente `DESDE $X COP` | desglose completo |
| **Sin sesión** | funciona con normalidad | no se pinta ni el formulario |

El filtrado lo hace el **servidor**, no el navegador: a una petición sin sesión
nunca se le envían costos, componentes ni margen — ni siquiera dentro del HTML.
Ver [Seguridad](#seguridad).

- Autónomo: no depende de JetEngine ni de ningún otro plugin.
- Panel de configuración propio; todo se guarda en la opción `ncm_calc_config`.
- Al activarse por primera vez precarga los valores exactos del Excel.

---

## Instalación

1. Generar el `.zip` con `bash bin/empaquetar.sh` y subirlo desde
   **Plugins → Añadir nuevo → Subir**. A mano: copiar el contenido de este
   repositorio en `wp-content/plugins/ncm-calculadora/` (la carpeta debe
   llamarse así).
2. Activar **Calculadora de Joyas NCM**.
3. Crear las páginas con los shortcodes:

   ```
   [ncm_calculadora]           → página pública, indexable
   [ncm_calculadora_interna]   → página interna, para el equipo
   ```

4. Ajustar precios y catálogos en **Calculadora NCM** en el menú del admin.

La activación **no pisa** una configuración que ya exista: si el plugin se
desactiva y se vuelve a activar, los precios ajustados por el equipo se
conservan. Para volver al Excel hay un botón explícito de
**Restaurar valores del Excel** en el panel.

---

## Importante: caché

> **La página que contenga `[ncm_calculadora]` debe quedar excluida del caché de
> página.** Si no, el botón de calcular deja de funcionar para parte del tráfico.

**El síntoma.** La página carga perfecta —el texto, el catálogo y el precio
"desde" están en el HTML— pero al pulsar *Calcular precio* no pasa nada: aparece
el aviso «No se pudo calcular. Recarga la página e inténtalo de nuevo» y en la
consola del navegador se ve un **403** contra `admin-ajax.php`. No hay error en
el log de PHP, así que es fácil no enterarse: el visitante simplemente no obtiene
su precio y se va.

**La causa.** La petición de cálculo va firmada con un *nonce* de WordPress, y
ese nonce viaja dentro del HTML de la página. Los nonces **caducan a las 24 h**.
Si la página se sirve desde un caché con TTL largo, a partir del día siguiente
todos los visitantes reciben un nonce ya muerto y el servidor rechaza la
petición. El caché de objetos, el de navegador y la minificación no molestan:
el problema es solo el **caché de página** (y el del CDN).

La página interna no suele verse afectada porque está detrás del login y los
plugins de caché no cachean a usuarios con sesión, pero conviene excluirla
igual.

### Cómo excluirla

Suponiendo que la página pública viva en `/cotizador/`, cambia esa ruta por la
que uses.

**WP Rocket** — *Ajustes → Rocket → Reglas avanzadas → Nunca almacenar en caché
(URL)*:

```
/cotizador/
```

**W3 Total Cache** — *Performance → Page Cache → Advanced → Never cache the
following pages*:

```
/cotizador/
```

**LiteSpeed Cache** — *LiteSpeed Cache → Caché → Excluir → No almacenar en caché
estas URI*:

```
/cotizador/
```

(LiteSpeed también permite cachear la página y dejar fuera solo el nonce con ESI,
pero es más frágil de mantener: si puedes, excluye la página entera.)

**WP Super Cache** — *Ajustes → WP Super Cache → Avanzado → Cadenas a no cachear*,
o añade la página a *Rechazar URIs*.

**CDN (Cloudflare)** — una *Cache Rule* que haga **bypass** cuando el path
coincida:

```
(http.request.uri.path contains "/cotizador")   →   Cache eligibility: Bypass cache
```

Con Page Rules clásicas: `ejemplo.com/cotizador*` → *Cache Level: Bypass*.

En otros CDN (Fastly, BunnyCDN, el caché de tu hosting) busca la opción
equivalente de «no cachear esta ruta».

### Cómo comprobar que quedó bien

El nonce debe **cambiar entre peticiones separadas en el tiempo**. Si dos
llamadas con varias horas de diferencia devuelven el mismo valor, la página está
cacheada:

```bash
curl -s https://ejemplo.com/cotizador/ | grep -o '"nonce":"[a-z0-9]*"'
```

Y la prueba definitiva: abre la página en una ventana privada **más de 24 horas
después** del último despliegue y pulsa *Calcular precio*. Si responde, está bien
configurado.

---

## Uso

Ambos modos piden las mismas seis opciones — tipo de joya, diseño, origen de la
gema, tipo de gema, talla y metal — y el selector de diseño se llena según el
tipo de joya elegido. Lo que cambia es la respuesta:

- **Pública:** el precio `DESDE $X COP`, un resumen de lo que el visitante eligió
  y la nota legal. Nada más.
- **Interna:** además, el código de diseño y el desglose detallado completo.

### Cómo se ve

El formulario es un recorrido de seis pasos, no una lista de desplegables:

- Cada opción es una **tarjeta con imagen** (o un monograma si aún no tiene).
- Los pasos se abren de a uno, con una **barra de progreso** y una palomita en
  los ya resueltos; la cabecera de cada paso cerrado muestra lo elegido.
- Al elegir, se avanza solo al paso siguiente; elegido el sexto, **el precio se
  calcula automáticamente**.
- Una **barra fija abajo** resume la selección en chips: al pulsar uno se vuelve a
  ese paso para cambiarlo.
- Por debajo son `input[type=radio]` con su `label`: se recorre con el teclado,
  los lectores de pantalla lo anuncian como grupo, y sin JavaScript los seis
  pasos quedan visibles con todo el catálogo en el HTML.
- Respeta `prefers-reduced-motion` y se reacomoda en móvil.

### SEO de la página pública

La página pública no depende del JavaScript para tener contenido. Desde la
primera carga, en el HTML, salen:

- el **texto descriptivo** (parámetro *Texto de la calculadora pública*, editable
  en el panel: es lo que leen los buscadores);
- **el catálogo entero**: el nombre de cada tipo, diseño, gema, talla y metal está
  en el HTML como texto, no lo inyecta el JavaScript;
- un **precio "desde" real**, calculado sobre todo el catálogo — la configuración
  más barata posible (`NCM_Calculator::precio_desde()`), no un número inventado —
  junto con el diseño que lo produce;
- la nota legal.

Así la página tiene algo que indexar aunque el visitante no toque nada.

Si la combinación de tipo + diseño no existe en la matriz, la salida es
**REVISAR CONFIGURACIÓN** y no se calcula nada (los administradores ven además
una línea con el motivo exacto).

---

## Panel de configuración

**Calculadora NCM** en el menú lateral del admin (requiere `manage_options`).
Cinco pestañas:

| Pestaña        | Qué contiene                                                          |
| -------------- | --------------------------------------------------------------------- |
| **Parámetros** | Margen comercial, factor de merma, redondeo, moneda, WhatsApp, texto de la calculadora pública y nota al pie. |
| **Diseños**    | Matriz de diseños: código, tipo, diseño, peso, gemas, mano de obra, extras. |
| **Gemas**      | Precio por 1 ct, natural y de laboratorio.                            |
| **Tallas**     | Ajuste en COP y disponibilidad.                                       |
| **Metales**    | Precio por gramo, factor adicional y disponibilidad.                  |
| **Apariencia** | La paleta de colores del formulario.                                  |

Las tablas admiten **agregar, eliminar y reordenar** filas (botones ↑ / ↓). El
orden de las filas es el orden en que salen las opciones en el formulario
público.

### Imágenes de las opciones

Diseños, gemas, tallas y metales tienen una columna **Imagen**. Al pulsar la
miniatura se abre la mediateca de WordPress: se elige (o se sube) una imagen y se
guarda su id junto con la fila. *Quitar* la desasigna — nunca borra el archivo de
la mediateca.

- En el formulario, cada opción es una **tarjeta con su imagen**. La que no tenga
  imagen cae en un monograma con su inicial, así que la calculadora funciona
  desde el primer día aunque no se haya subido nada.
- Se sirven con `srcset` y `loading="lazy"`, en formato cuadrado recortado
  (`object-fit: cover`). Lo ideal son imágenes cuadradas de ~600 px.
- Los **tipos de joya** no tienen columna propia: cada tipo usa la imagen del
  primer diseño de ese tipo que tenga una (ver `PENDIENTES.md`, punto 6).

### Botón de WhatsApp

En *Parámetros* hay un campo **WhatsApp de NCM**. Con un número ahí, el resultado
muestra un botón **Cotizar por WhatsApp** que abre el chat con el mensaje ya
escrito.

- El número va con **indicativo de país y sin el `+`**: para Colombia,
  `573001234567`. Los espacios, guiones y paréntesis se limpian solos; si lo que
  queda no tiene entre 7 y 15 dígitos, el panel avisa y el botón no se muestra.
- **Déjalo vacío (o desmarca la casilla) para ocultar el botón.**
- El mensaje es una plantilla editable con estos marcadores: `{tipo}`,
  `{diseno}`, `{origen}`, `{gema}`, `{talla}`, `{metal}` y `{precio}`.

> **No hay marcador para los costos ni el margen, y es a propósito.** El botón
> sale igual en la calculadora pública, así que el mensaje lo arma el servidor y
> solo puede llevar la selección del visitante y el precio final. Las pruebas
> recorren el mensaje y la URL buscando esas cifras.

### Colores

La pestaña **Apariencia** elige la paleta:

| Paleta | Qué hace |
| --- | --- |
| **NCM** (por defecto) | Se engancha a las variables globales de Elementor (`--e-global-color-primary`, `--e-global-color-secondary`…) con los colores de NCM como respaldo. Si el tema cambia de colores, la calculadora los sigue sola. |
| **Claro** | Beige y dorado, la paleta original del plugin. Fija, no depende del tema. |
| **Oscuro** | Fondo oscuro con dorado. Fija. |
| **Personalizada** | Seis selectores de color: principal, texto sobre el principal, texto, fondo, fondo secundario y bordes. |

Por dentro son variables CSS (`--ncm-acento`, `--ncm-tinta`…) que se inyectan
junto a la hoja de estilos. Un valor que no sea un color hexadecimal válido se
descarta y se usa el de por defecto, así que no se puede inyectar CSS por ahí.

### Validación al guardar

Nada se descarta en silencio: cada decisión aparece como aviso amarillo al
volver al panel.

| Regla | Qué pasa |
| --- | --- |
| Campos requeridos (código + tipo + diseño; gema; talla; metal) | La fila se descarta con aviso. Una fila del todo vacía se ignora sin ruido. |
| Códigos únicos | Se conserva la primera; la repetida se descarta con aviso (la calculadora solo encontraría la primera de todas formas). |
| Tipo + diseño único | Igual que arriba: no puede haber dos filas con la misma combinación. |
| Gema / talla / metal repetidos | Igual, comparando sin distinguir mayúsculas. |
| Números negativos | Se guardan como `0` con aviso. |
| Pestaña sin ninguna fila válida | No se guarda: se conserva la configuración anterior, con aviso. |

El margen se **escribe en porcentaje** (`35`) y se **guarda como fracción**
(`0.35`).

> **Formato de los números.** Los campos numéricos son `type="number"`: el
> separador decimal es el **punto** y **no hay separador de miles**. Seiscientos
> cincuenta mil se escribe `650000`; `650.000` vale 650. El panel lo advierte en
> pantalla. (Un valor pegado desde el Excel con separadores —`650.000,50`— sí se
> interpreta bien.)

Las tallas y metales marcados como no disponibles desaparecen del formulario
público y tampoco se calculan si llegan por AJAX.

---

## La lógica de cálculo

1. Tipo de joya + diseño → código de diseño (ej. `PU-BAN`). Si no existe →
   `REVISAR CONFIGURACIÓN`.
2. `componente_gema = precio_1ct(gema, origen) × cant_gemas × ct_por_gema + ajuste_talla`
3. `componente_metal = peso_base × factor_merma × precio_gramo × factor_adicional`
4. `costo = componente_gema + componente_metal + mano_obra + extras`
5. `precio = costo × (1 + margen)`
6. `precio_final = ceil(precio / redondeo) × redondeo`

> **Nota sobre el metal.** En el Excel original las celdas puente del precio de
> metal por gramo y del factor adicional (MASTER E11/E12/E13) están vacías, pero
> el desglose *muestra* el metal con la fórmula del paso 3. El plugin replica lo
> que el Excel muestra, tomando precio/g y factor del metal elegido desde la
> matriz de metales. Con los datos actuales los números coinciden exactamente.

---

## API interna

```php
$calc = NCM_Calculator::desde_config();               // usa la config guardada
$calc = new NCM_Calculator( $config_normalizada );    // o una config a mano

// Las 6 selecciones sueltas, en el orden del formulario…
$r = $calc->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );
// …o un array asociativo con las mismas claves.
$r = $calc->calcular( array( 'tipo' => 'Anillo', 'diseno' => 'Solitario', /* … */ ) );

$r['estado'];                   // 'OK' | 'REVISAR_CONFIGURACION'
$r['precio_final_formateado'];  // 'DESDE $19.290.000 COP'
NCM_Calculator::desglose( $r ); // filas del bloque DESGLOSE DETALLADO del Excel
```

El resultado trae el desglose completo: por gema (tipo, origen, cantidad, ct por
gema, ct totales, precio por ct, subtotal, ajuste de talla, componente), por
metal (peso base, factor de merma, gramos con merma, precio por gramo, factor
adicional, costo) y los totales (mano de obra, extras, costo de producción,
margen y su valor, precio calculado, redondeo, precio final).

`NCM_Calculator::desglose()` devuelve esas mismas cifras ya formateadas, en el
orden del Excel, como filas con `tipo` (`seccion` | `dato` | `total` |
`gran_total`), `etiqueta`, `valor` y `detalle` (la fórmula). El front pinta la
tabla desde ahí, así que motor y pantalla no se pueden desincronizar.

`NCM_Calculator::precio_desde()` devuelve el precio más bajo de todo el catálogo
(y el diseño que lo produce), que es lo que se muestra en la página pública.

### AJAX

`POST admin-ajax.php` con `action=ncm_calcular`, `nonce` y las 6 selecciones.
Funciona con y sin sesión, y la respuesta depende de eso:

```php
NCM_Shortcode::respuesta( $resultado, $interno );
```

- `$interno = false` → `modo`, `estado`, `entrada`, `precio_final`,
  `precio_final_formateado`, `moneda`, `texto_nota`, `whatsapp`, `html`.
- `$interno = true` → lo anterior más `codigo`, `gema`, `metal`, `mano_obra`,
  `extras`, `costo_produccion`, `margen_comercial`, `valor_margen`,
  `precio_calculado`, `redondeo_precio`, `desglose` y el `html` con el desglose.

Los catálogos para los selectores van incrustados en la página en
`ncmCalcData.catalogos` (tipos, diseños por tipo, orígenes, gemas, tallas y
metales disponibles), sin una segunda petición. Ahí no viaja nada sensible.

---

## Pruebas

Hay dos niveles. Las **suites rápidas** no necesitan WordPress:

```bash
bash tests/run.sh
```

Usan el PHP del sistema si está instalado y, si no, un contenedor `php:8.2-cli`
con el plugin montado; definen los pocos stubs de WP que hacen falta.

- `tests/test-calculator.php` — motor de cálculo: los dos casos de aceptación del
  Excel, los 15 diseños, combinaciones cruzadas entre tipos (Anillo + Tennis…),
  disponibilidad, redondeo, y que ningún parámetro en cero divida por cero ni
  levante avisos de PHP.
- `tests/test-respuesta.php` — la frontera de permisos: que una petición sin
  sesión reciba el número pero no gema, metal, margen ni costos (revisando el
  JSON *y* el HTML, en las 30 combinaciones de la matriz), y que una con sesión
  sí reciba el desglose completo.
- `tests/test-admin.php` — guardado del panel: porcentaje ↔ fracción, validación
  (negativos, duplicados, requeridos), lectura de números escritos a mano, y que
  editar un precio en el panel cambie el resultado sin tocar código.

### Entorno de desarrollo con wp-env

Requiere Docker, Node y `@wordpress/env` (`npm -g i @wordpress/env`).

```bash
cd ncm-calculadora
wp-env start        # WordPress en http://localhost:8888 (admin / password)
wp-env stop
wp-env destroy      # empezar de cero
```

El plugin queda montado y **activado**; `.wp-env.json` deja `WP_DEBUG` y
`WP_DEBUG_LOG` encendidos. Atajos útiles:

```bash
wp-env run cli wp plugin list
wp-env run cli wp option get ncm_calc_config --format=json
wp-env logs php
```

### Pruebas de PHPUnit (dentro de WordPress)

Las dependencias de desarrollo se instalan una vez (no hace falta tener Composer
en el sistema):

```bash
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer composer:2 install
```

Y las pruebas corren en el entorno de tests de `wp-env`:

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/ncm-calculadora vendor/bin/phpunit
wp-env run tests-cli --env-cwd=wp-content/plugins/ncm-calculadora vendor/bin/phpunit --testdox
```

- `tests/phpunit/CalculadoraTest.php` — los dos casos de aceptación del Excel,
  la semilla en `wp_options`, las tildes tras pasar por la base de datos, los 15
  diseños, combinaciones inválidas y el precio "desde" del catálogo.
- `tests/phpunit/RespuestaAjaxTest.php` — el endpoint real: qué recibe un anónimo
  y qué recibe alguien con sesión, más el rechazo por nonce.
- `tests/phpunit/ShortcodeTest.php` — los dos shortcodes, el contenido de SEO y
  que dos instancias en la misma página no repitan ids.

Ni `vendor/`, ni `tests/`, ni `composer.json`, ni `.wp-env.json` entran al `.zip`.

### Pruebas de integración (WordPress real)

```bash
bash tests/integracion-wp.sh
```

Levanta `mariadb` + `wordpress` en Docker con el plugin montado y comprueba lo
que no se puede probar sin WP: activación y siembra, los dos casos de aceptación
dentro de WordPress, el shortcode con y sin sesión, que los assets solo carguen
donde está el shortcode, el AJAX (válido, sin sesión, nonce inválido,
`REVISAR_CONFIGURACION`), que un editor no entre al
panel pero sí pueda cotizar, que reactivar no pise ediciones, y que el log de
PHP quede limpio. Al terminar desmonta todo.

Casos de aceptación obligatorios (deben dar exacto):

| Configuración                                                       | Resultado                |
| ------------------------------------------------------------------- | ------------------------ |
| Pulsera / Bangle (Rígida) / Natural / Diamante / Redonda / Oro blanco | `DESDE $14.480.000 COP`  |
| Anillo / Solitario / Natural / Diamante / Redonda / Oro blanco        | `DESDE $19.290.000 COP`  |

---

## Empaquetar para instalar

```bash
bash bin/empaquetar.sh
```

Corre las pruebas y, si pasan, deja `dist/ncm-calculadora-<version>.zip` listo
para subir desde **Plugins → Añadir nuevo → Subir plugin**. El paquete excluye
`tests/`: esos archivos se ejecutan fuera de WordPress y no deben quedar
accesibles por URL dentro de `wp-content/plugins/`.

Para subir la versión, cambia `Version:` en la cabecera de `ncm-calculadora.php`
y la constante `NCM_CALC_VERSION` (esa constante también rompe la caché de los
assets, así que conviene subirla en cada entrega).

---

## Estructura

```
ncm-calculadora/
├── ncm-calculadora.php           # header del plugin + bootstrap
├── includes/
│   ├── class-ncm-data.php        # acceso a la config y datos semilla
│   └── class-ncm-calculator.php  # motor de cálculo (puro, sin WP)
├── admin/
│   └── class-ncm-admin.php       # panel de configuración
├── public/
│   └── class-ncm-shortcode.php   # shortcodes público e interno + AJAX
├── assets/
│   ├── css/{ncm-calculadora,ncm-admin}.css
│   └── js/{ncm-calculadora,ncm-admin}.js
├── .wp-env.json             # entorno de desarrollo (no se empaqueta)
├── composer.json            # dependencias de desarrollo (no se empaqueta)
├── phpunit.xml.dist
├── tests/
│   ├── phpunit/             # pruebas dentro de WordPress (wp-env)
│   ├── run.sh               # suites PHP (no requieren WordPress)
│   ├── test-calculator.php
│   ├── test-admin.php
│   ├── test-respuesta.php   # filtrado público / interno
│   └── integracion-wp.sh    # pruebas contra un WordPress real en Docker
└── README.md
```

La raíz del repositorio es el plugin: lo que se ve arriba es lo que se copia a
`wp-content/plugins/ncm-calculadora/`. Las notas de trabajo (`CLAUDE.md`,
`FASES_Calculadora_NCM_WordPress.md`, `PENDIENTES.md`) y el empaquetador
`bin/empaquetar.sh` viven en local y están fuera del control de versiones.

`NCM_Calculator` no llama a ninguna función de WordPress ni conoce ningún número
del Excel: recibe la configuración ya normalizada, por eso se puede ejecutar y
testear fuera de WordPress.

---

## Seguridad

La calculadora pública y la interna comparten un solo endpoint AJAX
(`ncm_calcular`), registrado tanto para `wp_ajax_` como para `wp_ajax_nopriv_`.
**Quién pregunta decide qué se responde, y esa decisión es del servidor.**

- `NCM_Shortcode::respuesta()` arma la carga a partir de `is_user_logged_in()`.
  La versión pública es una *allowlist*: precio final, la selección que el propio
  visitante hizo, moneda y nota legal.
- Lo que **nunca** sale hacia un anónimo: componentes de gema y metal, mano de
  obra, extras, costo de producción, margen (ni su valor), precio antes de
  redondear, código de diseño, desglose y el motivo técnico de un
  `REVISAR CONFIGURACIÓN`. El **mensaje de WhatsApp** entra en la misma regla:
  solo la selección y el precio.
- Eso vale también para el **HTML**: el bloque que recibe un anónimo se genera
  aparte (`html_precio()`) y no contiene ninguna de esas cifras. Nada se oculta
  con CSS ni se borra en JavaScript.
- `tests/test-respuesta.php` recorre la carga entera —JSON y HTML— buscando esas
  cifras en las 30 combinaciones de la matriz.

### Quién puede ver costos y margen

La vista interna (`[ncm_calculadora_interna]`) muestra el **costo de producción y
el margen comercial**, así que está detrás de la capacidad **`edit_posts`**:
autores, editores y administradores. Un **suscriptor no la ve** — ni el
formulario, ni el desglose por AJAX: recibe exactamente lo mismo que un anónimo,
solo el precio.

Se eligió `edit_posts` y no `read` a propósito. Con `read` bastaría con estar
registrado, y eso deja de ser seguro en cuanto el sitio abre el registro por su
cuenta: al instalar WooCommerce, un plugin de membresías o al marcar *Cualquiera
puede registrarse*, cualquier visitante podría darse de alta y leer la estructura
de costos de NCM **sin que nadie toque este plugin**. Con `edit_posts` el riesgo
deja de depender de una configuración externa que puede cambiar sin aviso.

Si en tu instalación quien cotiza tiene otro rol, cambia la constante
`NCM_Shortcode::CAP` en `public/class-ncm-shortcode.php`: `edit_others_posts`
(solo editores) o `manage_options` (solo administradores) la restringen más.
**No la bajes a `read`.**

### Otras medidas

- **Desinstalación limpia:** `uninstall.php` borra `ncm_calc_config` al eliminar
  el plugin, para no dejar precios y márgenes huérfanos en la base de datos.
  Desactivar no borra nada. Las imágenes asignadas no se tocan: son adjuntos de
  la mediateca.
- **Adjuntos validados al mostrar:** una imagen que no exista, que no sea imagen
  o cuya entrada padre esté en borrador o sea privada **no se publica**; la
  tarjeta cae en su monograma. El panel avisa con «⚠ no se verá».
- **La opción no se autocarga:** ronda los 6 KB y solo hace falta en el panel y
  en las páginas con shortcode.
- `index.php` de silencio en cada directorio.

Además:

- El shortcode interno exige `is_user_logged_in()` y la capacidad `edit_posts`;
  a un anónimo —o a un suscriptor— no le pinta ni el formulario.
- **Nonce en las dos rutas**, con y sin sesión.
- El panel exige `manage_options`, con nonce en el guardado y en la restauración.
- Toda entrada pasa por `sanitize_*` y toda salida por `esc_*`.

> **Ojo con la caché de página.** El nonce viaja en el HTML y caduca a las 24 h,
> así que la página pública **debe excluirse del caché de página y del CDN**. Ver
> [Importante: caché](#importante-caché), con el síntoma a reconocer y los
> ajustes concretos para WP Rocket, W3 Total Cache, LiteSpeed y Cloudflare.

---

## Mantenimiento

- **Cambiar precios:** panel de admin, nunca en el código. El motor no tiene
  números del Excel escritos a mano.
- **Agregar un diseño:** pestaña **Diseños** → *Agregar fila*. El tipo de joya
  aparece solo en el formulario en cuanto exista un diseño con ese tipo.
- **Antes de dar por buena cualquier versión:** correr `bash tests/run.sh`. Si
  los dos casos de aceptación no dan exactos, es un bug.
- **Precios sin separador de miles:** `650000`, no `650.000`.
- **Al publicar en un sitio nuevo:** excluir la página pública del caché (ver
  [Importante: caché](#importante-caché)).
- **No bajar `NCM_Shortcode::CAP` a `read`:** dejaría los costos a la vista de
  cualquiera que se registre en el sitio.
- Los nombres de gemas, tallas, metales, tipos y diseños son **llaves de
  búsqueda**: se comparan literalmente, con tildes y paréntesis incluidos
  (`Rubí`, `Bangle (Rígida)`). Cambiar un nombre en el panel equivale a crear
  otro registro.

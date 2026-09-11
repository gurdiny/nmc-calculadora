# Pendientes — Calculadora de Joyas NCM

Decisiones abiertas y trabajo identificado pero no hecho. Cada punto dice qué
pasa hoy, cuál es el riesgo y qué haría falta para cerrarlo.

Última actualización: 2026-09-10. Cerrados los puntos 1, 3 y 4 antes de
publicar en producción.

---

## 1. Nonce y caché de página en la calculadora pública

**Estado: CERRADO** (documentado, sin tocar código).

El nonce sigue viajando en el HTML y sigue caducando a las 24 h; lo que se hizo
fue **documentar la exclusión del caché** en el README, en una sección propia
titulada *Importante: caché*, con:

- el **síntoma** a reconocer (el botón falla con 403 en silencio, sin error en el
  log de PHP);
- la causa y qué cachés afectan (solo el de página y el del CDN);
- los ajustes concretos para **WP Rocket, W3 Total Cache, LiteSpeed y WP Super
  Cache**, y las reglas de bypass para **Cloudflare**;
- cómo comprobar que quedó bien (el nonce debe cambiar entre peticiones separadas
  en el tiempo).

Queda recordado también en *Mantenimiento*, para cuando se despliegue en un sitio
nuevo.

**Lo que sigue abierto (por si algún día molesta):** si el equipo decide que la
página pública *tiene* que estar cacheada, las opciones son refrescar el nonce
por AJAX al cargar (una petición más por visita) o el ESI de LiteSpeed. No se
hizo porque excluir la página es más simple y más robusto.

---

## 2. ¿El ajuste de talla puede ser negativo?

**Estado:** abierto. Necesita una decisión de negocio.

La Fase 2 pidió "validación básica: números no negativos", y se aplicó a todas
las columnas numéricas. Eso incluye el **ajuste de talla**, así que hoy un valor
negativo se guarda como `0` y deja aviso en el panel.

**El caso de uso que quedaría bloqueado:** una talla más barata que la de
referencia (un descuento por talla). Con los datos actuales del Excel todos los
ajustes son `0`, así que no afecta a nada hoy.

**Para cerrarlo:** decidir si `ajuste` se saca de la regla de no-negativos. Es un
cambio de una línea en `NCM_Admin::sanitizar_coleccion()` (quitar `ajuste` de la
validación) más un test.

---

## 3. El documento de fases quedó desactualizado

**Estado: CERRADO.**

`FASES_Calculadora_NCM_WordPress.md` ya describe el modelo real:

- **Nota de precedencia al inicio**, en un bloque destacado: si hay cualquier
  discrepancia con `CLAUDE.md` sobre quién ve qué, gana `CLAUDE.md`. Y dice
  explícitamente que el registro `wp_ajax_nopriv_ncm_calcular` **es correcto y no
  se debe quitar**.
- **Fase 4 reescrita**: un endpoint para los dos modos, nonce siempre, la
  decisión en `is_user_logged_in()`, la tabla de allowlist pública frente a la
  respuesta interna, y el HTML público generado aparte. Lleva una *nota
  histórica* que dice qué decía antes y por qué ya no aplica.
- **Fase 5 reescrita**: los dos shortcodes, qué devuelve cada uno, el contenido
  de SEO y las tarjetas con imagen.
- **Sección "Qué NO hacer"** al final, encabezada por "no quitar el `nopriv`".
- Nota en los datos semilla sobre la columna `imagen`, que el Excel no tiene.

---

## 4. El proyecto no está bajo control de versiones

**Estado: CERRADO.**

`git init` hecho, con un primer commit de los 27 archivos del proyecto y el árbol
limpio, así que `/security-review` ya puede correr.

El `.gitignore` deja fuera `vendor/`, `dist/`, `node_modules/`,
`.wp-env.json` y `.phpunit.result.cache`. **Sí** se versiona `composer.lock`,
para que las dependencias de desarrollo sean reproducibles.

---

## 5. Verificación en navegador real

**Estado:** parcial.

Todo el flujo está verificado por HTTP y con PHPUnit dentro de WordPress, pero
lo que depende del navegador se ha comprobado por lógica y sintaxis, no
ejecutándolo:

- el **botón Imprimir / Guardar PDF** y cómo se ve la hoja resultante;
- los botones de **agregar / eliminar / reordenar filas** del panel;
- el **selector de imágenes** del panel (media library).

**Para cerrarlo:** abrir http://localhost:8888 con `wp-env` levantado y
recorrerlo a mano, o añadir pruebas de navegador (Playwright).

---

## 6. Imágenes de los tipos de joya

**Estado:** decisión tomada, revisable.

Los tipos de joya (Anillo, Aretes, Pulsera, Dije) no son una matriz propia: salen
de la columna `tipo` de los diseños. Para que las tarjetas de tipo tengan imagen
sin inventar una pestaña nueva en el panel, **cada tipo usa la imagen del primer
diseño de ese tipo que tenga una**.

**Si molesta:** habría que añadir una matriz `tipos` (nombre + imagen) al panel y
que los diseños apunten a ella. Es más limpio, pero cambia el modelo de datos y
la semilla.

---

## 7. La vista interna mostraba costos a cualquier usuario registrado

**Estado: CERRADO** en la v1.2.1.

`NCM_Shortcode::CAP` pasó de `read` a **`edit_posts`**. Un suscriptor ya no ve el
formulario interno y, si llama al AJAX, recibe la respuesta **pública**: solo el
precio, sin costo de producción ni margen.

Se subió como defensa en profundidad, sin esperar a que el registro se abriera:
el riesgo dependía de una configuración del sitio (WooCommerce, membresías,
«cualquiera puede registrarse») que puede cambiar sin que nadie toque el plugin.

Cubierto por pruebas: un suscriptor recibe la carga pública y ninguna cifra
sensible; autor, colaborador, editor y administrador reciben el desglose.

**Si alguna vez hace falta restringir más:** `edit_others_posts` (solo editores) o
`manage_options` (solo administradores). No bajarla a `read`.

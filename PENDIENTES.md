# Pendientes — Calculadora de Joyas NCM

Decisiones abiertas y trabajo identificado pero no hecho. Cada punto dice qué
pasa hoy, cuál es el riesgo y qué haría falta para cerrarlo.

Última actualización: 2026-09-10.

---

## 1. Nonce y caché de página en la calculadora pública

**Estado:** abierto. Necesita una decisión de infraestructura.

Desde que la calculadora es pública, el nonce de WordPress viaja dentro del HTML
de la página. Los nonces caducan (12–24 h) y van atados a la sesión.

**Riesgo:** si la página pública se sirve desde un plugin de caché o un CDN con
TTL largo, los visitantes recibirán un nonce ya vencido y el cálculo responderá
**403**. La página se verá bien —el texto y el precio "desde" están en el HTML—
pero el botón de calcular fallará en silencio para una parte del tráfico.

**Opciones:**

1. **Excluir del caché** la página de la calculadora (o el fragmento del nonce).
   Es lo más simple y no toca código.
2. **Refrescar el nonce por AJAX** al cargar la página (endpoint público que
   devuelve un nonce nuevo). Añade una petición por visita.
3. **Quitar el nonce en la ruta anónima.** El endpoint es de solo lectura y no
   cambia nada, así que el CSRF no aplica; pero contradice la instrucción
   explícita de mantener el nonce en ambos casos, así que no se hizo.

**Recomendación:** la 1. Si el sitio no usa caché de página, no hay nada que
hacer, pero conviene dejarlo escrito antes de que alguien instale un plugin de
caché.

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

**Estado:** abierto. Trabajo de documentación.

`FASES_Calculadora_NCM_WordPress.md` sigue describiendo la calculadora como
**solo interna** en las fases 4 y 5 ("registrar solo `wp_ajax_ncm_calcular` (NO
`nopriv`)", "solo visible logueado"). Eso ya no es cierto: desde el cambio de
alcance hay dos modos y `CLAUDE.md` documenta el modelo correcto.

**Riesgo:** que alguien (persona o agente) lea el documento de fases como fuente
de verdad y "arregle" el `nopriv`, rompiendo la calculadora pública.

**Para cerrarlo:** reescribir las fases 4 y 5 del documento para que describan el
filtrado en servidor, o marcarlas como históricas y remitir a `CLAUDE.md`.

---

## 4. El proyecto no está bajo control de versiones

**Estado:** abierto.

La carpeta no es un repositorio git, así que no hay historial, no se puede
revisar un diff y `/security-review` no puede correr.

**Para cerrarlo:** `git init`, un `.gitignore` que excluya `vendor/`, `dist/`,
`node_modules/` y `.wp-env.json` si se prefiere no versionarlo, y un primer
commit.

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

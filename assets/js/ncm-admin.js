/**
 * Panel de configuración: filas repetibles.
 */
( function () {
	'use strict';

	/**
	 * Reindexa los name="ncm[coleccion][i][campo]" de una tabla.
	 *
	 * @param {HTMLElement} tabla Tabla a reindexar.
	 */
	function reindexar( tabla ) {
		var coleccion = tabla.getAttribute( 'data-coleccion' );
		var filas = tabla.querySelectorAll( 'tbody > tr' );

		Array.prototype.forEach.call( filas, function ( fila, indice ) {
			var campos = fila.querySelectorAll( '[name]' );

			Array.prototype.forEach.call( campos, function ( campo ) {
				var nombre = campo.getAttribute( 'name' );
				var clave = nombre.substring( nombre.lastIndexOf( '[' ) + 1, nombre.length - 1 );

				campo.setAttribute( 'name', 'ncm[' + coleccion + '][' + indice + '][' + clave + ']' );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Tipo de joya: desplegable sobre el campo de texto
	 * ------------------------------------------------------------------ */

	/**
	 * Tipos que ya aparecen en algún desplegable, en orden.
	 *
	 * @return {Array} Lista de tipos.
	 */
	function tiposConocidos() {
		var select = document.querySelector( '[data-ncm-tipo-select]' );
		var fuera = [];

		if ( ! select ) {
			return fuera;
		}

		Array.prototype.forEach.call( select.options, function ( opcion ) {
			if ( '' !== opcion.value ) {
				fuera.push( opcion.value );
			}
		} );

		return fuera;
	}

	/**
	 * Pone el desplegable al mando y deja el campo de texto solo de respaldo.
	 *
	 * El texto es el que se envía, así que el desplegable lo que hace es
	 * escribir en él. Si la fila trae un tipo que no está en la lista —una
	 * errata heredada, por ejemplo— se deja el texto a la vista para poder
	 * arreglarlo a mano.
	 *
	 * @param {HTMLElement} celda Contenedor [data-ncm-tipo].
	 */
	function prepararTipo( celda ) {
		var select = celda.querySelector( '[data-ncm-tipo-select]' );
		var texto = celda.querySelector( '[data-ncm-tipo-texto]' );

		if ( ! select || ! texto || celda.hasAttribute( 'data-listo' ) ) {
			return;
		}

		celda.setAttribute( 'data-listo', '1' );
		select.hidden = false;

		var conocido = '' === texto.value || tiposConocidos().indexOf( texto.value ) !== -1;

		texto.hidden = conocido;
		select.value = conocido ? texto.value : '';

		select.addEventListener( 'change', function () {
			texto.value = select.value;
		} );
	}

	/** Prepara las celdas de tipo que aún no lo estén. */
	function prepararTipos() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ncm-tipo]' ),
			prepararTipo
		);
	}

	/**
	 * Añade un tipo a todos los desplegables de la tabla.
	 *
	 * @param {string} nombre Tipo nuevo.
	 * @return {boolean} Si se pudo añadir.
	 */
	function agregarTipo( nombre ) {
		nombre = nombre.replace( /^\s+|\s+$/g, '' );

		if ( '' === nombre ) {
			return false;
		}

		// Comparación sin distinguir mayúsculas: "Collar" y "collar" son el mismo
		// tipo, y tenerlos por separado parte el catálogo en dos.
		var existe = false;

		tiposConocidos().forEach( function ( tipo ) {
			if ( tipo.toLowerCase() === nombre.toLowerCase() ) {
				existe = true;
			}
		} );

		if ( existe ) {
			return false;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ncm-tipo-select]' ),
			function ( select ) {
				var opcion = document.createElement( 'option' );

				opcion.value = nombre;
				opcion.textContent = nombre;
				select.appendChild( opcion );
			}
		);

		// La plantilla de fila nueva también, para que nazca con el tipo dentro.
		var plantilla = document.getElementById( 'ncm-plantilla-disenos' );

		if ( plantilla && plantilla.innerHTML.indexOf( 'data-ncm-tipo-select' ) !== -1 ) {
			plantilla.innerHTML = plantilla.innerHTML.replace(
				'</select>',
				'<option value="' + nombre.replace( /"/g, '&quot;' ) + '">' + nombre + '</option></select>'
			);
		}

		return true;
	}

	var textos = window.ncmAdminData || {};

	/**
	 * Abre la mediateca y guarda el id de la imagen elegida en la fila.
	 *
	 * @param {HTMLElement} campo Contenedor [data-ncm-imagen] de la fila.
	 */
	function elegirImagen( campo ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var marco = window.wp.media( {
			title: textos.tituloMedia || 'Elegir imagen',
			button: { text: textos.botonMedia || 'Usar esta imagen' },
			library: { type: 'image' },
			multiple: false
		} );

		marco.on( 'select', function () {
			var adjunto = marco.state().get( 'selection' ).first().toJSON();
			var miniatura = adjunto.sizes && adjunto.sizes.thumbnail
				? adjunto.sizes.thumbnail.url
				: adjunto.url;

			campo.querySelector( '.ncm-imagen__id' ).value = adjunto.id;

			var vista = campo.querySelector( '.ncm-imagen__vista' );
			vista.src = miniatura;
			vista.hidden = false;

			campo.querySelector( '.ncm-imagen__placeholder' ).hidden = true;
			campo.querySelector( '.ncm-imagen__quitar' ).hidden = false;
		} );

		marco.open();
	}

	/**
	 * Deja la fila sin imagen.
	 *
	 * @param {HTMLElement} campo Contenedor [data-ncm-imagen] de la fila.
	 */
	function quitarImagen( campo ) {
		campo.querySelector( '.ncm-imagen__id' ).value = '';

		var vista = campo.querySelector( '.ncm-imagen__vista' );
		vista.removeAttribute( 'src' );
		vista.hidden = true;

		campo.querySelector( '.ncm-imagen__placeholder' ).hidden = false;
		campo.querySelector( '.ncm-imagen__quitar' ).hidden = true;
	}

	document.addEventListener( 'click', function ( evento ) {
		var destino = evento.target;

		var boton = destino.closest ? destino.closest( '.ncm-imagen__boton' ) : null;

		if ( boton ) {
			evento.preventDefault();
			elegirImagen( boton.closest( '[data-ncm-imagen]' ) );
			return;
		}

		if ( destino.classList.contains( 'ncm-imagen__quitar' ) ) {
			evento.preventDefault();
			quitarImagen( destino.closest( '[data-ncm-imagen]' ) );
			return;
		}

		if ( destino.classList.contains( 'ncm-agregar-tipo' ) ) {
			evento.preventDefault();

			var caja = destino.parentNode.querySelector( '.ncm-tipo-nuevo' );

			destino.hidden = true;
			caja.hidden = false;
			caja.querySelector( '.ncm-tipo-nuevo__campo' ).focus();

			return;
		}

		if ( destino.classList.contains( 'ncm-tipo-nuevo__confirmar' ) ) {
			evento.preventDefault();
			confirmarTipoNuevo( destino.closest( '.ncm-tipo-nuevo' ) );

			return;
		}

		if ( destino.classList.contains( 'ncm-tipo-nuevo__cancelar' ) ) {
			evento.preventDefault();
			cerrarTipoNuevo( destino.closest( '.ncm-tipo-nuevo' ) );

			return;
		}

		if ( destino.classList.contains( 'ncm-agregar-fila' ) ) {
			evento.preventDefault();

			var coleccion = destino.getAttribute( 'data-coleccion' );
			var tabla = document.querySelector( '.ncm-tabla[data-coleccion="' + coleccion + '"]' );
			var plantilla = document.getElementById( 'ncm-plantilla-' + coleccion );

			if ( ! tabla || ! plantilla ) {
				return;
			}

			var cuerpo = tabla.querySelector( 'tbody' );
			var html = plantilla.innerHTML.replace( /__i__/g, String( cuerpo.rows.length ) );

			cuerpo.insertAdjacentHTML( 'beforeend', html );
			reindexar( tabla );
			prepararTipos();

			var nueva = cuerpo.rows[ cuerpo.rows.length - 1 ];
			var primero = nueva ? nueva.querySelector( 'input' ) : null;

			if ( primero ) {
				primero.focus();
			}

			return;
		}

		if ( destino.classList.contains( 'ncm-mover-fila' ) ) {
			evento.preventDefault();

			var origen = destino.closest( 'tr' );
			var tabla = destino.closest( '.ncm-tabla' );

			if ( ! origen || ! tabla ) {
				return;
			}

			var direccion = parseInt( destino.getAttribute( 'data-dir' ), 10 );
			var vecino = direccion < 0 ? origen.previousElementSibling : origen.nextElementSibling;

			if ( ! vecino ) {
				return;
			}

			if ( direccion < 0 ) {
				origen.parentNode.insertBefore( origen, vecino );
			} else {
				origen.parentNode.insertBefore( vecino, origen );
			}

			reindexar( tabla );

			// El botón se movió con la fila: se devuelve el foco donde estaba.
			var mismoBoton = origen.querySelector( '.ncm-mover-fila[data-dir="' + direccion + '"]' );

			if ( mismoBoton ) {
				mismoBoton.focus();
			}

			return;
		}

		if ( destino.classList.contains( 'ncm-borrar-fila' ) ) {
			evento.preventDefault();

			var fila = destino.closest( 'tr' );
			var contenedor = destino.closest( '.ncm-tabla' );

			if ( fila && contenedor ) {
				fila.parentNode.removeChild( fila );
				reindexar( contenedor );
			}
		}
	} );

	/**
	 * Cierra la cajita de tipo nuevo y devuelve el botón a su sitio.
	 *
	 * @param {HTMLElement} caja Contenedor .ncm-tipo-nuevo.
	 */
	function cerrarTipoNuevo( caja ) {
		if ( ! caja ) {
			return;
		}

		var campo = caja.querySelector( '.ncm-tipo-nuevo__campo' );
		var boton = caja.parentNode.querySelector( '.ncm-agregar-tipo' );

		campo.value = '';
		campo.setCustomValidity( '' );
		caja.hidden = true;

		if ( boton ) {
			boton.hidden = false;
			boton.focus();
		}
	}

	/**
	 * Toma el nombre escrito y lo añade a los desplegables.
	 *
	 * @param {HTMLElement} caja Contenedor .ncm-tipo-nuevo.
	 */
	function confirmarTipoNuevo( caja ) {
		if ( ! caja ) {
			return;
		}

		var campo = caja.querySelector( '.ncm-tipo-nuevo__campo' );

		if ( agregarTipo( campo.value ) ) {
			cerrarTipoNuevo( caja );

			return;
		}

		// Vacío o repetido: se avisa sin perder lo escrito.
		campo.setCustomValidity(
			'' === campo.value.replace( /^\s+|\s+$/g, '' )
				? 'Escribe el nombre del tipo.'
				: 'Ese tipo de joya ya existe.'
		);
		campo.reportValidity();
	}

	// Enter dentro del campo añade el tipo; Escape cierra. Sin esto, Enter
	// enviaría el formulario entero del panel.
	document.addEventListener( 'keydown', function ( evento ) {
		var campo = evento.target;

		if ( ! campo.classList || ! campo.classList.contains( 'ncm-tipo-nuevo__campo' ) ) {
			return;
		}

		if ( 'Enter' === evento.key ) {
			evento.preventDefault();
			confirmarTipoNuevo( campo.closest( '.ncm-tipo-nuevo' ) );
		} else if ( 'Escape' === evento.key ) {
			evento.preventDefault();
			cerrarTipoNuevo( campo.closest( '.ncm-tipo-nuevo' ) );
		} else {
			campo.setCustomValidity( '' );
		}
	} );

	// Con JavaScript, el desplegable manda; sin él queda el campo de texto.
	prepararTipos();

	Array.prototype.forEach.call(
		document.querySelectorAll( '.ncm-agregar-tipo' ),
		function ( boton ) {
			boton.hidden = false;
		}
	);
} )();

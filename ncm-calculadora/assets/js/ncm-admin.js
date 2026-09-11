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
} )();

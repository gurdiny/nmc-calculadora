/**
 * Front de la calculadora NCM.
 *
 * Sin dependencias. El HTML de los pasos y de las tarjetas lo pinta el
 * servidor —también decide cuánta información lleva el resultado—; aquí se
 * gestiona el avance entre pasos, la cascada tipo → diseño y la petición.
 *
 * En la misma página pueden convivir varias instancias (pública e interna).
 */
( function () {
	'use strict';

	var datos = window.ncmCalcData || {};
	var textos = datos.textos || {};
	var campos = [ 'tipo', 'diseno', 'origen', 'gema', 'talla', 'metal' ];

	/**
	 * Prepara una instancia de la calculadora.
	 *
	 * @param {HTMLElement} raiz Contenedor de la instancia.
	 */
	function iniciar( raiz ) {
		var formulario = raiz.querySelector( '.ncm-calc__form' );
		var boton = raiz.querySelector( '.ncm-calc__calcular' );
		var limpiar = raiz.querySelector( '.ncm-calc__limpiar' );
		var aviso = raiz.querySelector( '.ncm-calc__aviso' );
		var resultado = raiz.querySelector( '.ncm-calc__resultado' );
		var resumen = raiz.querySelector( '[data-resumen]' );
		var barraProgreso = raiz.querySelector( '[data-progreso-barra]' );
		var pasos = raiz.querySelectorAll( '[data-paso]' );

		if ( ! formulario || ! boton || ! resultado ) {
			return;
		}

		var ultimaPeticion = 0;

		/**
		 * Devuelve el paso de un campo.
		 *
		 * @param {string} campo Nombre del campo.
		 * @return {HTMLElement|null} Sección del paso.
		 */
		function paso( campo ) {
			return raiz.querySelector( '[data-paso="' + campo + '"]' );
		}

		/**
		 * Valor elegido en un campo.
		 *
		 * @param {string} campo Nombre del campo.
		 * @return {string} Valor, o '' si no hay ninguno.
		 */
		function valor( campo ) {
			var marcado = raiz.querySelector( '[data-campo="' + campo + '"]:checked' );

			return marcado ? marcado.value : '';
		}

		/**
		 * Abre un paso y cierra los demás.
		 *
		 * @param {string} campo Campo del paso a abrir.
		 */
		function abrir( campo ) {
			Array.prototype.forEach.call( pasos, function ( seccion ) {
				var activo = seccion.getAttribute( 'data-paso' ) === campo;

				seccion.classList.toggle( 'ncm-paso--activo', activo );

				var disparador = seccion.querySelector( '[data-abrir-paso]' );

				if ( disparador ) {
					disparador.setAttribute( 'aria-expanded', activo ? 'true' : 'false' );
				}
			} );
		}

		/** Deja visibles solo los diseños del tipo de joya elegido. */
		function filtrarDisenos() {
			var tipo = valor( 'tipo' );
			var seccion = paso( 'diseno' );

			if ( ! seccion ) {
				return;
			}

			var visibles = 0;

			Array.prototype.forEach.call( seccion.querySelectorAll( '.ncm-opcion' ), function ( opcion ) {
				var coincide = opcion.getAttribute( 'data-tipo' ) === tipo;

				opcion.hidden = ! coincide;

				if ( coincide ) {
					visibles++;
					return;
				}

				// Un diseño que deja de aplicar no puede seguir seleccionado.
				var radio = opcion.querySelector( '.ncm-opcion__radio' );

				if ( radio && radio.checked ) {
					radio.checked = false;
				}
			} );

			var vacio = seccion.querySelector( '[data-sin-opciones]' );

			if ( vacio ) {
				vacio.hidden = visibles > 0;
			}
		}

		/** Refresca la etiqueta de cada paso, el resumen y la barra. */
		function pintarEstado() {
			var elegidos = 0;

			campos.forEach( function ( campo ) {
				var seccion = paso( campo );
				var elegido = valor( campo );

				if ( elegido ) {
					elegidos++;
				}

				if ( ! seccion ) {
					return;
				}

				seccion.classList.toggle( 'ncm-paso--completo', !! elegido );

				var etiqueta = seccion.querySelector( '[data-elegido]' );

				if ( etiqueta ) {
					etiqueta.textContent = elegido;
				}
			} );

			if ( barraProgreso ) {
				barraProgreso.style.width = ( elegidos / campos.length * 100 ) + '%';
			}

			if ( resumen ) {
				if ( elegidos === 0 ) {
					resumen.innerHTML = '<span class="ncm-calc__resumen-vacio">'
						+ ( textos.resumenVacio || 'Elige las seis opciones para ver el precio.' )
						+ '</span>';
				} else {
					resumen.innerHTML = '';

					campos.forEach( function ( campo ) {
						var elegido = valor( campo );

						if ( ! elegido ) {
							return;
						}

						var chip = document.createElement( 'button' );
						chip.type = 'button';
						chip.className = 'ncm-chip';
						chip.textContent = elegido;
						chip.setAttribute( 'data-ir-a', campo );
						chip.title = textos.editar || 'Cambiar';
						resumen.appendChild( chip );
					} );
				}
			}

			if ( limpiar ) {
				limpiar.hidden = elegidos === 0;
			}

			boton.disabled = elegidos < campos.length;

			return elegidos === campos.length;
		}

		/**
		 * Muestra un mensaje bajo el formulario.
		 *
		 * @param {string} texto Mensaje ('' lo oculta).
		 */
		function mostrarAviso( texto ) {
			if ( ! aviso ) {
				return;
			}

			aviso.textContent = texto || '';
			aviso.classList.toggle( 'ncm-calc__aviso--visible', !! texto );
		}

		/** Pide el cálculo al servidor y pinta lo que devuelva. */
		function calcular() {
			var seleccion = {};
			var completa = true;

			campos.forEach( function ( campo ) {
				seleccion[ campo ] = valor( campo );

				if ( ! seleccion[ campo ] ) {
					completa = false;
				}
			} );

			if ( ! completa ) {
				resultado.innerHTML = '';
				mostrarAviso( textos.incompleto || 'Completa las seis opciones para calcular.' );
				return;
			}

			mostrarAviso( '' );
			raiz.classList.add( 'ncm-calc--cargando' );
			boton.disabled = true;
			boton.textContent = textos.calculando || 'Calculando…';

			var cuerpo = new FormData();
			cuerpo.append( 'action', datos.accion );
			cuerpo.append( 'nonce', datos.nonce );

			// Desde qué shortcode se pregunta. El servidor lo usa solo para
			// rebajar: pedir 'interno' sin permisos no da nada extra.
			cuerpo.append( 'modo', raiz.getAttribute( 'data-ncm-calc' ) || 'publico' );

			campos.forEach( function ( campo ) {
				cuerpo.append( campo, seleccion[ campo ] );
			} );

			// Con el cálculo automático puede haber varias peticiones en vuelo:
			// solo se pinta la última que se lanzó.
			var peticion = ++ultimaPeticion;

			fetch( datos.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: cuerpo
			} )
				.then( function ( respuesta ) {
					return respuesta.json();
				} )
				.then( function ( json ) {
					if ( peticion !== ultimaPeticion ) {
						return;
					}

					var carga = json && json.data ? json.data : {};

					if ( carga.html ) {
						resultado.innerHTML = carga.html;
						resultado.classList.add( 'ncm-calc__resultado--nuevo' );

						window.setTimeout( function () {
							resultado.classList.remove( 'ncm-calc__resultado--nuevo' );
						}, 700 );
					} else {
						resultado.innerHTML = '';
						mostrarAviso( carga.mensaje || textos.errorRed );
					}
				} )
				.catch( function () {
					if ( peticion === ultimaPeticion ) {
						resultado.innerHTML = '';
						mostrarAviso( textos.errorRed || 'No se pudo calcular.' );
					}
				} )
				.then( function () {
					if ( peticion !== ultimaPeticion ) {
						return;
					}

					raiz.classList.remove( 'ncm-calc--cargando' );
					boton.disabled = false;
					boton.textContent = textos.calcular || 'Calcular precio';
				} );
		}

		/** Devuelve la calculadora a su estado inicial. */
		function reiniciar() {
			Array.prototype.forEach.call( raiz.querySelectorAll( '.ncm-opcion__radio' ), function ( radio ) {
				radio.checked = false;
			} );

			filtrarDisenos();
			pintarEstado();
			resultado.innerHTML = '';
			mostrarAviso( '' );
			abrir( 'tipo' );
		}

		// Elegir una opción: avanza al siguiente paso y, si ya está todo, calcula.
		raiz.addEventListener( 'change', function ( evento ) {
			var radio = evento.target;

			if ( ! radio.classList || ! radio.classList.contains( 'ncm-opcion__radio' ) ) {
				return;
			}

			var campo = radio.getAttribute( 'data-campo' );

			if ( 'tipo' === campo ) {
				filtrarDisenos();
			}

			var completa = pintarEstado();
			var siguiente = campos[ campos.indexOf( campo ) + 1 ];

			if ( siguiente && ! valor( siguiente ) ) {
				abrir( siguiente );
			} else if ( completa ) {
				abrir( '' );
			}

			if ( completa ) {
				calcular();
			} else {
				resultado.innerHTML = '';
			}
		} );

		// Cabecera de un paso: abrir/cerrar. Chip del resumen: volver a ese paso.
		raiz.addEventListener( 'click', function ( evento ) {
			var destino = evento.target;

			var cabecera = destino.closest ? destino.closest( '[data-abrir-paso]' ) : null;

			if ( cabecera ) {
				evento.preventDefault();

				var seccion = cabecera.closest( '[data-paso]' );
				var abierto = seccion.classList.contains( 'ncm-paso--activo' );

				abrir( abierto ? '' : seccion.getAttribute( 'data-paso' ) );
				return;
			}

			var chip = destino.closest ? destino.closest( '[data-ir-a]' ) : null;

			if ( chip ) {
				evento.preventDefault();
				abrir( chip.getAttribute( 'data-ir-a' ) );
			}
		} );

		formulario.addEventListener( 'submit', function ( evento ) {
			evento.preventDefault();
			calcular();
		} );

		if ( limpiar ) {
			limpiar.addEventListener( 'click', reiniciar );
		}

		raiz.classList.add( 'ncm-calc--con-js' );
		filtrarDisenos();
		pintarEstado();
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-ncm-calc]' ), iniciar );
} )();

<?php
/**
 * Shortcodes público e interno, y el endpoint AJAX que los alimenta.
 *
 * La calculadora tiene dos modos:
 *
 * - `[ncm_calculadora]`      Pública. La usa cualquiera, con o sin sesión.
 *                            Devuelve únicamente el precio "DESDE $X COP".
 * - `[ncm_calculadora_interna]` Interna. Solo se pinta a usuarios con sesión y
 *                            muestra el desglose completo más imprimir/PDF.
 *
 * El filtrado NO depende del front: el servidor decide qué sale del endpoint
 * según `is_user_logged_in()`. A una petición anónima jamás se le envían
 * costos, componentes ni margen, ni siquiera dentro del HTML.
 *
 * @package NCM_Calculadora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCM_Shortcode {

	/** Acción AJAX. */
	const ACCION = 'ncm_calcular';

	/** Capacidad mínima para ver el desglose interno. */
	const CAP = 'read';

	/** Modo público: solo el precio. */
	const MODO_PUBLICO = 'publico';

	/** Modo interno: desglose completo. */
	const MODO_INTERNO = 'interno';

	/** Evita encolar y localizar los assets dos veces en la misma página. */
	private static $encolado = false;

	/** Contador para dar ids únicos a cada instancia del shortcode. */
	private static $instancias = 0;

	/** Engancha shortcodes y AJAX. */
	public static function init() {
		add_shortcode( 'ncm_calculadora', array( __CLASS__, 'render_publica' ) );
		add_shortcode( 'ncm_calculadora_interna', array( __CLASS__, 'render_interna' ) );

		// Ambos registros apuntan al mismo handler; el filtrado va por dentro.
		add_action( 'wp_ajax_' . self::ACCION, array( __CLASS__, 'ajax_calcular' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION, array( __CLASS__, 'ajax_calcular' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'registrar_assets' ) );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Registra los assets del front y, si la entrada actual usa alguno de los
	 * shortcodes, los encola desde ya para que el CSS entre por la cabecera.
	 */
	public static function registrar_assets() {
		wp_register_style(
			'ncm-calculadora',
			NCM_CALC_URL . 'assets/css/ncm-calculadora.css',
			array(),
			NCM_CALC_VERSION
		);

		wp_register_script(
			'ncm-calculadora',
			NCM_CALC_URL . 'assets/js/ncm-calculadora.js',
			array(),
			NCM_CALC_VERSION,
			true
		);

		$entrada = get_post();

		if ( ! $entrada instanceof WP_Post ) {
			return;
		}

		if ( has_shortcode( $entrada->post_content, 'ncm_calculadora' )
			|| has_shortcode( $entrada->post_content, 'ncm_calculadora_interna' ) ) {
			self::encolar_assets();
		}
	}

	/**
	 * Encola el CSS y el JS del front con sus datos.
	 *
	 * Los temas de bloques renderizan el contenido *antes* de que se disparen
	 * los assets, así que si un shortcode se pinta cuando todavía no hay nada
	 * registrado, el encolado se aplaza hasta `wp_enqueue_scripts`.
	 */
	public static function encolar_assets() {
		if ( self::$encolado ) {
			return;
		}

		if ( ! wp_script_is( 'ncm-calculadora', 'registered' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'encolar_assets' ), 20 );

			return;
		}

		self::$encolado = true;

		wp_enqueue_style( 'ncm-calculadora' );
		wp_enqueue_script( 'ncm-calculadora' );
		wp_localize_script( 'ncm-calculadora', 'ncmCalcData', self::datos_js() );
	}

	/**
	 * Datos que necesita el JS del front.
	 *
	 * Son catálogos y textos: nada sensible. El desglose nunca viaja por aquí.
	 *
	 * @return array
	 */
	private static function datos_js() {
		$catalogos = NCM_Data::get_catalogos();

		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'accion'         => self::ACCION,
			'nonce'          => wp_create_nonce( self::ACCION ),
			'catalogos'      => $catalogos,
			'disenosPorTipo' => $catalogos['disenos_por_tipo'],
			'textos'         => array(
				'seleccione' => '— Selecciona —',
				'calculando' => 'Calculando…',
				'calcular'   => 'Calcular precio',
				'incompleto'   => 'Completa las seis opciones para calcular.',
				'resumenVacio' => 'Elige las seis opciones para ver el precio.',
				'editar'       => 'Cambiar',
				'errorRed'   => 'No se pudo calcular. Recarga la página e inténtalo de nuevo.',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	/**
	 * `[ncm_calculadora]` — calculadora pública.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML.
	 */
	public static function render_publica( $atts = array() ) {
		return self::render( self::MODO_PUBLICO );
	}

	/**
	 * `[ncm_calculadora_interna]` — calculadora interna con desglose.
	 *
	 * A un visitante sin sesión no se le pinta ni el formulario.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML.
	 */
	public static function render_interna( $atts = array() ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
			return '<div class="ncm-calc ncm-calc--bloqueada"><p>Esta versión de la calculadora es de uso interno. Inicia sesión para usarla.</p></div>';
		}

		return self::render( self::MODO_INTERNO );
	}

	/**
	 * Pinta la calculadora.
	 *
	 * Los seis pasos se imprimen completos en el HTML —con el nombre de cada
	 * opción— aunque el JavaScript solo muestre uno a la vez: así la página
	 * pública tiene todo el catálogo indexable y funciona sin JS.
	 *
	 * @param string $modo MODO_PUBLICO o MODO_INTERNO.
	 * @return string HTML.
	 */
	private static function render( $modo ) {
		$tipos = NCM_Data::get_tipos();

		if ( empty( $tipos ) ) {
			return '<div class="ncm-calc ncm-calc--bloqueada"><p>No hay diseños configurados todavía.</p></div>';
		}

		self::encolar_assets();

		++self::$instancias;

		$id         = 'ncm-calc-' . self::$instancias;
		$publico    = self::MODO_PUBLICO === $modo;
		$parametros = NCM_Data::get_parametros();

		ob_start();
		?>
		<div class="ncm-calc ncm-calc--<?php echo esc_attr( $modo ); ?>"
			id="<?php echo esc_attr( $id ); ?>" data-ncm-calc="<?php echo esc_attr( $modo ); ?>">

			<?php if ( $publico ) : ?>
				<?php self::render_intro_publica( $parametros ); ?>
			<?php endif; ?>

			<form class="ncm-calc__form" novalidate>
				<div class="ncm-calc__progreso" aria-hidden="true">
					<span class="ncm-calc__progreso-barra" data-progreso-barra></span>
				</div>

				<div class="ncm-calc__pasos">
					<?php
					self::render_paso( $id, 1, 'tipo', 'Tipo de joya', '¿Qué pieza quieres cotizar?', self::opciones_tipo() );
					self::render_paso( $id, 2, 'diseno', 'Diseño', 'Elige el estilo de la pieza.', self::opciones_diseno() );
					self::render_paso( $id, 3, 'origen', 'Origen de la gema', 'Natural o cultivada en laboratorio.', self::opciones_origen(), 'compacto' );
					self::render_paso( $id, 4, 'gema', 'Tipo de gema', 'La piedra principal.', self::opciones_gema() );
					self::render_paso( $id, 5, 'talla', 'Talla', 'La forma en que se corta la gema.', self::opciones_talla() );
					self::render_paso( $id, 6, 'metal', 'Metal', 'El material de la montura.', self::opciones_metal() );
					?>
				</div>

				<div class="ncm-calc__barra" data-barra>
					<div class="ncm-calc__resumen" data-resumen>
						<span class="ncm-calc__resumen-vacio">Elige las seis opciones para ver el precio.</span>
					</div>

					<div class="ncm-calc__acciones">
						<button type="button" class="ncm-calc__boton ncm-calc__boton--sec ncm-calc__limpiar" hidden>Limpiar</button>
						<button type="submit" class="ncm-calc__boton ncm-calc__calcular" disabled>Calcular precio</button>
					</div>
				</div>

				<p class="ncm-calc__aviso" role="status" aria-live="polite"></p>
			</form>

			<div class="ncm-calc__resultado" aria-live="polite"></div>
		</div>
		<?php

		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Opciones de cada paso
	 * ------------------------------------------------------------------ */

	/**
	 * Tipos de joya.
	 *
	 * @return array
	 */
	private static function opciones_tipo() {
		$opciones = array();

		foreach ( NCM_Data::get_tipos() as $tipo ) {
			$opciones[] = array(
				'valor'  => $tipo,
				'imagen' => NCM_Data::get_imagen_tipo( $tipo ),
			);
		}

		return $opciones;
	}

	/**
	 * Diseños de todos los tipos.
	 *
	 * Van todos al HTML; el JavaScript deja visibles los del tipo elegido.
	 *
	 * @return array
	 */
	private static function opciones_diseno() {
		$opciones = array();

		foreach ( NCM_Data::get_disenos() as $diseno ) {
			$opciones[] = array(
				'valor'  => $diseno['diseno'],
				'imagen' => $diseno['imagen'],
				'datos'  => array( 'tipo' => $diseno['tipo'] ),
				'oculto' => true,
			);
		}

		return $opciones;
	}

	/**
	 * Orígenes de la gema.
	 *
	 * @return array
	 */
	private static function opciones_origen() {
		$opciones = array();

		foreach ( NCM_Data::get_origenes() as $origen ) {
			$opciones[] = array( 'valor' => $origen );
		}

		return $opciones;
	}

	/**
	 * Gemas.
	 *
	 * @return array
	 */
	private static function opciones_gema() {
		$opciones = array();

		foreach ( NCM_Data::get_gemas() as $gema ) {
			$opciones[] = array(
				'valor'  => $gema['tipo_gema'],
				'imagen' => $gema['imagen'],
			);
		}

		return $opciones;
	}

	/**
	 * Tallas disponibles.
	 *
	 * @return array
	 */
	private static function opciones_talla() {
		$opciones = array();

		foreach ( NCM_Data::get_tallas_disponibles() as $talla ) {
			$opciones[] = array(
				'valor'  => $talla['talla'],
				'imagen' => $talla['imagen'],
			);
		}

		return $opciones;
	}

	/**
	 * Metales disponibles.
	 *
	 * @return array
	 */
	private static function opciones_metal() {
		$opciones = array();

		foreach ( NCM_Data::get_metales_disponibles() as $metal ) {
			$opciones[] = array(
				'valor'  => $metal['metal'],
				'imagen' => $metal['imagen'],
			);
		}

		return $opciones;
	}

	/* ---------------------------------------------------------------------
	 * Piezas del formulario
	 * ------------------------------------------------------------------ */

	/**
	 * Un paso del formulario: cabecera más rejilla de tarjetas.
	 *
	 * Cada opción es un `input[type=radio]` con su `label`: se puede recorrer
	 * con el teclado y funciona sin JavaScript.
	 *
	 * @param string $id           Id de la instancia.
	 * @param int    $numero       Número del paso.
	 * @param string $clave        Campo (tipo, diseno, origen…).
	 * @param string $titulo       Título visible.
	 * @param string $ayuda        Texto de apoyo.
	 * @param array  $opciones     Opciones a pintar.
	 * @param string $modificador  Variante de la rejilla ('' o 'compacto').
	 */
	private static function render_paso( $id, $numero, $clave, $titulo, $ayuda, $opciones, $modificador = '' ) {
		$clases = 'ncm-paso';

		if ( '' !== $modificador ) {
			$clases .= ' ncm-paso--' . $modificador;
		}

		// El primero arranca abierto; el resto se abren al avanzar.
		if ( 1 === $numero ) {
			$clases .= ' ncm-paso--activo';
		}
		?>
		<section class="<?php echo esc_attr( $clases ); ?>" data-paso="<?php echo esc_attr( $clave ); ?>">
			<h3 class="ncm-paso__cabecera">
				<button type="button" class="ncm-paso__boton" data-abrir-paso
					aria-expanded="<?php echo 1 === $numero ? 'true' : 'false'; ?>">
					<span class="ncm-paso__numero"><?php echo esc_html( $numero ); ?></span>
					<span class="ncm-paso__textos">
						<span class="ncm-paso__titulo"><?php echo esc_html( $titulo ); ?></span>
						<span class="ncm-paso__ayuda"><?php echo esc_html( $ayuda ); ?></span>
					</span>
					<span class="ncm-paso__elegido" data-elegido></span>
				</button>
			</h3>

			<div class="ncm-paso__cuerpo">
				<fieldset class="ncm-opciones">
					<legend class="ncm-sr"><?php echo esc_html( $titulo ); ?></legend>

					<?php foreach ( $opciones as $opcion ) : ?>
						<?php self::render_opcion( $id, $clave, $opcion ); ?>
					<?php endforeach; ?>

					<p class="ncm-opciones__vacio" data-sin-opciones hidden>
						Elige primero un tipo de joya.
					</p>
				</fieldset>
			</div>
		</section>
		<?php
	}

	/**
	 * Una tarjeta de opción.
	 *
	 * @param string $id      Id de la instancia.
	 * @param string $clave   Campo al que pertenece.
	 * @param array  $opcion  Datos de la opción.
	 */
	private static function render_opcion( $id, $clave, $opcion ) {
		$valor  = $opcion['valor'];
		$imagen = isset( $opcion['imagen'] ) ? (int) $opcion['imagen'] : 0;
		$datos  = isset( $opcion['datos'] ) ? $opcion['datos'] : array();
		$oculto = ! empty( $opcion['oculto'] );
		?>
		<label class="ncm-opcion" <?php echo $oculto ? 'hidden' : ''; ?>
			<?php foreach ( $datos as $atributo => $contenido ) : ?>
				data-<?php echo esc_attr( $atributo ); ?>="<?php echo esc_attr( $contenido ); ?>"
			<?php endforeach; ?>>

			<input type="radio" class="ncm-opcion__radio"
				name="<?php echo esc_attr( $id . '-' . $clave ); ?>"
				value="<?php echo esc_attr( $valor ); ?>"
				data-campo="<?php echo esc_attr( $clave ); ?>">

			<span class="ncm-opcion__cuerpo">
				<span class="ncm-opcion__media">
					<?php if ( $imagen ) : ?>
						<?php
						echo wp_get_attachment_image(
							$imagen,
							'medium',
							false,
							array(
								'class'   => 'ncm-opcion__img',
								'alt'     => '',
								'loading' => 'lazy',
							)
						);
						?>
					<?php else : ?>
						<span class="ncm-opcion__monograma" aria-hidden="true"><?php echo esc_html( self::monograma( $valor ) ); ?></span>
					<?php endif; ?>
					<span class="ncm-opcion__marca" aria-hidden="true"></span>
				</span>
				<span class="ncm-opcion__nombre"><?php echo esc_html( $valor ); ?></span>
			</span>
		</label>
		<?php
	}

	/**
	 * Iniciales de una opción, para cuando no tiene imagen cargada.
	 *
	 * @param string $texto Nombre de la opción.
	 * @return string
	 */
	private static function monograma( $texto ) {
		$texto = trim( (string) $texto );

		if ( '' === $texto ) {
			return '·';
		}

		$inicial = function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, 1, 'UTF-8' ) : substr( $texto, 0, 1 );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $inicial, 'UTF-8' ) : strtoupper( $inicial );
	}

	/**
	 * Contenido inicial de la calculadora pública.
	 *
	 * Se imprime en el HTML de la página, antes de cualquier interacción, para
	 * que los buscadores tengan texto y un precio de referencia que indexar.
	 *
	 * @param array $parametros Parámetros de la config.
	 */
	private static function render_intro_publica( $parametros ) {
		$desde = NCM_Calculator::desde_config()->precio_desde();
		?>
		<div class="ncm-calc__intro">
			<?php if ( '' !== $parametros['texto_publico'] ) : ?>
				<p class="ncm-calc__texto"><?php echo esc_html( $parametros['texto_publico'] ); ?></p>
			<?php endif; ?>

			<?php if ( null !== $desde ) : ?>
				<p class="ncm-calc__desde">
					<span class="ncm-calc__desde-etiqueta">Precios desde</span>
					<strong class="ncm-calc__desde-monto"><?php echo esc_html( NCM_Calculator::formato_moneda( $desde['precio_final'], $desde['moneda'] ) ); ?></strong>
					<span class="ncm-calc__desde-detalle">
						<?php
						printf(
							/* translators: 1: tipo de joya, 2: nombre del diseño */
							esc_html( '%1$s · %2$s, la configuración más económica del catálogo.' ),
							esc_html( $desde['tipo'] ),
							esc_html( $desde['diseno'] )
						);
						?>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $parametros['texto_nota'] ) : ?>
				<p class="ncm-calc__nota-intro"><?php echo esc_html( $parametros['texto_nota'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Calcula y devuelve el resultado que corresponda a quien pregunta.
	 *
	 * Con sesión: desglose completo. Sin sesión: solo el precio final.
	 */
	public static function ajax_calcular() {
		check_ajax_referer( self::ACCION, 'nonce' );

		// Única fuente de verdad sobre qué se puede devolver.
		$interno = is_user_logged_in() && current_user_can( self::CAP );

		$seleccion = array();

		foreach ( array( 'tipo', 'diseno', 'origen', 'gema', 'talla', 'metal' ) as $campo ) {
			$seleccion[ $campo ] = isset( $_POST[ $campo ] )
				? sanitize_text_field( wp_unslash( $_POST[ $campo ] ) )
				: '';

			if ( '' === $seleccion[ $campo ] ) {
				wp_send_json_error( array( 'mensaje' => 'Completa las seis opciones para calcular.' ), 400 );
			}
		}

		$resultado = NCM_Calculator::desde_config()->calcular( $seleccion );

		if ( empty( $resultado['ok'] ) ) {
			wp_send_json_error( self::respuesta_error( $resultado, $interno ), 200 );
		}

		wp_send_json_success( self::respuesta( $resultado, $interno ) );
	}

	/**
	 * Arma la respuesta de un cálculo correcto según el permiso de quien pide.
	 *
	 * Público: solo el precio final, la selección que el propio visitante hizo
	 * y la nota legal. Ni componentes, ni costos, ni margen, ni el HTML del
	 * desglose.
	 *
	 * @param array $r       Resultado del motor.
	 * @param bool  $interno Si quien pide tiene sesión.
	 * @return array
	 */
	public static function respuesta( array $r, $interno ) {
		$publica = array(
			'modo'                    => $interno ? self::MODO_INTERNO : self::MODO_PUBLICO,
			'estado'                  => $r['estado'],
			'entrada'                 => $r['entrada'],
			'precio_final'            => $r['precio_final'],
			'precio_final_formateado' => $r['precio_final_formateado'],
			'moneda'                  => $r['moneda'],
			'texto_nota'              => $r['texto_nota'],
		);

		if ( ! $interno ) {
			$publica['html'] = self::html_precio( $r );

			return $publica;
		}

		return array_merge(
			$publica,
			array(
				'codigo'           => $r['codigo'],
				'gema'             => $r['gema'],
				'metal'            => $r['metal'],
				'mano_obra'        => $r['mano_obra'],
				'extras'           => $r['extras'],
				'costo_produccion' => $r['costo_produccion'],
				'margen_comercial' => $r['margen_comercial'],
				'valor_margen'     => $r['valor_margen'],
				'precio_calculado' => $r['precio_calculado'],
				'redondeo_precio'  => $r['redondeo_precio'],
				'desglose'         => NCM_Calculator::desglose( $r ),
				'html'             => self::html_resultado( $r ),
			)
		);
	}

	/**
	 * Respuesta cuando la combinación no es calculable.
	 *
	 * El detalle técnico solo se le da a quien puede configurar el plugin.
	 *
	 * @param array $r       Resultado del motor.
	 * @param bool  $interno Si quien pide tiene sesión.
	 * @return array
	 */
	public static function respuesta_error( array $r, $interno ) {
		$salida = array(
			'modo'    => $interno ? self::MODO_INTERNO : self::MODO_PUBLICO,
			'estado'  => $r['estado'],
			'mensaje' => NCM_Calculator::ERROR_CONFIG,
			'html'    => self::html_error( $r, $interno ),
		);

		if ( $interno && ! empty( $r['error_detalle'] ) ) {
			$salida['detalle'] = $r['error_detalle'];
		}

		return $salida;
	}

	/* ---------------------------------------------------------------------
	 * Salida
	 * ------------------------------------------------------------------ */

	/**
	 * HTML público: únicamente el precio.
	 *
	 * @param array $r Resultado del motor.
	 * @return string
	 */
	private static function html_precio( $r ) {
		ob_start();
		?>
		<div class="ncm-res ncm-res--publico">
			<div class="ncm-res__precio">
				<span class="ncm-res__desde">DESDE</span>
				<strong class="ncm-res__monto"><?php echo esc_html( NCM_Calculator::formato_moneda( $r['precio_final'], $r['moneda'] ) ); ?></strong>
			</div>

			<p class="ncm-res__resumen">
				<?php
				echo esc_html(
					implode(
						' · ',
						array(
							$r['entrada']['tipo'],
							$r['entrada']['diseno'],
							$r['entrada']['gema'] . ' ' . strtolower( $r['entrada']['origen'] ),
							$r['entrada']['talla'],
							$r['entrada']['metal'],
						)
					)
				);
				?>
			</p>

			<?php if ( '' !== $r['texto_nota'] ) : ?>
				<p class="ncm-res__nota"><?php echo esc_html( $r['texto_nota'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * HTML interno: desglose completo más el bloque de impresión.
	 *
	 * @param array $r Resultado del motor.
	 * @return string
	 */
	private static function html_resultado( $r ) {
		$moneda = $r['moneda'];
		$fecha  = function_exists( 'wp_date' )
			? wp_date( 'j/m/Y, H:i' )
			: date_i18n( 'j/m/Y, H:i' );

		ob_start();
		?>
		<div class="ncm-res">
			<div class="ncm-res__membrete ncm-solo-print">
				<span class="ncm-res__marca">NCM</span>
				<span class="ncm-res__membrete-datos">
					<span class="ncm-res__membrete-titulo">Cotización interna</span>
					<span class="ncm-res__fecha"><?php echo esc_html( $fecha ); ?></span>
				</span>
			</div>

			<div class="ncm-res__precio">
				<span class="ncm-res__desde">DESDE</span>
				<strong class="ncm-res__monto"><?php echo esc_html( NCM_Calculator::formato_moneda( $r['precio_final'], $moneda ) ); ?></strong>
			</div>
			<p class="ncm-res__codigo">Código de diseño: <strong><?php echo esc_html( $r['codigo'] ); ?></strong></p>

			<table class="ncm-res__tabla">
				<caption>Configuración seleccionada</caption>
				<tbody>
					<tr><th scope="row">Tipo de joya</th><td><?php echo esc_html( $r['entrada']['tipo'] ); ?></td></tr>
					<tr><th scope="row">Diseño</th><td><?php echo esc_html( $r['entrada']['diseno'] ); ?></td></tr>
					<tr><th scope="row">Código</th><td><?php echo esc_html( $r['codigo'] ); ?></td></tr>
					<tr><th scope="row">Origen de la gema</th><td><?php echo esc_html( $r['entrada']['origen'] ); ?></td></tr>
					<tr><th scope="row">Tipo de gema</th><td><?php echo esc_html( $r['entrada']['gema'] ); ?></td></tr>
					<tr><th scope="row">Talla</th><td><?php echo esc_html( $r['entrada']['talla'] ); ?></td></tr>
					<tr><th scope="row">Metal</th><td><?php echo esc_html( $r['entrada']['metal'] ); ?></td></tr>
				</tbody>
			</table>

			<table class="ncm-res__tabla ncm-res__tabla--desglose">
				<caption>Desglose detallado</caption>
				<tbody>
					<?php foreach ( NCM_Calculator::desglose( $r ) as $fila ) : ?>
						<?php if ( 'seccion' === $fila['tipo'] ) : ?>
							<tr class="ncm-res__seccion">
								<th scope="colgroup" colspan="2"><?php echo esc_html( $fila['etiqueta'] ); ?></th>
							</tr>
						<?php else : ?>
							<tr class="ncm-res__fila--<?php echo esc_attr( $fila['tipo'] ); ?>">
								<th scope="row"><?php echo esc_html( $fila['etiqueta'] ); ?></th>
								<td>
									<?php if ( ! empty( $fila['detalle'] ) ) : ?>
										<span class="ncm-res__formula"><?php echo esc_html( $fila['detalle'] ); ?></span>
									<?php endif; ?>
									<span class="ncm-res__valor"><?php echo esc_html( $fila['valor'] ); ?></span>
								</td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( '' !== $r['texto_nota'] ) : ?>
				<p class="ncm-res__nota"><?php echo esc_html( $r['texto_nota'] ); ?></p>
			<?php endif; ?>

			<p class="ncm-res__acciones ncm-no-print">
				<button type="button" class="ncm-calc__boton ncm-calc__boton--sec ncm-calc__imprimir">Imprimir / Guardar PDF</button>
			</p>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * HTML del aviso cuando la combinación no es calculable.
	 *
	 * @param array $r       Resultado del motor.
	 * @param bool  $interno Si quien pide tiene sesión.
	 * @return string
	 */
	private static function html_error( $r, $interno ) {
		ob_start();
		?>
		<div class="ncm-res ncm-res--error">
			<p class="ncm-res__error"><?php echo esc_html( NCM_Calculator::ERROR_CONFIG ); ?></p>
			<?php if ( $interno && current_user_can( 'manage_options' ) && ! empty( $r['error_detalle'] ) ) : ?>
				<p class="ncm-res__error-detalle"><?php echo esc_html( $r['error_detalle'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}
}

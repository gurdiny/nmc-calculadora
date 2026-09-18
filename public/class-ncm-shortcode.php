<?php
/**
 * Shortcodes público e interno, y el endpoint AJAX que los alimenta.
 *
 * La calculadora tiene dos modos:
 *
 * - `[ncm_calculadora]`      Pública. La usa cualquiera, con o sin sesión.
 *                            Devuelve únicamente el precio "DESDE $X COP".
 * - `[ncm_calculadora_interna]` Interna. Solo se pinta a usuarios con sesión y
 *                            muestra el desglose completo.
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

	/**
	 * Capacidad mínima para ver el desglose interno.
	 *
	 * `edit_posts` (autores y superiores), no `read`: con `read` bastaría estar
	 * registrado —un suscriptor— para ver el costo de producción y el margen, y
	 * eso quedaría expuesto en cuanto el sitio abra el registro por su cuenta
	 * (WooCommerce, un plugin de membresías, «cualquiera puede registrarse»).
	 * Quien cotiza en NCM tiene cuenta de autor o superior de todas formas.
	 */
	const CAP = 'edit_posts';

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
	/**
	 * Encola los assets del front dentro del panel.
	 *
	 * El cotizador interno vive en una pantalla del admin, y ahí no corre
	 * `wp_enqueue_scripts`: los handles no llegan registrados, así que hay que
	 * registrarlos a mano antes de encolarlos.
	 */
	public static function encolar_en_admin() {
		self::registrar_assets();
		self::encolar_assets();
	}

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
		wp_add_inline_style( 'ncm-calculadora', self::css_paleta() );
		wp_enqueue_script( 'ncm-calculadora' );
		wp_localize_script( 'ncm-calculadora', 'ncmCalcData', self::datos_js() );
	}

	/**
	 * Colores del formulario, como variables CSS.
	 *
	 * La paleta `ncm` se engancha a las variables globales de Elementor con un
	 * respaldo propio: si el tema cambia sus colores, la calculadora los sigue
	 * sola; si no hay Elementor, usa los valores de NCM.
	 *
	 * @return string CSS listo para inyectar.
	 */
	public static function css_paleta() {
		$p       = NCM_Data::get_parametros();
		$paletas = array(
			'ncm'    => array(
				'tinta'        => 'var( --e-global-color-secondary, #1D1E1B )',
				'suave'        => 'var( --e-global-color-7240aa4, #5D6152 )',
				'tenue'        => 'var( --e-global-color-9fc7779, #6A6B5A )',
				'linea'        => 'var( --e-global-color-text, #E5DECC )',
				'linea_fuerte' => 'var( --e-global-color-accent, #C6B49A )',
				'fondo'        => '#FFFFFF',
				'fondo_alt'    => 'var( --e-global-color-d96b7c8, #F2EEE3 )',
				'acento'       => 'var( --e-global-color-primary, #354C3F )',
				'acento_claro' => 'var( --e-global-color-text, #E5DECC )',
				'acento_texto' => '#FFFFFF',
				'velo'         => 'rgba( 255, 255, 255, 0.92 )',
			),
			'claro'  => array(
				'tinta'        => '#1F1D1A',
				'suave'        => '#6F6A63',
				'tenue'        => '#A29B92',
				'linea'        => '#E4DED5',
				'linea_fuerte' => '#CFC6B8',
				'fondo'        => '#FFFFFF',
				'fondo_alt'    => '#FAF8F5',
				'acento'       => '#8A6D3B',
				'acento_claro' => '#F3ECE1',
				'acento_texto' => '#FFFFFF',
				'velo'         => 'rgba( 255, 255, 255, 0.92 )',
			),
			'oscuro' => array(
				'tinta'        => '#E5DECC',
				'suave'        => '#C6B49A',
				'tenue'        => '#8E8F7E',
				'linea'        => '#3A3B36',
				'linea_fuerte' => '#5D6152',
				'fondo'        => '#1D1E1B',
				'fondo_alt'    => '#33352F',
				'acento'       => '#C6B49A',
				'acento_claro' => '#33352F',
				'acento_texto' => '#1D1E1B',
				'velo'         => 'rgba( 29, 30, 27, 0.92 )',
			),
		);

		if ( 'personalizada' === $p['paleta'] ) {
			$colores = array(
				'tinta'        => $p['color_tinta'],
				'suave'        => $p['color_tinta'],
				'tenue'        => $p['color_tinta'],
				'linea'        => $p['color_linea'],
				'linea_fuerte' => $p['color_linea'],
				'fondo'        => $p['color_fondo'],
				'fondo_alt'    => $p['color_fondo_alt'],
				'acento'       => $p['color_acento'],
				'acento_claro' => $p['color_fondo_alt'],
				'acento_texto' => $p['color_acento_texto'],
				'velo'         => $p['color_fondo'],
			);
		} else {
			$clave   = isset( $paletas[ $p['paleta'] ] ) ? $p['paleta'] : 'ncm';
			$colores = $paletas[ $clave ];
		}

		// La clase va duplicada a propósito: la hoja del front define los valores
		// por defecto en `.ncm-calc.ncm-calc` para ganarle al reset del tema, así
		// que la paleta necesita esa misma especificidad para pisarlos.
		return sprintf(
			'.ncm-calc.ncm-calc{--ncm-tinta:%1$s;--ncm-suave:%2$s;--ncm-tenue:%3$s;--ncm-linea:%4$s;' .
			'--ncm-linea-fuerte:%5$s;--ncm-fondo:%6$s;--ncm-fondo-alt:%7$s;--ncm-acento:%8$s;' .
			'--ncm-acento-claro:%9$s;--ncm-sobre-acento:%10$s;--ncm-velo:%11$s;}',
			$colores['tinta'],
			$colores['suave'],
			$colores['tenue'],
			$colores['linea'],
			$colores['linea_fuerte'],
			$colores['fondo'],
			$colores['fondo_alt'],
			$colores['acento'],
			$colores['acento_claro'],
			$colores['acento_texto'],
			$colores['velo']
		);
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
				'incompleto'   => 'Completa todas las opciones para calcular.',
				'resumenVacio' => 'Elige todas las opciones para ver el precio.',
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
			return '<div class="ncm-calc ncm-calc--bloqueada"><p>Esta versión de la calculadora es de uso interno. Inicia sesión con una cuenta del equipo para usarla.</p></div>';
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

				<?php // Se enseña cuando el diseño elegido no lleva gemas. ?>
				<p class="ncm-calc__nota-sin-gemas" data-sin-gemas hidden>
					Esta pieza es solo metal: no hay que elegir origen, gema ni talla.
				</p>

				<div class="ncm-calc__barra" data-barra>
					<div class="ncm-calc__resumen" data-resumen>
						<span class="ncm-calc__resumen-vacio">Elige todas las opciones para ver el precio.</span>
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
				'datos'  => array(
					'tipo' => $diseno['tipo'],
					// El front esconde origen, gema y talla cuando vale "0".
					'gemas' => (float) $diseno['cant_gemas'] > 0 ? '1' : '0',
				),
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
					aria-expanded="<?php echo esc_attr( 1 === $numero ? 'true' : 'false' ); ?>">
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
		$valor = $opcion['valor'];

		// Si el adjunto no es publicable, la tarjeta cae en su monograma.
		$imagen = isset( $opcion['imagen'] ) ? NCM_Data::imagen_publicable( $opcion['imagen'] ) : 0;
		$datos  = isset( $opcion['datos'] ) ? $opcion['datos'] : array();
		$oculto = ! empty( $opcion['oculto'] );
		?>
		<label class="ncm-opcion" <?php echo $oculto ? 'hidden' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributo booleano literal. ?>
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
	 * Con permisos y preguntando desde la calculadora interna: desglose
	 * completo. En cualquier otro caso: solo el precio final.
	 */
	public static function ajax_calcular() {
		check_ajax_referer( self::ACCION, 'nonce' );

		/*
		 * Quién pregunta manda sobre qué se puede devolver; desde dónde
		 * pregunta, solo sobre qué se devuelve de lo permitido.
		 *
		 * El permiso es la única puerta: sin sesión y sin la capacidad no hay
		 * desglose, pida el modo que pida. El `modo` que manda el front dice
		 * desde qué shortcode se preguntó, y solo sirve para REBAJAR: así la
		 * página pública enseña la versión pública también al equipo, que es lo
		 * que se ve al revisar el sitio con la sesión abierta.
		 */
		$puede_interno = is_user_logged_in() && current_user_can( self::CAP );
		$modo_pedido   = isset( $_POST['modo'] ) ? sanitize_key( wp_unslash( $_POST['modo'] ) ) : self::MODO_PUBLICO;
		$interno       = $puede_interno && self::MODO_INTERNO === $modo_pedido;

		$seleccion = array();

		foreach ( array( 'tipo', 'diseno', 'origen', 'gema', 'talla', 'metal' ) as $campo ) {
			$seleccion[ $campo ] = isset( $_POST[ $campo ] )
				? sanitize_text_field( wp_unslash( $_POST[ $campo ] ) )
				: '';
		}

		/*
		 * Origen, gema y talla no se exigen aquí: hay diseños sin gemas
		 * (cant_gemas = 0) que no los tienen. Quien sabe si hacen falta es el
		 * motor, que conoce el catálogo; si faltan en un diseño que sí lleva,
		 * responde REVISAR CONFIGURACIÓN con el motivo.
		 */
		foreach ( array( 'tipo', 'diseno', 'metal' ) as $campo ) {
			if ( '' === $seleccion[ $campo ] ) {
				wp_send_json_error( array( 'mensaje' => 'Completa todas las opciones para calcular.' ), 400 );
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
			// Solo lleva la selección del visitante y el precio: ver whatsapp().
			'whatsapp'                => self::whatsapp( $r ),
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
	 * Lo que lee un visitante cuando la combinación no se puede cotizar.
	 *
	 * "REVISAR CONFIGURACIÓN" es un recado para el equipo —viene del Excel— y a
	 * un cliente no le dice nada: le suena a que la página está rota. El equipo
	 * lo sigue viendo tal cual en la versión interna, junto al motivo técnico.
	 */
	const MENSAJE_NO_DISPONIBLE = 'Esta combinación no está disponible en este momento. Escríbenos y la cotizamos para ti.';

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
			'mensaje' => $interno ? NCM_Calculator::ERROR_CONFIG : self::MENSAJE_NO_DISPONIBLE,
			'html'    => self::html_error( $r, $interno ),
		);

		if ( $interno && ! empty( $r['error_detalle'] ) ) {
			$salida['detalle'] = $r['error_detalle'];
		}

		return $salida;
	}

	/* ---------------------------------------------------------------------
	 * WhatsApp
	 * ------------------------------------------------------------------ */

	/**
	 * Enlace de WhatsApp con la cotización.
	 *
	 * El mensaje lo arma el servidor y **solo** lleva la selección del visitante
	 * y el precio final: ni componentes, ni costos, ni margen, ni el código de
	 * diseño. Por eso el mismo enlace vale para la versión pública y para la
	 * interna, y por eso puede ir en la respuesta de las dos.
	 *
	 * @param array $r Resultado del motor.
	 * @return array|null Null si no hay número configurado o está desactivado.
	 */
	public static function whatsapp( array $r ) {
		$p = NCM_Data::get_parametros();

		if ( empty( $p['whatsapp_activo'] ) || '' === $p['whatsapp_numero'] ) {
			return null;
		}

		$plantilla = '' !== trim( $p['whatsapp_mensaje'] )
			? $p['whatsapp_mensaje']
			: "Hola, quiero cotizar esta pieza:\n\n{tipo} · {diseno}\nGema: {gema} ({origen})\nTalla: {talla}\nMetal: {metal}\n\nPrecio estimado: {precio}";

		/*
		 * Si la pieza no lleva gemas, se caen las líneas de la plantilla que
		 * hablan de ellas. Sustituir por vacío dejaría un «Gema:  ()» y un
		 * «Talla:» sueltos, que es peor que no decir nada. Se quita la línea
		 * entera porque la plantilla la escribe el equipo y no hay forma de
		 * saber qué texto acompaña al marcador.
		 */
		if ( empty( $r['lleva_gemas'] ) ) {
			$lineas = preg_split( '/\R/', $plantilla );
			$quedan = array();

			foreach ( $lineas as $linea ) {
				if ( ! preg_match( '/\{(gema|origen|talla)\}/', $linea ) ) {
					$quedan[] = $linea;
				}
			}

			$plantilla = implode( "\n", $quedan );
		}

		$mensaje = strtr(
			$plantilla,
			array(
				'{tipo}'   => $r['entrada']['tipo'],
				'{diseno}' => $r['entrada']['diseno'],
				'{origen}' => $r['entrada']['origen'],
				'{gema}'   => $r['entrada']['gema'],
				'{talla}'  => $r['entrada']['talla'],
				'{metal}'  => $r['entrada']['metal'],
				'{precio}' => $r['precio_final_formateado'],
			)
		);

		return array(
			'numero'  => $p['whatsapp_numero'],
			'mensaje' => $mensaje,
			'url'     => 'https://wa.me/' . $p['whatsapp_numero'] . '?text=' . rawurlencode( $mensaje ),
		);
	}

	/**
	 * Botón de WhatsApp, si hay número configurado.
	 *
	 * @param array $r Resultado del motor.
	 */
	private static function boton_whatsapp( $r ) {
		$whatsapp = self::whatsapp( $r );

		if ( null === $whatsapp ) {
			return;
		}

		/*
		 * Aquí va esc_attr() y no esc_url() a propósito. esc_url() borra los
		 * `%0a` y `%0d` —se defiende de la inyección de cabeceras—, y con ellos
		 * se iban los saltos de línea del mensaje: al cliente le llegaba todo
		 * pegado en un párrafo. La URL no la toca el visitante: el número queda
		 * en dígitos (normalizar_telefono) y el mensaje entero pasa por
		 * rawurlencode(), así que el esquema y el host son siempre nuestros.
		 */
		?>
		<a class="ncm-calc__boton ncm-calc__whatsapp" href="<?php echo esc_attr( $whatsapp['url'] ); ?>"
			target="_blank" rel="noopener noreferrer nofollow">
			<svg class="ncm-calc__whatsapp-icono" viewBox="0 0 24 24" width="18" height="18"
				fill="currentColor" aria-hidden="true" focusable="false">
				<path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.65-2.05-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.92-2.2-.24-.58-.48-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.2 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.42 9.42 0 0 1-4.8-1.32l-.34-.2-3.57.94.95-3.48-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.23-9.43 9.44-9.43a9.37 9.37 0 0 1 6.67 2.77 9.35 9.35 0 0 1 2.76 6.67c0 5.2-4.24 9.43-9.44 9.43zM20.5 3.49A11.28 11.28 0 0 0 12.04 0C5.79 0 .7 5.08.7 11.33c0 2 .52 3.95 1.52 5.67L.6 24l7.16-1.88a11.3 11.3 0 0 0 5.41 1.38h.01c6.25 0 11.34-5.09 11.34-11.34 0-3.03-1.18-5.87-3.32-8.01z"/>
			</svg>
			Cotizar por WhatsApp
		</a>
		<?php
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

			<?php
			/*
			 * La misma tabla que ve el equipo, menos la fila «Código»: el
			 * código de diseño es nomenclatura interna y con ella se deduce el
			 * catálogo. Todo lo demás es lo que el propio visitante eligió, así
			 * que devolvérselo no filtra nada.
			 */
			?>
			<table class="ncm-res__tabla">
				<caption>Configuración seleccionada</caption>
				<tbody>
					<tr><th scope="row">Tipo de joya</th><td><?php echo esc_html( $r['entrada']['tipo'] ); ?></td></tr>
					<tr><th scope="row">Diseño</th><td><?php echo esc_html( $r['entrada']['diseno'] ); ?></td></tr>
					<?php if ( ! empty( $r['lleva_gemas'] ) ) : ?>
						<tr><th scope="row">Origen de la gema</th><td><?php echo esc_html( $r['entrada']['origen'] ); ?></td></tr>
						<tr><th scope="row">Tipo de gema</th><td><?php echo esc_html( $r['entrada']['gema'] ); ?></td></tr>
						<tr><th scope="row">Talla</th><td><?php echo esc_html( $r['entrada']['talla'] ); ?></td></tr>
					<?php endif; ?>
					<tr><th scope="row">Metal</th><td><?php echo esc_html( $r['entrada']['metal'] ); ?></td></tr>
				</tbody>
			</table>

			<?php if ( '' !== $r['texto_nota'] ) : ?>
				<p class="ncm-res__nota"><?php echo esc_html( $r['texto_nota'] ); ?></p>
			<?php endif; ?>

			<p class="ncm-res__acciones">
				<?php self::boton_whatsapp( $r ); ?>
			</p>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * HTML interno: desglose completo.
	 *
	 * @param array $r Resultado del motor.
	 * @return string
	 */
	private static function html_resultado( $r ) {
		$moneda = $r['moneda'];

		ob_start();
		?>
		<div class="ncm-res">
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
					<?php if ( empty( $r['lleva_gemas'] ) ) : ?>
						<tr><th scope="row">Gemas</th><td>Esta pieza no lleva</td></tr>
					<?php else : ?>
						<tr><th scope="row">Origen de la gema</th><td><?php echo esc_html( $r['entrada']['origen'] ); ?></td></tr>
						<tr><th scope="row">Tipo de gema</th><td><?php echo esc_html( $r['entrada']['gema'] ); ?></td></tr>
						<tr><th scope="row">Talla</th><td><?php echo esc_html( $r['entrada']['talla'] ); ?></td></tr>
					<?php endif; ?>
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

			<p class="ncm-res__acciones">
				<?php self::boton_whatsapp( $r ); ?>
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
			<p class="ncm-res__error">
				<?php echo esc_html( $interno ? NCM_Calculator::ERROR_CONFIG : self::MENSAJE_NO_DISPONIBLE ); ?>
			</p>
			<?php if ( $interno && current_user_can( 'manage_options' ) && ! empty( $r['error_detalle'] ) ) : ?>
				<p class="ncm-res__error-detalle"><?php echo esc_html( $r['error_detalle'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}
}

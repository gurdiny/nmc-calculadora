<?php
/**
 * Panel de configuración de la calculadora.
 *
 * Pantalla propia en el admin de WordPress con pestañas para parámetros y
 * para cada matriz (diseños, gemas, tallas, metales). Guarda todo en la
 * opción `ncm_calc_config` a través de NCM_Data.
 *
 * @package NCM_Calculadora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCM_Admin {

	/** Slug de la página del panel. */
	const SLUG = 'ncm-calculadora';

	/** Capacidad requerida para configurar. */
	const CAP = 'manage_options';

	/** Slug de la pantalla del cotizador interno. */
	const SLUG_COTIZADOR = 'ncm-cotizador';

	/**
	 * Hook de la pantalla del cotizador, tal como lo devuelve WordPress.
	 *
	 * @var string
	 */
	private static $hook_cotizador = '';

	/** Avisos de validación acumulados durante un guardado. */
	private static $avisos = array();

	/** Pestañas disponibles: slug => etiqueta. */
	private static function pestanas() {
		return array(
			'parametros' => 'Parámetros',
			'disenos'    => 'Diseños',
			'gemas'      => 'Gemas',
			'tallas'     => 'Tallas',
			'metales'    => 'Metales',
			'apariencia' => 'Apariencia',
		);
	}

	/** Engancha el panel a WordPress. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ncm_guardar_config', array( __CLASS__, 'guardar' ) );
		add_action( 'admin_post_ncm_restaurar_semilla', array( __CLASS__, 'restaurar' ) );
	}

	/** Registra la página de menú. */
	public static function registrar_menu() {
		add_menu_page(
			'Calculadora NCM',
			'Calculadora NCM',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-calculator',
			58
		);

		/*
		 * El cotizador interno pide `edit_posts`, no `manage_options`: es la
		 * misma puerta que el shortcode interno, para que el equipo cotice sin
		 * darle acceso a la configuración. WordPress enseña el menú padre a
		 * quien solo alcanza este submenú.
		 */
		self::$hook_cotizador = add_submenu_page(
			self::SLUG,
			'Cotizador interno',
			'Cotizador interno',
			NCM_Shortcode::CAP,
			self::SLUG_COTIZADOR,
			array( __CLASS__, 'render_cotizador' )
		);
	}

	/**
	 * Pantalla del cotizador interno: la calculadora con desglose, dentro del
	 * panel, sin necesidad de publicar una página para el equipo.
	 */
	public static function render_cotizador() {
		if ( ! current_user_can( NCM_Shortcode::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para usar el cotizador.', 'ncm-calculadora' ) );
		}
		?>
		<div class="wrap ncm-cotizador">
			<h1>Cotizador interno</h1>
			<p class="description">
				Cotización con el desglose completo, solo para el equipo. Lo que ve un
				visitante en la página pública es únicamente el precio.
			</p>

			<?php
			// El shortcode ya escapa todo lo que imprime.
			echo NCM_Shortcode::render_interna(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
	}

	/**
	 * Carga CSS y JS solo en la pantalla del panel.
	 *
	 * @param string $hook Hook de la pantalla actual.
	 */
	public static function assets( $hook ) {
		// El cotizador interno usa los assets del front, no los del panel.
		if ( '' !== self::$hook_cotizador && $hook === self::$hook_cotizador ) {
			NCM_Shortcode::encolar_en_admin();

			return;
		}

		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ncm-admin',
			NCM_CALC_URL . 'assets/css/ncm-admin.css',
			array(),
			NCM_CALC_VERSION
		);

		// La mediateca: el selector de imágenes de cada fila la necesita.
		wp_enqueue_media();

		wp_enqueue_script(
			'ncm-admin',
			NCM_CALC_URL . 'assets/js/ncm-admin.js',
			array( 'jquery' ),
			NCM_CALC_VERSION,
			true
		);

		wp_localize_script(
			'ncm-admin',
			'ncmAdminData',
			array(
				'tituloMedia' => 'Elegir imagen',
				'botonMedia'  => 'Usar esta imagen',
				'quitar'      => 'Quitar',
			)
		);
	}

	/** Pestaña activa según la URL. */
	private static function pestana_actual() {
		$pestanas = self::pestanas();
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'parametros'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return isset( $pestanas[ $tab ] ) ? $tab : 'parametros';
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------ */

	/** Pinta la pantalla completa. */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'ncm-calculadora' ) );
		}

		$tab    = self::pestana_actual();
		$config = NCM_Data::get_config();
		$aviso  = isset( $_GET['ncm_aviso'] ) ? sanitize_key( wp_unslash( $_GET['ncm_aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap ncm-admin">
			<h1>Calculadora de Joyas NCM</h1>

			<?php if ( 'guardado' === $aviso ) : ?>
				<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
			<?php elseif ( 'semilla' === $aviso ) : ?>
				<div class="notice notice-success is-dismissible"><p>Se restauraron los valores originales del Excel.</p></div>
			<?php endif; ?>

			<?php foreach ( self::leer_avisos() as $texto ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $texto ); ?></p></div>
			<?php endforeach; ?>

			<p class="ncm-admin__intro">
				Los valores de esta pantalla alimentan la calculadora. Publica el shortcode
				<code>[ncm_calculadora]</code> en cualquier página para usarla (solo la ven usuarios con sesión iniciada).
			</p>

			<p class="ncm-admin__aviso">
				<strong>Formato de los números:</strong> el separador decimal es el <strong>punto</strong> y
				<strong>no se usa separador de miles</strong>. Seiscientos cincuenta mil se escribe
				<code>650000</code>, nunca <code>650.000</code> (eso vale 650).
			</p>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( self::pestanas() as $slug => $etiqueta ) : ?>
					<a href="<?php echo esc_url( self::url_pestana( $slug ) ); ?>"
						class="nav-tab <?php echo esc_attr( $slug === $tab ? 'nav-tab-active' : '' ); ?>">
						<?php echo esc_html( $etiqueta ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ncm-admin__form">
				<input type="hidden" name="action" value="ncm_guardar_config">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( 'ncm_guardar_config_' . $tab, 'ncm_nonce' ); ?>

				<?php
				switch ( $tab ) {
					case 'disenos':
						self::render_disenos( $config['disenos'] );
						break;
					case 'gemas':
						self::render_gemas( $config['gemas'] );
						break;
					case 'tallas':
						self::render_tallas( $config['tallas'] );
						break;
					case 'metales':
						self::render_metales( $config['metales'] );
						break;
					case 'apariencia':
						self::render_apariencia( $config['parametros'] );
						break;
					default:
						self::render_parametros( $config['parametros'] );
				}
				?>

				<?php submit_button( 'Guardar cambios' ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('Esto reemplaza TODA la configuración por los valores originales del Excel. ¿Continuar?');">
				<input type="hidden" name="action" value="ncm_restaurar_semilla">
				<?php wp_nonce_field( 'ncm_restaurar_semilla', 'ncm_nonce' ); ?>
				<p class="description">Vuelve todos los catálogos y parámetros a los valores exactos del Excel.</p>
				<?php submit_button( 'Restaurar valores del Excel', 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * URL de una pestaña del panel.
	 *
	 * @param string $slug Slug de la pestaña.
	 * @return string
	 */
	private static function url_pestana( $slug ) {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Pestaña de parámetros generales.
	 *
	 * @param array $p Parámetros actuales.
	 */
	private static function render_parametros( $p ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ncm-margen">Margen comercial (%)</label></th>
				<td>
					<input type="number" step="0.01" min="0" id="ncm-margen" class="small-text"
						name="ncm[parametros][margen_comercial]"
						value="<?php echo esc_attr( self::num( $p['margen_comercial'] * 100 ) ); ?>">
					<p class="description">Se escribe en porcentaje: <code>35</code> = 35 % (se guarda como 0,35).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-merma">Factor de merma del metal</label></th>
				<td>
					<input type="number" step="0.0001" min="0" id="ncm-merma" class="small-text"
						name="ncm[parametros][factor_merma_metal]"
						value="<?php echo esc_attr( self::num( $p['factor_merma_metal'] ) ); ?>">
					<p class="description">Multiplicador sobre el peso base. Excel: <code>1.1</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-redondeo">Redondeo del precio</label></th>
				<td>
					<input type="number" step="1" min="0" id="ncm-redondeo" class="small-text"
						name="ncm[parametros][redondeo_precio]"
						value="<?php echo esc_attr( self::num( $p['redondeo_precio'] ) ); ?>">
					<p class="description">El precio final se redondea hacia arriba a este múltiplo. Excel: <code>10000</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-moneda">Moneda</label></th>
				<td>
					<input type="text" id="ncm-moneda" class="small-text"
						name="ncm[parametros][moneda]"
						value="<?php echo esc_attr( $p['moneda'] ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-texto-publico">Texto de la calculadora pública</label></th>
				<td>
					<textarea id="ncm-texto-publico" rows="4" class="large-text"
						name="ncm[parametros][texto_publico]"><?php echo esc_textarea( $p['texto_publico'] ); ?></textarea>
					<p class="description">
						Se muestra en <code>[ncm_calculadora]</code> desde la primera carga, antes de que el
						visitante elija nada. Es el texto que ven los buscadores: descríbelo con las palabras
						por las que quieres posicionar.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-whatsapp-numero">WhatsApp de NCM</label></th>
				<td>
					<input type="text" id="ncm-whatsapp-numero" class="regular-text"
						name="ncm[parametros][whatsapp_numero]"
						value="<?php echo esc_attr( $p['whatsapp_numero'] ); ?>"
						placeholder="573001234567" inputmode="tel">
					<p class="description">
						Con <strong>indicativo de país y sin el <code>+</code></strong>: Colombia es
						<code>57</code>, así que un móvil queda como <code>573001234567</code>. Los espacios,
						guiones y paréntesis se limpian solos. <strong>Déjalo vacío para ocultar el botón.</strong>
					</p>
					<p>
						<label>
							<input type="hidden" name="ncm[parametros][whatsapp_activo]" value="0">
							<input type="checkbox" name="ncm[parametros][whatsapp_activo]" value="1"
								<?php checked( ! empty( $p['whatsapp_activo'] ) ); ?>>
							Mostrar el botón «Cotizar por WhatsApp» en el resultado
						</label>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-whatsapp-mensaje">Mensaje de WhatsApp</label></th>
				<td>
					<textarea id="ncm-whatsapp-mensaje" rows="8" class="large-text code"
						name="ncm[parametros][whatsapp_mensaje]"><?php echo esc_textarea( $p['whatsapp_mensaje'] ); ?></textarea>
					<p class="description">
						Es el texto que el cliente verá ya escrito al abrir el chat. Puedes usar:
						<code>{tipo}</code>, <code>{diseno}</code>, <code>{origen}</code>, <code>{gema}</code>,
						<code>{talla}</code>, <code>{metal}</code> y <code>{precio}</code>.
						<br>
						No hay marcador para los costos ni el margen <strong>a propósito</strong>: el mensaje
						sale igual en la calculadora pública, y ahí esas cifras no pueden aparecer.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ncm-nota">Nota al pie del resultado</label></th>
				<td>
					<textarea id="ncm-nota" rows="4" class="large-text"
						name="ncm[parametros][texto_nota]"><?php echo esc_textarea( $p['texto_nota'] ); ?></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Pestaña de apariencia: la paleta del formulario.
	 *
	 * @param array $p Parámetros actuales.
	 */
	private static function render_apariencia( $p ) {
		$paletas = array(
			'ncm'           => 'NCM — sigue los colores del tema (Elementor) si existen',
			'claro'         => 'Claro — beige y dorado, la paleta original del plugin',
			'oscuro'        => 'Oscuro — fondo oscuro con dorado',
			'personalizada' => 'Personalizada — eliges tú los colores',
		);

		$colores = array(
			'color_acento'       => array( 'Color principal', 'Botones, opción elegida y acentos.' ),
			'color_acento_texto' => array( 'Texto sobre el color principal', 'Debe contrastar con el anterior.' ),
			'color_tinta'        => array( 'Color del texto', '' ),
			'color_fondo'        => array( 'Fondo', '' ),
			'color_fondo_alt'    => array( 'Fondo secundario', 'Tarjetas, cajas y la opción elegida.' ),
			'color_linea'        => array( 'Bordes', '' ),
		);
		?>
		<p class="description">
			Así se ve el formulario en la web. La opción <strong>NCM</strong> se engancha a las variables de
			color de Elementor (<code>--e-global-color-primary</code> y compañía) con un respaldo propio: si
			el tema cambia de colores, la calculadora los sigue sola.
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Paleta</th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">Paleta de colores</legend>
						<?php foreach ( $paletas as $clave => $etiqueta ) : ?>
							<p>
								<label>
									<input type="radio" name="ncm[parametros][paleta]"
										value="<?php echo esc_attr( $clave ); ?>"
										<?php checked( $p['paleta'], $clave ); ?>>
									<?php echo esc_html( $etiqueta ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>

			<?php foreach ( $colores as $clave => $col ) : ?>
				<tr>
					<th scope="row"><label for="ncm-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $col[0] ); ?></label></th>
					<td>
						<input type="color" id="ncm-<?php echo esc_attr( $clave ); ?>"
							class="ncm-color"
							name="ncm[parametros][<?php echo esc_attr( $clave ); ?>]"
							value="<?php echo esc_attr( $p[ $clave ] ); ?>">
						<code class="ncm-color__hex"><?php echo esc_html( $p[ $clave ] ); ?></code>
						<?php if ( '' !== $col[1] ) : ?>
							<p class="description"><?php echo esc_html( $col[1] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<p class="description">
			Los colores de arriba solo se aplican con la paleta <strong>Personalizada</strong>; con las demás
			quedan guardados pero no se usan.
		</p>
		<?php
	}

	/**
	 * Pestaña de diseños.
	 *
	 * @param array $filas Filas de diseños.
	 */
	private static function render_disenos( $filas ) {
		$columnas = array(
			'codigo'       => array( 'Código', 'text' ),
			'tipo'         => array( 'Tipo de joya', 'tipos' ),
			'diseno'       => array( 'Diseño', 'text' ),
			'peso_metal_g' => array( 'Peso metal (g)', 'number' ),
			'cant_gemas'   => array( 'Cant. gemas', 'number' ),
			'ct_por_gema'  => array( 'Ct por gema', 'number' ),
			'mano_obra'    => array( 'Mano de obra', 'number' ),
			'extras'       => array( 'Extras', 'number' ),
			'imagen'       => array( 'Imagen', 'imagen' ),
		);

		self::render_tabla( 'disenos', $columnas, $filas, 'Cada fila es una combinación de tipo de joya + diseño. Un tipo de joya existe mientras al menos un diseño lo use: para estrenar uno, añádelo con «+ Tipo de joya» y asígnaselo a una fila.' );
	}

	/**
	 * Pestaña de gemas.
	 *
	 * @param array $filas Filas de gemas.
	 */
	private static function render_gemas( $filas ) {
		$columnas = array(
			'tipo_gema'          => array( 'Gema', 'text' ),
			'precio_natural'     => array( 'Precio 1 ct natural', 'number' ),
			'precio_laboratorio' => array( 'Precio 1 ct laboratorio', 'number' ),
			'imagen'       => array( 'Imagen', 'imagen' ),
		);

		self::render_tabla( 'gemas', $columnas, $filas, 'Precios por 1 quilate. El origen (Natural / Laboratorio) elige cuál de las dos columnas se usa.' );
	}

	/**
	 * Pestaña de tallas.
	 *
	 * @param array $filas Filas de tallas.
	 */
	private static function render_tallas( $filas ) {
		$columnas = array(
			'talla'      => array( 'Talla', 'text' ),
			'ajuste'     => array( 'Ajuste (COP)', 'number' ),
			'disponible' => array( 'Disponible', 'check' ),
			'imagen'       => array( 'Imagen', 'imagen' ),
		);

		self::render_tabla( 'tallas', $columnas, $filas, 'El ajuste se suma al componente de gema. Las tallas no disponibles no aparecen en el formulario.' );
	}

	/**
	 * Pestaña de metales.
	 *
	 * @param array $filas Filas de metales.
	 */
	private static function render_metales( $filas ) {
		$columnas = array(
			'metal'            => array( 'Metal', 'text' ),
			'precio_gramo'     => array( 'Precio por gramo', 'number' ),
			'factor_adicional' => array( 'Factor adicional', 'number' ),
			'disponible'       => array( 'Disponible', 'check' ),
			'imagen'       => array( 'Imagen', 'imagen' ),
		);

		self::render_tabla( 'metales', $columnas, $filas, 'Componente metal = peso base × factor de merma × precio por gramo × factor adicional.' );
	}

	/**
	 * Tabla editable con filas repetibles.
	 *
	 * @param string $coleccion  Nombre de la colección.
	 * @param array  $columnas   Definición de columnas: clave => array(etiqueta, tipo).
	 * @param array  $filas      Filas actuales.
	 * @param string $ayuda      Texto de ayuda.
	 */
	private static function render_tabla( $coleccion, $columnas, $filas, $ayuda ) {
		?>
		<p class="description"><?php echo esc_html( $ayuda ); ?></p>

		<table class="widefat striped ncm-tabla" data-coleccion="<?php echo esc_attr( $coleccion ); ?>">
			<thead>
				<tr>
					<?php foreach ( $columnas as $clave => $col ) : ?>
						<th><?php echo esc_html( $col[0] ); ?></th>
					<?php endforeach; ?>
					<th class="ncm-tabla__acciones">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $filas ) as $i => $fila ) : ?>
					<?php self::render_fila( $coleccion, $columnas, $fila, $i ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button ncm-agregar-fila"
				data-coleccion="<?php echo esc_attr( $coleccion ); ?>">Agregar fila</button>

			<?php if ( 'disenos' === $coleccion ) : ?>
				<button type="button" class="button ncm-agregar-tipo" hidden>+ Tipo de joya</button>

				<span class="ncm-tipo-nuevo" hidden>
					<input type="text" class="regular-text ncm-tipo-nuevo__campo"
						placeholder="Nombre del tipo (ej. Collar)" aria-label="Nombre del tipo de joya nuevo">
					<button type="button" class="button button-primary ncm-tipo-nuevo__confirmar">Añadir</button>
					<button type="button" class="button-link ncm-tipo-nuevo__cancelar">Cancelar</button>
				</span>
			<?php endif; ?>

			<span class="description">El orden de las filas es el orden en que aparecen las opciones en el formulario.</span>
		</p>

		<script type="text/html" id="ncm-plantilla-<?php echo esc_attr( $coleccion ); ?>">
			<?php self::render_fila( $coleccion, $columnas, array(), '__i__' ); ?>
		</script>
		<?php
	}

	/**
	 * Una fila de la tabla editable.
	 *
	 * @param string $coleccion Nombre de la colección.
	 * @param array  $columnas  Definición de columnas.
	 * @param array  $fila      Valores de la fila.
	 * @param mixed  $indice    Índice numérico o marcador de plantilla.
	 */
	private static function render_fila( $coleccion, $columnas, $fila, $indice ) {
		?>
		<tr>
			<?php foreach ( $columnas as $clave => $col ) : ?>
				<?php
				$nombre = sprintf( 'ncm[%s][%s][%s]', $coleccion, $indice, $clave );
				$valor  = isset( $fila[ $clave ] ) ? $fila[ $clave ] : '';
				// Las filas nuevas (plantilla) nacen marcadas como disponibles.
				$marcado = '__i__' === $indice ? true : ! empty( $valor );
				?>
				<td data-label="<?php echo esc_attr( $col[0] ); ?>">
					<?php if ( 'check' === $col[1] ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $nombre ); ?>" value="0">
						<input type="checkbox" name="<?php echo esc_attr( $nombre ); ?>" value="1"
							<?php checked( $marcado ); ?>>
					<?php elseif ( 'imagen' === $col[1] ) : ?>
						<?php self::campo_imagen( $nombre, (int) $valor ); ?>
					<?php elseif ( 'tipos' === $col[1] ) : ?>
						<?php self::campo_tipo( $nombre, $valor ); ?>
					<?php elseif ( 'number' === $col[1] ) : ?>
						<input type="number" step="any" class="ncm-input-num"
							name="<?php echo esc_attr( $nombre ); ?>"
							value="<?php echo esc_attr( '' === $valor ? '' : self::num( $valor ) ); ?>">
					<?php else : ?>
						<input type="text" class="ncm-input-txt"
							name="<?php echo esc_attr( $nombre ); ?>"
							value="<?php echo esc_attr( $valor ); ?>">
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
			<td class="ncm-tabla__acciones">
				<button type="button" class="button ncm-mover-fila" data-dir="-1"
					aria-label="Subir fila" title="Subir">&uarr;</button>
				<button type="button" class="button ncm-mover-fila" data-dir="1"
					aria-label="Bajar fila" title="Bajar">&darr;</button>
				<button type="button" class="button-link ncm-borrar-fila" aria-label="Eliminar fila">Eliminar</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Selector de imagen de una fila (mediateca de WordPress).
	 *
	 * Guarda el id del adjunto en un campo oculto; lo visible es la miniatura.
	 *
	 * @param string $nombre Nombre del campo del formulario.
	 * @param int    $id     Id del adjunto actual (0 = ninguno).
	 */
	/**
	 * Campo de tipo de joya: desplegable con los tipos que ya existen.
	 *
	 * Los tipos no son una matriz propia: salen de la columna `tipo` de los
	 * diseños (ver NCM_Data::get_tipos). Por eso el campo que se envía sigue
	 * siendo el de texto, y el `<select>` es solo un asistente que lo rellena:
	 * así se evitan las erratas ("Aretes" contra "aretes"), que parten un tipo
	 * en dos y dejan medio catálogo sin diseños en el front.
	 *
	 * Sin JavaScript el desplegable no se muestra y queda el campo de texto de
	 * siempre, que es lo que de verdad guarda el valor.
	 *
	 * @param string $nombre Atributo name del campo.
	 * @param string $valor  Tipo actual de la fila.
	 */
	private static function campo_tipo( $nombre, $valor ) {
		$tipos = NCM_Data::get_tipos();
		?>
		<span class="ncm-tipo" data-ncm-tipo>
			<select class="ncm-tipo__select" data-ncm-tipo-select aria-label="Tipo de joya" hidden>
				<option value="">— Elegir tipo —</option>
				<?php foreach ( $tipos as $tipo ) : ?>
					<option value="<?php echo esc_attr( $tipo ); ?>" <?php selected( $tipo, $valor ); ?>>
						<?php echo esc_html( $tipo ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="text" class="ncm-input-txt ncm-tipo__texto" data-ncm-tipo-texto
				name="<?php echo esc_attr( $nombre ); ?>"
				value="<?php echo esc_attr( $valor ); ?>">
		</span>
		<?php
	}

	private static function campo_imagen( $nombre, $id ) {
		$src = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';

		// Se avisa si la imagen no se va a poder mostrar en la página pública.
		$publicable = NCM_Data::imagen_publicable( $id );
		$problema   = '';

		if ( $id && ! $publicable ) {
			$problema = wp_attachment_is_image( $id )
				? 'La entrada a la que pertenece esta imagen no está publicada, así que no se verá en la calculadora pública.'
				: 'Este adjunto no es una imagen (o ya no existe), así que no se verá en la calculadora.';
		}
		?>
		<span class="ncm-imagen" data-ncm-imagen>
			<input type="hidden" class="ncm-imagen__id" name="<?php echo esc_attr( $nombre ); ?>"
				value="<?php echo esc_attr( $id ? $id : '' ); ?>">

			<button type="button" class="ncm-imagen__boton" aria-label="Elegir imagen">
				<img class="ncm-imagen__vista <?php echo esc_attr( $src ? '' : 'ncm-imagen__vista--vacia' ); ?>"
					src="<?php echo esc_url( $src ); ?>" alt="" <?php echo $src ? '' : 'hidden'; ?>>
				<span class="ncm-imagen__placeholder" <?php echo $src ? 'hidden' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributo booleano literal. ?>>
					<span class="dashicons dashicons-format-image"></span>
				</span>
			</button>

			<button type="button" class="button-link ncm-imagen__quitar" <?php echo $src ? '' : 'hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributo booleano literal. ?>>Quitar</button>

			<?php if ( '' !== $problema ) : ?>
				<span class="ncm-imagen__problema" title="<?php echo esc_attr( $problema ); ?>">⚠ no se verá</span>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Número listo para un input: sin ceros decimales sobrantes y con punto.
	 *
	 * @param mixed $valor Número.
	 * @return string
	 */
	private static function num( $valor ) {
		$valor = (float) $valor;

		if ( floor( $valor ) === $valor ) {
			return (string) (int) $valor;
		}

		return rtrim( rtrim( number_format( $valor, 6, '.', '' ), '0' ), '.' );
	}

	/* ---------------------------------------------------------------------
	 * Guardado
	 * ------------------------------------------------------------------ */

	/** Procesa el guardado de una pestaña. */
	public static function guardar() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'ncm-calculadora' ) );
		}

		$tab      = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'parametros';
		$pestanas = self::pestanas();
		$tab      = isset( $pestanas[ $tab ] ) ? $tab : 'parametros';

		check_admin_referer( 'ncm_guardar_config_' . $tab, 'ncm_nonce' );

		$entrada = isset( $_POST['ncm'] ) ? wp_unslash( $_POST['ncm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$entrada = is_array( $entrada ) ? $entrada : array();

		// Se parte de la config actual y solo se reemplaza la pestaña enviada.
		$config = NCM_Data::get_config();

		if ( 'parametros' === $tab ) {
			$config['parametros'] = self::sanitizar_parametros(
				isset( $entrada['parametros'] ) ? (array) $entrada['parametros'] : array(),
				$config['parametros']
			);
		} else {
			$filas = self::sanitizar_coleccion(
				$tab,
				isset( $entrada[ $tab ] ) ? (array) $entrada[ $tab ] : array()
			);

			// Null = no quedó ninguna fila válida: se conserva lo que ya había.
			if ( null !== $filas ) {
				$config[ $tab ] = $filas;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();
		self::guardar_avisos();

		wp_safe_redirect( add_query_arg( 'ncm_aviso', 'guardado', self::url_pestana( $tab ) ) );
		exit;
	}

	/** Restaura los valores del Excel. */
	public static function restaurar() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'ncm-calculadora' ) );
		}

		check_admin_referer( 'ncm_restaurar_semilla', 'ncm_nonce' );

		NCM_Data::restaurar_semilla();
		NCM_Data::limpiar_cache();

		wp_safe_redirect( add_query_arg( 'ncm_aviso', 'semilla', self::url_pestana( 'parametros' ) ) );
		exit;
	}

	/**
	 * Convierte a número lo que se escribió en un campo.
	 *
	 * Los inputs son `type="number"`, así que lo normal llega con punto
	 * decimal. Aun así se aceptan valores pegados a mano con coma decimal
	 * (`1,15`) y con separador de miles (`300.000,5`): cuando aparecen los dos
	 * separadores, el último manda como decimal y el otro se descarta.
	 *
	 * @param mixed $valor Valor del formulario.
	 * @return float
	 */
	private static function a_numero( $valor ) {
		$texto = trim( (string) $valor );

		if ( '' === $texto ) {
			return 0.0;
		}

		// Fuera todo lo que no sea dígito, separador o signo.
		$texto = preg_replace( '/[^0-9,.\-]/', '', $texto );

		$pos_punto = strrpos( $texto, '.' );
		$pos_coma  = strrpos( $texto, ',' );

		if ( false !== $pos_punto && false !== $pos_coma ) {
			// Ambos separadores: el último es el decimal.
			if ( $pos_coma > $pos_punto ) {
				$texto = str_replace( '.', '', $texto );
				$texto = str_replace( ',', '.', $texto );
			} else {
				$texto = str_replace( ',', '', $texto );
			}
		} elseif ( false !== $pos_coma ) {
			// Una sola coma es decimal; varias son separador de miles.
			$texto = substr_count( $texto, ',' ) > 1
				? str_replace( ',', '', $texto )
				: str_replace( ',', '.', $texto );
		} elseif ( false !== $pos_punto && substr_count( $texto, '.' ) > 1 ) {
			// Varios puntos solo pueden ser separador de miles.
			$texto = str_replace( '.', '', $texto );
		}

		return (float) $texto;
	}

	/**
	 * Sanea los parámetros generales.
	 *
	 * @param array $entrada Datos del formulario.
	 * @param array $actual  Parámetros actuales (para conservar lo no enviado).
	 * @return array
	 */
	private static function sanitizar_parametros( $entrada, $actual ) {
		$margen = isset( $entrada['margen_comercial'] )
			? self::no_negativo( self::a_numero( $entrada['margen_comercial'] ), 'Margen comercial' )
			: $actual['margen_comercial'] * 100;

		$merma = isset( $entrada['factor_merma_metal'] )
			? self::no_negativo( self::a_numero( $entrada['factor_merma_metal'] ), 'Factor de merma del metal' )
			: $actual['factor_merma_metal'];

		$redondeo = isset( $entrada['redondeo_precio'] )
			? self::no_negativo( self::a_numero( $entrada['redondeo_precio'] ), 'Redondeo del precio' )
			: $actual['redondeo_precio'];

		if ( isset( $entrada['whatsapp_numero'] ) && '' !== trim( (string) $entrada['whatsapp_numero'] )
			&& '' === NCM_Data::normalizar_telefono( $entrada['whatsapp_numero'] ) ) {
			self::avisar( 'WhatsApp: el número no parece válido (deben quedar entre 7 y 15 dígitos con el indicativo de país), así que se guardó vacío y el botón no se mostrará.' );
		}

		if ( 0.0 === (float) $merma ) {
			self::avisar( 'Parámetros: un factor de merma de 0 deja el componente de metal en 0. Revísalo si no era la intención.' );
		}

		return array(
			// En pantalla se escribe en porcentaje; se guarda como fracción.
			'margen_comercial'   => $margen / 100,
			'factor_merma_metal' => $merma,
			'redondeo_precio'    => $redondeo,
			'whatsapp_activo'    => isset( $entrada['whatsapp_activo'] ) ? ! empty( $entrada['whatsapp_activo'] ) : $actual['whatsapp_activo'],
			'whatsapp_numero'    => isset( $entrada['whatsapp_numero'] ) ? NCM_Data::normalizar_telefono( $entrada['whatsapp_numero'] ) : $actual['whatsapp_numero'],
			'whatsapp_mensaje'   => isset( $entrada['whatsapp_mensaje'] ) ? sanitize_textarea_field( $entrada['whatsapp_mensaje'] ) : $actual['whatsapp_mensaje'],
			'paleta'             => isset( $entrada['paleta'] ) && in_array( $entrada['paleta'], NCM_Data::get_paletas(), true ) ? $entrada['paleta'] : $actual['paleta'],
			'color_acento'       => isset( $entrada['color_acento'] ) ? NCM_Data::normalizar_color( $entrada['color_acento'], $actual['color_acento'] ) : $actual['color_acento'],
			'color_acento_texto' => isset( $entrada['color_acento_texto'] ) ? NCM_Data::normalizar_color( $entrada['color_acento_texto'], $actual['color_acento_texto'] ) : $actual['color_acento_texto'],
			'color_tinta'        => isset( $entrada['color_tinta'] ) ? NCM_Data::normalizar_color( $entrada['color_tinta'], $actual['color_tinta'] ) : $actual['color_tinta'],
			'color_fondo'        => isset( $entrada['color_fondo'] ) ? NCM_Data::normalizar_color( $entrada['color_fondo'], $actual['color_fondo'] ) : $actual['color_fondo'],
			'color_fondo_alt'    => isset( $entrada['color_fondo_alt'] ) ? NCM_Data::normalizar_color( $entrada['color_fondo_alt'], $actual['color_fondo_alt'] ) : $actual['color_fondo_alt'],
			'color_linea'        => isset( $entrada['color_linea'] ) ? NCM_Data::normalizar_color( $entrada['color_linea'], $actual['color_linea'] ) : $actual['color_linea'],
			'moneda'             => isset( $entrada['moneda'] ) ? sanitize_text_field( $entrada['moneda'] ) : $actual['moneda'],
			'texto_publico'      => isset( $entrada['texto_publico'] ) ? sanitize_textarea_field( $entrada['texto_publico'] ) : $actual['texto_publico'],
			'texto_nota'         => isset( $entrada['texto_nota'] ) ? sanitize_textarea_field( $entrada['texto_nota'] ) : $actual['texto_nota'],
		);
	}

	/**
	 * Lleva a cero un valor negativo, dejando aviso.
	 *
	 * @param float  $valor    Valor recibido.
	 * @param string $etiqueta Nombre del campo para el aviso.
	 * @return float
	 */
	private static function no_negativo( $valor, $etiqueta ) {
		if ( $valor < 0 ) {
			self::avisar( sprintf( 'Parámetros: «%s» era negativo y se guardó como 0.', $etiqueta ) );

			return 0.0;
		}

		return (float) $valor;
	}

	/**
	 * Definición de cada colección: campos, requeridos y claves únicas.
	 *
	 * @return array
	 */
	private static function definicion_colecciones() {
		return array(
			'disenos' => array(
				'etiqueta' => 'Diseños',
				'imagen'   => array( 'imagen' ),
				'texto'    => array( 'codigo', 'tipo', 'diseno' ),
				'numero'   => array( 'peso_metal_g', 'cant_gemas', 'ct_por_gema', 'mano_obra', 'extras' ),
				'check'    => array(),
				'nombra'   => 'diseno',
				'requiere' => array(
					'codigo' => 'Código',
					'tipo'   => 'Tipo de joya',
					'diseno' => 'Diseño',
				),
				'unico'    => array(
					array(
						'campos'   => array( 'codigo' ),
						'etiqueta' => 'el código',
					),
					array(
						'campos'   => array( 'tipo', 'diseno' ),
						'etiqueta' => 'la combinación de tipo de joya y diseño',
					),
				),
			),
			'gemas'   => array(
				'etiqueta' => 'Gemas',
				'imagen'   => array( 'imagen' ),
				'texto'    => array( 'tipo_gema' ),
				'numero'   => array( 'precio_natural', 'precio_laboratorio' ),
				'check'    => array(),
				'nombra'   => 'tipo_gema',
				'requiere' => array( 'tipo_gema' => 'Gema' ),
				'unico'    => array(
					array(
						'campos'   => array( 'tipo_gema' ),
						'etiqueta' => 'la gema',
					),
				),
			),
			'tallas'  => array(
				'etiqueta' => 'Tallas',
				'imagen'   => array( 'imagen' ),
				'texto'    => array( 'talla' ),
				'numero'   => array( 'ajuste' ),
				'check'    => array( 'disponible' ),
				'nombra'   => 'talla',
				'requiere' => array( 'talla' => 'Talla' ),
				'unico'    => array(
					array(
						'campos'   => array( 'talla' ),
						'etiqueta' => 'la talla',
					),
				),
			),
			'metales' => array(
				'etiqueta' => 'Metales',
				'imagen'   => array( 'imagen' ),
				'texto'    => array( 'metal' ),
				'numero'   => array( 'precio_gramo', 'factor_adicional' ),
				'check'    => array( 'disponible' ),
				'nombra'   => 'metal',
				'requiere' => array( 'metal' => 'Metal' ),
				'unico'    => array(
					array(
						'campos'   => array( 'metal' ),
						'etiqueta' => 'el metal',
					),
				),
			),
		);
	}

	/**
	 * Sanea y valida una colección de filas.
	 *
	 * Descarta filas incompletas o duplicadas y lleva los números negativos a
	 * cero. Nada se descarta en silencio: cada decisión deja un aviso que se
	 * muestra al volver al panel.
	 *
	 * @param string $coleccion Nombre de la colección.
	 * @param array  $filas     Filas del formulario.
	 * @return array
	 */
	private static function sanitizar_coleccion( $coleccion, $filas ) {
		$definicion = self::definicion_colecciones();

		if ( ! isset( $definicion[ $coleccion ] ) ) {
			return array();
		}

		$def   = $definicion[ $coleccion ];
		$out   = array();
		$vistos = array();
		$numero_fila = 0;

		foreach ( $filas as $clave => $fila ) {
			if ( ! is_array( $fila ) || '__i__' === (string) $clave ) {
				continue;
			}

			++$numero_fila;

			$limpia = array();

			foreach ( $def['texto'] as $campo ) {
				$limpia[ $campo ] = isset( $fila[ $campo ] ) ? sanitize_text_field( $fila[ $campo ] ) : '';
			}

			foreach ( $def['check'] as $campo ) {
				$limpia[ $campo ] = ! empty( $fila[ $campo ] );
			}

			foreach ( $def['imagen'] as $campo ) {
				$limpia[ $campo ] = isset( $fila[ $campo ] ) ? absint( $fila[ $campo ] ) : 0;
			}

			// Campos requeridos: sin ellos la fila no identifica nada.
			$falta = '';

			foreach ( $def['requiere'] as $campo => $etiqueta ) {
				if ( '' === $limpia[ $campo ] ) {
					$falta = $etiqueta;
					break;
				}
			}

			if ( '' !== $falta ) {
				// Una fila del todo vacía es solo una fila que se agregó y no se usó.
				if ( ! self::fila_vacia( $fila ) ) {
					self::avisar(
						sprintf(
							'%s: se descartó la fila %d porque le falta «%s».',
							$def['etiqueta'],
							$numero_fila,
							$falta
						)
					);
				}

				continue;
			}

			$nombre = $limpia[ $def['nombra'] ];

			// Números: nunca negativos.
			foreach ( $def['numero'] as $campo ) {
				$valor = isset( $fila[ $campo ] ) ? self::a_numero( $fila[ $campo ] ) : 0.0;

				if ( $valor < 0 ) {
					self::avisar(
						sprintf(
							'%s: «%s» tenía un valor negativo en «%s» y se guardó como 0.',
							$def['etiqueta'],
							$nombre,
							$campo
						)
					);

					$valor = 0.0;
				}

				$limpia[ $campo ] = $valor;
			}

			// Claves únicas: la calculadora solo encontraría la primera fila.
			$duplicada = false;

			foreach ( $def['unico'] as $indice => $regla ) {
				$partes = array();

				foreach ( $regla['campos'] as $campo ) {
					$partes[] = self::minusculas( $limpia[ $campo ] );
				}

				$huella = implode( '||', $partes );

				if ( isset( $vistos[ $indice ][ $huella ] ) ) {
					self::avisar(
						sprintf(
							'%s: se descartó la fila %d porque %s «%s» ya está en la fila %d.',
							$def['etiqueta'],
							$numero_fila,
							$regla['etiqueta'],
							implode( ' / ', array_map( function ( $campo ) use ( $limpia ) {
								return $limpia[ $campo ];
							}, $regla['campos'] ) ),
							$vistos[ $indice ][ $huella ]
						)
					);

					$duplicada = true;
					break;
				}

				$vistos[ $indice ][ $huella ] = $numero_fila;
			}

			if ( $duplicada ) {
				continue;
			}

			$out[] = $limpia;
		}

		if ( empty( $out ) ) {
			self::avisar(
				sprintf(
					'%s: no quedó ninguna fila válida, así que se conservó la configuración anterior.',
					$def['etiqueta']
				)
			);

			return null;
		}

		return $out;
	}

	/**
	 * ¿La fila llegó completamente vacía?
	 *
	 * @param array $fila Fila del formulario.
	 * @return bool
	 */
	private static function fila_vacia( $fila ) {
		foreach ( $fila as $clave => $valor ) {
			if ( 'disponible' === $clave || 'imagen' === $clave ) {
				continue;
			}

			if ( '' !== trim( (string) $valor ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Minúsculas seguras con UTF-8, para comparar claves duplicadas.
	 *
	 * @param string $texto Texto a normalizar.
	 * @return string
	 */
	private static function minusculas( $texto ) {
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( (string) $texto, 'UTF-8' )
			: strtolower( (string) $texto );
	}

	/* ---------------------------------------------------------------------
	 * Avisos de validación
	 * ------------------------------------------------------------------ */

	/**
	 * Agrega un aviso para mostrarlo al volver al panel.
	 *
	 * @param string $texto Mensaje.
	 */
	private static function avisar( $texto ) {
		self::$avisos[] = $texto;
	}

	/** Guarda los avisos acumulados para el usuario actual. */
	private static function guardar_avisos() {
		if ( empty( self::$avisos ) ) {
			return;
		}

		set_transient( 'ncm_avisos_' . get_current_user_id(), self::$avisos, MINUTE_IN_SECONDS );
		self::$avisos = array();
	}

	/**
	 * Lee y descarta los avisos pendientes del usuario actual.
	 *
	 * @return array
	 */
	private static function leer_avisos() {
		$clave  = 'ncm_avisos_' . get_current_user_id();
		$avisos = get_transient( $clave );

		if ( empty( $avisos ) || ! is_array( $avisos ) ) {
			return array();
		}

		delete_transient( $clave );

		return $avisos;
	}

}

<?php
/**
 * Module LogiAdmin : Logiprof Animated Counters
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Affiche des compteurs animes pour les comptes, cours, lecons et questions Sensei LMS via shortcodes.
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Menu parent : fourni par LogiAdmin (logiprof_admin_menu_parent_slug). */

final class Logiprof_Animated_Counters {
	const VERSION          = '1.4.0';
	const ADMIN_PAGE       = 'logiprof-animated-counters';
	const SHORTCODE_ONE    = 'logiprof_counter';
	const SHORTCODE_GROUP  = 'logiprof_counters';
	const SHORTCODE_FR_ONE = 'logiprof_compteur';
	const SHORTCODE_FR_ALL = 'logiprof_compteurs';

	private static $assets_loaded = false;

	private static $counter_labels = array(
		'accounts'  => 'Comptes',
		'courses'   => 'Cours',
		'lessons'   => 'Leçons',
		'questions' => 'Questions',
	);

	private static $counter_icons = array(
		'accounts'  => 'people',
		'courses'   => 'book',
		'lessons'   => 'lesson',
		'questions' => 'question',
	);

	public static function init() {
		add_shortcode( self::SHORTCODE_ONE, array( __CLASS__, 'render_single_shortcode' ) );
		add_shortcode( self::SHORTCODE_GROUP, array( __CLASS__, 'render_group_shortcode' ) );
		add_shortcode( self::SHORTCODE_FR_ONE, array( __CLASS__, 'render_single_shortcode' ) );
		add_shortcode( self::SHORTCODE_FR_ALL, array( __CLASS__, 'render_group_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	public static function register_admin_page() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Compteurs', 'logiprof-animated-counters' ),
			__( 'Compteurs', 'logiprof-animated-counters' ),
			'manage_options',
			self::ADMIN_PAGE,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$layout_examples = array(
			array(
				'id'          => 'grid',
				'title'       => __( 'Grille classique', 'logiprof-animated-counters' ),
				'description' => __( 'Idéale pour une section avec plusieurs compteurs alignés.', 'logiprof-animated-counters' ),
				'shortcode'   => '[logiprof_compteurs columns="4" layout="grid"]',
			),
			array(
				'id'          => 'grid-three',
				'title'       => __( 'Grille 3 colonnes', 'logiprof-animated-counters' ),
				'description' => __( 'Pratique pour afficher seulement trois statistiques.', 'logiprof-animated-counters' ),
				'shortcode'   => '[logiprof_compteurs items="cours,lecons,questions" columns="3" layout="grid"]',
			),
			array(
				'id'          => 'row',
				'title'       => __( 'Ligne compacte', 'logiprof-animated-counters' ),
				'description' => __( 'Un rendu horizontal plus discret pour une page déjà dense.', 'logiprof-animated-counters' ),
				'shortcode'   => '[logiprof_compteurs layout="row" style="minimal"]',
			),
			array(
				'id'          => 'stack',
				'title'       => __( 'Empilé vertical', 'logiprof-animated-counters' ),
				'description' => __( 'Lisible dans une colonne, une barre latérale ou un bloc étroit.', 'logiprof-animated-counters' ),
				'shortcode'   => '[logiprof_compteurs layout="stack"]',
			),
			array(
				'id'          => 'inline',
				'title'       => __( 'Inline compact', 'logiprof-animated-counters' ),
				'description' => __( 'Pour insérer les chiffres dans une zone courte ou un bandeau.', 'logiprof-animated-counters' ),
				'shortcode'   => '[logiprof_compteurs layout="inline" style="inline"]',
			),
		);

		$single_examples = array(
			array(
				'title'     => __( 'Comptes', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="comptes"]',
			),
			array(
				'title'     => __( 'Cours', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="cours"]',
			),
			array(
				'title'     => __( 'Leçons', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="lecons"]',
			),
			array(
				'title'     => __( 'Questions', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="questions"]',
			),
			array(
				'title'     => __( 'Élèves seulement', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="comptes" role="subscriber" label="Eleves inscrits"]',
			),
			array(
				'title'     => __( 'Tous les statuts', 'logiprof-animated-counters' ),
				'shortcode' => '[logiprof_compteur type="cours" status="any" label="Cours crees"]',
			),
		);
		$palettes = array(
			'logione' => array(
				'label'  => __( 'LogiOne', 'logiprof-animated-counters' ),
				'accent' => '#067030',
				'text'   => '#313a45',
				'muted'  => '#5b6672',
				'bg'     => '#ffffff',
				'border' => '#dfe3dc',
			),
			'nuit'    => array(
				'label'  => __( 'Anthracite', 'logiprof-animated-counters' ),
				'accent' => '#f5c400',
				'text'   => '#ffffff',
				'muted'  => '#c9d1d9',
				'bg'     => '#313a45',
				'border' => '#313a45',
			),
			'origine' => array(
				'label'  => __( 'Sarcelle (d’origine)', 'logiprof-animated-counters' ),
				'accent' => '#118a8a',
				'text'   => '#17212b',
				'muted'  => '#5c6875',
				'bg'     => '#ffffff',
				'border' => '#d8dee6',
			),
		);
		$start = $palettes['logione'];

		LogiAdmin::page_open( 'compteurs', array( 'class' => 'logiprof-counters-admin' ) );
		?>
		<div class="lac-workbench">
			<div class="lac-steps">
				<section class="lad-card" aria-labelledby="lac-layouts-title">
					<h2 id="lac-layouts-title"><span class="lad-step">1</span><?php esc_html_e( 'Choisir une disposition', 'logiprof-animated-counters' ); ?></h2>
					<div class="lac-layout-choices" role="radiogroup" aria-labelledby="lac-layouts-title">
						<?php foreach ( $layout_examples as $index => $layout ) : ?>
							<button class="lac-layout-card<?php echo 0 === $index ? ' is-selected' : ''; ?>" type="button" role="radio" aria-checked="<?php echo 0 === $index ? 'true' : 'false'; ?>" data-lac-shortcode="<?php echo esc_attr( $layout['shortcode'] ); ?>">
								<span class="lac-layout-card__preview lac-preview--<?php echo esc_attr( $layout['id'] ); ?>" aria-hidden="true">
									<?php echo self::get_admin_layout_preview( $layout['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</span>
								<span class="lac-layout-card__title"><?php echo esc_html( $layout['title'] ); ?></span>
								<span class="lac-layout-card__description"><?php echo esc_html( $layout['description'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="lad-card" aria-labelledby="lac-design-title">
					<h2 id="lac-design-title"><span class="lad-step">2</span><?php esc_html_e( 'Ajuster le style', 'logiprof-animated-counters' ); ?></h2>
					<div class="lac-palettes" role="group" aria-label="<?php esc_attr_e( 'Palettes prêtes', 'logiprof-animated-counters' ); ?>">
						<?php foreach ( $palettes as $palette_key => $palette ) : ?>
							<button type="button" class="lac-palette<?php echo 'logione' === $palette_key ? ' is-selected' : ''; ?>" data-lac-palette="<?php echo esc_attr( wp_json_encode( array_diff_key( $palette, array( 'label' => 1 ) ) ) ); ?>">
								<span class="lac-palette__dots" aria-hidden="true"><i style="background:<?php echo esc_attr( $palette['bg'] ); ?>"></i><i style="background:<?php echo esc_attr( $palette['accent'] ); ?>"></i><i style="background:<?php echo esc_attr( $palette['text'] ); ?>"></i></span>
								<?php echo esc_html( $palette['label'] ); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="lac-design-controls">
						<div class="lac-design-control">
							<label for="lac-accent"><?php esc_html_e( 'Couleur principale', 'logiprof-animated-counters' ); ?></label>
							<input id="lac-accent" type="color" value="<?php echo esc_attr( $start['accent'] ); ?>" data-lac-design="accent">
						</div>
						<div class="lac-design-control">
							<label for="lac-text"><?php esc_html_e( 'Chiffres', 'logiprof-animated-counters' ); ?></label>
							<input id="lac-text" type="color" value="<?php echo esc_attr( $start['text'] ); ?>" data-lac-design="text">
						</div>
						<div class="lac-design-control">
							<label for="lac-muted"><?php esc_html_e( 'Libellés', 'logiprof-animated-counters' ); ?></label>
							<input id="lac-muted" type="color" value="<?php echo esc_attr( $start['muted'] ); ?>" data-lac-design="muted">
						</div>
						<div class="lac-design-control">
							<label for="lac-bg"><?php esc_html_e( 'Fond', 'logiprof-animated-counters' ); ?></label>
							<input id="lac-bg" type="color" value="<?php echo esc_attr( $start['bg'] ); ?>" data-lac-design="bg">
						</div>
						<div class="lac-design-control">
							<label for="lac-border"><?php esc_html_e( 'Bordure', 'logiprof-animated-counters' ); ?></label>
							<input id="lac-border" type="color" value="<?php echo esc_attr( $start['border'] ); ?>" data-lac-design="border">
						</div>
						<div class="lac-design-control">
							<label for="lac-radius"><?php esc_html_e( 'Arrondi', 'logiprof-animated-counters' ); ?> <output for="lac-radius" data-lac-radius-out>6 px</output></label>
							<input id="lac-radius" type="range" min="0" max="40" value="6" data-lac-design="radius">
						</div>
						<div class="lac-design-control">
							<label for="lac-shadow"><?php esc_html_e( 'Ombre', 'logiprof-animated-counters' ); ?></label>
							<select id="lac-shadow" data-lac-design="shadow">
								<option value="soft"><?php esc_html_e( 'Douce', 'logiprof-animated-counters' ); ?></option>
								<option value="none"><?php esc_html_e( 'Aucune', 'logiprof-animated-counters' ); ?></option>
								<option value="medium"><?php esc_html_e( 'Moyenne', 'logiprof-animated-counters' ); ?></option>
								<option value="strong"><?php esc_html_e( 'Forte', 'logiprof-animated-counters' ); ?></option>
							</select>
						</div>
						<div class="lac-design-control">
							<label for="lac-size"><?php esc_html_e( 'Taille des chiffres', 'logiprof-animated-counters' ); ?></label>
							<select id="lac-size" data-lac-design="size">
								<option value="normal"><?php esc_html_e( 'Normale', 'logiprof-animated-counters' ); ?></option>
								<option value="small"><?php esc_html_e( 'Petite', 'logiprof-animated-counters' ); ?></option>
								<option value="large"><?php esc_html_e( 'Grande', 'logiprof-animated-counters' ); ?></option>
							</select>
						</div>
						<label class="lac-design-toggle">
							<input type="checkbox" checked data-lac-design="icons">
							<span><?php esc_html_e( 'Afficher les icônes', 'logiprof-animated-counters' ); ?></span>
						</label>
					</div>
				</section>
			</div>

			<aside class="lac-result" aria-labelledby="lac-result-title">
				<section class="lad-card lac-result-card">
					<h2 id="lac-result-title"><span class="lad-step">3</span><?php esc_html_e( 'Aperçu et shortcode', 'logiprof-animated-counters' ); ?></h2>
					<div class="lac-live" data-lac-live>
						<?php echo self::render_group_shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<p class="lac-live-note"><?php esc_html_e( 'Aperçu avec les vrais chiffres du site.', 'logiprof-animated-counters' ); ?></p>
					<label class="lad-label" for="lac-selected-shortcode"><?php esc_html_e( 'Shortcode à coller dans la page', 'logiprof-animated-counters' ); ?></label>
					<div class="lac-copy-row">
						<textarea id="lac-selected-shortcode" class="code" rows="3" readonly><?php echo esc_textarea( $layout_examples[0]['shortcode'] ); ?></textarea>
						<button class="button button-primary lac-copy-button" type="button" data-lac-copy="#lac-selected-shortcode"><?php esc_html_e( 'Copier', 'logiprof-animated-counters' ); ?></button>
					</div>
					<p class="lac-copy-feedback" aria-live="polite"></p>
				</section>
			</aside>
		</div>

		<section class="lad-card" aria-labelledby="lac-singles-title">
			<h2 id="lac-singles-title"><?php esc_html_e( 'Un seul chiffre', 'logiprof-animated-counters' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ces shortcodes reprennent le style choisi ci-dessus.', 'logiprof-animated-counters' ); ?></p>
			<div class="lac-single-grid">
				<?php foreach ( $single_examples as $single ) : ?>
					<div class="lac-single-card">
						<span class="lac-single-card__title"><?php echo esc_html( $single['title'] ); ?></span>
						<code><?php echo esc_html( $single['shortcode'] ); ?></code>
						<button class="button lac-copy-button" type="button" data-lac-shortcode="<?php echo esc_attr( $single['shortcode'] ); ?>"><?php esc_html_e( 'Copier', 'logiprof-animated-counters' ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
		</section>

		<details class="lad-card lac-options">
			<summary><?php esc_html_e( 'Toutes les options du shortcode', 'logiprof-animated-counters' ); ?></summary>
			<dl class="lac-option-list">
				<dt><code>layout</code></dt><dd><?php esc_html_e( 'grid, row, stack ou inline.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>columns</code></dt><dd><?php esc_html_e( 'nombre de colonnes sur ordinateur pour la grille, de 1 à 4.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>style</code></dt><dd><?php esc_html_e( 'card, minimal ou inline.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>items</code></dt><dd><?php esc_html_e( 'comptes, cours, lecons, questions.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>type</code></dt><dd><?php esc_html_e( 'pour un seul chiffre : comptes, cours, lecons ou questions.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>role</code></dt><dd><?php esc_html_e( 'ne compter que les comptes d’un rôle WordPress (ex. subscriber).', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>status</code></dt><dd><?php esc_html_e( 'publish (par défaut) ou any pour compter aussi les brouillons.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>label</code>, <code>prefix</code>, <code>suffix</code></dt><dd><?php esc_html_e( 'texte personnalisé autour d’un chiffre.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>duration</code></dt><dd><?php esc_html_e( 'durée de l’animation en millisecondes (300 à 6000).', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>accent</code>, <code>text</code>, <code>muted</code>, <code>bg</code>, <code>border</code></dt><dd><?php esc_html_e( 'couleurs au format #rrggbb.', 'logiprof-animated-counters' ); ?></dd>
				<dt><code>radius</code>, <code>shadow</code>, <code>size</code>, <code>icons</code></dt><dd><?php esc_html_e( 'arrondi (px), ombre (none, soft, medium, strong), taille (small, normal, large), icônes (yes ou no).', 'logiprof-animated-counters' ); ?></dd>
			</dl>
		</details>
		<?php
		LogiAdmin::page_close();
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::ADMIN_PAGE ) ) {
			return;
		}

		// Aperçu en direct : les vrais styles et l'animation des compteurs.
		self::register_assets();
		self::enqueue_assets_once();

		wp_enqueue_style(
			'logiprof-animated-counters-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'logiprof-animated-counters-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array( 'logiprof-animated-counters' ),
			self::VERSION,
			true
		);
	}

	private static function get_admin_layout_preview( $layout ) {
		if ( 'row' === $layout ) {
			return '<span></span><span></span><span></span><span></span>';
		}

		if ( 'stack' === $layout ) {
			return '<span></span><span></span><span></span>';
		}

		if ( 'inline' === $layout ) {
			return '<span></span><span></span><span></span><span></span>';
		}

		if ( 'grid-three' === $layout ) {
			return '<span></span><span></span><span></span>';
		}

		return '<span></span><span></span><span></span><span></span>';
	}

	public static function register_assets() {
		wp_register_style(
			'logiprof-animated-counters',
			plugins_url( 'assets/counters.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_register_script(
			'logiprof-animated-counters',
			plugins_url( 'assets/counters.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
	}

	public static function render_single_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'     => 'accounts',
				'label'    => '',
				'status'   => 'publish',
				'role'     => '',
				'style'    => 'card',
				'duration' => 1400,
				'prefix'   => '',
				'suffix'   => '',
				'accent'   => '',
				'text'     => '',
				'muted'    => '',
				'bg'       => '',
				'border'   => '',
				'radius'   => '',
				'shadow'   => '',
				'size'     => '',
				'icons'    => 'yes',
			),
			$atts,
			self::SHORTCODE_ONE
		);

		$type = self::normalize_type( $atts['type'] );
		if ( ! $type ) {
			return '';
		}

		self::enqueue_assets_once();

		return self::render_counter(
			$type,
			array(
				'label'    => $atts['label'],
				'status'   => $atts['status'],
				'role'     => $atts['role'],
				'style'    => $atts['style'],
				'duration' => $atts['duration'],
				'prefix'   => $atts['prefix'],
				'suffix'   => $atts['suffix'],
				'accent'   => $atts['accent'],
				'text'     => $atts['text'],
				'muted'    => $atts['muted'],
				'bg'       => $atts['bg'],
				'border'   => $atts['border'],
				'radius'   => $atts['radius'],
				'shadow'   => $atts['shadow'],
				'size'     => $atts['size'],
				'icons'    => $atts['icons'],
			)
		);
	}

	public static function render_group_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'items'    => 'accounts,courses,lessons,questions',
				'status'   => 'publish',
				'role'     => '',
				'columns'  => 4,
				'layout'   => 'grid',
				'style'    => 'card',
				'duration' => 1400,
				'accent'   => '',
				'text'     => '',
				'muted'    => '',
				'bg'       => '',
				'border'   => '',
				'radius'   => '',
				'shadow'   => '',
				'size'     => '',
				'icons'    => 'yes',
			),
			$atts,
			self::SHORTCODE_GROUP
		);

		$items = self::parse_items( $atts['items'] );
		if ( empty( $items ) ) {
			return '';
		}

		self::enqueue_assets_once();

		$columns = max( 1, min( 4, absint( $atts['columns'] ) ) );
		$layout  = self::sanitize_layout( $atts['layout'] );
		$style   = self::sanitize_style( $atts['style'] );
		$design  = self::get_design_args( $atts );

		$output  = '<div class="logiprof-counters logiprof-counters--' . esc_attr( $style ) . ' logiprof-counters--layout-' . esc_attr( $layout ) . '" style="--logiprof-counter-columns:' . esc_attr( $columns ) . ';' . esc_attr( self::get_design_css_vars( $design ) ) . '">';
		foreach ( $items as $type ) {
			$output .= self::render_counter(
				$type,
				array(
					'status'   => $atts['status'],
					'role'     => $atts['role'],
					'style'    => $style,
					'duration' => $atts['duration'],
					'accent'   => $atts['accent'],
					'text'     => $atts['text'],
					'muted'    => $atts['muted'],
					'bg'       => $atts['bg'],
					'border'   => $atts['border'],
					'radius'   => $atts['radius'],
					'shadow'   => $atts['shadow'],
					'size'     => $atts['size'],
					'icons'    => $atts['icons'],
				)
			);
		}
		$output .= '</div>';

		return $output;
	}

	private static function render_counter( $type, $args ) {
		$defaults = array(
			'label'    => '',
			'status'   => 'publish',
			'role'     => '',
			'style'    => 'card',
			'duration' => 1400,
			'prefix'   => '',
			'suffix'   => '',
			'accent'   => '',
			'text'     => '',
			'muted'    => '',
			'bg'       => '',
			'border'   => '',
			'radius'   => '',
			'shadow'   => '',
			'size'     => '',
			'icons'    => 'yes',
		);
		$args     = wp_parse_args( $args, $defaults );
		$value    = self::get_counter_value( $type, $args );
		$label    = '' !== $args['label'] ? $args['label'] : self::$counter_labels[ $type ];
		$style    = self::sanitize_style( $args['style'] );
		$duration = max( 300, min( 6000, absint( $args['duration'] ) ) );
		$design   = self::get_design_args( $args );
		$css_vars = self::get_design_css_vars( $design );

		$output  = '<div class="logiprof-counter logiprof-counter--' . esc_attr( $type ) . ' logiprof-counter--' . esc_attr( $style ) . '" style="' . esc_attr( $css_vars ) . '">';
		if ( $design['icons'] ) {
			$output .= '<span class="logiprof-counter__icon" aria-hidden="true">' . self::get_icon_svg( self::$counter_icons[ $type ] ) . '</span>';
		}
		$output .= '<span class="logiprof-counter__value-wrap">';
		$output .= '<span class="logiprof-counter__prefix">' . esc_html( $args['prefix'] ) . '</span>';
		$output .= '<span class="logiprof-counter__value" data-logiprof-counter="' . esc_attr( $value ) . '" data-duration="' . esc_attr( $duration ) . '">0</span>';
		$output .= '<span class="logiprof-counter__suffix">' . esc_html( $args['suffix'] ) . '</span>';
		$output .= '</span>';
		$output .= '<span class="logiprof-counter__label">' . esc_html( $label ) . '</span>';
		$output .= '</div>';

		return $output;
	}

	private static function get_counter_value( $type, $args ) {
		if ( 'accounts' === $type ) {
			return self::count_users( $args['role'] );
		}

		if ( 'questions' === $type ) {
			return self::count_posts( array( 'question', 'multiple_question' ), $args['status'] );
		}

		$post_types = array(
			'courses' => array( 'course' ),
			'lessons' => array( 'lesson' ),
		);

		return self::count_posts( $post_types[ $type ], $args['status'] );
	}

	private static function count_users( $role ) {
		global $wpdb;

		$role = sanitize_key( $role );

		if ( '' === $role ) {
			return absint( $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->users}" ) );
		}

		$capabilities_key = $wpdb->get_blog_prefix() . 'capabilities';
		$role_like        = '%"' . $wpdb->esc_like( $role ) . '"%';

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT u.ID)
					FROM {$wpdb->users} u
					INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
					WHERE um.meta_key = %s
					AND um.meta_value LIKE %s",
					$capabilities_key,
					$role_like
				)
			)
		);
	}

	private static function count_posts( $post_types, $status ) {
		global $wpdb;

		$total  = 0;
		$status = sanitize_key( $status );
		$status = '' !== $status ? $status : 'publish';

		foreach ( $post_types as $post_type ) {
			if ( 'any' === $status ) {
				$total += absint(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit', 'trash')",
							$post_type
						)
					)
				);
				continue;
			}

			$total += absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
						$post_type,
						$status
					)
				)
			);
		}

		return $total;
	}

	private static function get_design_args( $args ) {
		return array(
			'accent' => self::sanitize_hex( $args['accent'] ),
			'text'   => self::sanitize_hex( $args['text'] ),
			'muted'  => self::sanitize_hex( $args['muted'] ),
			'bg'     => self::sanitize_hex( $args['bg'] ),
			'border' => self::sanitize_hex( $args['border'] ),
			'radius' => '' !== $args['radius'] ? max( 0, min( 40, absint( $args['radius'] ) ) ) : '',
			'shadow' => self::sanitize_shadow( $args['shadow'] ),
			'size'   => self::sanitize_size( $args['size'] ),
			'icons'  => ! in_array( strtolower( (string) $args['icons'] ), array( 'no', 'non', 'false', '0' ), true ),
		);
	}

	private static function get_design_css_vars( $design ) {
		$vars = array();

		if ( $design['accent'] ) {
			$vars[] = '--logiprof-counter-accent:' . $design['accent'];
			$vars[] = '--counter-local-accent:' . $design['accent'];
		}

		if ( $design['text'] ) {
			$vars[] = '--logiprof-counter-ink:' . $design['text'];
		}

		if ( $design['muted'] ) {
			$vars[] = '--logiprof-counter-muted:' . $design['muted'];
		}

		if ( $design['bg'] ) {
			$vars[] = '--logiprof-counter-surface:' . $design['bg'];
		}

		if ( $design['border'] ) {
			$vars[] = '--logiprof-counter-border:' . $design['border'];
		}

		if ( '' !== $design['radius'] ) {
			$vars[] = '--logiprof-counter-radius:' . $design['radius'] . 'px';
		}

		if ( $design['shadow'] ) {
			$vars[] = '--logiprof-counter-shadow:' . $design['shadow'];
		}

		if ( $design['size'] ) {
			$vars[] = '--logiprof-counter-value-size:' . $design['size'];
		}

		return implode( ';', $vars );
	}

	private static function sanitize_hex( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '';
	}

	private static function sanitize_shadow( $shadow ) {
		$shadow = sanitize_key( $shadow );

		$values = array(
			'none'   => 'none',
			'soft'   => '0 14px 34px rgba(23, 33, 43, 0.08)',
			'medium' => '0 18px 42px rgba(23, 33, 43, 0.14)',
			'strong' => '0 22px 54px rgba(23, 33, 43, 0.22)',
		);

		return isset( $values[ $shadow ] ) ? $values[ $shadow ] : '';
	}

	private static function sanitize_size( $size ) {
		$size = sanitize_key( $size );

		$values = array(
			'small'  => '32px',
			'normal' => '42px',
			'large'  => '54px',
		);

		return isset( $values[ $size ] ) ? $values[ $size ] : '';
	}

	private static function parse_items( $items ) {
		$raw_items = array_map( 'trim', explode( ',', (string) $items ) );
		$items     = array();

		foreach ( $raw_items as $item ) {
			$type = self::normalize_type( $item );
			if ( $type && ! in_array( $type, $items, true ) ) {
				$items[] = $type;
			}
		}

		return $items;
	}

	private static function normalize_type( $type ) {
		$type = sanitize_key( $type );

		$aliases = array(
			'account'   => 'accounts',
			'accounts'  => 'accounts',
			'compte'    => 'accounts',
			'comptes'   => 'accounts',
			'user'      => 'accounts',
			'users'     => 'accounts',
			'course'    => 'courses',
			'courses'   => 'courses',
			'cours'     => 'courses',
			'lesson'    => 'lessons',
			'lessons'   => 'lessons',
			'lecon'     => 'lessons',
			'lecons'    => 'lessons',
			'question'  => 'questions',
			'questions' => 'questions',
		);

		return isset( $aliases[ $type ] ) ? $aliases[ $type ] : false;
	}

	private static function sanitize_style( $style ) {
		$style = sanitize_key( $style );
		return in_array( $style, array( 'card', 'minimal', 'inline' ), true ) ? $style : 'card';
	}

	private static function sanitize_layout( $layout ) {
		$layout = sanitize_key( $layout );

		$aliases = array(
			'grid'      => 'grid',
			'grille'    => 'grid',
			'row'       => 'row',
			'ligne'     => 'row',
			'horizontal' => 'row',
			'stack'     => 'stack',
			'pile'      => 'stack',
			'vertical'  => 'stack',
			'inline'    => 'inline',
			'compact'   => 'inline',
			'compacte'  => 'inline',
		);

		return isset( $aliases[ $layout ] ) ? $aliases[ $layout ] : 'grid';
	}

	private static function enqueue_assets_once() {
		if ( self::$assets_loaded ) {
			return;
		}

		wp_enqueue_style( 'logiprof-animated-counters' );
		wp_enqueue_script( 'logiprof-animated-counters' );
		self::$assets_loaded = true;
	}

	private static function get_icon_svg( $icon ) {
		$icons = array(
			'people'   => '<svg viewBox="0 0 24 24" focusable="false"><path d="M16 11c1.66 0 3-1.57 3-3.5S17.66 4 16 4s-3 1.57-3 3.5 1.34 3.5 3 3.5Zm-8 0c1.66 0 3-1.57 3-3.5S9.66 4 8 4 5 5.57 5 7.5 6.34 11 8 11Zm0 2c-2.67 0-6 1.34-6 4v2h12v-2c0-2.66-3.33-4-6-4Zm8 0c-.32 0-.67.02-1.03.06 1.23.9 2.03 2.13 2.03 3.94v2h5v-2c0-2.66-3.33-4-6-4Z"/></svg>',
			'book'     => '<svg viewBox="0 0 24 24" focusable="false"><path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H20v17H7.5A2.5 2.5 0 0 0 5 21.5v-17Zm2.5-.5a.5.5 0 0 0-.5.5v12.55c.17-.03.33-.05.5-.05H18V4H7.5ZM4 4.5C4 3.67 4.67 3 5.5 3H6v16h-.5A1.5 1.5 0 0 0 4 20.5v-16Z"/></svg>',
			'lesson'   => '<svg viewBox="0 0 24 24" focusable="false"><path d="M4 3h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6l-2 3-2-3H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm2 4v2h12V7H6Zm0 4v2h8v-2H6Z"/></svg>',
			'question' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm.1 15.75a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5Zm1.1-4.32v.57h-2.13v-.72c0-1.24.72-1.91 1.62-2.49.85-.55 1.48-.98 1.48-1.88 0-.95-.72-1.56-1.84-1.56-1.17 0-2.05.66-2.62 1.62L8.1 7.78c.84-1.5 2.34-2.45 4.36-2.45 2.38 0 4.05 1.37 4.05 3.43 0 1.72-1.05 2.58-2.08 3.23-.8.5-1.23.83-1.23 1.44Z"/></svg>',
		);

		return isset( $icons[ $icon ] ) ? $icons[ $icon ] : '';
	}
}

Logiprof_Animated_Counters::init();

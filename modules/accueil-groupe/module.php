<?php
/**
 * Module LogiAdmin : Accueil selon groupe
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Definit une page d'accueil differente selon le groupe ou role Ultimate Member de l'utilisateur connecte.
 * Version: 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Menu parent : fourni par LogiAdmin (logiprof_admin_menu_parent_slug). */

final class Logiprof_UM_Connected_Home {
	const VERSION         = '1.0.4';
	const OPTION_FALLBACK = 'logiprof_um_connected_home_url';
	const USER_META_URL   = '_logiprof_um_connected_home_url';

	private static $building_url = false;

	public static function init() {
		add_filter( 'um_login_redirect_url', array( __CLASS__, 'remember_um_redirect_url' ), 10, 2 );
		add_action( 'parse_request', array( __CLASS__, 'force_connected_home_page_query' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_front_page' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'start_frontend_link_rewrite' ), 2 );
		add_filter( 'home_url', array( __CLASS__, 'filter_home_url' ), 20, 4 );
		add_filter( 'get_custom_logo', array( __CLASS__, 'filter_custom_logo' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_public_home' ), 31 );
	}

	/**
	 * Barre d'administration : lien « Voir l'accueil public » pour les personnes qui modifient le site
	 * et qui ont une page d'accueil personnelle.
	 */
	public static function admin_bar_public_home( $admin_bar ) {
		if ( ! is_user_logged_in() || ( ! current_user_can( 'edit_pages' ) && ! current_user_can( 'edit_posts' ) ) ) {
			return;
		}
		if ( ! self::get_connected_home_url() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'parent' => is_admin() ? 'site-name' : 'top-secondary',
				'id'     => 'logiprof-accueil-public',
				'title'  => esc_html__( 'Voir l’accueil public', 'logiprof-um-connected-home' ),
				'href'   => add_query_arg( 'accueil_public', '1', self::raw_home_url( '/' ) ),
				'meta'   => array( 'title' => esc_attr__( 'Afficher la page d’accueil que voient les visiteurs, sans être renvoyé vers votre page personnelle.', 'logiprof-um-connected-home' ) ),
			)
		);
	}

	public static function remember_um_redirect_url( $url ) {
		$user_id = self::find_user_id_from_args( func_get_args() );
		$url     = self::normalize_url( $url );

		if ( $user_id && $url ) {
			update_user_meta( $user_id, self::USER_META_URL, $url );
		}

		return $url;
	}

	public static function redirect_logged_in_front_page() {
		if ( ! self::should_use_connected_home() || ! self::is_public_home_request() ) {
			return;
		}

		$target_url = self::get_connected_home_url();
		if ( ! $target_url || self::is_current_url( $target_url ) ) {
			return;
		}

		wp_safe_redirect( $target_url, 302 );
		exit;
	}

	public static function force_connected_home_page_query( $wp ) {
		if ( ! self::should_use_connected_home() || ! $wp instanceof WP ) {
			return;
		}

		$target_url = self::get_connected_home_url();
		if ( ! $target_url || ! self::is_current_url( $target_url ) ) {
			return;
		}

		self::disable_page_cache();

		$page = self::get_page_from_url( $target_url );
		if ( ! $page ) {
			return;
		}

		$wp->query_vars = array(
			'page_id'  => $page->ID,
			'pagename' => get_page_uri( $page ),
		);
	}

	public static function start_frontend_link_rewrite() {
		if ( ! self::should_use_connected_home() || self::is_public_home_request() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		if ( ! self::get_connected_home_url() ) {
			return;
		}

		ob_start( array( __CLASS__, 'rewrite_home_links_in_html' ) );
	}

	public static function filter_home_url( $url, $path, $orig_scheme, $blog_id ) {
		if ( self::$building_url || ! self::should_use_connected_home() || ! self::is_home_path( $path ) ) {
			return $url;
		}

		$target_url = self::get_connected_home_url();
		return $target_url ? $target_url : $url;
	}

	public static function filter_custom_logo( $html ) {
		if ( empty( $html ) || ! self::should_use_connected_home() ) {
			return $html;
		}

		$target_url = self::get_connected_home_url();
		if ( ! $target_url ) {
			return $html;
		}

		$home_url = self::raw_home_url( '/' );
		return str_replace(
			array(
				'href="' . esc_url( $home_url ) . '"',
				"href='" . esc_url( $home_url ) . "'",
			),
			array(
				'href="' . esc_url( $target_url ) . '"',
				"href='" . esc_url( $target_url ) . "'",
			),
			$html
		);
	}

	public static function rewrite_home_links_in_html( $html ) {
		if ( false === stripos( $html, '<a ' ) ) {
			return $html;
		}

		$target_url = self::get_connected_home_url();
		if ( ! $target_url ) {
			return $html;
		}

		$home_urls    = self::get_public_home_url_variants();
		$replacements = array();

		foreach ( $home_urls as $home_url ) {
			$replacements[ 'href="' . esc_url( $home_url ) . '"' ]  = 'href="' . esc_url( $target_url ) . '"';
			$replacements[ "href='" . esc_url( $home_url ) . "'" ]  = "href='" . esc_url( $target_url ) . "'";
			$replacements[ 'href="' . esc_attr( $home_url ) . '"' ] = 'href="' . esc_attr( $target_url ) . '"';
			$replacements[ "href='" . esc_attr( $home_url ) . "'" ] = "href='" . esc_attr( $target_url ) . "'";
		}

		if ( self::site_is_installed_at_root() ) {
			$replacements['href="/"'] = 'href="' . esc_url( $target_url ) . '"';
			$replacements["href='/'"] = "href='" . esc_url( $target_url ) . "'";
		}

		return strtr( $html, $replacements );
	}

	public static function register_settings_page() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Accueil selon groupe', 'logiprof-um-connected-home' ),
			__( 'Accueil selon groupe', 'logiprof-um-connected-home' ),
			'manage_options',
			'logiprof-um-connected-home',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'logiprof_um_connected_home',
			self::OPTION_FALLBACK,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'normalize_url' ),
				'default'           => '',
			)
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$fallback = get_option( self::OPTION_FALLBACK, '' );
		LogiAdmin::page_open( 'accueil-groupe', array( 'class' => 'lad-accueil' ) );
		settings_errors( 'general' );
		settings_errors( self::OPTION_FALLBACK );
		?>
		<div class="lad-columns">
			<section class="lad-card">
				<h2><?php esc_html_e( 'Comment la page d’accueil est choisie', 'logiprof-um-connected-home' ); ?></h2>
				<ol class="lad-steps">
					<li><?php esc_html_e( 'L’adresse calculée par Ultimate Member à la connexion, mémorisée pour chaque personne.', 'logiprof-um-connected-home' ); ?></li>
					<li><?php esc_html_e( 'Sinon, l’adresse de redirection réglée dans le rôle Ultimate Member de la personne.', 'logiprof-um-connected-home' ); ?></li>
					<li><?php esc_html_e( 'Sinon, l’URL de secours ci-dessous.', 'logiprof-um-connected-home' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Les visiteurs non connectés gardent la page d’accueil publique. Le logo et les liens « Accueil » mènent chaque personne connectée vers sa propre page.', 'logiprof-um-connected-home' ); ?></p>
				<p class="lad-hint"><?php esc_html_e( 'Pour modifier le site, rien n’est redirigé : le constructeur Divi, l’éditeur WordPress et les aperçus ouvrent toujours la page demandée, accueil public compris. Le lien « Voir l’accueil public » de la barre d’administration affiche l’accueil des visiteurs.', 'logiprof-um-connected-home' ); ?></p>

				<form method="post" action="options.php" class="lad-form">
					<?php settings_fields( 'logiprof_um_connected_home' ); ?>
					<label class="lad-field" for="<?php echo esc_attr( self::OPTION_FALLBACK ); ?>">
						<span class="lad-label"><?php esc_html_e( 'URL de secours', 'logiprof-um-connected-home' ); ?></span>
						<input type="text" class="regular-text" id="<?php echo esc_attr( self::OPTION_FALLBACK ); ?>" name="<?php echo esc_attr( self::OPTION_FALLBACK ); ?>" value="<?php echo esc_attr( $fallback ); ?>" placeholder="<?php echo esc_attr( self::raw_home_url( '/accueil-connecte/' ) ); ?>">
						<span class="description"><?php esc_html_e( 'Une adresse du site, complète ou commençant par / (exemple : /accueil-connecte/). Laisser vide pour ne pas avoir de secours.', 'logiprof-um-connected-home' ); ?></span>
					</label>
					<?php submit_button( __( 'Enregistrer', 'logiprof-um-connected-home' ), 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="lad-card">
				<h2><?php esc_html_e( 'Où arrive chaque rôle', 'logiprof-um-connected-home' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Adresse trouvée dans les réglages Ultimate Member de chaque rôle. « Secours » signifie que l’URL de secours sera utilisée.', 'logiprof-um-connected-home' ); ?></p>
				<table class="widefat striped lad-table">
					<thead><tr><th><?php esc_html_e( 'Rôle', 'logiprof-um-connected-home' ); ?></th><th><?php esc_html_e( 'Page d’accueil', 'logiprof-um-connected-home' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( wp_roles()->roles as $role_key => $role ) : ?>
							<?php $role_url = self::find_redirect_url_in_settings( get_option( 'um_role_' . sanitize_key( $role_key ), array() ) ); ?>
							<tr>
								<td><strong><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></strong></td>
								<td>
									<?php if ( $role_url ) : ?>
										<a href="<?php echo esc_url( $role_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $role_url ); ?></a>
									<?php elseif ( $fallback ) : ?>
										<span class="lad-pill"><?php esc_html_e( 'Secours', 'logiprof-um-connected-home' ); ?></span> <?php echo esc_html( self::normalize_url( $fallback ) ); ?>
									<?php else : ?>
										<span class="lad-pill is-muted"><?php esc_html_e( 'Accueil public', 'logiprof-um-connected-home' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! function_exists( 'um_fetch_user' ) ) : ?>
					<p class="lad-hint"><?php esc_html_e( 'Ultimate Member n’est pas actif sur ce site : seule l’URL de secours peut s’appliquer.', 'logiprof-um-connected-home' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<?php
		LogiAdmin::page_close();
	}

	private static function get_connected_home_url() {
		$user_id = get_current_user_id();

		$url = $user_id ? self::normalize_url( get_user_meta( $user_id, self::USER_META_URL, true ) ) : '';
		if ( $url ) {
			return apply_filters( 'logiprof_um_connected_home_url', $url, $user_id );
		}

		$url = self::get_url_from_um_current_user( $user_id );
		if ( $url ) {
			return apply_filters( 'logiprof_um_connected_home_url', $url, $user_id );
		}

		$url = self::get_url_from_um_role_settings( $user_id );
		if ( $url ) {
			return apply_filters( 'logiprof_um_connected_home_url', $url, $user_id );
		}

		$url = self::normalize_url( get_option( self::OPTION_FALLBACK, '' ) );
		return apply_filters( 'logiprof_um_connected_home_url', $url, $user_id );
	}

	private static function get_url_from_um_current_user( $user_id ) {
		if ( ! $user_id ) {
			return '';
		}

		$url = self::normalize_url( get_user_meta( $user_id, 'login_redirect_url', true ) );
		if ( $url ) {
			return $url;
		}

		if ( ! function_exists( 'um_fetch_user' ) || ! function_exists( 'um_user' ) ) {
			return '';
		}

		um_fetch_user( $user_id );

		$after_login = um_user( 'after_login' );
		$url         = self::normalize_url( um_user( 'login_redirect_url' ) );

		if ( 'redirect_url' === $after_login && $url ) {
			return $url;
		}

		return '';
	}

	private static function get_url_from_um_role_settings( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return '';
		}

		foreach ( (array) $user->roles as $role ) {
			$role_settings = get_option( 'um_role_' . sanitize_key( $role ), array() );
			$url           = self::find_redirect_url_in_settings( $role_settings );

			if ( $url ) {
				return $url;
			}
		}

		return '';
	}

	private static function get_page_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( (string) $path, '/' );

		if ( '' === $path ) {
			return null;
		}

		$page = get_page_by_path( $path );
		if ( $page instanceof WP_Post && 'page' === $page->post_type ) {
			return $page;
		}

		$segments = explode( '/', $path );
		$page     = get_page_by_path( end( $segments ) );

		return $page instanceof WP_Post && 'page' === $page->post_type ? $page : null;
	}

	private static function find_redirect_url_in_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$preferred_keys = array(
			'login_redirect_url',
			'login_redirect',
			'after_login_redirect_url',
			'redirect_url',
			'url',
			'_um_login_redirect_url',
		);

		foreach ( $preferred_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$url = self::normalize_url( $settings[ $key ] );
				if ( $url ) {
					return $url;
				}
			}
		}

		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$url = self::find_redirect_url_in_settings( $value );
				if ( $url ) {
					return $url;
				}
				continue;
			}

			if ( false === stripos( (string) $key, 'redirect' ) || false === stripos( (string) $key, 'url' ) ) {
				continue;
			}

			$url = self::normalize_url( $value );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}

	private static function should_use_connected_home() {
		if ( ! is_user_logged_in() || is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		// Modification d'une page (Divi, éditeur WordPress, aperçu…) : on laisse WordPress afficher
		// exactement la page demandée, y compris l'accueil public.
		if ( self::is_editing_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * Vrai quand la page est ouverte pour être modifiée ou prévisualisée, et non simplement consultée.
	 * Seules les personnes qui peuvent modifier des contenus sont concernées : un élève qui ajoute
	 * ces paramètres à une adresse reste envoyé vers sa page d'accueil.
	 */
	private static function is_editing_request() {
		static $editing = null;
		if ( null !== $editing ) {
			return $editing;
		}

		$editing = false;
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			return $editing;
		}

		$keys = array(
			// Divi : constructeur visuel, constructeur dans l'administration, Theme Builder, aperçus.
			'et_fb', 'et_bfb', 'et_tb', 'et_pb_preview', 'et_pb_preview_nonce', 'et_post_type', 'et_block_layout_preview',
			// Lien « Voir l'accueil public » de la barre d'administration.
			'accueil_public',
			// WordPress : aperçu d'une page ou d'un brouillon, personnalisateur, éditeur du site.
			'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_theme', 'customize_messenger_channel', 'wp_site_preview', 'canvas', 'rest_route',
			// Autres constructeurs courants.
			'elementor-preview', 'fl_builder', 'ct_builder', 'vc_editable', 'vc_action', 'tve', 'brizy-edit', 'brizy-edit-iframe', 'bricks',
		);
		foreach ( $keys as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$editing = true;
				break;
			}
		}

		if ( ! $editing && function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			$editing = true;
		}

		/** Permet d'ajouter d'autres situations d'édition. */
		$editing = (bool) apply_filters( 'logiprof_um_connected_home_is_editing', $editing );

		return $editing;
	}

	private static function disable_page_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) {
			nocache_headers();
		}
	}

	private static function is_home_path( $path ) {
		return '' === (string) $path || '/' === (string) $path;
	}

	private static function is_public_home_request() {
		if ( is_front_page() ) {
			return true;
		}

		$current_path = self::get_current_request_path();
		$home_path    = wp_parse_url( self::raw_home_url( '/' ), PHP_URL_PATH );

		$current_path = '/' . trim( (string) $current_path, '/' );
		$home_path    = '/' . trim( (string) $home_path, '/' );

		return untrailingslashit( $current_path ) === untrailingslashit( $home_path );
	}

	private static function is_current_url( $target_url ) {
		$current_path = self::get_current_request_path();
		$target_path  = wp_parse_url( $target_url, PHP_URL_PATH );

		return untrailingslashit( (string) $current_path ) === untrailingslashit( (string) $target_path );
	}

	private static function get_current_request_path() {
		return isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
	}

	private static function get_public_home_url_variants() {
		$home_url = self::raw_home_url( '/' );
		$variants = array(
			$home_url,
			untrailingslashit( $home_url ),
		);

		if ( is_ssl() ) {
			$variants[] = set_url_scheme( $home_url, 'http' );
			$variants[] = untrailingslashit( set_url_scheme( $home_url, 'http' ) );
		} else {
			$variants[] = set_url_scheme( $home_url, 'https' );
			$variants[] = untrailingslashit( set_url_scheme( $home_url, 'https' ) );
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	private static function site_is_installed_at_root() {
		$home_path = wp_parse_url( self::raw_home_url( '/' ), PHP_URL_PATH );
		return '' === trim( (string) $home_path, '/' );
	}

	public static function normalize_url( $url ) { // publique : sert aussi de filtre de nettoyage du réglage (register_setting)
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = self::raw_home_url( $url );
		}

		$url = esc_url_raw( $url );
		return $url ? $url : '';
	}

	private static function raw_home_url( $path = '/' ) {
		self::$building_url = true;
		$url                = home_url( $path );
		self::$building_url = false;

		return $url;
	}

	private static function find_user_id_from_args( $args ) {
		foreach ( $args as $arg ) {
			if ( is_numeric( $arg ) && get_userdata( absint( $arg ) ) ) {
				return absint( $arg );
			}

			if ( $arg instanceof WP_User ) {
				return absint( $arg->ID );
			}
		}

		return get_current_user_id();
	}
}

Logiprof_UM_Connected_Home::init();

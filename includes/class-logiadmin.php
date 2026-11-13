<?php
/**
 * Cœur de LogiAdmin : chargement des outils, menu, tableau de bord, habillage LogiOne
 * et reprise des anciennes extensions sans perte de données.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogiAdmin {
	const PARENT_SLUG    = 'logiprof-tools';
	const OPTION_MODULES = 'logiadmin_disabled_modules';
	const OPTION_VERSION = 'logiadmin_version';
	const OPTION_MIGRATE = 'logiadmin_migration';
	const NONCE          = 'logiadmin_nonce';

	/** @var array Outils réellement chargés. */
	private static $loaded = array();

	/** @var array Outils non chargés car l'ancienne extension est encore active. */
	private static $conflicts = array();

	

	/** @var array|null */
	private static $iso = null;

	/** @var bool */
	private static $menu_registered = false;

	/** @var string */
	private static $dashboard_cap = 'manage_options';

	/**
	 * Liste des outils réunis dans LogiAdmin.
	 * « class » et « legacy » permettent de reconnaître l'ancienne extension correspondante.
	 */
	public static function modules( $translated = true ) {
		if ( ! $translated ) {
			return array_map(
				function ( $module ) {
					return array_diff_key( $module, array( 'title' => 1, 'menu' => 1, 'text' => 1 ) );
				},
				self::raw_modules()
			);
		}
		return self::raw_modules();
	}

	private static function raw_modules() {
		return array(
			'export-sensei'     => array(
				'title'   => self::t( 'Export Sensei' ),
				'menu'    => self::t( 'Export Sensei' ),
				'group'   => 'formation',
				'text'    => self::t( 'Exporte les cours Sensei en documents Word, images et quiz Excel, dans une archive ZIP.' ),
				'class'   => 'LogiSensei_Export_Pro',
				'legacy'  => 'logisensei-export-pro/logisensei-export-pro.php',
				'name'    => 'LogiSensei Export Pro',
				'page'    => 'logisensei-export-pro',
				'cap'     => 'edit_courses',
				'front'   => '',
			),
			'sensei-shortcodes' => array(
				'title'   => self::t( 'Shortcodes Sensei' ),
				'menu'    => self::t( 'Shortcodes Sensei' ),
				'group'   => 'formation',
				'text'    => self::t( 'Génère les listes de cours, leçons et quiz à afficher aux élèves.' ),
				'class'   => 'Logiprof_Sensei_Smart_Shortcodes',
				'legacy'  => 'logiprof-sensei-smart-shortcodes/logiprof-sensei-smart-shortcodes.php',
				'name'    => 'Sensei Shortcode',
				'page'    => 'logiprof-sensei-shortcodes',
				'cap'     => 'manage_options',
				'front'   => '[logiprof_sensei]',
			),
			'compteurs'         => array(
				'title'   => self::t( 'Compteurs' ),
				'menu'    => self::t( 'Compteurs' ),
				'group'   => 'formation',
				'text'    => self::t( 'Chiffres animés du site : comptes, cours, leçons et questions.' ),
				'class'   => 'Logiprof_Animated_Counters',
				'legacy'  => 'logiprof-animated-counters/logiprof-animated-counters.php',
				'name'    => 'Logiprof Animated Counters',
				'page'    => 'logiprof-animated-counters',
				'cap'     => 'manage_options',
				'front'   => '[logiprof_compteurs]',
			),
			'accueil-groupe'    => array(
				'title'   => self::t( 'Accueil selon groupe' ),
				'menu'    => self::t( 'Accueil selon groupe' ),
				'group'   => 'site',
				'text'    => self::t( 'Envoie chaque personne connectée vers la page d’accueil de son groupe Ultimate Member.' ),
				'class'   => 'Logiprof_UM_Connected_Home',
				'legacy'  => 'logiprof-um-connected-home/logiprof-um-connected-home.php',
				'name'    => 'Accueil selon groupe',
				'page'    => 'logiprof-um-connected-home',
				'cap'     => 'manage_options',
				'front'   => '',
			),
			'medias-inutilises' => array(
				'title'   => self::t( 'Médias inutilisés' ),
				'menu'    => self::t( 'Médias inutilisés' ),
				'group'   => 'site',
				'text'    => self::t( 'Repère les images et fichiers qui ne semblent utilisés nulle part. Rien n’est supprimé automatiquement.' ),
				'class'   => 'Logiprof_Unused_Media_Finder',
				'legacy'  => 'logiprof-unused-media-finder/logiprof-unused-media-finder.php',
				'name'    => 'Logiprof Unused Media Finder',
				'page'    => 'logiprof-unused-media-finder',
				'cap'     => 'upload_files',
				'front'   => '',
			),
			'menu-admin'        => array(
				'title'   => self::t( 'Menu admin' ),
				'menu'    => self::t( 'Menu admin' ),
				'group'   => 'site',
				'text'    => self::t( 'Choisit les éléments du menu WordPress à garder et range les autres dans « Menu admin ».' ),
				'class'   => 'Logiprof_Admin_Menu_Manager',
				'legacy'  => 'logiprof-admin-menu-manager/logiprof-admin-menu-manager.php',
				'name'    => 'Logiprof Admin Menu Manager',
				'page'    => 'logiprof-admin-menu-manager-settings',
				'cap'     => 'manage_options',
				'front'   => '',
			),
		);
	}

	/** Traduit seulement quand WordPress est prêt (après « init »). */
	private static function t( $text ) {
		return did_action( 'init' ) ? __( $text, 'logiadmin' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	public static function groups() {
		return array(
			'formation' => __( 'Formation', 'logiadmin' ),
			'site'      => __( 'Site', 'logiadmin' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Démarrage
	 * ------------------------------------------------------------------ */

	public static function boot() {
		$disabled = self::disabled_modules();

		// Pas de traduction à ce stade (trop tôt) : seules les données techniques sont lues.
		foreach ( self::modules( false ) as $key => $module ) {
			if ( in_array( $key, $disabled, true ) ) {
				continue;
			}

			

			// L'ancienne extension est encore active : on ne charge pas le doublon (évite toute erreur fatale).
			if ( class_exists( $module['class'], false ) ) {
				self::$conflicts[ $key ] = $module;
				continue;
			}

			require_once LOGIADMIN_DIR . 'modules/' . $key . '/module.php';
			self::$loaded[ $key ] = $module;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_parent_menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'order_submenu' ), 998 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_logiadmin_save_modules', array( __CLASS__, 'handle_save_modules' ) );
		add_action( 'admin_post_logiadmin_switch_legacy', array( __CLASS__, 'handle_switch_legacy' ) );
		add_action( 'admin_post_logiadmin_dismiss_migration', array( __CLASS__, 'handle_dismiss_migration' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOGIADMIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 20 );
		add_action( 'current_screen', array( __CLASS__, 'fill_page_title' ) );
	}

	/** Les onglets internes n'ont pas d'entrée de menu : WordPress ne trouve pas leur titre tout seul. */
	public static function fill_page_title() {
		global $title;
		$key = self::current_screen_key();
		if ( $key && empty( $title ) ) {
			$modules = self::modules();
			$title   = isset( $modules[ $key ] ) ? $modules[ $key ]['title'] : __( 'LogiAdmin', 'logiadmin' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public static function is_loaded( $key ) {
		return isset( self::$loaded[ $key ] );
	}

	private static function disabled_modules() {
		$value = get_option( self::OPTION_MODULES, array() );
		return is_array( $value ) ? array_values( array_intersect( $value, array_keys( self::modules( false ) ) ) ) : array();
	}

	/* ------------------------------------------------------------------
	 * Activation et reprise des anciennes extensions
	 * ------------------------------------------------------------------ */

	public static function activate() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$legacy = array();
		foreach ( self::modules() as $module ) {
			if ( is_plugin_active( $module['legacy'] ) ) {
				$legacy[] = $module['legacy'];
			}
		}

		// Désactivation silencieuse : aucune routine de désactivation ou de désinstallation n'est lancée,
		// les tables, réglages et métadonnées des anciennes extensions restent intacts.
		if ( $legacy ) {
			deactivate_plugins( $legacy, true );
		}

		$previous = get_option( self::OPTION_MIGRATE, array() );
		update_option(
			self::OPTION_MIGRATE,
			array(
				'date'        => current_time( 'mysql' ),
				'deactivated' => array_values( array_unique( array_merge( $legacy, isset( $previous['deactivated'] ) ? (array) $previous['deactivated'] : array() ) ) ),
				'dismissed'   => false,
			),
			false
		);

		// Les tables et migrations des outils sont (re)vérifiées au prochain chargement de l'administration.
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Crée ou complète les tables des outils. dbDelta n'efface jamais de colonne ni de ligne.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION ) === LOGIADMIN_VERSION ) {
			return;
		}

		

		// Le repérage des médias inutilisés a été corrigé : l'ancien rapport en cache est recalculé.
		delete_transient( 'logiprof_umf_report' );

		update_option( self::OPTION_VERSION, LOGIADMIN_VERSION, false );
	}

	public static function handle_switch_legacy() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'logiadmin' ) );
		}
		check_admin_referer( 'logiadmin_switch_legacy' );
		self::activate();
		wp_safe_redirect( self::dashboard_url( array( 'logiadmin_notice' => 'switched' ) ) );
		exit;
	}

	public static function handle_dismiss_migration() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'logiadmin' ) );
		}
		check_admin_referer( 'logiadmin_dismiss_migration' );
		$migration              = (array) get_option( self::OPTION_MIGRATE, array() );
		$migration['dismissed'] = true;
		update_option( self::OPTION_MIGRATE, $migration, false );
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	public static function handle_save_modules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'logiadmin' ) );
		}
		check_admin_referer( 'logiadmin_save_modules' );

		$enabled  = isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array();
		$disabled = array_values( array_diff( array_keys( self::modules() ), $enabled ) );
		update_option( self::OPTION_MODULES, $disabled, false );

		wp_safe_redirect( self::dashboard_url( array( 'logiadmin_notice' => 'modules' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------ */

	public static function register_parent_menu() {
		if ( self::$menu_registered ) {
			return;
		}
		self::$menu_registered = true;

		$icon = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( LOGIADMIN_DIR . 'assets/img/menu-icon.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Chaque outil garde ses propres droits. Le tableau de bord n'apparaît qu'aux personnes
		// qui peuvent ouvrir au moins un outil (les élèves ne le voient donc pas).
		$cap = 'manage_options';
		foreach ( self::$loaded as $module ) {
			if ( current_user_can( $module['cap'] ) ) {
				$cap = 'read';
				break;
			}
		}
		self::$dashboard_cap = $cap;

		add_menu_page(
			__( 'LogiAdmin', 'logiadmin' ),
			__( 'LogiAdmin', 'logiadmin' ),
			$cap,
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			$icon,
			3
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Tableau de bord LogiAdmin', 'logiadmin' ),
			__( 'Tableau de bord', 'logiadmin' ),
			$cap,
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);
	}

	/** Range les sous-menus dans l'ordre du tableau de bord. */
	public static function order_submenu() {
		global $submenu;

		// Onglets internes enregistrés sans parent : WordPress les rangerait sous un parent vide et
		// oublierait alors d'ouvrir le menu LogiAdmin. Leurs pages vérifient elles-mêmes les droits.
		if ( ! empty( $submenu[''] ) ) {
			foreach ( $submenu[''] as $index => $item ) {
				foreach ( self::modules( false ) as $module ) {
					if ( isset( $item[2] ) && 0 === strpos( $item[2], $module['page'] . '-' ) ) {
						unset( $submenu[''][ $index ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					}
				}
			}
			if ( empty( $submenu[''] ) ) {
				unset( $submenu[''] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		if ( empty( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		$order = array( self::PARENT_SLUG => 0 );
		$i     = 1;
		foreach ( self::modules() as $module ) {
			$order[ $module['page'] ] = $i++;
		}

		usort(
			$submenu[ self::PARENT_SLUG ],
			function ( $a, $b ) use ( $order ) {
				$wa = isset( $order[ $a[2] ] ) ? $order[ $a[2] ] : 100;
				$wb = isset( $order[ $b[2] ] ) ? $order[ $b[2] ] : 100;
				return $wa <=> $wb;
			}
		);
	}

	public static function dashboard_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PARENT_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function module_url( $key, $args = array() ) {
		$modules = self::modules();
		if ( empty( $modules[ $key ] ) ) {
			return self::dashboard_url();
		}
		return add_query_arg( array_merge( array( 'page' => $modules[ $key ]['page'] ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::dashboard_url() ) . '">' . esc_html__( 'Tableau de bord', 'logiadmin' ) . '</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------
	 * Écrans LogiAdmin : détection, styles, scripts
	 * ------------------------------------------------------------------ */

	/** Clé de l'outil affiché, « dashboard » pour l'accueil, ou chaîne vide hors LogiAdmin. */
	public static function current_screen_key() {
		if ( ! is_admin() || empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PARENT_SLUG === $page ) {
			return 'dashboard';
		}

		foreach ( self::modules() as $key => $module ) {
			if ( ! self::is_loaded( $key ) ) {
				continue;
			}
			if ( $page === $module['page'] || 0 === strpos( $page, $module['page'] . '-' ) ) {
				return $key;
			}
		}

		// Page d'accueil du menu « Menu admin » (fonction de l'outil Menu admin).
		if ( 'logiprof-admin-menu-manager' === $page ) {
			return 'menu-admin';
		}

		return '';
	}

	public static function body_class( $classes ) {
		$key = self::current_screen_key();
		if ( $key ) {
			$classes .= ' logiadmin-screen logiadmin-screen--' . sanitize_html_class( $key );
		}
		return $classes;
	}

	public static function enqueue_assets() {
		$key = self::current_screen_key();
		if ( ! $key ) {
			return;
		}

		wp_enqueue_style( 'logiadmin', LOGIADMIN_URL . 'assets/css/logiadmin.css', array(), LOGIADMIN_VERSION );
		wp_enqueue_script( 'logiadmin', LOGIADMIN_URL . 'assets/js/logiadmin.js', array(), LOGIADMIN_VERSION, true );
		wp_localize_script(
			'logiadmin',
			'LogiAdminUI',
			array(
				'copied'  => __( 'Copié dans le presse-papiers', 'logiadmin' ),
				'copyErr' => __( 'Copie impossible : sélectionnez le texte puis Ctrl+C.', 'logiadmin' ),
			)
		);
	}

	public static function footer_text( $text ) {
		if ( self::current_screen_key() ) {
			return '<span class="lad-footer">LogiAdmin ' . esc_html( LOGIADMIN_VERSION ) . ' · LogiProf</span>';
		}
		return $text;
	}

	/* ------------------------------------------------------------------
	 * Habillage commun des pages
	 * ------------------------------------------------------------------ */

	public static function iso( $key ) {
		if ( null === self::$iso ) {
			self::$iso = (array) include LOGIADMIN_DIR . 'includes/iso.php';
		}
		return isset( self::$iso[ $key ] ) ? self::$iso[ $key ] : '';
	}

	public static function logo( $on_dark = true ) {
		$file = $on_dark ? 'logiadmin-logo-blanc.svg' : 'logiadmin-logo.svg';
		return '<img class="lad-logo" src="' . esc_url( LOGIADMIN_URL . 'assets/img/' . $file ) . '" alt="LogiAdmin by LogiProf" width="250" height="80" decoding="async">';
	}

	/**
	 * Ouvre une page LogiAdmin : bandeau anthracite, titre, onglets éventuels.
	 *
	 * @param string $key  Clé de l'outil.
	 * @param array  $args title, intro, class, tabs (url => libellé), active (url), actions (HTML déjà échappé).
	 */
	public static function page_open( $key, $args = array() ) {
		$modules = self::modules();
		$module  = isset( $modules[ $key ] ) ? $modules[ $key ] : null;
		$groups  = self::groups();
		$args    = wp_parse_args(
			$args,
			array(
				'title'   => $module ? $module['title'] : __( 'LogiAdmin', 'logiadmin' ),
				'intro'   => $module ? $module['text'] : '',
				'class'   => '',
				'tabs'    => array(),
				'active'  => '',
				'actions' => '',
				'art'     => $key,
			)
		);
		?>
		<div class="wrap logiadmin-wrap <?php echo esc_attr( $args['class'] ); ?>">
			<header class="lad-hero">
				<div class="lad-hero-top">
					<a class="lad-brand" href="<?php echo esc_url( self::dashboard_url() ); ?>"><?php echo self::logo( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<?php if ( $module ) : ?>
						<nav class="lad-crumbs" aria-label="<?php esc_attr_e( 'Fil d’Ariane', 'logiadmin' ); ?>">
							<a href="<?php echo esc_url( self::dashboard_url() ); ?>"><?php esc_html_e( 'Tableau de bord', 'logiadmin' ); ?></a>
							<span aria-hidden="true">/</span>
							<span><?php echo esc_html( $groups[ $module['group'] ] ); ?></span>
							<span aria-hidden="true">/</span>
							<span aria-current="page"><?php echo esc_html( $module['title'] ); ?></span>
						</nav>
					<?php endif; ?>
				</div>
				<div class="lad-hero-main">
					<div class="lad-hero-text">
						<h1 class="lad-title"><?php echo esc_html( $args['title'] ); ?></h1>
						<?php if ( $args['intro'] ) : ?>
							<p class="lad-intro"><?php echo esc_html( $args['intro'] ); ?></p>
						<?php endif; ?>
						<?php if ( $args['actions'] ) : ?>
							<div class="lad-hero-actions"><?php echo $args['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
					</div>
					<?php if ( $args['art'] && self::iso( $args['art'] ) ) : ?>
						<div class="lad-hero-art"><?php echo self::iso( $args['art'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $args['tabs'] ) : ?>
					<nav class="lad-tabs" aria-label="<?php esc_attr_e( 'Sections', 'logiadmin' ); ?>">
						<?php foreach ( $args['tabs'] as $url => $label ) : ?>
							<a class="lad-tab<?php echo $url === $args['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $url === $args['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
			</header>
			<hr class="wp-header-end">
			<div class="lad-body">
		<?php
	}

	public static function page_close() {
		echo '</div></div>';
	}

	/** Champ de shortcode en lecture seule avec bouton « Copier ». */
	public static function copy_field( $value, $label = '' ) {
		$id = 'lad-copy-' . wp_rand( 1000, 999999 );
		$out  = '<span class="lad-copy">';
		$out .= '<input type="text" id="' . esc_attr( $id ) . '" class="lad-copy-input code" readonly value="' . esc_attr( $value ) . '"' . ( $label ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . '>';
		$out .= '<button type="button" class="button lad-copy-button" data-logiadmin-copy="#' . esc_attr( $id ) . '">' . esc_html__( 'Copier', 'logiadmin' ) . '</button>';
		$out .= '</span>';
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Notifications
	 * ------------------------------------------------------------------ */

	public static function notices() {
		$key = self::current_screen_key();
		$can = current_user_can( 'activate_plugins' );

		// Ancienne extension encore active : l'outil LogiAdmin correspondant est en pause.
		if ( self::$conflicts && $can ) {
			$names = wp_list_pluck( self::$conflicts, 'name' );
			$url   = wp_nonce_url( admin_url( 'admin-post.php?action=logiadmin_switch_legacy' ), 'logiadmin_switch_legacy' );
			echo '<div class="notice notice-warning logiadmin-notice"><p><strong>' . esc_html__( 'LogiAdmin :', 'logiadmin' ) . '</strong> ';
			echo esc_html( sprintf( /* translators: %s: liste d'extensions */ __( 'ces anciennes extensions sont encore actives : %s. LogiAdmin laisse ces outils de côté tant qu’elles le sont, pour éviter les doublons.', 'logiadmin' ), implode( ', ', $names ) ) );
			echo '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Désactiver les anciennes extensions et passer à LogiAdmin', 'logiadmin' ) . '</a> ';
			echo '<span class="description">' . esc_html__( 'Les données ne sont ni effacées ni modifiées.', 'logiadmin' ) . '</span></p></div>';
		}

		if ( 'dashboard' !== $key ) {
			return;
		}

		$notice = isset( $_GET['logiadmin_notice'] ) ? sanitize_key( wp_unslash( $_GET['logiadmin_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'modules' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Choix des outils enregistré.', 'logiadmin' ) . '</p></div>';
		} elseif ( 'checked' === $notice ) {
			$message = LogiAdmin_Updater::has_update()
				? __( 'Une nouvelle version de LogiAdmin est disponible : voir « Mises à jour » plus bas.', 'logiadmin' )
				: __( 'Vérification faite : LogiAdmin est à jour.', 'logiadmin' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( 'switched' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Les anciennes extensions sont désactivées. Tous les outils tournent maintenant dans LogiAdmin, avec leurs données.', 'logiadmin' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------
	 * Tableau de bord
	 * ------------------------------------------------------------------ */

	public static function render_dashboard() {
		if ( ! current_user_can( self::$dashboard_cap ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'logiadmin' ) );
		}

		$user     = wp_get_current_user();
		$first    = $user->first_name ? $user->first_name : $user->display_name;
		$disabled = self::disabled_modules();
		$migrate  = (array) get_option( self::OPTION_MIGRATE, array() );

		self::page_open(
			'dashboard',
			array(
				/* translators: %s: prénom */
				'title' => sprintf( __( 'Bonjour %s', 'logiadmin' ), $first ),
				'intro' => __( 'Tous les outils d’administration LogiProf, au même endroit. Choisissez un outil pour l’ouvrir.', 'logiadmin' ),
				'art'   => 'admin',
				'class' => 'lad-dashboard',
			)
		);

		if ( ! empty( $migrate['deactivated'] ) && empty( $migrate['dismissed'] ) && current_user_can( 'activate_plugins' ) ) {
			self::render_migration_report( $migrate );
		}

		foreach ( self::groups() as $group => $label ) {
			$tiles = array_filter(
				self::modules(),
				function ( $module ) use ( $group ) {
					return $module['group'] === $group;
				}
			);
			echo '<section class="lad-section"><h2 class="lad-section-title">' . esc_html( $label ) . '</h2><div class="lad-tiles">';
			foreach ( $tiles as $key => $module ) {
				self::render_tile( $key, $module, in_array( $key, $disabled, true ) );
			}
			echo '</div></section>';
		}

		LogiAdmin_Updater::render_card();

		if ( current_user_can( 'manage_options' ) ) {
			self::render_modules_form( $disabled );
		}

		self::page_close();
	}

	private static function render_tile( $key, $module, $is_disabled ) {
		$can      = current_user_can( $module['cap'] ) || current_user_can( 'manage_options' );
		$loaded   = self::is_loaded( $key );
		$conflict = isset( self::$conflicts[ $key ] );
		$facts    = $loaded ? self::facts( $key ) : array();
		$tag      = $loaded && $can ? 'a' : 'div';
		$href     = $loaded && $can ? ' href="' . esc_url( self::module_url( $key ) ) . '"' : '';
		$classes  = 'lad-tile lad-tile--' . $key . ( $loaded ? '' : ' is-off' );
		?>
		<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="<?php echo esc_attr( $classes ); ?>"<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<span class="lad-tile-art"><?php echo self::iso( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="lad-tile-body">
				<strong class="lad-tile-title"><?php echo esc_html( $module['title'] ); ?></strong>
				<span class="lad-tile-text"><?php echo esc_html( $module['text'] ); ?></span>
				
				<?php if ( $facts ) : ?>
					<span class="lad-facts">
						<?php foreach ( $facts as $fact ) : ?>
							<span class="lad-fact<?php echo ! empty( $fact[2] ) ? ' is-' . esc_attr( $fact[2] ) : ''; ?>"><b><?php echo esc_html( $fact[0] ); ?></b> <?php echo esc_html( $fact[1] ); ?></span>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</span>
			<span class="lad-tile-cta">
				<?php
				if ( $conflict ) {
					esc_html_e( 'En pause : ancienne extension active', 'logiadmin' );
				} elseif ( $is_disabled ) {
					esc_html_e( 'Outil désactivé', 'logiadmin' );
				} elseif ( ! $can ) {
					esc_html_e( 'Réservé aux administrateurs', 'logiadmin' );
				} else {
					esc_html_e( 'Ouvrir', 'logiadmin' );
					echo ' <span aria-hidden="true">→</span>';
				}
				?>
			</span>
		</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php
	}

	/**
	 * Quelques chiffres clés par outil, lus directement dans les données existantes.
	 *
	 * @return array Liste de [valeur, libellé, ton éventuel].
	 */
	private static function facts( $key ) {
		global $wpdb;
		$facts = array();

		switch ( $key ) {
			case 'export-sensei':
				$history = get_option( 'logisensei_export_history', array() );
				$history = is_array( $history ) ? $history : array();
				$facts[] = array( number_format_i18n( count( $history ) ), _n( 'export conservé', 'exports conservés', count( $history ), 'logiadmin' ) );
				if ( ! empty( $history[0]['date'] ) ) {
					$facts[] = array( mysql2date( 'j M Y', $history[0]['date'] ), __( 'dernier export', 'logiadmin' ) );
				}
				break;

			case 'sensei-shortcodes':
				$courses = (int) wp_count_posts( 'course' )->publish;
				$profils = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_logiprof_allowed_profile_roles' ) );
				$facts[] = array( number_format_i18n( $courses ), _n( 'cours publié', 'cours publiés', $courses, 'logiadmin' ) );
				$facts[] = array( number_format_i18n( $profils ), _n( 'cours avec profils', 'cours avec profils', $profils, 'logiadmin' ) );
				break;

			case 'compteurs':
				$users   = count_users();
				$lessons = (int) wp_count_posts( 'lesson' )->publish;
				$total   = (int) $users['total_users'];
				$facts[] = array( number_format_i18n( $total ), _n( 'compte', 'comptes', $total, 'logiadmin' ) );
				$facts[] = array( number_format_i18n( (int) wp_count_posts( 'course' )->publish ), __( 'cours', 'logiadmin' ) );
				$facts[] = array( number_format_i18n( $lessons ), _n( 'leçon', 'leçons', $lessons, 'logiadmin' ) );
				break;

			case 'accueil-groupe':
				$remembered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", '_logiprof_um_connected_home_url' ) );
				$facts[]    = array( number_format_i18n( $remembered ), _n( 'personne reconnue', 'personnes reconnues', $remembered, 'logiadmin' ) );
				$facts[]    = get_option( 'logiprof_um_connected_home_url' ) ? array( '✓', __( 'URL de secours définie', 'logiadmin' ), 'ok' ) : array( '—', __( 'pas d’URL de secours', 'logiadmin' ) );
				break;

			case 'medias-inutilises':
				$report = get_transient( 'logiprof_umf_report' );
				if ( is_array( $report ) ) {
					$unused  = count( (array) $report['unused'] );
					$facts[] = array( number_format_i18n( $unused ), _n( 'fichier à vérifier', 'fichiers à vérifier', $unused, 'logiadmin' ), $unused ? 'warn' : 'ok' );
					$facts[] = array( mysql2date( 'j M, H:i', $report['generated_at'] ), __( 'dernière analyse', 'logiadmin' ) );
				} else {
					$facts[] = array( '—', __( 'analyse lancée à l’ouverture', 'logiadmin' ) );
				}
				break;

			case 'menu-admin':
				$hidden  = get_option( 'logiprof_amm_hidden_slugs', array() );
				$count   = is_array( $hidden ) ? count( $hidden ) : 0;
				$facts[] = array( number_format_i18n( $count ), _n( 'élément rangé dans Menu admin', 'éléments rangés dans Menu admin', $count, 'logiadmin' ) );
				break;
		}

		return $facts;
	}

	private static function render_migration_report( $migrate ) {
		$names = array();
		foreach ( self::modules() as $module ) {
			if ( in_array( $module['legacy'], (array) $migrate['deactivated'], true ) ) {
				$names[] = $module['name'];
			}
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=logiadmin_dismiss_migration' ), 'logiadmin_dismiss_migration' );
		?>
		<section class="lad-card lad-migration">
			<div>
				<h2><?php esc_html_e( 'Reprise terminée', 'logiadmin' ); ?></h2>
				<p><?php esc_html_e( 'LogiAdmin a repris le travail des anciennes extensions. Historique des exports, réglages, profils des cours et choix de menu sont conservés tels quels.', 'logiadmin' ); ?></p>
				<p><strong><?php esc_html_e( 'Anciennes extensions désactivées :', 'logiadmin' ); ?></strong> <?php echo esc_html( implode( ', ', $names ) ); ?>.</p>
				<p class="description"><?php esc_html_e( 'Vous pouvez les supprimer depuis la page Extensions : aucune d’elles n’efface de données à la suppression. Les shortcodes déjà placés dans vos pages continuent de fonctionner.', 'logiadmin' ); ?></p>
			</div>
			<p class="lad-migration-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ); ?>"><?php esc_html_e( 'Voir les extensions', 'logiadmin' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'C’est noté', 'logiadmin' ); ?></a>
			</p>
		</section>
		<?php
	}

	private static function render_modules_form( $disabled ) {
		?>
		<details class="lad-card lad-modules-form">
			<summary><?php esc_html_e( 'Choisir les outils actifs', 'logiadmin' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="logiadmin_save_modules">
				<?php wp_nonce_field( 'logiadmin_save_modules' ); ?>
				<p class="description"><?php esc_html_e( 'Un outil désactivé disparaît du menu et ses shortcodes ne s’affichent plus. Ses données restent enregistrées : il suffit de le réactiver pour les retrouver.', 'logiadmin' ); ?></p>
				<div class="lad-switches">
					<?php foreach ( self::modules() as $key => $module ) : ?>
						<label class="lad-switch">
							<input type="checkbox" name="modules[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! in_array( $key, $disabled, true ) ); ?>>
							<span class="lad-switch-ui" aria-hidden="true"></span>
							<span><strong><?php echo esc_html( $module['title'] ); ?></strong><?php echo $module['front'] ? ' <code>' . esc_html( $module['front'] ) . '</code>' : ''; ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer', 'logiadmin' ); ?></button></p>
			</form>
		</details>
		<?php
	}
}

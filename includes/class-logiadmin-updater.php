<?php
/**
 * Mises à jour de LogiAdmin, sur le modèle de LogiAi.
 *
 * Le code source reste privé. Les versions publiées sont rangées dans le dépôt public
 * « logiadmin-releases » : latest.json (version, notes, adresse du paquet, empreinte, signature)
 * et versions/X.Y.Z/logiadmin.zip. WordPress propose la mise à jour comme pour toute extension,
 * mais LogiAdmin refuse d'installer un paquet qui ne porte pas la signature LogiProf.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogiAdmin_Updater {
	/** Fiche de la dernière version (remplaçable par la constante LOGIADMIN_UPDATE_URL). */
	const DEFAULT_URL = 'https://raw.githubusercontent.com/logiprof/logiadmin-releases/main/latest.json';

	/** Clé publique Ed25519 de LogiProf : seuls les paquets signés avec la clé privée correspondante sont installés. */
	const PUBLIC_KEY = 'mIOPZld3oHl1RivpoanuDzT3dFb9T1GaqY1+giD7wfI=';

	const CACHE     = 'logiadmin_update_info';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const SLUG      = 'logiadmin';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_and_verify' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'keep_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		add_action( 'admin_post_logiadmin_check_updates', array( __CLASS__, 'handle_check_now' ) );
	}

	public static function basename() {
		return plugin_basename( LOGIADMIN_FILE );
	}

	public static function feed_url() {
		$url = defined( 'LOGIADMIN_UPDATE_URL' ) ? LOGIADMIN_UPDATE_URL : self::DEFAULT_URL;
		return (string) apply_filters( 'logiadmin_update_url', $url );
	}

	/**
	 * Lit latest.json (mis en cache 12 h ; 1 h en cas d'échec pour ne pas insister).
	 *
	 * @return array{ok:bool,checked:int,error?:string,version?:string,notes?:string,pub_date?:string,package?:array,requires?:string,requires_php?:string,tested?:string}
	 */
	public static function info( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$info     = array( 'ok' => false, 'checked' => time() );
		$response = wp_remote_get(
			add_query_arg( 't', (string) time(), self::feed_url() ), // évite un cache intermédiaire périmé
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$info['error'] = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$info['error'] = sprintf( 'HTTP %d', (int) wp_remote_retrieve_response_code( $response ) );
		} else {
			$parsed = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
			if ( is_wp_error( $parsed ) ) {
				$info['error'] = $parsed->get_error_message();
			} else {
				$info = array_merge( $parsed, array( 'ok' => true, 'checked' => time() ) );
			}
		}

		set_site_transient( self::CACHE, $info, $info['ok'] ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $info;
	}

	/** Contrôle la forme de latest.json et refuse un paquet hébergé ailleurs que dans le dépôt des versions. */
	private static function parse( $data ) {
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package']['url'] ) ) {
			return new WP_Error( 'logiadmin_feed', __( 'Fiche de version illisible.', 'logiadmin' ) );
		}

		$version = (string) $data['version'];
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return new WP_Error( 'logiadmin_feed', __( 'Numéro de version invalide.', 'logiadmin' ) );
		}

		$url  = (string) $data['package']['url'];
		$base = trailingslashit( dirname( strtok( self::feed_url(), '?' ) ) );
		if ( 0 !== strpos( $url, $base ) ) {
			return new WP_Error( 'logiadmin_feed', __( 'Le paquet n’est pas dans le dépôt des versions LogiAdmin.', 'logiadmin' ) );
		}

		$sha = isset( $data['package']['sha256'] ) ? strtolower( (string) $data['package']['sha256'] ) : '';
		$sig = isset( $data['package']['signature'] ) ? (string) $data['package']['signature'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha ) || '' === $sig ) {
			return new WP_Error( 'logiadmin_feed', __( 'Paquet sans empreinte ni signature.', 'logiadmin' ) );
		}

		return array(
			'version'      => $version,
			'notes'        => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'pub_date'     => isset( $data['pub_date'] ) ? (string) $data['pub_date'] : '',
			'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '6.0',
			'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '7.4',
			'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'package'      => array(
				'url'       => esc_url_raw( $url ),
				'sha256'    => $sha,
				'signature' => $sig,
			),
		);
	}

	public static function has_update( $info = null ) {
		$info = $info ? $info : self::info();
		return ! empty( $info['ok'] ) && version_compare( $info['version'], LOGIADMIN_VERSION, '>' );
	}

	/* ------------------------------------------------------------------
	 * Intégration au système de mises à jour de WordPress
	 * ------------------------------------------------------------------ */

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = self::info();
		$item = (object) array(
			'id'          => 'logiprof/logiadmin',
			'slug'        => self::SLUG,
			'plugin'      => self::basename(),
			'new_version' => ! empty( $info['ok'] ) ? $info['version'] : LOGIADMIN_VERSION,
			'url'         => 'https://github.com/logiprof/logiadmin-releases',
			'package'     => ! empty( $info['ok'] ) ? $info['package']['url'] : '',
			'icons'       => array( 'svg' => LOGIADMIN_URL . 'assets/img/menu-icon.svg' ),
		);

		if ( self::has_update( $info ) ) {
			$item->requires     = $info['requires'];
			$item->requires_php = $info['requires_php'];
			$item->tested       = $info['tested'];
			$transient->response[ self::basename() ] = $item;
		} else {
			// Déclarer l'extension « à jour » active aussi le réglage des mises à jour automatiques.
			$item->package                              = '';
			$transient->no_update[ self::basename() ] = $item;
			unset( $transient->response[ self::basename() ] );
		}

		return $transient;
	}

	/** Fenêtre « Voir les détails de la version ». */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$info = self::info();
		$v    = ! empty( $info['ok'] ) ? $info['version'] : LOGIADMIN_VERSION;

		return (object) array(
			'name'          => 'LogiAdmin',
			'slug'          => self::SLUG,
			'version'       => $v,
			'author'        => 'LogiProf',
			'homepage'      => 'https://github.com/logiprof/logiadmin-releases',
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '6.0',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '7.4',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'last_updated'  => isset( $info['pub_date'] ) ? $info['pub_date'] : '',
			'download_link' => ! empty( $info['ok'] ) ? $info['package']['url'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Les outils d’administration LogiProf réunis dans une seule extension.', 'logiadmin' ) . '</p>',
				'changelog'   => ! empty( $info['notes'] ) ? '<h4>' . esc_html( $v ) . '</h4>' . wpautop( esc_html( $info['notes'] ) ) : '',
			),
		);
	}

	/**
	 * Télécharge le paquet et vérifie son empreinte et sa signature avant de laisser WordPress l'installer.
	 * Un paquet modifié ou non signé par LogiProf est refusé.
	 */
	public static function download_and_verify( $reply, $package, $upgrader, $hook_extra = array() ) {
		$info = self::info();
		if ( empty( $info['ok'] ) || $package !== $info['package']['url'] ) {
			return $reply; // Pas un paquet LogiAdmin : WordPress continue normalement.
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( isset( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( __( 'Téléchargement de LogiAdmin et vérification de la signature LogiProf…', 'logiadmin' ) );
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$check = self::verify_file( $file, $info['package']['sha256'], $info['package']['signature'] );
		if ( is_wp_error( $check ) ) {
			wp_delete_file( $file );
			return $check;
		}

		return $file;
	}

	public static function verify_file( $file, $sha256, $signature ) {
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents ) {
			return new WP_Error( 'logiadmin_package', __( 'Paquet LogiAdmin illisible.', 'logiadmin' ) );
		}

		if ( ! hash_equals( $sha256, hash( 'sha256', $contents ) ) ) {
			return new WP_Error( 'logiadmin_package', __( 'Mise à jour refusée : le paquet téléchargé ne correspond pas à la version publiée.', 'logiadmin' ) );
		}

		$sig = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$key = base64_decode( self::PUBLIC_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$ok  = false;
		if ( $sig && $key && function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			try {
				$ok = sodium_crypto_sign_verify_detached( $sig, $contents, $key );
			} catch ( Exception $e ) {
				$ok = false;
			}
		}

		if ( ! $ok ) {
			return new WP_Error( 'logiadmin_package', __( 'Mise à jour refusée : le paquet ne porte pas la signature LogiProf.', 'logiadmin' ) );
		}

		return true;
	}

	/** Garde le dossier « logiadmin », quel que soit le nom du dossier dans l'archive. */
	public static function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || is_wp_error( $source ) ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || ! $wp_filesystem ) {
			return $source;
		}

		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	public static function after_upgrade( $upgrader, $options ) {
		if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( self::basename(), (array) $options['plugins'], true ) ) {
			delete_site_transient( self::CACHE );
		}
	}

	/* ------------------------------------------------------------------
	 * Bouton « Vérifier maintenant » du tableau de bord
	 * ------------------------------------------------------------------ */

	public static function handle_check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'logiadmin' ) );
		}
		check_admin_referer( 'logiadmin_check_updates' );

		self::info( true );
		delete_site_transient( 'update_plugins' ); // WordPress refait sa liste avec la nouvelle information.
		wp_update_plugins();

		wp_safe_redirect( LogiAdmin::dashboard_url( array( 'logiadmin_notice' => 'checked' ) ) . '#lad-updates' );
		exit;
	}

	public static function update_url() {
		$basename = self::basename();
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ), 'upgrade-plugin_' . $basename );
	}

	/** Carte « Mises à jour » du tableau de bord LogiAdmin. */
	public static function render_card() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$info  = self::info();
		$newer = self::has_update( $info );
		$check = wp_nonce_url( admin_url( 'admin-post.php?action=logiadmin_check_updates' ), 'logiadmin_check_updates' );
		?>
		<section class="lad-card lad-updates" id="lad-updates">
			<div class="lad-updates-text">
				<h2><?php esc_html_e( 'Mises à jour', 'logiadmin' ); ?></h2>
				<p>
					<?php
					/* translators: %s: numéro de version */
					echo esc_html( sprintf( __( 'Version installée : %s', 'logiadmin' ), LOGIADMIN_VERSION ) );
					?>
					<?php if ( ! empty( $info['ok'] ) ) : ?>
						· <?php /* translators: %s: numéro de version */ echo esc_html( sprintf( __( 'dernière version publiée : %s', 'logiadmin' ), $info['version'] ) ); ?>
					<?php endif; ?>
				</p>
				<?php if ( $newer ) : ?>
					<p><span class="lad-pill is-new"><?php esc_html_e( 'Nouvelle version disponible', 'logiadmin' ); ?></span></p>
					<?php if ( $info['notes'] ) : ?>
						<div class="lad-update-notes"><?php echo wpautop( esc_html( $info['notes'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				<?php elseif ( ! empty( $info['ok'] ) ) : ?>
					<p><span class="lad-pill"><?php esc_html_e( 'LogiAdmin est à jour', 'logiadmin' ); ?></span></p>
				<?php else : ?>
					<p class="lad-hint">
						<?php
						/* translators: %s: message d'erreur */
						echo esc_html( sprintf( __( 'Impossible de lire la dernière version pour le moment (%s). Nouvel essai automatique dans une heure.', 'logiadmin' ), isset( $info['error'] ) ? $info['error'] : '?' ) );
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					/* translators: %s: date et heure */
					echo esc_html( sprintf( __( 'Dernière vérification : %s. Chaque paquet est signé : LogiAdmin refuse tout paquet qui ne porte pas la signature LogiProf.', 'logiadmin' ), wp_date( 'j M Y, H:i', (int) $info['checked'] ) ) );
					?>
				</p>
			</div>
			<p class="lad-updates-actions">
				<a class="button" href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Vérifier maintenant', 'logiadmin' ); ?></a>
				<?php if ( $newer ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::update_url() ); ?>">
						<?php /* translators: %s: numéro de version */ echo esc_html( sprintf( __( 'Installer la version %s', 'logiadmin' ), $info['version'] ) ); ?>
					</a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}
}

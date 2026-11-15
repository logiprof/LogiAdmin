<?php
/**
 * Module LogiAdmin : Leçons pleine largeur
 * Reprise de l'extension « Sensei – Leçons pleine largeur » 1.2.0, intégrée à LogiAdmin.
 *
 * Le contenu des leçons (et des quiz) Sensei occupe toute la largeur disponible, avec une marge
 * réglable à gauche et à droite. Le réglage reste enregistré dans l'option « lpl_reglages »,
 * comme dans l'extension d'origine.
 */

defined( 'ABSPATH' ) || exit;

final class LPL_Sensei_Pleine_Largeur {
	const OPTION  = 'lpl_reglages';
	const VERSION = '1.2.0';
	const SLUG    = 'lpl-reglages';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 999 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_lpl_enregistrer', array( __CLASS__, 'enregistrer' ) );
		add_action( 'wp_footer', array( __CLASS__, 'panneau_lecon' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'barre_admin' ), 100 );
	}

	/* ------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------ */

	public static function reglages() {
		$r = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'marge'        => 40,
				'marge_mobile' => 16,
				'quiz'         => 1,
			)
		);
		$r['marge']        = max( 0, min( 300, (int) $r['marge'] ) );
		$r['marge_mobile'] = max( 0, min( 100, (int) $r['marge_mobile'] ) );
		$r['quiz']         = empty( $r['quiz'] ) ? 0 : 1;
		return $r;
	}

	/** Nettoie et enregistre les réglages. */
	private static function sauver( $in ) {
		$in = (array) $in;
		update_option(
			self::OPTION,
			array(
				'marge'        => isset( $in['marge'] ) ? max( 0, min( 300, (int) $in['marge'] ) ) : 40,
				'marge_mobile' => isset( $in['marge_mobile'] ) ? max( 0, min( 100, (int) $in['marge_mobile'] ) ) : 16,
				'quiz'         => empty( $in['quiz'] ) ? 0 : 1,
			)
		);
	}

	/** Formulaire envoyé depuis la page LogiAdmin ou depuis la leçon. */
	public static function enregistrer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'sensei-lecons-pleine-largeur' ) );
		}
		check_admin_referer( 'lpl_enregistrer' );
		$in = isset( $_POST[ self::OPTION ] ) ? wp_unslash( (array) $_POST[ self::OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nettoyé dans sauver().
		self::sauver( $in );
		$defaut = admin_url( 'admin.php?page=' . self::SLUG );
		$retour = isset( $_POST['lpl_retour'] ) ? esc_url_raw( wp_unslash( $_POST['lpl_retour'] ) ) : $defaut;
		wp_safe_redirect( add_query_arg( 'lpl_ok', '1', wp_validate_redirect( $retour, $defaut ) ) );
		exit;
	}

	/** Champs communs (marge, marge téléphone, quiz). */
	private static function champs( $r, $id ) {
		$n = esc_attr( self::OPTION );
		?>
		<label class="lpl-label" for="<?php echo esc_attr( $id ); ?>-m"><?php esc_html_e( 'Marge à gauche et à droite', 'sensei-lecons-pleine-largeur' ); ?></label>
		<span class="lpl-ligne"><input id="<?php echo esc_attr( $id ); ?>-m" type="range" min="0" max="200" step="2" value="<?php echo esc_attr( $r['marge'] ); ?>" data-lpl-curseur="marge">
		<input type="number" min="0" max="300" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[marge]" value="<?php echo esc_attr( $r['marge'] ); ?>" data-lpl-valeur="marge" class="small-text" aria-label="<?php esc_attr_e( 'Marge en pixels', 'sensei-lecons-pleine-largeur' ); ?>"> px</span>
		<label class="lpl-label" for="<?php echo esc_attr( $id ); ?>-t"><?php esc_html_e( 'Marge sur téléphone', 'sensei-lecons-pleine-largeur' ); ?></label>
		<span class="lpl-ligne"><input id="<?php echo esc_attr( $id ); ?>-t" type="range" min="0" max="60" step="1" value="<?php echo esc_attr( $r['marge_mobile'] ); ?>" data-lpl-curseur="marge_mobile">
		<input type="number" min="0" max="100" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[marge_mobile]" value="<?php echo esc_attr( $r['marge_mobile'] ); ?>" data-lpl-valeur="marge_mobile" class="small-text" aria-label="<?php esc_attr_e( 'Marge sur téléphone en pixels', 'sensei-lecons-pleine-largeur' ); ?>"> px</span>
		<label class="lpl-case"><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[quiz]" value="1" <?php checked( $r['quiz'] ); ?>> <?php esc_html_e( 'Aussi pour les quiz', 'sensei-lecons-pleine-largeur' ); ?></label>
		<?php
	}

	/** Script : curseur et champ chiffré restent synchronisés ; aperçu en direct (leçon et schéma). */
	private static function script_curseurs() {
		?>
		<script>
		(function () {
			function apercu(form) {
				var m = form.querySelector('[data-lpl-valeur="marge"]').value;
				var t = form.querySelector('[data-lpl-valeur="marge_mobile"]').value;
				if (document.body.classList.contains('lpl-actif')) {
					document.body.style.setProperty('--lpl-marge', (window.innerWidth <= 640 ? t : m) + 'px');
				}
				document.querySelectorAll('[data-lpl-schema]').forEach(function (s) {
					s.style.setProperty('--lpl-m', m);
					s.style.setProperty('--lpl-t', t);
					s.querySelectorAll('[data-lpl-chiffre="marge"]').forEach(function (e) { e.textContent = m + ' px'; });
					s.querySelectorAll('[data-lpl-chiffre="marge_mobile"]').forEach(function (e) { e.textContent = t + ' px'; });
				});
			}
			document.addEventListener('input', function (e) {
				var k = e.target.getAttribute('data-lpl-curseur') || e.target.getAttribute('data-lpl-valeur');
				if (!k) return;
				var form = e.target.closest('.lpl-form');
				var autre = form.querySelector(e.target.hasAttribute('data-lpl-curseur') ? '[data-lpl-valeur="' + k + '"]' : '[data-lpl-curseur="' + k + '"]');
				if (autre) autre.value = e.target.value;
				apercu(form);
			});
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Page LogiAdmin
	 * ------------------------------------------------------------------ */

	public static function menu() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Leçons pleine largeur', 'sensei-lecons-pleine-largeur' ),
			__( 'Leçons pleine largeur', 'sensei-lecons-pleine-largeur' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'page' )
		);
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lpl-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
	}

	/** Dernière leçon publiée : pour vérifier le résultat sur une vraie page. */
	private static function lecon_exemple() {
		$ids = get_posts(
			array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $ids ? get_permalink( $ids[0] ) : '';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$r      = self::reglages();
		$lecon  = self::lecon_exemple();
		$action = $lecon ? '<a class="button" href="' . esc_url( $lecon . '#lpl-marges' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir sur une leçon', 'sensei-lecons-pleine-largeur' ) . '</a>' : '';

		LogiAdmin::page_open( 'lecons-pleine-largeur', array( 'class' => 'lpl-admin', 'actions' => $action ) );
		if ( isset( $_GET['lpl_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Marges enregistrées : elles s’appliquent à toutes les leçons.', 'sensei-lecons-pleine-largeur' ) . '</p></div>';
		}
		?>
		<div class="lad-columns">
			<form class="lad-card lpl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h2><?php esc_html_e( 'Marges des leçons', 'sensei-lecons-pleine-largeur' ); ?></h2>
				<input type="hidden" name="action" value="lpl_enregistrer">
				<input type="hidden" name="lpl_retour" value="<?php echo esc_attr( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
				<?php wp_nonce_field( 'lpl_enregistrer' ); ?>
				<div class="lpl-champs"><?php self::champs( $r, 'lpl-page' ); ?></div>
				<p class="description"><?php esc_html_e( 'La marge sur téléphone s’applique aux écrans de 640 px de large ou moins.', 'sensei-lecons-pleine-largeur' ); ?></p>
				<?php submit_button( __( 'Enregistrer pour toutes les leçons', 'sensei-lecons-pleine-largeur' ), 'primary', 'submit', false ); ?>
			</form>

			<section class="lad-card">
				<h2><?php esc_html_e( 'Aperçu', 'sensei-lecons-pleine-largeur' ); ?></h2>
				<div class="lpl-schemas" data-lpl-schema style="--lpl-m:<?php echo (int) $r['marge']; ?>;--lpl-t:<?php echo (int) $r['marge_mobile']; ?>">
					<figure class="lpl-ecran lpl-ecran--ordinateur">
						<div class="lpl-page"><span class="lpl-bloc lpl-titre"></span><span class="lpl-bloc"></span><span class="lpl-bloc"></span><span class="lpl-bloc lpl-image"></span><span class="lpl-bloc"></span></div>
						<figcaption><?php esc_html_e( 'Ordinateur', 'sensei-lecons-pleine-largeur' ); ?> · <span data-lpl-chiffre="marge"><?php echo (int) $r['marge']; ?> px</span></figcaption>
					</figure>
					<figure class="lpl-ecran lpl-ecran--telephone">
						<div class="lpl-page"><span class="lpl-bloc lpl-titre"></span><span class="lpl-bloc"></span><span class="lpl-bloc lpl-image"></span><span class="lpl-bloc"></span></div>
						<figcaption><?php esc_html_e( 'Téléphone', 'sensei-lecons-pleine-largeur' ); ?> · <span data-lpl-chiffre="marge_mobile"><?php echo (int) $r['marge_mobile']; ?> px</span></figcaption>
					</figure>
				</div>
				<p class="lad-hint"><?php esc_html_e( 'Sur une leçon, le bouton « ↔ Marges » de la barre d’administration ouvre les mêmes réglages, avec un aperçu directement sur la page. Il n’est visible que par les administrateurs.', 'sensei-lecons-pleine-largeur' ); ?></p>
			</section>
		</div>
		<?php
		self::script_curseurs();
		LogiAdmin::page_close();
	}

	/* ------------------------------------------------------------------
	 * Sur la leçon (administrateurs)
	 * ------------------------------------------------------------------ */

	private static function est_admin() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/** Entrée « ↔ Marges » dans la barre d'administration. */
	public static function barre_admin( $bar ) {
		if ( ! self::concerne() || ! self::est_admin() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'lpl-marges',
				'title' => '↔ ' . esc_html__( 'Marges', 'sensei-lecons-pleine-largeur' ),
				'href'  => '#lpl-marges',
				'meta'  => array( 'title' => __( 'Régler les marges des leçons', 'sensei-lecons-pleine-largeur' ) ),
			)
		);
	}

	/** Panneau « Marges » sur la leçon : aperçu en direct puis enregistrement. */
	public static function panneau_lecon() {
		if ( ! self::concerne() || ! self::est_admin() ) {
			return;
		}
		$r      = self::reglages();
		$barre  = is_admin_bar_showing();
		$retour = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
		$retour = remove_query_arg( 'lpl_ok', $retour );
		$ouvert = isset( $_GET['lpl_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="lpl-panneau<?php echo $barre ? ' lpl-sous-barre' : ''; ?>" id="lpl-marges" role="dialog" aria-label="<?php esc_attr_e( 'Marges des leçons', 'sensei-lecons-pleine-largeur' ); ?>" <?php echo $ouvert ? '' : 'hidden'; ?>>
			<button type="button" class="lpl-fermer" aria-label="<?php esc_attr_e( 'Fermer', 'sensei-lecons-pleine-largeur' ); ?>">×</button>
			<form class="lpl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<strong class="lpl-titre-panneau"><?php esc_html_e( 'Marges des leçons', 'sensei-lecons-pleine-largeur' ); ?></strong>
				<input type="hidden" name="action" value="lpl_enregistrer">
				<input type="hidden" name="lpl_retour" value="<?php echo esc_attr( $retour ); ?>">
				<?php wp_nonce_field( 'lpl_enregistrer' ); ?>
				<?php self::champs( $r, 'lpl-lecon' ); ?>
				<button type="submit"><?php esc_html_e( 'Enregistrer pour toutes les leçons', 'sensei-lecons-pleine-largeur' ); ?></button>
				<?php if ( $ouvert ) : ?>
					<p class="lpl-ok">✓ <?php esc_html_e( 'Enregistré', 'sensei-lecons-pleine-largeur' ); ?></p>
				<?php endif; ?>
				<p class="lpl-note"><?php esc_html_e( 'Visible seulement par les administrateurs.', 'sensei-lecons-pleine-largeur' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Réglages dans LogiAdmin', 'sensei-lecons-pleine-largeur' ); ?></a></p>
			</form>
		</div>
		<?php if ( ! $barre ) : ?>
			<button type="button" class="lpl-ouvrir" data-lpl-ouvrir>↔ <?php esc_html_e( 'Marges', 'sensei-lecons-pleine-largeur' ); ?></button>
		<?php endif; ?>
		<script>
		(function () {
			var p = document.getElementById('lpl-marges');
			if (!p) return;
			if (window.location.hash === '#lpl-marges') { p.hidden = false; }
			document.addEventListener('click', function (e) {
				if (e.target.closest('#wp-admin-bar-lpl-marges a, [data-lpl-ouvrir]')) { e.preventDefault(); p.hidden = !p.hidden; return; }
				if (e.target.closest('.lpl-fermer')) { p.hidden = true; }
			});
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape') p.hidden = true; });
		})();
		</script>
		<style>
			.lpl-panneau{--ink:#313a45;--green:#067030;--yellow:#f5c400;position:fixed;right:16px;top:16px;z-index:100000;font:14px/1.45 "Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}
			.lpl-panneau[hidden]{display:none}
			.lpl-panneau.lpl-sous-barre{top:calc(var(--wp-admin--admin-bar--height, 32px) + 8px)}
			.lpl-panneau .lpl-fermer{position:absolute;top:8px;right:10px;border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:#c9d1d9;padding:2px 6px;margin:0}
			.lpl-ouvrir{position:fixed;left:12px;top:12px;z-index:100000;border:0;border-radius:999px;background:#313a45;color:#fff;font:600 13px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:9px 14px;cursor:pointer;box-shadow:0 6px 18px rgba(0,0,0,.18)}
			.lpl-panneau .lpl-form{display:grid;gap:6px;width:280px;padding:16px;background:#fff;border-radius:12px;box-shadow:0 14px 34px rgba(49,58,69,.25);overflow:hidden}
			.lpl-panneau .lpl-titre-panneau{margin:-16px -16px 8px;padding:12px 16px;background:var(--ink);color:#fff;font-weight:600;border-bottom:4px solid var(--yellow)}
			.lpl-panneau .lpl-label{font-weight:600}
			.lpl-panneau .lpl-ligne{display:flex;align-items:center;gap:6px}
			.lpl-panneau input[type=range]{flex:1;min-width:0;accent-color:var(--green)}
			.lpl-panneau input[type=number]{width:64px;padding:4px 6px;border:1px solid #c3c9be;border-radius:6px}
			.lpl-panneau .lpl-case{display:flex;gap:6px;align-items:center}
			.lpl-panneau input[type=checkbox]{accent-color:var(--green)}
			.lpl-panneau button[type=submit]{margin-top:8px;padding:10px 14px;border:0;border-radius:999px;background:var(--green);color:#fff;font-weight:600;cursor:pointer}
			.lpl-panneau button[type=submit]:hover{background:#0b4d22}
			.lpl-panneau :focus-visible{outline:3px solid var(--yellow);outline-offset:2px}
			.lpl-panneau .lpl-ok{margin:0;color:var(--green);font-weight:600}
			.lpl-panneau .lpl-note{margin:0;color:#5b6672;font-size:12px}
			.lpl-panneau .lpl-note a{color:var(--green)}
		</style>
		<?php
		self::script_curseurs();
	}

	/* ------------------------------------------------------------------
	 * Pleine largeur
	 * ------------------------------------------------------------------ */

	/** Page concernée : une leçon, ou un quiz si l'option est cochée. */
	private static function concerne() {
		if ( is_admin() ) {
			return false;
		}
		$types = array( 'lesson' );
		if ( self::reglages()['quiz'] ) {
			$types[] = 'quiz';
		}
		return is_singular( $types );
	}

	public static function body_class( $classes ) {
		if ( self::concerne() ) {
			$classes[] = 'lpl-actif';
			if ( ! in_array( 'sensei-course-theme', $classes, true ) ) {
				$classes[] = 'lpl-classique';
			}
		}
		return $classes;
	}

	public static function styles() {
		if ( ! self::concerne() ) {
			return;
		}
		$r = self::reglages();
		wp_enqueue_style( 'lpl-pleine-largeur', plugins_url( 'assets/pleine-largeur.css', __FILE__ ), array(), self::VERSION );
		wp_add_inline_style(
			'lpl-pleine-largeur',
			sprintf(
				'body.lpl-actif{--lpl-marge:%1$dpx}@media (max-width:640px){body.lpl-actif{--lpl-marge:%2$dpx}}',
				$r['marge'],
				$r['marge_mobile']
			)
		);
	}
}

LPL_Sensei_Pleine_Largeur::init();

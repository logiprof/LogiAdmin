<?php
/**
 * Module LogiAdmin : LogiSensei Export Pro
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Exporte les cours Sensei LMS en dossiers DOCX, images, quiz et archive ZIP.
 * Version: 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Menu parent : fourni par LogiAdmin (logiprof_admin_menu_parent_slug). */

final class LogiSensei_Export_Pro {
	const VERSION        = '0.4.0';
	const SLUG           = 'logisensei-export-pro';
	const OPTION_HISTORY = 'logisensei_export_history';
	const OPTION_SETTINGS = 'logisensei_export_settings';
	const NONCE_ACTION   = 'logisensei_export_nonce';

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_logisensei_download_export', array( $this, 'download_export' ) );
		add_action( 'admin_post_logisensei_delete_export', array( $this, 'delete_export' ) );
		add_action( 'admin_post_logisensei_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_logisensei_start_export', array( $this, 'ajax_start_export' ) );
		add_action( 'wp_ajax_logisensei_export_step', array( $this, 'ajax_export_step' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent' ) );
	}

	public function register_menu() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Export Sensei', 'logisensei-export-pro' ),
			__( 'Export Sensei', 'logisensei-export-pro' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		// Pages internes (onglets) : sans entrée de menu, mais rattachées à LogiAdmin pour garder le menu ouvert.
		add_submenu_page( '', __( 'Exporter des cours', 'logisensei-export-pro' ), '', $this->capability(), self::SLUG . '-export', array( $this, 'render_export_page' ) );
		add_submenu_page( '', __( 'Historique des exports', 'logisensei-export-pro' ), '', $this->capability(), self::SLUG . '-history', array( $this, 'render_history_page' ) );
		add_submenu_page( '', __( 'Paramètres de l’export', 'logisensei-export-pro' ), '', $this->capability(), self::SLUG . '-settings', array( $this, 'render_settings_page' ) );
	}

	public function highlight_parent( $parent_file ) {
		global $plugin_page, $submenu_file;
		if ( is_string( $plugin_page ) && 0 === strpos( $plugin_page, self::SLUG . '-' ) ) {
			$submenu_file = self::SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return logiprof_admin_menu_parent_slug();
		}
		return $parent_file;
	}

	/** Ouvre une page de l'outil dans l'habillage LogiAdmin, avec les onglets. */
	private function open_page( $active ) {
		$tabs = array();
		foreach ( $this->tabs() as $slug => $label ) {
			$tabs[ admin_url( 'admin.php?page=' . $slug ) ] = $label;
		}
		LogiAdmin::page_open(
			'export-sensei',
			array(
				'class'  => 'logisensei-wrap',
				'tabs'   => $tabs,
				'active' => admin_url( 'admin.php?page=' . $active ),
			)
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( self::SLUG, plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( self::SLUG, plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
		wp_localize_script(
			self::SLUG,
			'LogiSenseiExport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'labels'  => array(
					'selectCourse' => __( 'Sélectionnez au moins un cours.', 'logisensei-export-pro' ),
					'exportError'  => __( 'Une erreur est survenue pendant l’export.', 'logisensei-export-pro' ),
					'working'      => __( 'Export en cours…', 'logisensei-export-pro' ),
					'finished'     => __( 'Export terminé. Votre archive est prête.', 'logisensei-export-pro' ),
					'none'         => __( 'Aucun cours sélectionné', 'logisensei-export-pro' ),
					'one'          => __( '1 cours sélectionné', 'logisensei-export-pro' ),
					/* translators: %d: nombre de cours */
					'many'         => __( '%d cours sélectionnés', 'logisensei-export-pro' ),
				),
			)
		);
	}

	public function render_dashboard() {
		$this->guard();
		$courses = $this->get_courses();
		$history = $this->get_history();
		?>
		<?php $this->open_page( self::SLUG ); ?>
			<div class="lad-stats logisensei-stats">
				<div><strong><?php echo esc_html( number_format_i18n( count( $courses ) ) ); ?></strong><span><?php esc_html_e( 'cours disponibles', 'logisensei-export-pro' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( count( $history ) ) ); ?></strong><span><?php esc_html_e( 'exports conservés', 'logisensei-export-pro' ); ?></span></div>
				<div class="<?php echo $this->sensei_detected() ? 'is-ok' : 'is-warn'; ?>"><strong><?php echo esc_html( $this->sensei_detected() ? __( 'Oui', 'logisensei-export-pro' ) : __( 'Non', 'logisensei-export-pro' ) ); ?></strong><span><?php esc_html_e( 'Sensei détecté', 'logisensei-export-pro' ); ?></span></div>
			</div>
			<div class="lad-columns">
				<section class="lad-card">
					<h2><?php esc_html_e( 'Ce que contient un export', 'logisensei-export-pro' ); ?></h2>
					<ul class="lad-list">
						<li><?php esc_html_e( 'Un dossier par cours, avec un document Word par leçon (titres, listes, tableaux, gras, couleurs).', 'logisensei-export-pro' ); ?></li>
						<li><?php esc_html_e( 'Les images des leçons, si l’option est activée dans les paramètres.', 'logisensei-export-pro' ); ?></li>
						<li><?php esc_html_e( 'Les quiz de chaque leçon dans un classeur Excel : question, bonne réponse, réponses fausses, points.', 'logisensei-export-pro' ); ?></li>
						<li><?php esc_html_e( 'Le tout réuni dans une archive ZIP à télécharger.', 'logisensei-export-pro' ); ?></li>
					</ul>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-export' ) ); ?>"><?php esc_html_e( 'Choisir les cours à exporter', 'logisensei-export-pro' ); ?></a></p>
				</section>
				<section class="lad-card">
					<h2><?php esc_html_e( 'Derniers exports', 'logisensei-export-pro' ); ?></h2>
					<?php if ( empty( $history ) ) : ?>
						<p class="lad-empty"><?php esc_html_e( 'Aucun export pour le moment.', 'logisensei-export-pro' ); ?></p>
					<?php else : ?>
						<ul class="lad-list lad-list--plain">
							<?php foreach ( array_slice( $history, 0, 4 ) as $row ) : ?>
								<li>
									<strong><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $row['date'] ) ); ?></strong> · <?php echo esc_html( implode( ', ', (array) $row['courses'] ) ); ?>
									<?php if ( ! empty( $row['download_url'] ) ) : ?> · <a href="<?php echo esc_url( $row['download_url'] ); ?>"><?php esc_html_e( 'Télécharger', 'logisensei-export-pro' ); ?></a><?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-history' ) ); ?>"><?php esc_html_e( 'Tout l’historique', 'logisensei-export-pro' ); ?> →</a></p>
					<?php endif; ?>
				</section>
			</div>
		<?php LogiAdmin::page_close(); ?>
		<?php
	}

	public function render_export_page() {
		$this->guard();
		$courses = $this->get_courses();
		$categories = get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<?php $this->open_page( self::SLUG . '-export' ); ?>
			<div class="lad-toolbar logisensei-toolbar">
				<input type="search" id="logisensei-search" placeholder="<?php esc_attr_e( 'Rechercher un cours…', 'logisensei-export-pro' ); ?>" aria-label="<?php esc_attr_e( 'Rechercher un cours', 'logisensei-export-pro' ); ?>">
				<select id="logisensei-category" aria-label="<?php esc_attr_e( 'Catégorie', 'logisensei-export-pro' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes les catégories', 'logisensei-export-pro' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category->slug ); ?>"><?php echo esc_html( $category->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="logisensei-select-all" data-label-all="<?php esc_attr_e( 'Tout sélectionner', 'logisensei-export-pro' ); ?>" data-label-none="<?php esc_attr_e( 'Tout désélectionner', 'logisensei-export-pro' ); ?>"><?php esc_html_e( 'Tout sélectionner', 'logisensei-export-pro' ); ?></button>
				<span class="lad-toolbar-spacer"></span>
				<span class="lad-count" id="logisensei-selected-count" aria-live="polite"></span>
				<button type="button" class="button button-primary" id="logisensei-export-button"><?php esc_html_e( 'Lancer l’export', 'logisensei-export-pro' ); ?></button>
			</div>

			<table class="widefat striped logisensei-course-table">
				<thead>
					<tr>
						<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Choisir', 'logisensei-export-pro' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Nom du cours', 'logisensei-export-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Catégorie', 'logisensei-export-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Leçons', 'logisensei-export-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Créé le', 'logisensei-export-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $courses as $course ) : ?>
						<tr data-title="<?php echo esc_attr( strtolower( remove_accents( $course['title'] ) ) ); ?>" data-category="<?php echo esc_attr( implode( ' ', $course['category_slugs'] ) ); ?>">
							<td class="check-column"><input type="checkbox" class="logisensei-course-check" id="lse-course-<?php echo esc_attr( $course['id'] ); ?>" value="<?php echo esc_attr( $course['id'] ); ?>"></td>
							<td><label for="lse-course-<?php echo esc_attr( $course['id'] ); ?>"><strong><?php echo esc_html( $course['title'] ); ?></strong></label></td>
							<td><?php echo esc_html( $course['categories'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $course['lesson_count'] ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $course['date'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $courses ) ) : ?>
						<tr><td colspan="5" class="lad-empty"><?php esc_html_e( 'Aucun cours Sensei trouvé.', 'logisensei-export-pro' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="logisensei-progress lad-card" hidden>
				<div class="logisensei-progress-heading">
					<span class="logisensei-spinner" aria-hidden="true"></span>
					<strong id="logisensei-progress-title"><?php esc_html_e( 'Export en cours…', 'logisensei-export-pro' ); ?></strong>
				</div>
				<div class="logisensei-progress-bar"><span style="width:0%"></span></div>
				<p id="logisensei-progress-label"><?php esc_html_e( 'Prêt.', 'logisensei-export-pro' ); ?></p>
				<div id="logisensei-complete-notice" class="notice notice-success inline" hidden>
					<p><?php esc_html_e( 'Export terminé. Votre archive ZIP est prête à être téléchargée.', 'logisensei-export-pro' ); ?></p>
				</div>
				<p id="logisensei-download-link"></p>
			</div>
		<?php LogiAdmin::page_close(); ?>
		<?php
	}

	public function render_history_page() {
		$this->guard();
		$history = $this->get_history();
		?>
		<?php $this->open_page( self::SLUG . '-history' ); ?>
			<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Export supprimé.', 'logisensei-export-pro' ); ?></p>
				</div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'logisensei-export-pro' ); ?></th>
						<th><?php esc_html_e( 'Utilisateur', 'logisensei-export-pro' ); ?></th>
						<th><?php esc_html_e( 'Cours exportes', 'logisensei-export-pro' ); ?></th>
						<th><?php esc_html_e( 'Taille ZIP', 'logisensei-export-pro' ); ?></th>
						<th><?php esc_html_e( 'Statut', 'logisensei-export-pro' ); ?></th>
						<th><?php esc_html_e( 'Archive', 'logisensei-export-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $history ) ) : ?>
						<tr><td colspan="6" class="lad-empty"><?php esc_html_e( 'Aucun export pour le moment.', 'logisensei-export-pro' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $history as $index => $row ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $row['date'] ) ); ?></td>
							<td><?php echo esc_html( $row['user'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $row['courses'] ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) $row['size'] ) ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td>
								<?php if ( ! empty( $row['download_url'] ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $row['download_url'] ); ?>"><?php esc_html_e( 'Télécharger', 'logisensei-export-pro' ); ?></a>
								<?php else : ?>
									<?php esc_html_e( 'Indisponible', 'logisensei-export-pro' ); ?>
								<?php endif; ?>
								<?php
								$delete_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=logisensei_delete_export&history_index=' . absint( $index ) ),
									'logisensei_delete_export_' . absint( $index )
								);
								?>
								<span class="logisensei-row-actions">
									<a class="logisensei-delete-link" href="<?php echo esc_url( $delete_url ); ?>" data-logiadmin-confirm="<?php echo esc_attr__( 'Supprimer cet export et son archive ZIP ?', 'logisensei-export-pro' ); ?>"><?php esc_html_e( 'Supprimer', 'logisensei-export-pro' ); ?></a>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php LogiAdmin::page_close(); ?>
		<?php
	}

	public function render_settings_page() {
		$this->guard();
		$settings = $this->get_settings();
		?>
		<?php $this->open_page( self::SLUG . '-settings' ); ?>
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Paramètres enregistrés.', 'logisensei-export-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lad-card lad-form">
				<input type="hidden" name="action" value="logisensei_save_settings">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<label class="lad-field" for="folder_name">
					<span class="lad-label"><?php esc_html_e( 'Dossier des exports', 'logisensei-export-pro' ); ?></span>
					<input name="folder_name" id="folder_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['folder_name'] ); ?>">
					<span class="description"><?php esc_html_e( 'Sous-dossier de wp-content/uploads où les exports sont rangés.', 'logisensei-export-pro' ); ?></span>
				</label>
				<label class="lad-field lad-field--short" for="max_image_width">
					<span class="lad-label"><?php esc_html_e( 'Largeur maximale des images', 'logisensei-export-pro' ); ?></span>
					<span class="lad-input-unit"><input name="max_image_width" id="max_image_width" type="number" min="300" max="2400" value="<?php echo esc_attr( $settings['max_image_width'] ); ?>"> px</span>
				</label>
				<fieldset class="lad-checks">
					<legend class="lad-label"><?php esc_html_e( 'Contenu de l’export', 'logisensei-export-pro' ); ?></legend>
					<label class="lad-check"><input name="include_quiz" type="checkbox" value="1" <?php checked( $settings['include_quiz'] ); ?>> <?php esc_html_e( 'Quiz (classeurs Excel)', 'logisensei-export-pro' ); ?></label>
					<label class="lad-check"><input name="include_media" type="checkbox" value="1" <?php checked( $settings['include_media'] ); ?>> <?php esc_html_e( 'Images des leçons', 'logisensei-export-pro' ); ?></label>
					<label class="lad-check"><input name="zip_enabled" type="checkbox" value="1" <?php checked( $settings['zip_enabled'] ); ?>> <?php esc_html_e( 'Archive ZIP à télécharger', 'logisensei-export-pro' ); ?></label>
				</fieldset>
				<?php submit_button( __( 'Enregistrer les paramètres', 'logisensei-export-pro' ), 'primary', 'submit', false ); ?>
			</form>
		<?php LogiAdmin::page_close(); ?>
		<?php
	}

	private function tabs() {
		return array(
			self::SLUG               => __( 'Vue d’ensemble', 'logisensei-export-pro' ),
			self::SLUG . '-export'   => __( 'Exporter des cours', 'logisensei-export-pro' ),
			self::SLUG . '-history'  => __( 'Historique', 'logisensei-export-pro' ),
			self::SLUG . '-settings' => __( 'Paramètres', 'logisensei-export-pro' ),
		);
	}

	public function save_settings() {
		$this->guard();
		check_admin_referer( self::NONCE_ACTION );

		$settings = array(
			'folder_name'     => sanitize_file_name( wp_unslash( $_POST['folder_name'] ?? 'exports-logisensei' ) ),
			'max_image_width' => max( 300, min( 2400, absint( $_POST['max_image_width'] ?? 1200 ) ) ),
			'include_quiz'    => ! empty( $_POST['include_quiz'] ),
			'include_media'   => ! empty( $_POST['include_media'] ),
			'zip_enabled'     => ! empty( $_POST['zip_enabled'] ),
		);

		update_option( self::OPTION_SETTINGS, $settings, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-settings&updated=1' ) );
		exit;
	}

	public function ajax_start_export() {
		$this->ajax_guard();
		$course_ids = array_map( 'absint', (array) ( $_POST['course_ids'] ?? array() ) );
		$course_ids = array_values( array_filter( $course_ids ) );

		if ( empty( $course_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun cours selectionne.', 'logisensei-export-pro' ) ), 400 );
		}

		$upload = wp_upload_dir();
		$job_id = wp_generate_uuid4();
		$settings = $this->get_settings();
		$base_dir = trailingslashit( $upload['basedir'] ) . trailingslashit( $settings['folder_name'] ) . $job_id;
		wp_mkdir_p( $base_dir );

		$queue = array();
		foreach ( $course_ids as $course_id ) {
			$course = get_post( $course_id );
			if ( $course && 'course' === $course->post_type ) {
				$queue[] = array( 'type' => 'course', 'course_id' => $course_id );
			}
		}

		set_transient(
			$this->job_key( $job_id ),
			array(
				'id'         => $job_id,
				'base_dir'   => $base_dir,
				'base_url'   => trailingslashit( $upload['baseurl'] ) . trailingslashit( $settings['folder_name'] ) . $job_id,
				'queue'      => $queue,
				'done'       => 0,
				'total'      => max( 1, count( $queue ) ),
				'courses'    => array(),
				'errors'     => array(),
				'zip_path'   => '',
				'settings'   => $settings,
				'started_by' => get_current_user_id(),
			),
			DAY_IN_SECONDS
		);

		wp_send_json_success( array( 'job_id' => $job_id, 'message' => __( 'Analyse des cours...', 'logisensei-export-pro' ) ) );
	}

	public function ajax_export_step() {
		$this->ajax_guard();
		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$job = get_transient( $this->job_key( $job_id ) );

		if ( empty( $job ) || empty( $job['base_dir'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Export introuvable ou expire.', 'logisensei-export-pro' ) ), 404 );
		}

		if ( ! empty( $job['queue'] ) ) {
			$item = array_shift( $job['queue'] );
			if ( 'course' === $item['type'] ) {
				$this->export_course( (int) $item['course_id'], $job );
			}
			$job['done']++;
			$job['total'] = max( $job['total'], $job['done'] + count( $job['queue'] ) );
			set_transient( $this->job_key( $job_id ), $job, DAY_IN_SECONDS );

			wp_send_json_success(
				array(
					'done'     => false,
					'progress' => min( 90, round( ( $job['done'] / $job['total'] ) * 90 ) ),
					'message'  => __( 'Creation des documents...', 'logisensei-export-pro' ),
				)
			);
		}

		if ( empty( $job['zip_path'] ) && ! empty( $job['settings']['zip_enabled'] ) ) {
			$zip_path = trailingslashit( dirname( $job['base_dir'] ) ) . 'export-' . sanitize_file_name( $job['id'] ) . '.zip';
			$this->zip_directory( $job['base_dir'], $zip_path );
			$job['zip_path'] = $zip_path;
			set_transient( $this->job_key( $job_id ), $job, DAY_IN_SECONDS );
		}

		$download_url = $this->download_url( $job_id );
		$this->record_history( $job, $download_url );
		delete_transient( $this->job_key( $job_id ) );

		wp_send_json_success(
			array(
				'done'         => true,
				'progress'     => 100,
				'message'      => __( 'Export termine.', 'logisensei-export-pro' ),
				'download_url' => $download_url,
			)
		);
	}

	public function download_export() {
		$this->guard();
		$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );
		check_admin_referer( 'logisensei_download_' . $job_id );

		$upload = wp_upload_dir();
		$settings = $this->get_settings();
		$zip_path = trailingslashit( $upload['basedir'] ) . trailingslashit( $settings['folder_name'] ) . 'export-' . sanitize_file_name( $job_id ) . '.zip';
		// Si le dossier a été renommé depuis, on retrouve l'archive grâce à l'historique.
		foreach ( $this->get_history() as $row ) {
			$real    = isset( $row['zip_path'] ) && $row['zip_path'] ? realpath( $row['zip_path'] ) : false;
			$uploads = realpath( $upload['basedir'] );
			if ( isset( $row['id'] ) && $row['id'] === $job_id && $real && $uploads && 0 === strpos( $real, trailingslashit( $uploads ) ) && 'zip' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
				$zip_path = $row['zip_path'];
				break;
			}
		}
		if ( ! file_exists( $zip_path ) ) {
			wp_die( esc_html__( 'Archive introuvable.', 'logisensei-export-pro' ) );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $zip_path ) . '"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path );
		exit;
	}

	public function delete_export() {
		$this->guard();
		$index = absint( $_GET['history_index'] ?? 0 );
		check_admin_referer( 'logisensei_delete_export_' . $index );

		$history = $this->get_history();
		if ( isset( $history[ $index ] ) ) {
			$row = $history[ $index ];
			if ( ! empty( $row['zip_path'] ) && file_exists( $row['zip_path'] ) && $this->is_export_file_path( $row['zip_path'] ) ) {
				wp_delete_file( $row['zip_path'] );
			}
			if ( ! empty( $row['base_dir'] ) && is_dir( $row['base_dir'] ) && $this->is_export_dir_path( $row['base_dir'] ) ) {
				$this->delete_directory( $row['base_dir'] );
			}
			unset( $history[ $index ] );
			update_option( self::OPTION_HISTORY, array_values( $history ), false );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-history&deleted=1' ) );
		exit;
	}

	private function export_course( $course_id, &$job ) {
		$course = get_post( $course_id );
		if ( ! $course ) {
			return;
		}

		$course_dir = trailingslashit( $job['base_dir'] ) . $this->safe_name( $course->post_title );
		$lesson_dir = trailingslashit( $course_dir ) . 'Lecons';
		$image_dir = trailingslashit( $course_dir ) . 'Images';
		$quiz_dir = trailingslashit( $course_dir ) . 'Quiz';
		wp_mkdir_p( $lesson_dir );
		wp_mkdir_p( $image_dir );
		wp_mkdir_p( $quiz_dir );

		$lessons = $this->get_course_lessons( $course_id );
		$docx = new LogiSensei_Docx_Writer();
		$xlsx = new LogiSensei_Xlsx_Writer();

		foreach ( $lessons as $index => $lesson ) {
			$lesson_file = trailingslashit( $lesson_dir ) . sprintf( '%02d_%s.docx', $index + 1, $this->safe_name( $lesson->post_title ) );
			$html = apply_filters( 'the_content', $lesson->post_content );
			$media = ! empty( $job['settings']['include_media'] ) ? $this->collect_images( $html, $image_dir ) : array();
			$videos = $this->collect_videos( $html );

			$docx->create_lesson_doc(
				$lesson_file,
				array(
					'course_title' => $course->post_title,
					'lesson_title' => $lesson->post_title,
					'html'         => $html,
					'images'       => $media,
					'videos'       => $videos,
					'export_date'  => wp_date( get_option( 'date_format' ) ),
				)
			);

			if ( ! empty( $job['settings']['include_quiz'] ) ) {
				$quiz_rows = $this->get_lesson_quiz_rows( $lesson->ID );
				if ( ! empty( $quiz_rows ) ) {
					$quiz_file = trailingslashit( $quiz_dir ) . sprintf( 'Quiz_Lecon_%02d_%s.xlsx', $index + 1, $this->safe_name( $lesson->post_title ) );
					$xlsx->create_quiz_workbook(
						$quiz_file,
						array(
							'course_title' => $course->post_title,
							'lesson_title' => $lesson->post_title,
							'rows'         => $quiz_rows,
							'export_date'  => wp_date( get_option( 'date_format' ) ),
						)
					);
				}
			}
		}

		$job['courses'][] = $course->post_title;
	}

	private function get_courses() {
		$posts = get_posts(
			array(
				'post_type'      => 'course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$courses = array();
		foreach ( $posts as $post ) {
			$terms = get_the_terms( $post->ID, 'course-category' );
			$terms = is_wp_error( $terms ) || empty( $terms ) ? array() : $terms;
			$courses[] = array(
				'id'             => $post->ID,
				'title'          => get_the_title( $post ),
				'categories'     => $terms ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : __( 'Non classe', 'logisensei-export-pro' ),
				'category_slugs' => $terms ? wp_list_pluck( $terms, 'slug' ) : array(),
				'lesson_count'   => count( $this->get_course_lessons( $post->ID ) ),
				'date'           => $post->post_date,
			);
		}
		return $courses;
	}

	private function get_course_lessons( $course_id ) {
		if ( function_exists( 'Sensei' ) && isset( Sensei()->course ) && method_exists( Sensei()->course, 'course_lessons' ) ) {
			$lessons = Sensei()->course->course_lessons( $course_id, 'any', 'ids' );
			if ( is_array( $lessons ) && ! empty( $lessons ) ) {
				return array_values( array_filter( array_map( 'get_post', $lessons ) ) );
			}
		}

		$lesson_ids = get_post_meta( $course_id, '_lesson_order', true );
		if ( is_array( $lesson_ids ) && ! empty( $lesson_ids ) ) {
			return array_values( array_filter( array_map( 'get_post', array_map( 'absint', $lesson_ids ) ) ) );
		}

		return get_posts(
			array(
				'post_type'      => 'lesson',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_lesson_course',
						'value' => $course_id,
					),
				),
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'order'          => 'ASC',
			)
		);
	}

	private function get_lesson_quiz_rows( $lesson_id ) {
		$quiz_id = (int) get_post_meta( $lesson_id, '_lesson_quiz', true );
		if ( ! $quiz_id ) {
			$quiz = get_posts(
				array(
					'post_type'      => 'quiz',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_quiz_lesson',
					'meta_value'     => $lesson_id,
				)
			);
			$quiz_id = $quiz ? (int) $quiz[0]->ID : 0;
		}
		if ( ! $quiz_id ) {
			return array();
		}

		$quiz = get_post( $quiz_id );
		$question_ids = get_post_meta( $quiz_id, '_question_order', true );
		if ( ! is_array( $question_ids ) || empty( $question_ids ) ) {
			$question_ids = get_posts(
				array(
					'post_type'      => array( 'question', 'multiple_question' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_quiz_id',
					'meta_value'     => $quiz_id,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'date'       => 'ASC',
					),
					'order'          => 'ASC',
				)
			);
		}

		$rows = array();
		foreach ( array_values( array_map( 'absint', $question_ids ) ) as $index => $question_id ) {
			$question = get_post( $question_id );
			if ( ! $question ) {
				continue;
			}
			$question_meta = get_post_meta( $question_id );
			$options = $this->get_question_options( $question_id, $question_meta );
			$rows[] = array(
				'order'            => $index + 1,
				'quiz_id'          => $quiz_id,
				'quiz_title'       => wp_strip_all_tags( get_the_title( $lesson_id ) ),
				'sensei_quiz_title' => $quiz ? wp_strip_all_tags( $quiz->post_title ) : '',
				'question_id'      => $question_id,
				'question_title'   => wp_strip_all_tags( $question->post_title ),
				'question_content' => wp_strip_all_tags( $question->post_content ),
				'type'             => $this->get_question_type( $question_id ),
				'choices'          => implode( "\n", $options['all'] ),
				'answer'           => $options['correct'],
				'wrong_1'          => $options['wrong'][0] ?? '',
				'wrong_2'          => $options['wrong'][1] ?? '',
				'wrong_3'          => $options['wrong'][2] ?? '',
				'feedback'         => $this->get_question_feedback( $question_meta ),
				'points'           => $this->get_question_points( $question_meta ),
				'status'           => $question->post_status,
				'meta'             => $this->stringify_meta( $question_meta ),
			);
		}
		return $rows;
	}

	private function get_question_type( $question_id ) {
		$type = get_post_meta( $question_id, '_question_type', true );
		if ( $type ) {
			return (string) $type;
		}
		$terms = get_the_terms( $question_id, 'question-type' );
		return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->name : __( 'Question', 'logisensei-export-pro' );
	}

	private function get_question_answer( $question_id ) {
		$keys = array( '_question_right_answer', '_right_answer', '_answer', '_answers' );
		foreach ( $keys as $key ) {
			$value = get_post_meta( $question_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				return wp_strip_all_tags( implode( ', ', array_map( 'strval', $value ) ) );
			}
			return wp_strip_all_tags( (string) $value );
		}
		return '';
	}

	private function get_question_options( $question_id, $meta ) {
		$all = array();
		$correct = array_filter( array( $this->get_question_answer( $question_id ) ) );
		$wrong = array();

		foreach ( $meta as $key => $raw_values ) {
			$is_answer_key = false !== stripos( $key, 'answer' ) || false !== stripos( $key, 'choice' ) || false !== stripos( $key, 'option' );
			if ( ! $is_answer_key || false !== stripos( $key, 'feedback' ) || false !== stripos( $key, 'grade' ) ) {
				continue;
			}

			foreach ( (array) $raw_values as $raw_value ) {
				$this->collect_answer_values( maybe_unserialize( $raw_value ), $all, $correct, $wrong );
			}
		}

		$all = $this->clean_answer_list( $all );
		$correct = $this->clean_answer_list( $correct );
		$wrong = $this->clean_answer_list( $wrong );

		if ( empty( $correct ) ) {
			$correct[] = $this->get_question_answer( $question_id );
			$correct = $this->clean_answer_list( $correct );
		}

		$wrong = array_values(
			array_filter(
				$wrong ?: $all,
				function ( $value ) use ( $correct ) {
					return ! in_array( $value, $correct, true );
				}
			)
		);

		return array(
			'all'     => $all,
			'correct' => implode( "\n", $correct ),
			'wrong'   => array_slice( $wrong, 0, 3 ),
		);
	}

	private function collect_answer_values( $value, &$all, &$correct, &$wrong ) {
		if ( is_array( $value ) ) {
			$text = '';
			foreach ( array( 'answer', 'text', 'label', 'value', 'title', 'name' ) as $key ) {
				if ( isset( $value[ $key ] ) && ! is_array( $value[ $key ] ) && ! is_object( $value[ $key ] ) ) {
					$text = $this->stringify_value( $value[ $key ] );
					break;
				}
			}

			$is_correct = false;
			foreach ( array( 'correct', 'is_correct', 'right', 'is_right' ) as $key ) {
				if ( isset( $value[ $key ] ) ) {
					$is_correct = (bool) $value[ $key ];
					break;
				}
			}

			if ( '' !== trim( $text ) ) {
				$all[] = $text;
				if ( $is_correct ) {
					$correct[] = $text;
				} else {
					$wrong[] = $text;
				}
			}

			foreach ( $value as $nested ) {
				$this->collect_answer_values( $nested, $all, $correct, $wrong );
			}
			return;
		}

		if ( is_scalar( $value ) ) {
			$text = $this->stringify_value( $value );
			if ( '' !== trim( $text ) && strlen( $text ) < 500 ) {
				$all[] = $text;
			}
		}
	}

	private function clean_answer_list( $values ) {
		$values = array_map( 'trim', array_map( 'wp_strip_all_tags', $values ) );
		$values = array_filter(
			$values,
			function ( $value ) {
				return '' !== $value && ! is_numeric( $value );
			}
		);
		return array_values( array_unique( $values ) );
	}

	private function get_question_choices( $meta ) {
		$values = array();
		foreach ( $meta as $key => $raw_values ) {
			if ( false === stripos( $key, 'answer' ) && false === stripos( $key, 'choice' ) && false === stripos( $key, 'option' ) ) {
				continue;
			}
			foreach ( (array) $raw_values as $raw_value ) {
				$value = maybe_unserialize( $raw_value );
				$values[] = $this->stringify_value( $value );
			}
		}
		return implode( "\n", array_values( array_filter( array_unique( $values ) ) ) );
	}

	private function get_question_feedback( $meta ) {
		$values = array();
		foreach ( $meta as $key => $raw_values ) {
			if ( false === stripos( $key, 'feedback' ) && false === stripos( $key, 'explanation' ) ) {
				continue;
			}
			foreach ( (array) $raw_values as $raw_value ) {
				$values[] = $this->stringify_value( maybe_unserialize( $raw_value ) );
			}
		}
		return implode( "\n", array_values( array_filter( array_unique( $values ) ) ) );
	}

	private function get_question_points( $meta ) {
		foreach ( array( '_question_grade', '_question_points', '_points', 'points' ) as $key ) {
			if ( isset( $meta[ $key ][0] ) ) {
				return $this->stringify_value( maybe_unserialize( $meta[ $key ][0] ) );
			}
		}
		return '';
	}

	private function stringify_meta( $meta ) {
		$flat = array();
		foreach ( $meta as $key => $values ) {
			$flat[ $key ] = array_map(
				function ( $value ) {
					return maybe_unserialize( $value );
				},
				(array) $values
			);
		}
		return wp_json_encode( $flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private function stringify_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return wp_strip_all_tags( (string) $value );
	}

	private function collect_images( $html, $image_dir ) {
		$images = array();
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			return $images;
		}

		foreach ( array_unique( $matches[1] ) as $src ) {
			$attachment_id = attachment_url_to_postid( $src );
			$path = $attachment_id ? get_attached_file( $attachment_id ) : $this->path_from_upload_url( $src );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			$target = trailingslashit( $image_dir ) . sanitize_file_name( basename( $path ) );
			if ( ! file_exists( $target ) ) {
				copy( $path, $target );
			}
			$images[ $src ] = $target;
		}
		return $images;
	}

	private function collect_videos( $html ) {
		$videos = array();
		if ( preg_match_all( '~https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|vimeo\.com/)[^\s<"\']+~i', $html, $matches ) ) {
			$videos = array_values( array_unique( $matches[0] ) );
		}
		return $videos;
	}

	private function path_from_upload_url( $url ) {
		$upload = wp_upload_dir();
		if ( 0 !== strpos( $url, $upload['baseurl'] ) ) {
			return '';
		}
		return $upload['basedir'] . str_replace( $upload['baseurl'], '', $url );
	}

	private function zip_directory( $source, $destination ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$source = realpath( $source );
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $files as $file ) {
			$file_path = $file->getRealPath();
			$relative = substr( $file_path, strlen( $source ) + 1 );
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $file_path, $relative );
			}
		}
		$zip->close();
		return true;
	}

	private function record_history( $job, $download_url ) {
		$history = $this->get_history();
		$user = get_userdata( (int) $job['started_by'] );
		array_unshift( $history, array(
			'id'           => $job['id'],
			'date'         => wp_date( 'Y-m-d H:i:s' ),
			'user'         => $user ? $user->display_name : __( 'Utilisateur inconnu', 'logisensei-export-pro' ),
			'courses'      => $job['courses'],
			'size'         => file_exists( $job['zip_path'] ) ? filesize( $job['zip_path'] ) : 0,
			'status'       => empty( $job['errors'] ) ? __( 'Termine', 'logisensei-export-pro' ) : __( 'Termine avec erreurs', 'logisensei-export-pro' ),
			'base_dir'     => $job['base_dir'],
			'zip_path'     => $job['zip_path'],
			'download_url' => $download_url,
		) );
		update_option( self::OPTION_HISTORY, array_slice( $history, 0, 50 ), false );
	}

	private function get_history() {
		$history = get_option( self::OPTION_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	private function get_settings() {
		$defaults = array(
			'folder_name'     => 'exports-logisensei',
			'max_image_width' => 1200,
			'include_quiz'    => true,
			'include_media'   => true,
			'zip_enabled'     => true,
		);
		$settings = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	private function download_url( $job_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=logisensei_download_export&job_id=' . rawurlencode( $job_id ) ),
			'logisensei_download_' . $job_id
		);
	}

	private function is_export_file_path( $path ) {
		$real_path = realpath( $path );
		if ( ! $real_path ) {
			return false;
		}

		$upload = wp_upload_dir();
		$settings = $this->get_settings();
		$export_root = realpath( trailingslashit( $upload['basedir'] ) . $settings['folder_name'] );
		return $export_root && 0 === strpos( $real_path, trailingslashit( $export_root ) ) && 'zip' === strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );
	}

	private function is_export_dir_path( $path ) {
		$real_path = realpath( $path );
		if ( ! $real_path ) {
			return false;
		}

		$upload = wp_upload_dir();
		$settings = $this->get_settings();
		$export_root = realpath( trailingslashit( $upload['basedir'] ) . $settings['folder_name'] );
		return $export_root && $real_path !== $export_root && 0 === strpos( $real_path, trailingslashit( $export_root ) );
	}

	private function delete_directory( $path ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				wp_delete_file( $file->getRealPath() );
			}
		}
		rmdir( $path );
	}

	private function job_key( $job_id ) {
		return 'logisensei_export_' . sanitize_key( $job_id );
	}

	private function safe_name( $name ) {
		$name = sanitize_file_name( remove_accents( $name ) );
		return $name ? $name : 'element';
	}

	private function sensei_detected() {
		return post_type_exists( 'course' ) || function_exists( 'Sensei' ) || class_exists( 'Sensei_Main' );
	}

	private function capability() {
		$capability = apply_filters( 'logisensei_export_capability', 'edit_courses' );
		// Un administrateur garde l'accès même si Sensei ne lui donne pas « edit_courses ».
		if ( ! current_user_can( $capability ) && current_user_can( 'manage_options' ) ) {
			return 'manage_options';
		}
		return $capability;
	}

	private function guard() {
		if ( ! current_user_can( $this->capability() ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'logisensei-export-pro' ) );
		}
	}

	private function ajax_guard() {
		$this->guard();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}
}

final class LogiSensei_Docx_Writer {
	private $media = array();
	private $relationships = array();

	public function create_lesson_doc( $path, $data ) {
		$body = array();
		$body[] = $this->paragraph( $data['course_title'], 'Title' );
		$body[] = $this->paragraph( $data['lesson_title'], 'Heading1' );
		$body[] = $this->paragraph( sprintf( 'Export: %s', $data['export_date'] ), 'Subtitle' );
		$body = array_merge( $body, $this->html_to_word_blocks( $data['html'], $data['images'] ) );

		if ( ! empty( $data['videos'] ) ) {
			$body[] = $this->paragraph( 'Videos', 'Heading2' );
			foreach ( $data['videos'] as $video ) {
				$body[] = $this->paragraph( $video, 'Hyperlink' );
			}
		}

		$this->write_docx( $path, implode( '', $body ) );
	}

	public function create_quiz_doc( $path, $data ) {
		$body = array();
		$body[] = $this->paragraph( $data['course_title'], 'Title' );
		$body[] = $this->paragraph( 'Quiz - ' . $data['lesson_title'], 'Heading1' );
		$body[] = $this->paragraph( sprintf( 'Export: %s', $data['export_date'] ), 'Subtitle' );
		$body[] = $this->table( array( 'Question', 'Type', 'Reponse correcte' ), $data['rows'] );
		$this->write_docx( $path, implode( '', $body ) );
	}

	private function html_to_word_blocks( $html, $images ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->plain_markdown_blocks( $html );
		}

		$document = new DOMDocument( '1.0', 'UTF-8' );
		$wrapped_html = '<?xml encoding="UTF-8"><div id="logisensei-root">' . $html . '</div>';
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $document->getElementById( 'logisensei-root' );
		if ( ! $root ) {
			return $this->plain_markdown_blocks( $html );
		}

		return $this->blocks_from_children( $root, $images );
	}

	private function blocks_from_children( $node, $images, $list_type = '' ) {
		$blocks = array();
		foreach ( $node->childNodes as $child ) {
			$blocks = array_merge( $blocks, $this->blocks_from_node( $child, $images, $list_type ) );
		}
		return $blocks;
	}

	private function blocks_from_node( $node, $images, $list_type = '' ) {
		if ( 3 === $node->nodeType ) {
			$text = trim( $node->textContent );
			return '' === $text ? array() : array( $this->paragraph( $text ) );
		}

		if ( 1 !== $node->nodeType ) {
			return array();
		}

		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, array( 'script', 'style', 'noscript' ), true ) ) {
			return array();
		}

		if ( 'img' === $tag ) {
			$src = $node->getAttribute( 'src' );
			return ! empty( $images[ $src ] ) ? array( $this->image_paragraph( $images[ $src ] ) ) : array();
		}

		if ( in_array( $tag, array( 'h1', 'h2', 'h3' ), true ) ) {
			return array( $this->paragraph_from_dom( $node, 'Heading' . substr( $tag, 1 ) ) );
		}

		if ( 'p' === $tag || 'figcaption' === $tag || 'blockquote' === $tag ) {
			return $this->mixed_paragraph_blocks( $node, $images );
		}

		if ( 'ul' === $tag || 'ol' === $tag ) {
			return $this->blocks_from_children( $node, $images, $tag );
		}

		if ( 'li' === $tag ) {
			$prefix = 'ol' === $list_type ? '1. ' : '- ';
			return array( $this->paragraph_from_dom( $node, '', $prefix ) );
		}

		if ( 'table' === $tag ) {
			return array( $this->table_from_dom( $node ) );
		}

		return $this->blocks_from_children( $node, $images, $list_type );
	}

	private function mixed_paragraph_blocks( $node, $images ) {
		$blocks = array();
		$runs = '';

		foreach ( $node->childNodes as $child ) {
			if ( 1 === $child->nodeType && 'img' === strtolower( $child->nodeName ) ) {
				if ( '' !== trim( wp_strip_all_tags( $runs ) ) || '' !== $runs ) {
					$blocks[] = $this->paragraph_from_runs( $runs );
					$runs = '';
				}
				$src = $child->getAttribute( 'src' );
				if ( ! empty( $images[ $src ] ) ) {
					$blocks[] = $this->image_paragraph( $images[ $src ] );
				}
				continue;
			}
			$runs .= $this->inline_runs_from_node( $child );
		}

		if ( '' !== trim( $node->textContent ) && '' !== $runs ) {
			$blocks[] = $this->paragraph_from_runs( $runs );
		}

		return $blocks;
	}

	private function paragraph_from_dom( $node, $style = '', $prefix = '' ) {
		$runs = $prefix ? $this->run( $prefix ) : '';
		foreach ( $node->childNodes as $child ) {
			if ( 1 === $child->nodeType && 'img' === strtolower( $child->nodeName ) ) {
				continue;
			}
			$runs .= $this->inline_runs_from_node( $child );
		}
		return $this->paragraph_from_runs( $runs, $style );
	}

	private function paragraph_from_runs( $runs, $style = '' ) {
		$style_xml = $style ? '<w:pPr><w:pStyle w:val="' . esc_attr( $style ) . '"/></w:pPr>' : '';
		return '<w:p>' . $style_xml . $runs . '</w:p>';
	}

	private function inline_runs_from_node( $node, $options = array() ) {
		if ( 3 === $node->nodeType ) {
			return $this->inline_runs( html_entity_decode( $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $options );
		}

		if ( 1 !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );
		if ( 'br' === $tag ) {
			return '<w:r><w:br/></w:r>';
		}
		if ( 'img' === $tag ) {
			return '';
		}

		$child_options = $options;
		if ( in_array( $tag, array( 'strong', 'b' ), true ) ) {
			$child_options['bold'] = true;
		}
		if ( in_array( $tag, array( 'em', 'i' ), true ) ) {
			$child_options['italic'] = true;
		}
		if ( 'code' === $tag ) {
			$child_options['code'] = true;
		}
		if ( 'a' === $tag ) {
			$child_options['link'] = true;
		}
		if ( $node->hasAttribute( 'style' ) && preg_match( '/color\s*:\s*#?([0-9a-f]{6})/i', $node->getAttribute( 'style' ), $color ) ) {
			$child_options['color'] = strtoupper( $color[1] );
		}

		$runs = '';
		foreach ( $node->childNodes as $child ) {
			$runs .= $this->inline_runs_from_node( $child, $child_options );
		}

		if ( 'a' === $tag && $node->hasAttribute( 'href' ) ) {
			$runs .= $this->run( ' (' . $node->getAttribute( 'href' ) . ')', array( 'link' => true ) );
		}

		return $runs;
	}

	private function table_from_dom( $table ) {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( 1 === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'th', 'td' ), true ) ) {
					$cells[] = trim( preg_replace( '/\s+/', ' ', $cell->textContent ) );
				}
			}
			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}
		return $this->table_xml( $rows );
	}

	private function plain_markdown_blocks( $html ) {
		$blocks = array();
		$plain = trim( $this->html_inline_to_markdown( $html ) );
		foreach ( preg_split( '/\R+/', $plain ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^###\s+(.+)$/', $line, $heading ) ) {
				$blocks[] = $this->paragraph( $heading[1], 'Heading3' );
			} elseif ( preg_match( '/^##\s+(.+)$/', $line, $heading ) ) {
				$blocks[] = $this->paragraph( $heading[1], 'Heading2' );
			} elseif ( preg_match( '/^#\s+(.+)$/', $line, $heading ) ) {
				$blocks[] = $this->paragraph( $heading[1], 'Heading1' );
			} else {
				$blocks[] = $this->paragraph( $line );
			}
		}
		return $blocks;
	}

	private function write_docx( $path, $body_xml ) {
		$this->relationships = array();
		$media = $this->media;
		$this->media = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return;
		}

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', $this->content_types() );
		$zip->addFromString( '_rels/.rels', $this->rels() );
		$zip->addFromString( 'word/_rels/document.xml.rels', $this->document_rels( $media ) );
		$zip->addFromString( 'word/styles.xml', $this->styles() );
		$zip->addFromString( 'word/document.xml', $this->document( $body_xml ) );
		foreach ( $media as $item ) {
			if ( file_exists( $item['path'] ) ) {
				$zip->addFile( $item['path'], 'word/media/' . $item['name'] );
			}
		}
		$zip->close();
	}

	private function document( $body_xml ) {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>' . $body_xml . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>';
	}

	private function paragraph( $text, $style = '' ) {
		$style_xml = $style ? '<w:pPr><w:pStyle w:val="' . esc_attr( $style ) . '"/></w:pPr>' : '';
		return '<w:p>' . $style_xml . $this->inline_runs( (string) $text ) . '</w:p>';
	}

	private function html_inline_to_markdown( $html ) {
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $html );
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );
		$html = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
			function ( $match ) {
				return '[' . wp_strip_all_tags( $match[2] ) . '](' . esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ')';
			},
			$html
		);
		return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	private function inline_runs( $text, $base_options = array() ) {
		$xml = '';
		$offset = 0;
		$pattern = '/(\*\*|__)(.+?)\1|(\*|_)(.+?)\3|`([^`]+)`|\[([^\]]+)\]\(([^)]+)\)/u';

		if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $full ) {
				$start = $full[1];
				if ( $start > $offset ) {
					$xml .= $this->run( substr( $text, $offset, $start - $offset ), $base_options );
				}

				if ( '' !== $matches[2][ $index ][0] ) {
					$xml .= $this->run( $matches[2][ $index ][0], array_merge( $base_options, array( 'bold' => true ) ) );
				} elseif ( '' !== $matches[4][ $index ][0] ) {
					$xml .= $this->run( $matches[4][ $index ][0], array_merge( $base_options, array( 'italic' => true ) ) );
				} elseif ( '' !== $matches[5][ $index ][0] ) {
					$xml .= $this->run( $matches[5][ $index ][0], array_merge( $base_options, array( 'code' => true ) ) );
				} elseif ( '' !== $matches[6][ $index ][0] ) {
					$label = $matches[6][ $index ][0];
					$url = $matches[7][ $index ][0];
					$xml .= $this->run( $label, array_merge( $base_options, array( 'link' => true ) ) );
					$xml .= $this->run( ' (' . $url . ')', array_merge( $base_options, array( 'link' => true ) ) );
				}

				$offset = $start + strlen( $full[0] );
			}
		}

		if ( $offset < strlen( $text ) ) {
			$xml .= $this->run( substr( $text, $offset ), $base_options );
		}

		return $xml ?: $this->run( $text, $base_options );
	}

	private function run( $text, $options = array() ) {
		if ( '' === $text ) {
			return '';
		}

		$props = '';
		if ( ! empty( $options['bold'] ) ) {
			$props .= '<w:b/>';
		}
		if ( ! empty( $options['italic'] ) ) {
			$props .= '<w:i/>';
		}
		if ( ! empty( $options['link'] ) ) {
			$props .= '<w:color w:val="0563C1"/><w:u w:val="single"/>';
		}
		if ( ! empty( $options['color'] ) ) {
			$props .= '<w:color w:val="' . esc_attr( $options['color'] ) . '"/>';
		}
		if ( ! empty( $options['code'] ) ) {
			$props .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:color w:val="333333"/>';
		}
		$props = $props ? '<w:rPr>' . $props . '</w:rPr>' : '';

		return '<w:r>' . $props . '<w:t xml:space="preserve">' . esc_html( $this->xml_text( $text ) ) . '</w:t></w:r>';
	}

	private function xml_text( $text ) {
		return preg_replace( '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $text );
	}

	private function image_paragraph( $path ) {
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$info = getimagesize( $path );
		if ( empty( $info[0] ) || empty( $info[1] ) ) {
			return $this->paragraph( 'Image: ' . basename( $path ) );
		}

		$rid = 'rIdImage' . ( count( $this->media ) + 1 );
		$name = ( count( $this->media ) + 1 ) . '-' . sanitize_file_name( basename( $path ) );
		$max_width_px = 620;
		$ratio = min( 1, $max_width_px / (int) $info[0] );
		$width_emu = (int) round( (int) $info[0] * $ratio * 9525 );
		$height_emu = (int) round( (int) $info[1] * $ratio * 9525 );

		$this->media[] = array(
			'id'   => $rid,
			'name' => $name,
			'path' => $path,
		);

		return '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . esc_attr( $width_emu ) . '" cy="' . esc_attr( $height_emu ) . '"/><wp:docPr id="' . esc_attr( count( $this->media ) ) . '" name="' . esc_attr( $name ) . '"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="' . esc_attr( $name ) . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . esc_attr( $rid ) . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . esc_attr( $width_emu ) . '" cy="' . esc_attr( $height_emu ) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}

	private function table( $headers, $rows ) {
		$table_rows = array( $headers );
		foreach ( $rows as $row ) {
			$table_rows[] = array( $row['question'], $row['type'], $row['answer'] );
		}
		return $this->table_xml( $table_rows );
	}

	private function table_xml( $rows ) {
		$xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>';
		foreach ( $rows as $row ) {
			$xml .= $this->table_row( $row );
		}
		return $xml . '</w:tbl>';
	}

	private function table_row( $cells ) {
		$xml = '<w:tr>';
		foreach ( $cells as $cell ) {
			$xml .= '<w:tc><w:tcPr><w:tcW w:w="3200" w:type="dxa"/></w:tcPr>' . $this->paragraph( $cell ) . '</w:tc>';
		}
		return $xml . '</w:tr>';
	}

	private function content_types() {
		return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/><Default Extension="gif" ContentType="image/gif"/><Default Extension="webp" ContentType="image/webp"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>';
	}

	private function rels() {
		return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
	}

	private function document_rels( $media ) {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $media as $item ) {
			$xml .= '<Relationship Id="' . esc_attr( $item['id'] ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . esc_attr( $item['name'] ) . '"/>';
		}
		return $xml . '</Relationships>';
	}

	private function styles() {
		return '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:rPr><w:i/><w:color w:val="666666"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style></w:styles>';
	}
}

final class LogiSensei_Xlsx_Writer {
	private $headers = array(
		'Ordre',
		'ID quiz',
		'Nom du quiz',
		'Titre quiz Sensei',
		'ID question',
		'Titre question',
		'Enonce / contenu',
		'Type',
		'Reponses / propositions',
		'Reponse correcte',
		'Proposition erronee 1',
		'Proposition erronee 2',
		'Proposition erronee 3',
		'Feedback / explication',
		'Points',
		'Statut',
		'Meta Sensei complete',
	);

	public function create_quiz_workbook( $path, $data ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return;
		}

		$rows = array();
		$rows[] = array( 'Cours', $data['course_title'] );
		$rows[] = array( 'Lecon', $data['lesson_title'] );
		$rows[] = array( 'Date export', $data['export_date'] );
		$rows[] = array();
		$rows[] = $this->headers;

		foreach ( $data['rows'] as $row ) {
			$rows[] = array(
				$row['order'],
				$row['quiz_id'],
				$row['quiz_title'],
				$row['sensei_quiz_title'],
				$row['question_id'],
				$row['question_title'],
				$row['question_content'],
				$row['type'],
				$row['choices'],
				$row['answer'],
				$row['wrong_1'],
				$row['wrong_2'],
				$row['wrong_3'],
				$row['feedback'],
				$row['points'],
				$row['status'],
				$row['meta'],
			);
		}

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', $this->content_types() );
		$zip->addFromString( '_rels/.rels', $this->rels() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels() );
		$zip->addFromString( 'xl/worksheets/_rels/sheet1.xml.rels', $this->worksheet_rels() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook( $data['lesson_title'] ) );
		$zip->addFromString( 'xl/styles.xml', $this->styles() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $this->worksheet( $rows ) );
		$zip->addFromString( 'xl/tables/table1.xml', $this->table_definition( max( 5, count( $rows ) ) ) );
		$zip->close();
	}

	private function worksheet( $rows ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. '<cols>'
			. '<col min="1" max="1" width="8" customWidth="1"/>'
			. '<col min="2" max="4" width="12" customWidth="1"/>'
			. '<col min="5" max="13" width="34" customWidth="1"/>'
			. '<col min="14" max="15" width="12" customWidth="1"/>'
			. '<col min="16" max="16" width="60" customWidth="1"/>'
			. '</cols><sheetData>';

		foreach ( $rows as $row_index => $row ) {
			$excel_row = $row_index + 1;
			$xml .= '<row r="' . esc_attr( $excel_row ) . '">';
			foreach ( $row as $column_index => $value ) {
				$style = 5 === $excel_row ? 1 : 0;
				if ( $excel_row <= 3 && 0 === $column_index ) {
					$style = 1;
				}
				$xml .= $this->cell( $column_index + 1, $excel_row, $value, $style );
			}
			$xml .= '</row>';
		}

		$last_row = max( 5, count( $rows ) );
		$last_column = $this->column_name( count( $this->headers ) );
		$xml .= '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
			. '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
			. '</worksheet>';

		return $xml;
	}

	private function cell( $column, $row, $value, $style = 0 ) {
		$ref = $this->column_name( $column ) . $row;
		$value = $this->xml_text( (string) $value );
		$style_xml = $style ? ' s="' . esc_attr( $style ) . '"' : '';

		if ( is_numeric( $value ) && '' !== $value && false === strpos( $value, "\n" ) ) {
			return '<c r="' . esc_attr( $ref ) . '"' . $style_xml . '><v>' . esc_html( $value ) . '</v></c>';
		}

		return '<c r="' . esc_attr( $ref ) . '" t="inlineStr"' . $style_xml . '><is><t xml:space="preserve">' . esc_html( $value ) . '</t></is></c>';
	}

	private function xml_text( $value ) {
		$value = preg_replace( '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value );
		if ( strlen( $value ) > 32000 ) {
			$value = substr( $value, 0, 32000 ) . '...';
		}
		return $value;
	}

	private function column_name( $index ) {
		$name = '';
		while ( $index > 0 ) {
			$index--;
			$name = chr( 65 + ( $index % 26 ) ) . $name;
			$index = (int) floor( $index / 26 );
		}
		return $name;
	}

	private function content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/></Types>';
	}

	private function rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
	}

	private function workbook_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
	}

	private function worksheet_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/></Relationships>';
	}

	private function table_definition( $last_row ) {
		$last_column = $this->column_name( count( $this->headers ) );
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="QuizSensei" displayName="QuizSensei" ref="A5:' . esc_attr( $last_column . $last_row ) . '" totalsRowShown="0">'
			. '<autoFilter ref="A5:' . esc_attr( $last_column . $last_row ) . '"/>'
			. '<tableColumns count="' . esc_attr( count( $this->headers ) ) . '">';
		foreach ( $this->headers as $index => $header ) {
			$xml .= '<tableColumn id="' . esc_attr( $index + 1 ) . '" name="' . esc_attr( $header ) . '"/>';
		}
		return $xml . '</tableColumns><tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>';
	}

	private function workbook( $sheet_name ) {
		$sheet_name = $this->sheet_name( $sheet_name );
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . esc_attr( $sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
	}

	private function sheet_name( $name ) {
		$name = preg_replace( '/[\[\]\:\*\?\/\\\\]/', ' ', wp_strip_all_tags( (string) $name ) );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		if ( '' === $name ) {
			$name = 'Quiz';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 );
	}

	private function styles() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E2EC"/></left><right style="thin"><color rgb="FFD9E2EC"/></right><top style="thin"><color rgb="FFD9E2EC"/></top><bottom style="thin"><color rgb="FFD9E2EC"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}
}

LogiSensei_Export_Pro::init();

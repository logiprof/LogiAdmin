<?php
/**
 * Module LogiAdmin : Logiprof Unused Media Finder
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Ajoute une page dans Medias pour visualiser les images et fichiers probablement inutilises.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Menu parent : fourni par LogiAdmin (logiprof_admin_menu_parent_slug). */

final class Logiprof_Unused_Media_Finder {
	const VERSION       = '1.0.0';
	const SLUG          = 'logiprof-unused-media-finder';
	const NONCE         = 'logiprof_umf_nonce';
	const TRANSIENT_KEY = 'logiprof_umf_report';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_media_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_logiprof_umf_rescan', array( __CLASS__, 'handle_rescan' ) );
		add_action( 'admin_post_logiprof_umf_export_csv', array( __CLASS__, 'handle_export_csv' ) );
	}

	public static function register_media_page() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Médias inutilisés', 'logiprof-unused-media-finder' ),
			__( 'Médias inutilisés', 'logiprof-unused-media-finder' ),
			'upload_files',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( self::SLUG, plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-unused-media-finder' ) );
		}

		$report  = self::get_report();
		$filters = self::get_filters();
		$items   = self::filter_items( $report['unused'], $filters );
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="logiprof_umf_rescan">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Relancer l’analyse', 'logiprof-unused-media-finder' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="logiprof_umf_export_csv">
			<button type="submit" class="button"><?php esc_html_e( 'Exporter en CSV', 'logiprof-unused-media-finder' ); ?></button>
		</form>
		<?php
		$actions = ob_get_clean();

		LogiAdmin::page_open( 'medias-inutilises', array( 'class' => 'logiprof-umf', 'actions' => $actions ) );
		if ( ! empty( $_GET['rescanned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analyse relancée : la liste est à jour.', 'logiprof-unused-media-finder' ) . '</p></div>';
		}
		?>
		<div class="lad-hint">
			<?php esc_html_e( 'Sont vérifiés : contenus, extraits, images mises en avant, menus, widgets, réglages et métadonnées. Un fichier peut tout de même servir ailleurs (feuille de style, constructeur de page, code sur mesure) : vérifiez-le avant de le supprimer. Rien n’est supprimé automatiquement.', 'logiprof-unused-media-finder' ); ?>
		</div>

		<?php self::render_summary( $report ); ?>
		<?php self::render_filters( $filters ); ?>
		<?php self::render_table( $items ); ?>
		<?php
		LogiAdmin::page_close();
	}

	private static function render_summary( $report ) {
		?>
		<div class="lad-stats logiprof-umf-summary">
			<div>
				<strong><?php echo esc_html( number_format_i18n( $report['total'] ) ); ?></strong>
				<span><?php esc_html_e( 'fichiers analysés', 'logiprof-unused-media-finder' ); ?></span>
			</div>
			<div>
				<strong><?php echo esc_html( number_format_i18n( $report['used_count'] ) ); ?></strong>
				<span><?php esc_html_e( 'fichiers utilisés', 'logiprof-unused-media-finder' ); ?></span>
			</div>
			<div>
				<strong><?php echo esc_html( number_format_i18n( count( $report['unused'] ) ) ); ?></strong>
				<span><?php esc_html_e( 'fichiers probablement inutilisés', 'logiprof-unused-media-finder' ); ?></span>
			</div>
			<div>
				<strong><?php echo esc_html( mysql2date( 'j M Y, H:i', $report['generated_at'] ) ); ?></strong>
				<span><?php esc_html_e( 'dernière analyse', 'logiprof-unused-media-finder' ); ?></span>
			</div>
		</div>
		<?php
	}

	private static function render_filters( $filters ) {
		?>
		<form method="get" class="lad-toolbar logiprof-umf-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Type', 'logiprof-unused-media-finder' ); ?></span>
				<select name="media_type">
					<option value=""><?php esc_html_e( 'Tous', 'logiprof-unused-media-finder' ); ?></option>
					<option value="image" <?php selected( $filters['media_type'], 'image' ); ?>><?php esc_html_e( 'Images', 'logiprof-unused-media-finder' ); ?></option>
					<option value="file" <?php selected( $filters['media_type'], 'file' ); ?>><?php esc_html_e( 'Fichiers', 'logiprof-unused-media-finder' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Recherche', 'logiprof-unused-media-finder' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Nom, URL ou type MIME', 'logiprof-unused-media-finder' ); ?>">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'logiprof-unused-media-finder' ); ?></button>
			<?php if ( '' !== $filters['media_type'] || '' !== $filters['s'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Effacer les filtres', 'logiprof-unused-media-finder' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	private static function render_table( $items ) {
		?>
		<p class="lad-count"><?php echo esc_html( sprintf( _n( '%s fichier affiché', '%s fichiers affichés', count( $items ), 'logiprof-unused-media-finder' ), number_format_i18n( count( $items ) ) ) ); ?></p>
		<table class="widefat striped logiprof-umf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Apercu', 'logiprof-unused-media-finder' ); ?></th>
					<th><?php esc_html_e( 'Fichier', 'logiprof-unused-media-finder' ); ?></th>
					<th><?php esc_html_e( 'Type', 'logiprof-unused-media-finder' ); ?></th>
					<th><?php esc_html_e( 'Date', 'logiprof-unused-media-finder' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'logiprof-unused-media-finder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr>
						<td colspan="5" class="lad-empty"><?php esc_html_e( 'Aucun fichier ne correspond à ces critères.', 'logiprof-unused-media-finder' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td class="logiprof-umf-thumb"><?php echo wp_kses_post( self::get_preview_html( $item ) ); ?></td>
							<td>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<br>
								<code><?php echo esc_html( $item['file_name'] ); ?></code>
								<br>
								<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a>
							</td>
							<td><?php echo esc_html( $item['mime_type'] ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $item['date'] ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php esc_html_e( 'Modifier', 'logiprof-unused-media-finder' ); ?></a>
								<a class="button button-small lad-danger" href="<?php echo esc_url( get_delete_post_link( $item['id'] ) ); ?>" data-logiadmin-confirm="<?php echo esc_attr( sprintf( __( 'Supprimer « %s » de la médiathèque ? Vérifiez d’abord qu’il ne sert nulle part.', 'logiprof-unused-media-finder' ), $item['title'] ) ); ?>"><?php esc_html_e( 'Supprimer', 'logiprof-unused-media-finder' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function get_preview_html( $item ) {
		if ( 0 === strpos( $item['mime_type'], 'image/' ) ) {
			return wp_get_attachment_image( $item['id'], array( 72, 72 ), true );
		}

		return '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>';
	}

	public static function handle_rescan() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-unused-media-finder' ) );
		}

		check_admin_referer( self::NONCE );
		delete_transient( self::TRANSIENT_KEY );
		self::get_report();

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'rescanned' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_export_csv() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-unused-media-finder' ) );
		}

		check_admin_referer( self::NONCE );
		$report = self::get_report();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=medias-inutilises-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'ID', 'Titre', 'Fichier', 'URL', 'Type MIME', 'Date' ) );

		foreach ( $report['unused'] as $item ) {
			fputcsv( $output, array( $item['id'], $item['title'], $item['file_name'], $item['url'], $item['mime_type'], $item['date'] ) );
		}

		fclose( $output );
		exit;
	}

	private static function get_filters() {
		return array(
			'media_type' => isset( $_GET['media_type'] ) ? sanitize_key( wp_unslash( $_GET['media_type'] ) ) : '',
			's'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
	}

	private static function filter_items( $items, $filters ) {
		return array_values(
			array_filter(
				$items,
				function ( $item ) use ( $filters ) {
					if ( 'image' === $filters['media_type'] && 0 !== strpos( $item['mime_type'], 'image/' ) ) {
						return false;
					}

					if ( 'file' === $filters['media_type'] && 0 === strpos( $item['mime_type'], 'image/' ) ) {
						return false;
					}

					if ( '' !== $filters['s'] ) {
						$haystack = strtolower( $item['title'] . ' ' . $item['file_name'] . ' ' . $item['url'] . ' ' . $item['mime_type'] );
						return false !== strpos( $haystack, strtolower( $filters['s'] ) );
					}

					return true;
				}
			)
		);
	}

	private static function get_report() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$used_ids     = self::find_used_attachment_ids();
		$used_strings = self::collect_reference_text();
		$unused       = array();

		foreach ( $attachments as $attachment ) {
			$item = self::format_attachment( $attachment );
			if ( self::is_attachment_used( $item, $used_ids, $used_strings ) ) {
				continue;
			}
			$unused[] = $item;
		}

		$report = array(
			'total'        => count( $attachments ),
			'used_count'   => count( $attachments ) - count( $unused ),
			'unused'       => $unused,
			'generated_at' => current_time( 'mysql' ),
		);

		set_transient( self::TRANSIENT_KEY, $report, 6 * HOUR_IN_SECONDS );

		return $report;
	}

	private static function format_attachment( WP_Post $attachment ) {
		$url       = wp_get_attachment_url( $attachment->ID );
		$file_path = get_attached_file( $attachment->ID );

		return array(
			'id'        => (int) $attachment->ID,
			'title'     => get_the_title( $attachment ),
			'url'       => $url ? $url : '',
			'file_name' => $file_path ? wp_basename( $file_path ) : wp_basename( $url ),
			'mime_type' => get_post_mime_type( $attachment ),
			'date'      => $attachment->post_date,
		);
	}

	private static function is_attachment_used( $item, $used_ids, $used_strings ) {
		if ( in_array( $item['id'], $used_ids, true ) ) {
			return true;
		}

		$candidates = array_filter(
			array(
				$item['url'],
				wp_basename( $item['url'] ),
				$item['file_name'],
				self::url_without_size_suffix( $item['url'] ),
			)
		);

		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && false !== strpos( $used_strings, strtolower( $candidate ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function find_used_attachment_ids() {
		global $wpdb;

		$ids = array();

		$thumbnail_ids = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value REGEXP '^[0-9]+$'"
		);

		$ids = array_merge( $ids, array_map( 'intval', $thumbnail_ids ) );

		$shortcode_ids = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_product_image_gallery', 'gallery_image_ids')"
		);

		foreach ( $shortcode_ids as $value ) {
			$ids = array_merge( $ids, self::extract_ids_from_text( $value ) );
		}

		$content_rows = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE '%wp-image-%'"
		);

		foreach ( $content_rows as $content ) {
			if ( preg_match_all( '/wp-image-([0-9]+)/', $content, $matches ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function collect_reference_text() {
		global $wpdb;

		$chunks = array();

		$post_columns = $wpdb->get_results(
			"SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft')",
			ARRAY_A
		);
		foreach ( $post_columns as $row ) {
			$chunks[] = $row['post_content'];
			$chunks[] = $row['post_excerpt'];
		}

		// Les métadonnées que WordPress range sur chaque fichier (chemin, tailles, texte alternatif)
		// citent forcément ce fichier : elles ne prouvent pas qu'il est utilisé et sont donc ignorées,
		// de même que le rapport de cet outil gardé en cache.
		$meta_tables = array(
			$wpdb->postmeta => array( 'meta_value', "meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_image_alt', '_wp_attachment_context')" ),
			$wpdb->usermeta => array( 'meta_value', '1=1' ),
			$wpdb->termmeta => array( 'meta_value', '1=1' ),
			$wpdb->options  => array( 'option_value', "option_name NOT IN ('_transient_" . self::TRANSIENT_KEY . "', '_transient_timeout_" . self::TRANSIENT_KEY . "')" ),
		);

		foreach ( $meta_tables as $table => $def ) {
			list( $column, $exclude ) = $def;
			$values = $wpdb->get_col(
				"SELECT {$column} FROM {$table} WHERE {$exclude} AND ({$column} LIKE '%/uploads/%' OR {$column} LIKE '%.jpg%' OR {$column} LIKE '%.jpeg%' OR {$column} LIKE '%.png%' OR {$column} LIKE '%.gif%' OR {$column} LIKE '%.webp%' OR {$column} LIKE '%.pdf%' OR {$column} LIKE '%.doc%' OR {$column} LIKE '%.xls%' OR {$column} LIKE '%.ppt%')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$chunks = array_merge( $chunks, $values );
		}

		return strtolower( implode( "\n", array_filter( $chunks ) ) );
	}

	private static function extract_ids_from_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}

		preg_match_all( '/[0-9]+/', $text, $matches );
		return array_map( 'intval', $matches[0] );
	}

	private static function url_without_size_suffix( $url ) {
		return preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
	}
}

Logiprof_Unused_Media_Finder::init();

<?php
/**
 * Module LogiAdmin : Sensei Shortcode
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Genere des shortcodes Sensei LMS selon des criteres choisis : cours commences, cours termines, lecons et quiz a reprendre.
 * Version: 1.3.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Menu parent : fourni par LogiAdmin (logiprof_admin_menu_parent_slug). */

final class Logiprof_Sensei_Smart_Shortcodes {
	const VERSION   = '1.3.9';
	const SHORTCODE = 'logiprof_sensei';
	const COURSE_PROFILE_ROLES_META = '_logiprof_allowed_profile_roles';

	private static $assets_loaded = false;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_front_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_sensei_notice' ) );
		add_action( 'add_meta_boxes_course', array( __CLASS__, 'register_course_profile_metabox' ) );
		add_action( 'save_post_course', array( __CLASS__, 'save_course_profile_metabox' ) );
	}

	public static function register_admin_page() {
		logiprof_register_admin_menu_parent();
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Shortcodes Sensei', 'logiprof-sensei-smart-shortcodes' ),
			__( 'Shortcodes Sensei', 'logiprof-sensei-smart-shortcodes' ),
			'manage_options',
			'logiprof-sensei-shortcodes',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function register_course_profile_metabox() {
		add_meta_box(
			'logiprof-course-profile-roles',
			__( 'Profils Logiprof autorises', 'logiprof-sensei-smart-shortcodes' ),
			array( __CLASS__, 'render_course_profile_metabox' ),
			'course',
			'side',
			'default'
		);
	}

	public static function render_course_profile_metabox( $post ) {
		wp_nonce_field( 'logiprof_course_profile_roles', 'logiprof_course_profile_roles_nonce' );

		$selected_roles = get_post_meta( $post->ID, self::COURSE_PROFILE_ROLES_META, true );
		$selected_roles = is_array( $selected_roles ) ? $selected_roles : array();
		$roles          = wp_roles()->roles;

		echo '<p>' . esc_html__( 'Cochez les profils qui voient ce cours dans les listes « Cours par catégorie et profil ».', 'logiprof-sensei-smart-shortcodes' ) . '</p>';

		foreach ( $roles as $role_key => $role ) {
			echo '<label style="display:block;margin:0 0 6px;">';
			echo '<input type="checkbox" name="logiprof_profile_roles[]" value="' . esc_attr( $role_key ) . '" ' . checked( in_array( $role_key, $selected_roles, true ), true, false ) . '> ';
			echo esc_html( translate_user_role( $role['name'] ) );
			echo '</label>';
		}
	}

	public static function save_course_profile_metabox( $post_id ) {
		if ( ! isset( $_POST['logiprof_course_profile_roles_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['logiprof_course_profile_roles_nonce'] ) ), 'logiprof_course_profile_roles' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$roles = isset( $_POST['logiprof_profile_roles'] ) ? (array) wp_unslash( $_POST['logiprof_profile_roles'] ) : array();
		$roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );

		if ( empty( $roles ) ) {
			delete_post_meta( $post_id, self::COURSE_PROFILE_ROLES_META );
			return;
		}

		update_post_meta( $post_id, self::COURSE_PROFILE_ROLES_META, $roles );
	}

	public static function maybe_show_sensei_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || self::is_sensei_available() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::is_admin_generator_screen( $screen->id ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Sensei LMS ne semble pas actif. Les listes de cours publiés fonctionnent quand même, mais la progression des élèves demande Sensei.', 'logiprof-sensei-smart-shortcodes' ) . '</p></div>';
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( ! self::is_admin_generator_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'logiprof-sensei-shortcodes-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'logiprof-sensei-shortcodes-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
	}

	private static function is_admin_generator_screen( $screen_id ) {
		$screen_id = (string) $screen_id;
		if ( false !== strpos( $screen_id, 'logiprof-sensei-shortcodes' ) ) {
			return true;
		}

		return in_array(
			$screen_id,
			array(
				'logiprof_page_logiprof-sensei-shortcodes',
				'logiprof-tools_page_logiprof-sensei-shortcodes',
				'tools-logiprof_page_logiprof-sensei-shortcodes',
				'toplevel_page_logiprof-sensei-shortcodes',
			),
			true
		);
	}

	public static function register_front_assets() {
		wp_register_style(
			'logiprof-sensei-shortcodes',
			plugins_url( 'assets/frontend.css', __FILE__ ),
			array(),
			self::VERSION
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$course_categories = get_terms(
			array(
				'taxonomy'   => 'course-category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$course_categories = is_wp_error( $course_categories ) ? array() : $course_categories;
		$courses           = get_posts(
			array(
				'post_type'      => 'course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$groups            = post_type_exists( 'group' ) ? get_posts(
			array(
				'post_type'      => 'group',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) : array();

		$types = array(
			__( 'Progression de l’élève connecté', 'logiprof-sensei-smart-shortcodes' ) => array(
				'started_courses'   => array( __( 'Derniers cours commencés', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours que l’élève a ouverts, du plus récent au plus ancien.', 'logiprof-sensei-smart-shortcodes' ) ),
				'active_courses'    => array( __( 'Cours en cours', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours commencés et pas encore terminés.', 'logiprof-sensei-smart-shortcodes' ) ),
				'completed_courses' => array( __( 'Cours terminés', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours que l’élève a terminés.', 'logiprof-sensei-smart-shortcodes' ) ),
				'lessons_to_finish' => array( __( 'Leçons à terminer', 'logiprof-sensei-smart-shortcodes' ), __( 'Les leçons commencées ou échouées.', 'logiprof-sensei-smart-shortcodes' ) ),
				'quizzes_to_finish' => array( __( 'Quiz à terminer ou refaire', 'logiprof-sensei-smart-shortcodes' ), __( 'Seulement les vrais quiz (avec questions) des leçons déjà ouvertes, non réussis.', 'logiprof-sensei-smart-shortcodes' ) ),
				'next_lessons'      => array( __( 'Prochaines leçons à suivre', 'logiprof-sensei-smart-shortcodes' ), __( 'La prochaine leçon non terminée de chaque cours en cours.', 'logiprof-sensei-smart-shortcodes' ) ),
			),
			__( 'Catalogue de cours', 'logiprof-sensei-smart-shortcodes' )        => array(
				'category_courses'         => array( __( 'Cours d’une ou plusieurs catégories', 'logiprof-sensei-smart-shortcodes' ), __( 'Tous les cours publiés des catégories cochées.', 'logiprof-sensei-smart-shortcodes' ) ),
				'latest_courses'           => array( __( 'Derniers cours publiés', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours les plus récents du site.', 'logiprof-sensei-smart-shortcodes' ) ),
				'featured_courses'         => array( __( 'Cours mis en avant', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours marqués « mis en avant » dans Sensei.', 'logiprof-sensei-smart-shortcodes' ) ),
				'profile_category_courses' => array( __( 'Cours par catégorie et profil', 'logiprof-sensei-smart-shortcodes' ), __( 'Les cours dont le bloc « Profils Logiprof autorisés » contient le rôle de l’élève.', 'logiprof-sensei-smart-shortcodes' ) ),
			),
			__( 'Suivi enseignant', 'logiprof-sensei-smart-shortcodes' )          => array(
				'group_course_progress' => array( __( 'Tableau de progression d’un groupe', 'logiprof-sensei-smart-shortcodes' ), __( 'Un tableau élèves × leçons pour un cours et un groupe Sensei Pro. Visible seulement par les enseignants et administrateurs.', 'logiprof-sensei-smart-shortcodes' ) ),
			),
		);
		$all_but_group = 'started_courses active_courses completed_courses lessons_to_finish quizzes_to_finish next_lessons category_courses latest_courses featured_courses profile_category_courses';
		$lists         = $all_but_group;

		LogiAdmin::page_open( 'sensei-shortcodes', array( 'class' => 'logiprof-sensei-generator' ) );
		?>
		<div class="lad-split">
			<div class="lad-split-main">
				<section class="lad-card">
					<h2><span class="lad-step">1</span><?php esc_html_e( 'Que faut-il afficher ?', 'logiprof-sensei-smart-shortcodes' ); ?></h2>
					<label class="lad-field" for="lssc-type">
						<span class="lad-label"><?php esc_html_e( 'Contenu', 'logiprof-sensei-smart-shortcodes' ); ?></span>
						<select id="lssc-type" data-shortcode-field="type">
							<?php foreach ( $types as $group_label => $group_types ) : ?>
								<optgroup label="<?php echo esc_attr( $group_label ); ?>">
									<?php foreach ( $group_types as $value => $type ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" data-help="<?php echo esc_attr( $type[1] ); ?>" <?php selected( 'category_courses', $value ); ?>><?php echo esc_html( $type[0] ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<span class="description" id="lssc-type-help"></span>
					</label>
				</section>

				<section class="lad-card">
					<h2><span class="lad-step">2</span><?php esc_html_e( 'Choisir les cours', 'logiprof-sensei-smart-shortcodes' ); ?></h2>

					<div class="lad-field" data-lssc-show="<?php echo esc_attr( $all_but_group ); ?>">
						<span class="lad-label" id="lssc-category-label"><?php esc_html_e( 'Catégories de cours', 'logiprof-sensei-smart-shortcodes' ); ?></span>
						<input id="lssc-category" type="hidden" value="" data-shortcode-field="category">
						<div class="lssc-category-checkboxes lad-checklist" role="group" aria-labelledby="lssc-category-label">
							<?php if ( empty( $course_categories ) ) : ?>
								<p class="description"><?php esc_html_e( 'Aucune catégorie Sensei trouvée.', 'logiprof-sensei-smart-shortcodes' ); ?></p>
							<?php endif; ?>
							<?php self::render_course_category_checkboxes( $course_categories ); ?>
						</div>
						<span class="description"><?php esc_html_e( 'Aucune case cochée = toutes les catégories.', 'logiprof-sensei-smart-shortcodes' ); ?></span>
					</div>

					<div class="lad-field-row" data-lssc-show="profile_category_courses">
						<label class="lad-field" for="lssc-profile">
							<span class="lad-label"><?php esc_html_e( 'Profil (rôle WordPress)', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<select id="lssc-profile" data-shortcode-field="profile">
								<option value=""><?php esc_html_e( 'Rôle de la personne connectée', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<?php foreach ( wp_roles()->roles as $role_key => $role ) : ?>
									<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="lad-check">
							<input type="checkbox" data-shortcode-field="include_unassigned" data-checked-value="yes" data-unchecked-value="no">
							<?php esc_html_e( 'Inclure aussi les cours sans profil attribué', 'logiprof-sensei-smart-shortcodes' ); ?>
						</label>
					</div>

					<div class="lad-field-row" data-lssc-show="group_course_progress">
						<label class="lad-field" for="lssc-group-id">
							<span class="lad-label"><?php esc_html_e( 'Groupe Sensei', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<?php if ( $groups ) : ?>
								<select id="lssc-group-id" data-shortcode-field="group_id">
									<option value=""><?php esc_html_e( 'Choisir un groupe', 'logiprof-sensei-smart-shortcodes' ); ?></option>
									<?php foreach ( $groups as $group ) : ?>
										<option value="<?php echo esc_attr( $group->ID ); ?>"><?php echo esc_html( $group->post_title . ' (#' . $group->ID . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input id="lssc-group-id" type="number" min="0" value="" data-shortcode-field="group_id" placeholder="<?php esc_attr_e( 'ID du groupe', 'logiprof-sensei-smart-shortcodes' ); ?>">
							<?php endif; ?>
						</label>
						<label class="lad-field" for="lssc-course-id">
							<span class="lad-label"><?php esc_html_e( 'Cours', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<select id="lssc-course-id" data-shortcode-field="course_id">
								<option value=""><?php esc_html_e( 'Choisir un cours', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<?php foreach ( $courses as $course ) : ?>
									<option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( get_the_title( $course ) . ' (#' . $course->ID . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div class="lad-field-row" data-lssc-show="<?php echo esc_attr( $lists ); ?>">
						<label class="lad-field lad-field--short" for="lssc-limit">
							<span class="lad-label"><?php esc_html_e( 'Nombre maximum', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<input id="lssc-limit" type="number" min="1" max="24" value="" placeholder="<?php esc_attr_e( 'Tous', 'logiprof-sensei-smart-shortcodes' ); ?>" data-shortcode-field="limit">
						</label>
						<label class="lad-field" for="lssc-orderby">
							<span class="lad-label"><?php esc_html_e( 'Trier par', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<select id="lssc-orderby" data-shortcode-field="orderby">
								<option value="activity"><?php esc_html_e( 'Activité la plus récente', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<option value="date"><?php esc_html_e( 'Date de publication', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<option value="title" selected><?php esc_html_e( 'Titre', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<option value="menu_order"><?php esc_html_e( 'Ordre personnalisé', 'logiprof-sensei-smart-shortcodes' ); ?></option>
							</select>
						</label>
						<label class="lad-field" for="lssc-order">
							<span class="lad-label"><?php esc_html_e( 'Sens', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<select id="lssc-order" data-shortcode-field="order">
								<option value="asc" selected><?php esc_html_e( 'A → Z / plus ancien d’abord', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<option value="desc"><?php esc_html_e( 'Z → A / plus récent d’abord', 'logiprof-sensei-smart-shortcodes' ); ?></option>
							</select>
						</label>
					</div>
				</section>

				<section class="lad-card">
					<h2><span class="lad-step">3</span><?php esc_html_e( 'Régler l’affichage', 'logiprof-sensei-smart-shortcodes' ); ?></h2>
					<div class="lad-checks" data-lssc-show="<?php echo esc_attr( $lists ); ?>">
						<label class="lad-check"><input type="checkbox" checked data-shortcode-field="show_progress" data-checked-value="yes" data-unchecked-value="no"> <?php esc_html_e( 'Barre de progression', 'logiprof-sensei-smart-shortcodes' ); ?></label>
						<label class="lad-check"><input type="checkbox" checked data-shortcode-field="show_excerpt" data-checked-value="yes" data-unchecked-value="no"> <?php esc_html_e( 'Extrait', 'logiprof-sensei-smart-shortcodes' ); ?></label>
						<label class="lad-check"><input type="checkbox" checked data-shortcode-field="show_image" data-checked-value="yes" data-unchecked-value="no"> <?php esc_html_e( 'Image d’en-tête du module', 'logiprof-sensei-smart-shortcodes' ); ?></label>
						<label class="lad-check"><input type="checkbox" id="lssc-show-filters" data-shortcode-field="show_filters" data-checked-value="yes" data-unchecked-value="no"> <?php esc_html_e( 'Filtre « Nombre » modifiable par l’élève', 'logiprof-sensei-smart-shortcodes' ); ?></label>
					</div>
					<label class="lad-field" for="lssc-filter-id" data-lssc-show-if="lssc-show-filters">
						<span class="lad-label"><?php esc_html_e( 'Identifiant du filtre', 'logiprof-sensei-smart-shortcodes' ); ?></span>
						<input id="lssc-filter-id" type="text" value="default" data-shortcode-field="filter_id">
						<span class="description"><?php esc_html_e( 'À changer seulement si plusieurs listes avec filtre sont sur la même page.', 'logiprof-sensei-smart-shortcodes' ); ?></span>
					</label>

					<label class="lad-check"><input type="checkbox" id="lssc-reveal" checked data-shortcode-field="reveal" data-checked-value="yes" data-unchecked-value="no"> <?php esc_html_e( 'Replier le contenu derrière un bouton', 'logiprof-sensei-smart-shortcodes' ); ?></label>
					<div class="lad-field-row">
						<label class="lad-field" for="lssc-reveal-label" data-lssc-show-if="lssc-reveal">
							<span class="lad-label"><?php esc_html_e( 'Texte du bouton qui déplie', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<input id="lssc-reveal-label" type="text" value="Accéder au programme de cours" data-shortcode-field="reveal_label">
						</label>
						<label class="lad-field" for="lssc-button-label" data-lssc-show="<?php echo esc_attr( $lists ); ?>">
							<span class="lad-label"><?php esc_html_e( 'Texte du bouton de chaque carte', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<input id="lssc-button-label" type="text" value="Accéder au cours" data-shortcode-field="button_label">
						</label>
					</div>
					<div class="lad-field-row">
						<label class="lad-field" for="lssc-link-target" data-lssc-show="<?php echo esc_attr( $lists ); ?>">
							<span class="lad-label"><?php esc_html_e( 'Ouverture des liens', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<select id="lssc-link-target" data-shortcode-field="link_target">
								<option value="same"><?php esc_html_e( 'Dans la même page', 'logiprof-sensei-smart-shortcodes' ); ?></option>
								<option value="blank"><?php esc_html_e( 'Dans un nouvel onglet', 'logiprof-sensei-smart-shortcodes' ); ?></option>
							</select>
						</label>
						<label class="lad-field" for="lssc-empty-text">
							<span class="lad-label"><?php esc_html_e( 'Message si la liste est vide', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<input id="lssc-empty-text" type="text" value="Aucun contenu à afficher pour le moment." data-shortcode-field="empty_text">
						</label>
					</div>
					<details class="lad-more">
						<summary><?php esc_html_e( 'Réglage avancé', 'logiprof-sensei-smart-shortcodes' ); ?></summary>
						<label class="lad-field" for="lssc-class">
							<span class="lad-label"><?php esc_html_e( 'Classe CSS', 'logiprof-sensei-smart-shortcodes' ); ?></span>
							<input id="lssc-class" type="text" placeholder="ex: accueil-connecte" data-shortcode-field="class">
						</label>
					</details>
				</section>
			</div>

			<aside class="lad-split-side">
				<section class="lad-card lad-sticky">
					<h2><?php esc_html_e( 'Votre shortcode', 'logiprof-sensei-smart-shortcodes' ); ?></h2>
					<textarea id="lssc-output" class="code lad-output" rows="9" readonly aria-label="<?php esc_attr_e( 'Shortcode généré', 'logiprof-sensei-smart-shortcodes' ); ?>"></textarea>
					<p class="lad-output-actions">
						<button type="button" class="button button-primary" id="lssc-copy"><?php esc_html_e( 'Copier le shortcode', 'logiprof-sensei-smart-shortcodes' ); ?></button>
						<span class="lad-feedback" id="lssc-feedback" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Il se met à jour à chaque réglage. Collez-le dans une page, un module Divi ou un widget.', 'logiprof-sensei-smart-shortcodes' ); ?></p>
					<details class="lad-more">
						<summary><?php esc_html_e( 'Exemples prêts à l’emploi', 'logiprof-sensei-smart-shortcodes' ); ?></summary>
						<pre class="lad-pre"><code>[logiprof_sensei type="started_courses"]
[logiprof_sensei type="quizzes_to_finish" limit="4" button_label="Terminer le quiz"]
[logiprof_sensei type="next_lessons" button_label="Continuer"]
[logiprof_sensei type="category_courses" category="comptabilite" limit="6"]
[logiprof_sensei type="profile_category_courses" category="comptabilite"]
[logiprof_sensei type="group_course_progress" group_id="123" course_id="456"]</code></pre>
					</details>
				</section>
			</aside>
		</div>
		<?php
		LogiAdmin::page_close();
	}

	private static function render_course_category_checkboxes( $terms, $parent = 0, $level = 0 ) {
		$children = array_filter(
			$terms,
			function ( $term ) use ( $parent ) {
				return (int) $term->parent === (int) $parent;
			}
		);

		usort(
			$children,
			function ( $a, $b ) {
				return strnatcasecmp( $a->name, $b->name );
			}
		);

		foreach ( $children as $term ) {
			echo '<label class="lad-tree-item lad-tree-level-' . esc_attr( min( 4, (int) $level ) ) . '">';
			echo '<input type="checkbox" class="lssc-category-choice" value="' . esc_attr( $term->slug ) . '"> ';
			echo esc_html( $term->name );
			echo '</label>';

			self::render_course_category_checkboxes( $terms, $term->term_id, $level + 1 );
		}
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'          => 'category_courses',
				'limit'         => 0,
				'orderby'       => 'title',
				'order'         => 'asc',
				'category'      => '',
				'profile'       => '',
				'include_unassigned' => 'no',
				'show_filters'  => 'no',
				'filter_id'     => 'default',
				'reveal'        => 'yes',
				'reveal_label'  => __( 'Accéder au programme de cours', 'logiprof-sensei-smart-shortcodes' ),
				'group_id'      => 0,
				'course_id'     => 0,
				'show_progress' => 'yes',
				'show_excerpt'  => 'yes',
				'show_image'    => 'yes',
				'button_label'  => __( 'Accéder au cours', 'logiprof-sensei-smart-shortcodes' ),
				'link_target'   => 'same',
				'empty_text'    => __( 'Aucun contenu à afficher pour le moment.', 'logiprof-sensei-smart-shortcodes' ),
				'class'         => '',
			),
			$atts,
			self::SHORTCODE
		);

		$atts['limit'] = self::normalize_limit( $atts['limit'] );
		$atts['order'] = strtolower( $atts['order'] ) === 'asc' ? 'ASC' : 'DESC';
		$atts['orderby'] = self::normalize_orderby( $atts['orderby'] );
		$atts['type'] = sanitize_key( $atts['type'] );
		$atts['profile'] = sanitize_key( $atts['profile'] );
		$atts['include_unassigned'] = strtolower( $atts['include_unassigned'] );
		$atts['show_filters'] = strtolower( $atts['show_filters'] );
		$atts['reveal'] = strtolower( $atts['reveal'] );
		$atts['link_target'] = self::normalize_link_target( $atts['link_target'] );
		$atts['filter_id'] = sanitize_key( $atts['filter_id'] );
		if ( '' === $atts['filter_id'] ) {
			$atts['filter_id'] = 'default';
		}
		$atts['group_id'] = absint( $atts['group_id'] );
		$atts['course_id'] = absint( $atts['course_id'] );

		self::enqueue_front_assets();

		if ( 'group_course_progress' === $atts['type'] ) {
			return self::wrap_reveal_content( self::render_group_course_progress( $atts ), $atts );
		}

		if ( 'yes' === $atts['show_filters'] ) {
			$atts = self::apply_front_filters( $atts );
		}

		$items = self::maybe_limit_items( self::sort_items( self::get_items( $atts ), $atts['orderby'], $atts['order'] ), $atts );
		$filters = 'yes' === $atts['show_filters'] ? self::render_front_filters( $atts ) : '';
		if ( empty( $items ) ) {
			return self::wrap_reveal_content( $filters . '<div class="logiprof-sensei-list logiprof-sensei-empty">' . esc_html( $atts['empty_text'] ) . '</div>', $atts );
		}

		$classes = array( 'logiprof-sensei-list', 'logiprof-sensei-' . $atts['type'] );
		if ( ! empty( $atts['class'] ) ) {
			$classes[] = sanitize_html_class( $atts['class'] );
		}

		ob_start();
		echo $filters; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<?php echo self::render_item( $item, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</div>
		<?php
		return self::wrap_reveal_content( ob_get_clean(), $atts );
	}

	private static function enqueue_front_assets() {
		if ( self::$assets_loaded ) {
			return;
		}

		wp_enqueue_style( 'logiprof-sensei-shortcodes' );
		self::$assets_loaded = true;
	}

	private static function wrap_reveal_content( $content, $atts ) {
		if ( 'yes' !== strtolower( $atts['reveal'] ) ) {
			return $content;
		}

		$label = '' !== trim( (string) $atts['reveal_label'] ) ? $atts['reveal_label'] : __( 'Prendre connaissance du contenu', 'logiprof-sensei-smart-shortcodes' );

		ob_start();
		?>
		<details class="logiprof-sensei-reveal">
			<summary class="logiprof-sensei-reveal-button"><?php echo esc_html( $label ); ?></summary>
			<div class="logiprof-sensei-reveal-content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}

	private static function apply_front_filters( $atts ) {
		$prefix = self::get_filter_prefix( $atts );

		$limit = self::get_front_filter_value( $prefix . 'limit' );
		if ( '' !== $limit ) {
			$atts['limit'] = self::normalize_limit( $limit );
		}

		return $atts;
	}

	private static function render_front_filters( $atts ) {
		$prefix = self::get_filter_prefix( $atts );
		$action = remove_query_arg(
			array(
				$prefix . 'limit',
			)
		);

		ob_start();
		?>
		<form class="logiprof-sensei-filters" method="get" action="<?php echo esc_url( $action ); ?>">
			<?php self::render_preserved_query_inputs( $prefix ); ?>
			<label class="logiprof-sensei-filter-field">
				<span><?php esc_html_e( 'Nombre', 'logiprof-sensei-smart-shortcodes' ); ?></span>
				<input type="number" name="<?php echo esc_attr( $prefix . 'limit' ); ?>" min="1" max="24" value="<?php echo esc_attr( $atts['limit'] ? $atts['limit'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Tous', 'logiprof-sensei-smart-shortcodes' ); ?>">
			</label>
			<button class="logiprof-sensei-filter-button" type="submit"><?php esc_html_e( 'Filtrer', 'logiprof-sensei-smart-shortcodes' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function render_preserved_query_inputs( $prefix ) {
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				continue;
			}

			echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $value ) ) ) . '">';
		}
	}

	private static function get_filter_prefix( $atts ) {
		return 'lssc_' . sanitize_key( $atts['filter_id'] ) . '_';
	}

	private static function get_front_filter_value( $key ) {
		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function normalize_limit( $limit ) {
		$limit = absint( $limit );
		return $limit > 0 ? min( 24, $limit ) : 0;
	}

	private static function normalize_orderby( $orderby ) {
		$orderby = sanitize_key( $orderby );
		$allowed = array( 'activity', 'date', 'title', 'menu_order' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'title';
	}

	private static function normalize_link_target( $target ) {
		$target = sanitize_key( $target );
		return in_array( $target, array( 'same', 'blank' ), true ) ? $target : 'same';
	}

	private static function has_item_limit( $atts ) {
		return ! empty( $atts['limit'] );
	}

	private static function get_posts_per_page( $atts ) {
		return self::has_item_limit( $atts ) ? $atts['limit'] : -1;
	}

	private static function get_fetch_limit( $atts, $multiplier = 10, $minimum = 50 ) {
		return self::has_item_limit( $atts ) && 'activity' === $atts['orderby'] ? max( $minimum, $atts['limit'] * $multiplier ) : 0;
	}

	private static function reached_item_limit( $items, $atts ) {
		return self::has_item_limit( $atts ) && 'activity' === $atts['orderby'] && count( $items ) >= $atts['limit'];
	}

	private static function maybe_limit_items( $items, $atts ) {
		return self::has_item_limit( $atts ) ? array_slice( $items, 0, $atts['limit'] ) : $items;
	}

	private static function get_items( $atts ) {
		switch ( $atts['type'] ) {
			case 'latest_courses':
				return self::get_course_query_items( $atts, false );
			case 'category_courses':
				return self::get_course_query_items( $atts, false );
			case 'featured_courses':
				return self::get_course_query_items( $atts, true );
			case 'profile_category_courses':
				return self::get_profile_category_course_items( $atts );
			case 'completed_courses':
				return self::get_progress_items( 'course', array( 'complete' ), $atts );
			case 'active_courses':
			case 'started_courses':
				return self::get_progress_items( 'course', array( 'in-progress' ), $atts );
			case 'lessons_to_finish':
				return self::get_progress_items( 'lesson', array( 'in-progress', 'failed' ), $atts );
			case 'quizzes_to_finish':
				return self::get_quiz_items_to_finish( $atts );
			case 'next_lessons':
				return self::get_next_lesson_items( $atts );
			default:
				return array();
		}
	}

	private static function get_course_query_items( $atts, $featured_only ) {
		$args = array(
			'post_type'           => 'course',
			'post_status'         => 'publish',
			'posts_per_page'      => self::get_posts_per_page( $atts ),
			'ignore_sticky_posts' => true,
			'orderby'             => self::map_post_orderby( $atts['orderby'] ),
			'order'               => $atts['order'],
		);

		if ( $featured_only ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_course_featured',
					'value'   => array( 'featured', 'yes', '1' ),
					'compare' => 'IN',
				),
			);
		}

		self::add_course_category_query( $args, $atts['category'] );

		$query = new WP_Query( $args );
		return array_map( array( __CLASS__, 'post_to_item' ), $query->posts );
	}

	private static function get_profile_category_course_items( $atts ) {
		$profile_roles = self::get_requested_profile_roles( $atts['profile'] );
		if ( empty( $profile_roles ) ) {
			return array();
		}

		$args = array(
			'post_type'           => 'course',
			'post_status'         => 'publish',
			'posts_per_page'      => self::get_posts_per_page( $atts ),
			'ignore_sticky_posts' => true,
			'orderby'             => self::map_post_orderby( $atts['orderby'] ),
			'order'               => $atts['order'],
		);

		self::add_course_category_query( $args, $atts['category'] );

		$role_queries = array( 'relation' => 'OR' );
		foreach ( $profile_roles as $role ) {
			$role_queries[] = array(
				'key'     => self::COURSE_PROFILE_ROLES_META,
				'value'   => '"' . $role . '"',
				'compare' => 'LIKE',
			);
		}

		if ( 'yes' === $atts['include_unassigned'] ) {
			$role_queries[] = array(
				'key'     => self::COURSE_PROFILE_ROLES_META,
				'compare' => 'NOT EXISTS',
			);
		}

		$args['meta_query'] = array( $role_queries );

		$query = new WP_Query( $args );
		return array_map( array( __CLASS__, 'post_to_item' ), $query->posts );
	}

	private static function get_progress_items( $post_type, $statuses, $atts ) {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$items = self::get_repository_progress_items( $post_type, $statuses, $atts );
		if ( ! empty( $items ) ) {
			return $items;
		}

		$items = self::get_comment_progress_items( $post_type, $statuses, $atts );
		if ( ! empty( $items ) ) {
			return $items;
		}

		if ( 'course' === $post_type ) {
			return self::get_course_items_from_lesson_statuses( $statuses, $atts );
		}

		return array();
	}

	private static function get_repository_progress_items( $post_type, $statuses, $atts ) {
		$repository = self::get_progress_repository( $post_type );
		if ( ! $repository || ! method_exists( $repository, 'find' ) ) {
			return array();
		}

		$progress_records = array();
		foreach ( $statuses as $status ) {
			try {
				$progress_records = array_merge(
					$progress_records,
					$repository->find(
						array(
							'user_id' => get_current_user_id(),
							'status'  => $status,
							'order'   => $atts['order'],
							'orderby' => 'activity' === $atts['orderby'] ? 'updated_at' : 'id',
						)
					)
				);
			} catch ( Exception $e ) {
				return array();
			} catch ( Error $e ) {
				return array();
			}
		}

		$items = array();
		foreach ( $progress_records as $progress ) {
			$post_id = self::get_progress_post_id( $progress, $post_type );
			if ( ! $post_id ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			if ( 'course' === $post_type && ! self::course_matches_category( $post->ID, $atts['category'] ) ) {
				continue;
			}

			$status = method_exists( $progress, 'get_status' ) ? $progress->get_status() : '';
			if ( $status && ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			$item                  = self::post_to_item( $post );
			$item['status']        = $status;
			$item['progress']      = self::get_progress_percent_for_post( $post->ID, $post_type );
			$item['activity_date'] = self::get_progress_updated_at( $progress );
			if ( 'lesson' === $post_type ) {
				$item['image'] = self::get_course_image_for_lesson( $post->ID, $item['image'] );
			}
			$items[]               = $item;

			if ( self::reached_item_limit( $items, $atts ) ) {
				break;
			}
		}

		if ( 'activity' !== $atts['orderby'] ) {
			$items = self::sort_items( $items, $atts['orderby'], $atts['order'] );
		}

		return self::maybe_limit_items( $items, $atts );
	}

	private static function get_course_items_from_lesson_statuses( $statuses, $atts ) {
		global $wpdb;

		$lesson_statuses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID, comment_approved, comment_date
				FROM {$wpdb->comments}
				WHERE user_id = %d AND comment_type = 'sensei_lesson_status'
				ORDER BY comment_date_gmt DESC",
				get_current_user_id()
			)
		);

		if ( empty( $lesson_statuses ) ) {
			return array();
		}

		$course_activity = array();
		foreach ( $lesson_statuses as $lesson_status ) {
			$course_id = self::get_lesson_course_id( (int) $lesson_status->comment_post_ID );
			if ( ! $course_id || isset( $course_activity[ $course_id ] ) ) {
				continue;
			}

			$course_activity[ $course_id ] = $lesson_status->comment_date;
		}

		$items = array();
		foreach ( $course_activity as $course_id => $activity_date ) {
			$post = get_post( $course_id );
			if ( ! $post || 'course' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			if ( ! self::course_matches_category( $course_id, $atts['category'] ) ) {
				continue;
			}

			$course_state = self::get_course_state_from_lessons( $course_id, get_current_user_id() );
			if ( ! in_array( $course_state, $statuses, true ) ) {
				continue;
			}

			$item                  = self::post_to_item( $post );
			$item['status']        = $course_state;
			$item['progress']      = self::get_lesson_based_course_progress( $course_id, get_current_user_id() );
			$item['activity_date'] = $activity_date;
			$items[]               = $item;

			if ( self::reached_item_limit( $items, $atts ) ) {
				break;
			}
		}

		if ( 'activity' !== $atts['orderby'] ) {
			$items = self::sort_items( $items, $atts['orderby'], $atts['order'] );
		}

		return self::maybe_limit_items( $items, $atts );
	}

	private static function get_comment_progress_items( $post_type, $statuses, $atts ) {
		$comment_args = array(
			'type'    => 'sensei_' . $post_type . '_status',
			'user_id' => get_current_user_id(),
			'status'  => 'all',
			'orderby' => 'activity' === $atts['orderby'] ? 'comment_date_gmt' : 'comment_post_ID',
			'order'   => $atts['order'],
		);
		$fetch_limit  = self::get_fetch_limit( $atts );
		if ( $fetch_limit > 0 ) {
			$comment_args['number'] = $fetch_limit;
		}

		$comments = get_comments( $comment_args );

		$items = array();
		foreach ( $comments as $comment ) {
			if ( ! in_array( $comment->comment_approved, $statuses, true ) ) {
				continue;
			}

			$post = get_post( (int) $comment->comment_post_ID );
			if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			if ( 'course' === $post_type && ! self::course_matches_category( $post->ID, $atts['category'] ) ) {
				continue;
			}

			$item = self::post_to_item( $post );
			if ( 'lesson' === $post_type ) {
				$item['image'] = self::get_course_image_for_lesson( $post->ID, $item['image'] );
			}
			$item['status'] = $comment->comment_approved;
			$item['progress'] = self::get_progress_percent_for_post( $post->ID, $post_type, $comment );
			$item['activity_date'] = $comment->comment_date;
			$items[] = $item;

			if ( self::reached_item_limit( $items, $atts ) ) {
				break;
			}
		}

		if ( 'activity' !== $atts['orderby'] ) {
			$items = self::sort_items( $items, $atts['orderby'], $atts['order'] );
		}

		return self::maybe_limit_items( $items, $atts );
	}

	private static function get_quiz_items_to_finish( $atts ) {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$opened_lessons = self::get_opened_lesson_statuses_for_quizzes( $atts );

		$items = array();
		foreach ( $opened_lessons as $lesson ) {
			$lesson_id = (int) $lesson['lesson_id'];
			$quiz_id   = self::get_lesson_quiz_id( $lesson_id );
			if ( ! $quiz_id ) {
				continue;
			}

			if ( ! self::lesson_has_quiz_questions( $lesson_id, $quiz_id ) ) {
				continue;
			}

			if ( self::is_quiz_passed_or_completed( $quiz_id ) || self::is_lesson_completed( $lesson_id ) ) {
				continue;
			}

			$item = self::post_to_item( get_post( $quiz_id ) );
			$item['parent_title'] = get_the_title( $lesson_id );
			$item['status'] = $lesson['status'];
			$item['activity_date'] = $lesson['activity_date'];
			$item['image'] = self::get_course_image_for_lesson( $lesson_id, $item['image'] );
			$items[] = $item;

			if ( self::reached_item_limit( $items, $atts ) ) {
				break;
			}
		}

		if ( 'activity' !== $atts['orderby'] ) {
			$items = self::sort_items( $items, $atts['orderby'], $atts['order'] );
		}

		return $items;
	}

	private static function get_opened_lesson_statuses_for_quizzes( $atts ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID, comment_approved, comment_date
				FROM {$wpdb->comments}
				WHERE user_id = %d AND comment_type = 'sensei_lesson_status'
				ORDER BY comment_date_gmt DESC",
				get_current_user_id()
			)
		);

		$lessons = array();
		$seen    = array();

		foreach ( $rows as $row ) {
			$lesson_id = absint( $row->comment_post_ID );
			$status    = (string) $row->comment_approved;

			if ( ! $lesson_id || isset( $seen[ $lesson_id ] ) ) {
				continue;
			}
			$seen[ $lesson_id ] = true;

			if ( '' === $status || in_array( $status, array( 'complete', 'passed', 'graded' ), true ) ) {
				continue;
			}

			$lesson = get_post( $lesson_id );
			if ( ! $lesson || 'lesson' !== $lesson->post_type || 'publish' !== $lesson->post_status ) {
				continue;
			}

			$course_id = self::get_lesson_course_id( $lesson_id );
			if ( $course_id && ! self::course_matches_category( $course_id, $atts['category'] ) ) {
				continue;
			}

			$quiz_id = self::get_lesson_quiz_id( $lesson_id );
			if ( ! $quiz_id || ! self::lesson_has_quiz_questions( $lesson_id, $quiz_id ) ) {
				continue;
			}

			$lessons[] = array(
				'lesson_id'     => $lesson_id,
				'status'        => $status,
				'activity_date' => $row->comment_date,
			);

			if ( self::reached_item_limit( $lessons, array_merge( $atts, array( 'limit' => self::get_fetch_limit( $atts ) ) ) ) ) {
				break;
			}
		}

		return $lessons;
	}

	private static function get_next_lesson_items( $atts ) {
		$course_fetch_atts = array_merge( $atts, array( 'limit' => 0 ) );
		$active_courses    = self::get_progress_items( 'course', array( 'in-progress' ), $course_fetch_atts );
		$items             = array();

		foreach ( $active_courses as $course ) {
			foreach ( self::get_course_lesson_ids( $course['id'] ) as $lesson_id ) {
				if ( self::is_lesson_completed( $lesson_id ) ) {
					continue;
				}

				$item                  = self::post_to_item( get_post( $lesson_id ) );
				$item['parent_title']  = $course['title'];
				$item['status']        = self::get_post_progress_status( $lesson_id, 'lesson' );
				$item['progress']      = 0;
				$item['image']         = self::get_course_image_for_lesson( $lesson_id, $item['image'] );
				$items[]               = $item;
				break;
			}

			if ( self::reached_item_limit( $items, $atts ) ) {
				break;
			}
		}

		return $items;
	}

	private static function render_group_course_progress( $atts ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) ) {
			return '<div class="logiprof-sensei-list logiprof-sensei-empty">' . esc_html__( 'Acces non autorise.', 'logiprof-sensei-smart-shortcodes' ) . '</div>';
		}

		if ( ! $atts['group_id'] || ! $atts['course_id'] ) {
			return '<div class="logiprof-sensei-list logiprof-sensei-empty">' . esc_html__( 'Ajoute group_id et course_id au shortcode.', 'logiprof-sensei-smart-shortcodes' ) . '</div>';
		}

		$student_ids = self::get_group_student_ids( $atts['group_id'] );
		$lessons     = self::get_course_lessons_for_table( $atts['course_id'] );

		if ( empty( $student_ids ) || empty( $lessons ) ) {
			return '<div class="logiprof-sensei-list logiprof-sensei-empty">' . esc_html( $atts['empty_text'] ) . '</div>';
		}

		ob_start();
		?>
		<div class="logiprof-sensei-progress-table-wrap">
			<table class="logiprof-sensei-progress-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Etudiant', 'logiprof-sensei-smart-shortcodes' ); ?></th>
						<?php foreach ( $lessons as $lesson ) : ?>
							<th><?php echo esc_html( $lesson->post_title ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Progression', 'logiprof-sensei-smart-shortcodes' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $student_ids as $student_id ) : ?>
						<?php
						$student  = get_userdata( $student_id );
						$statuses = self::get_user_lesson_statuses( $student_id );
						if ( ! $student ) {
							continue;
						}
						?>
						<tr>
							<th><?php echo esc_html( $student->display_name ); ?></th>
							<?php foreach ( $lessons as $lesson ) : ?>
								<td><?php echo esc_html( self::format_lesson_status( isset( $statuses[ $lesson->ID ] ) ? $statuses[ $lesson->ID ] : '' ) ); ?></td>
							<?php endforeach; ?>
							<td><?php echo esc_html( self::get_lesson_based_course_progress( $atts['course_id'], $student_id ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_item( $item, $atts ) {
		$show_progress = 'yes' === strtolower( $atts['show_progress'] ) && isset( $item['progress'] );
		$show_excerpt  = 'yes' === strtolower( $atts['show_excerpt'] ) && ! empty( $item['excerpt'] );
		$show_image    = 'yes' === strtolower( $atts['show_image'] ) && ! empty( $item['image'] );
		$link_attrs    = self::get_link_target_attrs( $atts['link_target'] );

		ob_start();
		?>
		<article class="logiprof-sensei-card<?php echo $show_image ? ' logiprof-sensei-card-with-image' : ''; ?>">
			<?php if ( $show_image ) : ?>
				<a class="logiprof-sensei-thumb" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo wp_kses_post( $item['image'] ); ?>
				</a>
			<?php endif; ?>
			<div class="logiprof-sensei-card-body">
				<h3 class="logiprof-sensei-title"><a href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $item['title'] ); ?></a></h3>
				<?php if ( ! empty( $item['parent_title'] ) ) : ?>
					<p class="logiprof-sensei-meta"><?php echo esc_html( $item['parent_title'] ); ?></p>
				<?php endif; ?>
				<?php if ( $show_excerpt ) : ?>
					<p class="logiprof-sensei-excerpt"><?php echo esc_html( $item['excerpt'] ); ?></p>
				<?php endif; ?>
				<?php if ( $show_progress ) : ?>
					<div class="logiprof-sensei-progress" aria-label="<?php echo esc_attr( sprintf( __( 'Progression : %d%%', 'logiprof-sensei-smart-shortcodes' ), $item['progress'] ) ); ?>">
						<span style="width: <?php echo esc_attr( $item['progress'] ); ?>%"></span>
					</div>
					<p class="logiprof-sensei-progress-label"><?php echo esc_html( sprintf( __( '%d%% complete', 'logiprof-sensei-smart-shortcodes' ), $item['progress'] ) ); ?></p>
				<?php endif; ?>
				<a class="logiprof-sensei-button" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $atts['button_label'] ); ?></a>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	private static function get_link_target_attrs( $target ) {
		if ( 'blank' !== $target ) {
			return '';
		}

		return ' target="_blank" rel="noopener noreferrer"';
	}

	private static function post_to_item( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'date'       => $post->post_date,
			'menu_order' => $post->menu_order,
			'url'        => get_permalink( $post ),
			'excerpt'    => wp_trim_words( get_the_excerpt( $post ), 22 ),
			'image'      => get_the_post_thumbnail( $post, 'medium' ),
			'progress'   => 'course' === $post->post_type && is_user_logged_in() ? self::get_progress_percent_for_post( $post->ID, 'course' ) : null,
		);
	}

	private static function get_course_image_for_lesson( $lesson_id, $fallback_image = '' ) {
		$course_id = self::get_lesson_course_id( $lesson_id );
		if ( ! $course_id ) {
			return $fallback_image;
		}

		$course_image = get_the_post_thumbnail( $course_id, 'medium' );
		return ! empty( $course_image ) ? $course_image : $fallback_image;
	}

	private static function get_progress_percent_for_post( $post_id, $post_type, $comment = null ) {
		if ( 'course' === $post_type && function_exists( 'Sensei' ) && isset( Sensei()->course ) && method_exists( Sensei()->course, 'get_completion_percentage' ) ) {
			return max( 0, min( 100, absint( Sensei()->course->get_completion_percentage( $post_id, get_current_user_id() ) ) ) );
		}

		if ( ! $comment ) {
			return 'course' === $post_type && 'complete' === self::get_post_progress_status( $post_id, $post_type ) ? 100 : 0;
		}

		$percent = get_comment_meta( $comment->comment_ID, 'percent', true );
		if ( '' === $percent ) {
			$percent = get_comment_meta( $comment->comment_ID, 'complete', true );
		}

		if ( '' === $percent && 'complete' === $comment->comment_approved ) {
			$percent = 100;
		}

		return max( 0, min( 100, absint( $percent ) ) );
	}

	private static function get_progress_repository( $post_type ) {
		if ( ! function_exists( 'Sensei' ) ) {
			return null;
		}

		$sensei = Sensei();
		$property = $post_type . '_progress_repository';
		if ( isset( $sensei->{$property} ) ) {
			return $sensei->{$property};
		}

		$factory_property = $post_type . '_progress_repository_factory';
		if ( isset( $sensei->{$factory_property} ) && method_exists( $sensei->{$factory_property}, 'create_comments_based_repository' ) ) {
			return $sensei->{$factory_property}->create_comments_based_repository();
		}

		return null;
	}

	private static function get_progress_post_id( $progress, $post_type ) {
		$method = 'get_' . $post_type . '_id';
		if ( is_object( $progress ) && method_exists( $progress, $method ) ) {
			return absint( $progress->$method() );
		}

		return 0;
	}

	private static function get_progress_updated_at( $progress ) {
		if ( ! is_object( $progress ) || ! method_exists( $progress, 'get_updated_at' ) ) {
			return '';
		}

		$updated_at = $progress->get_updated_at();
		if ( $updated_at instanceof DateTimeInterface ) {
			return $updated_at->format( 'Y-m-d H:i:s' );
		}

		return (string) $updated_at;
	}

	private static function get_post_progress_status( $post_id, $post_type ) {
		$repository = self::get_progress_repository( $post_type );
		if ( $repository && method_exists( $repository, 'get' ) ) {
			try {
				$progress = $repository->get( $post_id, get_current_user_id() );
				if ( $progress && method_exists( $progress, 'get_status' ) ) {
					return $progress->get_status();
				}
			} catch ( Exception $e ) {
				// Fall through to comments.
			} catch ( Error $e ) {
				// Fall through to comments.
			}
		}

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'user_id' => get_current_user_id(),
				'type'    => 'sensei_' . $post_type . '_status',
				'number'  => 1,
				'status'  => 'all',
			)
		);

		return ! empty( $comments ) ? $comments[0]->comment_approved : '';
	}

	private static function get_lesson_quiz_id( $lesson_id ) {
		$quiz = get_posts(
			array(
				'post_type'      => 'quiz',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_quiz_lesson',
				'meta_value'     => $lesson_id,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $quiz ) && self::is_valid_quiz_post_id( $quiz[0] ) ) {
			return absint( $quiz[0] );
		}

		$quiz = get_posts(
			array(
				'post_type'      => 'quiz',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'post_parent'    => $lesson_id,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $quiz ) && self::is_valid_quiz_post_id( $quiz[0] ) ) {
			return absint( $quiz[0] );
		}

		if ( function_exists( 'Sensei' ) && isset( Sensei()->lesson ) && method_exists( Sensei()->lesson, 'lesson_quizzes' ) ) {
			$quiz_id = absint( Sensei()->lesson->lesson_quizzes( $lesson_id ) );
			if ( self::is_valid_quiz_post_id( $quiz_id ) ) {
				return $quiz_id;
			}
		}

		return 0;
	}

	private static function is_valid_quiz_post_id( $quiz_id ) {
		$quiz = get_post( $quiz_id );
		return $quiz instanceof WP_Post && 'quiz' === $quiz->post_type && 'publish' === $quiz->post_status;
	}

	private static function lesson_has_quiz_questions( $lesson_id, $quiz_id ) {
		if ( ! self::is_valid_quiz_post_id( $quiz_id ) ) {
			return false;
		}

		if ( class_exists( 'Sensei_Lesson' ) && method_exists( 'Sensei_Lesson', 'lesson_quiz_has_questions' ) && ! Sensei_Lesson::lesson_quiz_has_questions( $lesson_id ) ) {
			return false;
		}

		$question_order = get_post_meta( $quiz_id, '_question_order', true );
		if ( is_array( $question_order ) ) {
			$question_ids = array_filter( array_map( 'absint', $question_order ) );
			if ( ! empty( $question_ids ) ) {
				return true;
			}
		}

		if ( is_string( $question_order ) && '' !== trim( $question_order ) ) {
			$question_ids = array_filter( array_map( 'absint', explode( ',', $question_order ) ) );
			if ( ! empty( $question_ids ) ) {
				return true;
			}
		}

		$questions = get_posts(
			array(
				'post_type'      => array( 'question', 'multiple_question' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_quiz_id',
						'value' => $quiz_id,
					),
				),
			)
		);

		return ! empty( $questions );
	}

	private static function get_course_lesson_ids( $course_id ) {
		$ordered_lessons = self::get_lesson_ids_from_course_order( $course_id );
		if ( ! empty( $ordered_lessons ) ) {
			return $ordered_lessons;
		}

		if ( function_exists( 'Sensei' ) && isset( Sensei()->course ) && method_exists( Sensei()->course, 'course_lessons' ) ) {
			return array_map( 'absint', (array) Sensei()->course->course_lessons( $course_id, 'publish', 'ids' ) );
		}

		return get_posts(
			array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_lesson_course',
				'meta_value'     => $course_id,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
	}

	private static function get_lesson_ids_from_course_order( $course_id ) {
		$lesson_order = (string) get_post_meta( $course_id, '_lesson_order', true );
		if ( '' === trim( $lesson_order ) ) {
			return array();
		}

		$lesson_ids = array_values( array_filter( array_map( 'absint', explode( ',', $lesson_order ) ) ) );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$published = get_posts(
			array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'post__in'       => $lesson_ids,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return array_values( array_intersect( $lesson_ids, array_map( 'absint', $published ) ) );
	}

	private static function get_lesson_course_id( $lesson_id ) {
		if ( function_exists( 'Sensei' ) && isset( Sensei()->lesson ) && method_exists( Sensei()->lesson, 'get_course_id' ) ) {
			$course_id = absint( Sensei()->lesson->get_course_id( $lesson_id ) );
			if ( $course_id ) {
				return $course_id;
			}
		}

		$course_id = absint( get_post_meta( $lesson_id, '_lesson_course', true ) );
		if ( $course_id ) {
			return $course_id;
		}

		return self::get_lesson_course_id_from_order( $lesson_id );
	}

	private static function get_lesson_course_id_from_order( $lesson_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_lesson_order' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( (string) $lesson_id ) . '%'
			)
		);

		foreach ( $rows as $row ) {
			$lesson_ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $row->meta_value ) ) ) );
			if ( in_array( (int) $lesson_id, $lesson_ids, true ) ) {
				return absint( $row->post_id );
			}
		}

		return 0;
	}

	private static function get_course_state_from_lessons( $course_id, $user_id ) {
		$lesson_ids = self::get_course_lesson_ids( $course_id );
		if ( empty( $lesson_ids ) ) {
			return '';
		}

		$statuses = self::get_user_lesson_statuses( $user_id );
		$started  = false;
		$complete = 0;

		foreach ( $lesson_ids as $lesson_id ) {
			$status = isset( $statuses[ $lesson_id ] ) ? $statuses[ $lesson_id ] : '';
			if ( '' !== $status ) {
				$started = true;
			}
			if ( in_array( $status, array( 'complete', 'passed', 'graded' ), true ) ) {
				$complete++;
			}
		}

		if ( count( $lesson_ids ) === $complete ) {
			return 'complete';
		}

		return $started ? 'in-progress' : '';
	}

	private static function get_lesson_based_course_progress( $course_id, $user_id ) {
		$lesson_ids = self::get_course_lesson_ids( $course_id );
		if ( empty( $lesson_ids ) ) {
			return 0;
		}

		$statuses = self::get_user_lesson_statuses( $user_id );
		$complete = 0;
		foreach ( $lesson_ids as $lesson_id ) {
			$status = isset( $statuses[ $lesson_id ] ) ? $statuses[ $lesson_id ] : '';
			if ( in_array( $status, array( 'complete', 'passed', 'graded' ), true ) ) {
				$complete++;
			}
		}

		return (int) round( ( $complete / count( $lesson_ids ) ) * 100 );
	}

	private static function get_user_lesson_statuses( $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID, comment_approved
				FROM {$wpdb->comments}
				WHERE user_id = %d AND comment_type = 'sensei_lesson_status'",
				$user_id
			)
		);

		$statuses = array();
		foreach ( $rows as $row ) {
			$statuses[ (int) $row->comment_post_ID ] = $row->comment_approved;
		}

		return $statuses;
	}

	private static function get_group_student_ids( $group_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sensei_pro_groups_students';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		return array_map(
			'absint',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT student_id FROM {$table} WHERE group_id = %d",
					$group_id
				)
			)
		);
	}

	private static function get_course_lessons_for_table( $course_id ) {
		$lesson_ids = self::get_course_lesson_ids( $course_id );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$lessons = get_posts(
			array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'post__in'       => $lesson_ids,
				'posts_per_page' => -1,
				'orderby'        => 'post__in',
			)
		);

		return $lessons;
	}

	private static function format_lesson_status( $status ) {
		if ( 'in-progress' === $status ) {
			return __( 'En cours', 'logiprof-sensei-smart-shortcodes' );
		}

		if ( in_array( $status, array( 'complete', 'passed', 'graded' ), true ) ) {
			return __( 'Termine', 'logiprof-sensei-smart-shortcodes' );
		}

		if ( 'failed' === $status ) {
			return __( 'A refaire', 'logiprof-sensei-smart-shortcodes' );
		}

		return __( 'Non commence', 'logiprof-sensei-smart-shortcodes' );
	}

	private static function is_lesson_completed( $lesson_id ) {
		if ( class_exists( 'Sensei_Utils' ) && method_exists( 'Sensei_Utils', 'user_completed_lesson' ) ) {
			return (bool) Sensei_Utils::user_completed_lesson( $lesson_id, get_current_user_id() );
		}

		return in_array( self::get_post_progress_status( $lesson_id, 'lesson' ), array( 'complete', 'graded', 'passed' ), true );
	}

	private static function is_quiz_passed_or_completed( $quiz_id ) {
		$comments = get_comments(
			array(
				'post_id' => $quiz_id,
				'user_id' => get_current_user_id(),
				'type'    => 'sensei_quiz_status',
				'number'  => 1,
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		if ( empty( $comments ) ) {
			return false;
		}

		return in_array( $comments[0]->comment_approved, array( 'complete', 'passed', 'graded' ), true );
	}

	private static function is_sensei_available() {
		return function_exists( 'Sensei' ) || class_exists( 'Sensei_Main' ) || class_exists( 'Sensei_Utils' );
	}

	private static function get_requested_profile_roles( $profile ) {
		if ( '' !== trim( (string) $profile ) ) {
			return array( sanitize_key( $profile ) );
		}

		if ( ! is_user_logged_in() ) {
			return array();
		}

		$user = wp_get_current_user();
		return array_values( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) );
	}

	private static function map_post_orderby( $orderby ) {
		$allowed = array( 'date', 'title', 'menu_order' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'date';
	}

	private static function sort_items( $items, $orderby, $order ) {
		usort(
			$items,
			function ( $a, $b ) use ( $orderby, $order ) {
				$key     = 'activity' === $orderby ? 'activity_date' : $orderby;
				$value_a = isset( $a[ $key ] ) ? $a[ $key ] : $a['title'];
				$value_b = isset( $b[ $key ] ) ? $b[ $key ] : $b['title'];
				$result  = strnatcasecmp( (string) $value_a, (string) $value_b );
				return 'ASC' === $order ? $result : -$result;
			}
		);

		return $items;
	}

	private static function add_course_category_query( &$args, $category ) {
		if ( '' === trim( (string) $category ) ) {
			return;
		}

		$categories = self::parse_category_filter( $category );
		if ( empty( $categories ) ) {
			return;
		}

		$all_numeric = count( $categories ) === count( array_filter( $categories, 'is_numeric' ) );
		$field = $all_numeric ? 'term_id' : 'slug';
		$terms = $all_numeric ? array_map( 'absint', $categories ) : array_map( 'sanitize_title', $categories );

		$args['tax_query'] = array(
			array(
				'taxonomy' => 'course-category',
				'field'    => $field,
				'terms'    => $terms,
				'operator' => 'IN',
			),
		);
	}

	private static function course_matches_category( $course_id, $category ) {
		if ( '' === trim( (string) $category ) ) {
			return true;
		}

		$categories = self::parse_category_filter( $category );
		if ( empty( $categories ) ) {
			return true;
		}

		$all_numeric = count( $categories ) === count( array_filter( $categories, 'is_numeric' ) );
		$field = $all_numeric ? 'ids' : 'slugs';
		$terms = wp_get_post_terms( $course_id, 'course-category', array( 'fields' => $field ) );
		if ( is_wp_error( $terms ) ) {
			return true;
		}

		$needles = $all_numeric ? array_map( 'absint', $categories ) : array_map( 'sanitize_title', $categories );
		return ! empty( array_intersect( $needles, $terms ) );
	}

	private static function parse_category_filter( $category ) {
		if ( is_array( $category ) ) {
			$categories = $category;
		} else {
			$categories = explode( ',', (string) $category );
		}

		return array_values( array_filter( array_map( 'trim', $categories ), 'strlen' ) );
	}
}

Logiprof_Sensei_Smart_Shortcodes::init();

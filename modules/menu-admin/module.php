<?php
/**
 * Module LogiAdmin : Logiprof Admin Menu Manager
 * Reprise de l'extension d'origine, intégrée à LogiAdmin.
 * Description: Permet de choisir les elements du menu lateral WordPress a garder dans le menu principal. Les elements decoches sont regroupes dans un nouveau menu.
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logiprof_Admin_Menu_Manager {
	const VERSION       = '1.2.0';
	const SLUG          = 'logiprof-admin-menu-manager';
	const SETTINGS_SLUG = 'logiprof-admin-menu-manager-settings';
	const OPTION_HIDDEN = 'logiprof_amm_hidden_slugs';
	const OPTION_ORDER  = 'logiprof_amm_menu_order';
	const NONCE         = 'logiprof_amm_save_settings';
	const ACTION_SAVE   = 'logiprof_amm_save_settings';
	const ACTION_RESET  = 'logiprof_amm_reset_settings';

	private static $menu_items    = array();
	private static $submenu_items = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::ACTION_RESET, array( __CLASS__, 'handle_reset_settings' ) );
		
		add_filter( 'custom_menu_order', array( __CLASS__, 'enable_custom_menu_order' ), 9999 );
		add_filter( 'menu_order', array( __CLASS__, 'filter_menu_order' ), 9999 );
	}

	public static function register_admin_menu() {
		global $menu, $submenu;

		self::$menu_items    = self::collect_menu_items( $menu );
		self::$submenu_items = self::collect_submenu_items( $submenu );

		$hidden_slugs      = self::get_hidden_slugs();
		$relocated_items   = self::get_relocated_items( $hidden_slugs );
		$has_visible_items = ! empty( $relocated_items );

		if ( ! current_user_can( 'manage_options' ) && ! $has_visible_items ) {
			return;
		}

		add_menu_page(
			__( 'Menu admin', 'logiprof-admin-menu-manager' ),
			__( 'Menu admin', 'logiprof-admin-menu-manager' ),
			'read',
			self::SLUG,
			array( __CLASS__, 'render_landing_page' ),
			'dashicons-screenoptions',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Accueil', 'logiprof-admin-menu-manager' ),
			__( 'Accueil', 'logiprof-admin-menu-manager' ),
			'read',
			self::SLUG,
			array( __CLASS__, 'render_landing_page' )
		);

		// Les réglages se trouvent dans LogiAdmin ; le menu « Menu admin » garde un raccourci.
		add_submenu_page(
			logiprof_admin_menu_parent_slug(),
			__( 'Menu admin', 'logiprof-admin-menu-manager' ),
			__( 'Menu admin', 'logiprof-admin-menu-manager' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);

		if ( current_user_can( 'manage_options' ) ) {
			$submenu[ self::SLUG ][] = array( __( 'Réglages', 'logiprof-admin-menu-manager' ), 'manage_options', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ), __( 'Réglages', 'logiprof-admin-menu-manager' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		self::$menu_items    = self::collect_menu_items( $menu );
		self::$submenu_items = self::collect_submenu_items( $submenu );
		$relocated_items     = self::get_relocated_items( $hidden_slugs );

		self::register_relocated_submenus( $relocated_items );

		foreach ( $hidden_slugs as $slug ) {
			if ( isset( self::$menu_items[ $slug ] ) ) {
				remove_menu_page( $slug );
			}
		}
	}

	public static function enqueue_assets( $hook ) {
		$menu_panel_data = self::get_menu_panel_data();

		if ( ! empty( $menu_panel_data['items'] ) ) {
			wp_enqueue_style(
				self::SLUG . '-menu',
				plugins_url( 'assets/menu.css', __FILE__ ),
				array(),
				self::VERSION
			);

			wp_enqueue_script(
				self::SLUG . '-menu',
				plugins_url( 'assets/menu.js', __FILE__ ),
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_localize_script( self::SLUG . '-menu', 'logiprofAmmMenu', $menu_panel_data );
		}

		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			self::SLUG,
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			self::SLUG,
			plugins_url( 'assets/admin.js', __FILE__ ),
			array( 'jquery', 'jquery-ui-sortable' ),
			self::VERSION,
			true
		);
	}

	public static function render_landing_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-admin-menu-manager' ) );
		}

		$hidden_slugs    = self::get_hidden_slugs();
		$relocated_items = self::get_relocated_items( $hidden_slugs );
		?>
		<?php
		LogiAdmin::page_open(
			'menu-admin',
			array(
				'class'   => 'logiprof-amm',
				'intro'   => __( 'Les éléments retirés du menu WordPress principal se retrouvent ici, avec leurs sous-pages.', 'logiprof-admin-menu-manager' ),
				'actions' => current_user_can( 'manage_options' ) ? '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Configurer le menu', 'logiprof-admin-menu-manager' ) . '</a>' : '',
			)
		);
		?>

			<?php if ( empty( $relocated_items ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Aucun élément n’est rangé ici pour votre profil pour le moment.', 'logiprof-admin-menu-manager' ); ?></p>
				</div>
			<?php else : ?>
				<div class="lad-tiles lad-tiles--compact logiprof-amm-groups">
					<?php foreach ( $relocated_items as $item ) : ?>
						<?php
						$children = self::get_visible_submenu_items_for_parent( $item['slug'] );
						?>
						<section class="lad-card logiprof-amm-group">
							<h2>
								<a href="<?php echo esc_url( self::admin_url_for_slug( $item['slug'] ) ); ?>">
									<?php echo esc_html( $item['title'] ); ?>
								</a>
							</h2>

							<?php if ( empty( $children ) ) : ?>
								<p>
									<a href="<?php echo esc_url( self::admin_url_for_slug( $item['slug'] ) ); ?>">
										<?php esc_html_e( 'Ouvrir', 'logiprof-admin-menu-manager' ); ?>
									</a>
								</p>
							<?php else : ?>
								<ul>
									<?php foreach ( $children as $child ) : ?>
										<li>
											<a href="<?php echo esc_url( self::admin_url_for_slug( $child['slug'], $item['slug'] ) ); ?>">
												<?php echo esc_html( $child['title'] ); ?>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php
		LogiAdmin::page_close();
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-admin-menu-manager' ) );
		}

		if ( empty( self::$menu_items ) ) {
			global $menu, $submenu;
			self::$menu_items    = self::collect_menu_items( $menu );
			self::$submenu_items = self::collect_submenu_items( $submenu );
		}

		$hidden_slugs     = self::get_hidden_slugs();
		$hidden_lookup    = array_flip( $hidden_slugs );
		$menu_order_map   = self::get_order_map();
		$ordered_items    = self::sort_items_by_order( self::$menu_items );
		$total_items      = count( self::$menu_items );
		$relocated_count  = count( array_intersect( array_keys( self::$menu_items ), $hidden_slugs ) );
		$main_menu_count  = max( 0, $total_items - $relocated_count );
		$reset_url        = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_RESET ),
			self::NONCE
		);
		?>
		<?php
		LogiAdmin::page_open(
			'menu-admin',
			array(
				'class' => 'logiprof-amm',
				'title' => __( 'Menu admin', 'logiprof-admin-menu-manager' ),
				'intro' => __( 'Gardez dans le menu WordPress seulement ce qui sert souvent. Le reste est rangé dans « Menu admin », sans rien perdre.', 'logiprof-admin-menu-manager' ),
			)
		);
		?>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Le menu est mis à jour.', 'logiprof-admin-menu-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Tous les éléments sont revenus dans le menu principal.', 'logiprof-admin-menu-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<ol class="lad-steps lad-steps--inline">
				<li><?php esc_html_e( 'Cochez ce qui reste dans le menu principal.', 'logiprof-admin-menu-manager' ); ?></li>
				<li><?php esc_html_e( 'Glissez les lignes (poignée ☰) ou changez les numéros pour l’ordre.', 'logiprof-admin-menu-manager' ); ?></li>
				<li><?php esc_html_e( 'Enregistrez. Les éléments décochés passent dans « Menu admin », dans le même ordre.', 'logiprof-admin-menu-manager' ); ?></li>
			</ol>

			<div class="lad-stats logiprof-amm-summary">
				<div>
					<strong><?php echo esc_html( number_format_i18n( $total_items ) ); ?></strong>
					<span><?php esc_html_e( 'éléments détectés', 'logiprof-admin-menu-manager' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html( number_format_i18n( $main_menu_count ) ); ?></strong>
					<span><?php esc_html_e( 'dans le menu principal', 'logiprof-admin-menu-manager' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html( number_format_i18n( $relocated_count ) ); ?></strong>
					<span><?php esc_html_e( 'dans Menu admin', 'logiprof-admin-menu-manager' ); ?></span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">

				<div class="lad-toolbar logiprof-amm-toolbar">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer', 'logiprof-admin-menu-manager' ); ?></button>
					<button type="button" class="button logiprof-amm-renumber"><?php esc_html_e( 'Renuméroter', 'logiprof-admin-menu-manager' ); ?></button>
					<span class="lad-toolbar-spacer"></span>
					<a class="button lad-danger" href="<?php echo esc_url( $reset_url ); ?>" data-logiadmin-confirm="<?php echo esc_attr__( 'Remettre tous les éléments dans le menu principal et effacer l’ordre choisi ?', 'logiprof-admin-menu-manager' ); ?>"><?php esc_html_e( 'Tout remettre dans le menu principal', 'logiprof-admin-menu-manager' ); ?></a>
				</div>

				<table class="widefat striped logiprof-amm-table">
					<thead>
						<tr>
							<th class="logiprof-amm-order-column"><?php esc_html_e( 'Ordre', 'logiprof-admin-menu-manager' ); ?></th>
							<th class="logiprof-amm-show-column"><?php esc_html_e( 'Dans le menu', 'logiprof-admin-menu-manager' ); ?></th>
							<th><?php esc_html_e( 'Élément', 'logiprof-admin-menu-manager' ); ?></th>
							<th><?php esc_html_e( 'Identifiant', 'logiprof-admin-menu-manager' ); ?></th>
							<th><?php esc_html_e( 'Après enregistrement', 'logiprof-admin-menu-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $ordered_items ) ) : ?>
							<tr>
								<td colspan="5" class="lad-empty"><?php esc_html_e( 'Aucun élément de menu détecté.', 'logiprof-admin-menu-manager' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $ordered_items as $index => $item ) : ?>
								<?php
								$is_relocated = isset( $hidden_lookup[ $item['slug'] ] );
								$is_protected = ! self::is_hideable_slug( $item['slug'] );
								$order_value  = isset( $menu_order_map[ $item['slug'] ] ) ? $menu_order_map[ $item['slug'] ] : $index + 1;
								?>
								<tr data-menu-row>
									<td class="logiprof-amm-order-cell">
										<input type="hidden" name="all_menu_slugs[]" value="<?php echo esc_attr( $item['slug'] ); ?>">
										<button
											type="button"
											class="button-link logiprof-amm-drag-handle"
											aria-label="<?php echo esc_attr( sprintf( __( 'Deplacer %s', 'logiprof-admin-menu-manager' ), $item['title'] ) ); ?>"
											title="<?php esc_attr_e( 'Glisser pour deplacer', 'logiprof-admin-menu-manager' ); ?>"
										>
											<span class="dashicons dashicons-menu" aria-hidden="true"></span>
										</button>
										<input
											type="number"
											class="small-text logiprof-amm-order-input"
											min="1"
											step="1"
											name="menu_order[<?php echo esc_attr( $item['slug'] ); ?>]"
											value="<?php echo esc_attr( $order_value ); ?>"
											aria-label="<?php echo esc_attr( sprintf( __( 'Ordre de %s', 'logiprof-admin-menu-manager' ), $item['title'] ) ); ?>"
										>
									</td>
									<td class="logiprof-amm-show-cell">
										<?php if ( $is_protected ) : ?>
											<input
												type="checkbox"
												checked
												disabled
												aria-label="<?php echo esc_attr( sprintf( __( '%s reste toujours visible', 'logiprof-admin-menu-manager' ), $item['title'] ) ); ?>"
											>
											<input type="hidden" name="visible_menu_slugs[]" value="<?php echo esc_attr( $item['slug'] ); ?>">
										<?php else : ?>
											<input
												type="checkbox"
												name="visible_menu_slugs[]"
												value="<?php echo esc_attr( $item['slug'] ); ?>"
												<?php checked( ! $is_relocated ); ?>
												class="logiprof-amm-visible"
												aria-label="<?php echo esc_attr( sprintf( __( 'Afficher %s dans le menu principal', 'logiprof-admin-menu-manager' ), $item['title'] ) ); ?>"
											>
										<?php endif; ?>
									</td>
									<td>
										<strong><?php echo esc_html( $item['title'] ); ?></strong>
										<?php if ( $is_protected ) : ?>
											<span class="logiprof-amm-subcount"><?php esc_html_e( 'menu de cet outil', 'logiprof-admin-menu-manager' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( self::$submenu_items[ $item['slug'] ] ) ) : ?>
											<span class="logiprof-amm-subcount">
												<?php
												printf(
													esc_html__( '%s sous-menu(s)', 'logiprof-admin-menu-manager' ),
													esc_html( number_format_i18n( count( self::$submenu_items[ $item['slug'] ] ) ) )
												);
												?>
											</span>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $item['slug'] ); ?></code></td>
									<td>
										<span class="logiprof-amm-status <?php echo $is_protected ? 'is-locked' : ( $is_relocated ? 'is-relocated' : 'is-main' ); ?>" data-label-main="<?php esc_attr_e( 'Menu principal', 'logiprof-admin-menu-manager' ); ?>" data-label-relocated="<?php esc_attr_e( 'Menu admin', 'logiprof-admin-menu-manager' ); ?>">
											<?php
											if ( $is_protected ) {
												echo esc_html__( 'Toujours visible', 'logiprof-admin-menu-manager' );
											} elseif ( $is_relocated ) {
												echo esc_html__( 'Menu admin', 'logiprof-admin-menu-manager' );
											} else {
												echo esc_html__( 'Menu principal', 'logiprof-admin-menu-manager' );
											}
											?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="lad-toolbar logiprof-amm-toolbar is-bottom">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer', 'logiprof-admin-menu-manager' ); ?></button>
				</div>
			</form>
		<?php
		LogiAdmin::page_close();
		?>
		<?php
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-admin-menu-manager' ) );
		}

		check_admin_referer( self::NONCE );

		$all_slugs     = self::sanitize_posted_slugs( 'all_menu_slugs' );
		$visible_slugs = self::sanitize_posted_slugs( 'visible_menu_slugs' );
		$hidden_slugs  = array_values( array_diff( $all_slugs, $visible_slugs ) );
		$hidden_slugs  = array_values( array_filter( $hidden_slugs, array( __CLASS__, 'is_hideable_slug' ) ) );
		$order_map     = self::sanitize_posted_order( $all_slugs );

		update_option( self::OPTION_HIDDEN, $hidden_slugs, false );
		update_option( self::OPTION_ORDER, $order_map, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SETTINGS_SLUG,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n avez pas les droits necessaires.', 'logiprof-admin-menu-manager' ) );
		}

		check_admin_referer( self::NONCE );
		delete_option( self::OPTION_HIDDEN );
		delete_option( self::OPTION_ORDER );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SETTINGS_SLUG,
					'reset' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Reglages', 'logiprof-admin-menu-manager' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function enable_custom_menu_order( $enabled ) {
		return ! empty( self::get_order_map() ) ? true : $enabled;
	}

	public static function filter_menu_order( $menu_order ) {
		$order_map = self::get_order_map();

		if ( empty( $order_map ) || ! is_array( $menu_order ) ) {
			return $menu_order;
		}

		$indexed_items = array();

		foreach ( $menu_order as $index => $slug ) {
			$clean_slug      = self::sanitize_menu_slug( $slug );
			$indexed_items[] = array(
				'slug'     => $slug,
				'weight'   => isset( $order_map[ $clean_slug ] ) ? (int) $order_map[ $clean_slug ] : 100000 + (int) $index,
				'fallback' => (int) $index,
			);
		}

		usort(
			$indexed_items,
			function ( $a, $b ) {
				if ( $a['weight'] === $b['weight'] ) {
					return $a['fallback'] <=> $b['fallback'];
				}

				return $a['weight'] < $b['weight'] ? -1 : 1;
			}
		);

		return wp_list_pluck( $indexed_items, 'slug' );
	}

	private static function collect_menu_items( $menu ) {
		$items = array();

		foreach ( (array) $menu as $position => $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}

			$slug = self::sanitize_menu_slug( $item[2] );

			if ( ! self::is_orderable_slug( $slug ) ) {
				continue;
			}

			$title = self::clean_menu_title( isset( $item[0] ) ? $item[0] : $slug );

			if ( '' === $title ) {
				$title = $slug;
			}

			$items[ $slug ] = array(
				'slug'       => $slug,
				'title'      => $title,
				'capability' => isset( $item[1] ) && '' !== $item[1] ? (string) $item[1] : 'read',
				'position'   => $position,
				'fallback'   => is_numeric( $position ) ? (float) $position : count( $items ) + 1,
			);
		}

		return $items;
	}

	private static function sort_items_by_order( $items ) {
		$items     = array_values( $items );
		$order_map = self::get_order_map();

		usort(
			$items,
			function ( $a, $b ) use ( $order_map ) {
				$a_weight = self::sort_weight_for_item( $a, $order_map );
				$b_weight = self::sort_weight_for_item( $b, $order_map );

				if ( $a_weight === $b_weight ) {
					$a_fallback = isset( $a['fallback'] ) ? (float) $a['fallback'] : 0;
					$b_fallback = isset( $b['fallback'] ) ? (float) $b['fallback'] : 0;

					if ( $a_fallback === $b_fallback ) {
						return strcmp( $a['slug'], $b['slug'] );
					}

					return $a_fallback < $b_fallback ? -1 : 1;
				}

				return $a_weight < $b_weight ? -1 : 1;
			}
		);

		return $items;
	}

	private static function sort_weight_for_item( $item, $order_map ) {
		$slug = isset( $item['slug'] ) ? $item['slug'] : '';

		if ( isset( $order_map[ $slug ] ) ) {
			return (int) $order_map[ $slug ];
		}

		$fallback = isset( $item['fallback'] ) ? (float) $item['fallback'] : 100000;

		return 100000 + $fallback;
	}

	private static function collect_submenu_items( $submenu ) {
		$items = array();

		foreach ( (array) $submenu as $parent_slug => $children ) {
			$parent_slug = self::sanitize_menu_slug( $parent_slug );

			foreach ( (array) $children as $child ) {
				if ( ! is_array( $child ) || empty( $child[2] ) ) {
					continue;
				}

				$slug  = self::sanitize_menu_slug( $child[2] );
				$title = self::clean_menu_title( isset( $child[0] ) ? $child[0] : $slug );

				if ( '' === $title ) {
					$title = $slug;
				}

				$items[ $parent_slug ][] = array(
					'slug'        => $slug,
					'title'       => $title,
					'capability'  => isset( $child[1] ) && '' !== $child[1] ? (string) $child[1] : 'read',
					'parent_slug' => $parent_slug,
				);
			}
		}

		return $items;
	}

	private static function register_relocated_submenus( $items ) {
		foreach ( $items as $item ) {
			self::add_relocated_parent_link( $item['title'], $item['capability'], $item['slug'] );
		}
	}

	private static function add_relocated_parent_link( $title, $capability, $target_slug ) {
		global $submenu;

		if ( ! current_user_can( $capability ) ) {
			return;
		}

		if ( ! isset( $submenu[ self::SLUG ] ) || ! is_array( $submenu[ self::SLUG ] ) ) {
			$submenu[ self::SLUG ] = array();
		}

		$submenu[ self::SLUG ][] = array(
			$title,
			$capability,
			self::admin_url_for_slug( $target_slug ),
			$title,
		);
	}

	private static function get_menu_panel_data() {
		self::ensure_menu_cache();

		$hidden_slugs = self::get_hidden_slugs();
		$parents      = self::get_relocated_items( $hidden_slugs );
		$items        = array();

		foreach ( $parents as $parent ) {
			$children = self::get_visible_submenu_items_for_parent( $parent['slug'] );

			$items[] = array(
				'slug'     => $parent['slug'],
				'title'    => $parent['title'],
				'url'      => self::admin_url_for_slug( $parent['slug'] ),
				'children' => array_map(
					function ( $child ) use ( $parent ) {
						return array(
							'slug'  => $child['slug'],
							'title' => $child['title'],
							'url'   => self::admin_url_for_slug( $child['slug'], $parent['slug'] ),
						);
					},
					$children
				),
			);
		}

		return array(
			'menuId'      => 'toplevel_page_' . self::SLUG,
			'homeUrl'     => admin_url( 'admin.php?page=' . self::SLUG ),
			'settingsUrl' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
			'items'       => $items,
		);
	}

	private static function get_visible_submenu_items_for_parent( $parent_slug ) {
		$parent_slug = self::sanitize_menu_slug( $parent_slug );

		if ( empty( self::$submenu_items[ $parent_slug ] ) ) {
			return array();
		}

		$children = array();

		foreach ( self::$submenu_items[ $parent_slug ] as $child ) {
			if ( $child['slug'] === $parent_slug || ! current_user_can( $child['capability'] ) ) {
				continue;
			}

			$children[] = $child;
		}

		return $children;
	}

	private static function ensure_menu_cache() {
		if ( ! empty( self::$menu_items ) && ! empty( self::$submenu_items ) ) {
			return;
		}

		global $menu, $submenu;

		self::$menu_items    = self::collect_menu_items( $menu );
		self::$submenu_items = self::collect_submenu_items( $submenu );
	}

	private static function get_relocated_items( $hidden_slugs ) {
		$items = array();

		foreach ( $hidden_slugs as $slug ) {
			if ( isset( self::$menu_items[ $slug ] ) && current_user_can( self::$menu_items[ $slug ]['capability'] ) ) {
				$items[] = self::$menu_items[ $slug ];
			}
		}

		return self::sort_items_by_order( $items );
	}

	private static function get_hidden_slugs() {
		$value = get_option( self::OPTION_HIDDEN, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$value = array_map( array( __CLASS__, 'sanitize_menu_slug' ), $value );
		$value = array_filter( $value, array( __CLASS__, 'is_hideable_slug' ) );

		return array_values( array_unique( $value ) );
	}

	private static function get_order_map() {
		$value = get_option( self::OPTION_ORDER, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$order_map = array();

		foreach ( $value as $slug => $order ) {
			$slug  = self::sanitize_menu_slug( $slug );
			$order = max( 1, (int) $order );

			if ( self::is_orderable_slug( $slug ) ) {
				$order_map[ $slug ] = $order;
			}
		}

		return $order_map;
	}

	private static function sanitize_posted_slugs( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $key ] );

		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}

		$slugs = array_map( array( __CLASS__, 'sanitize_menu_slug' ), $raw );
		$slugs = array_filter( $slugs, array( __CLASS__, 'is_orderable_slug' ) );

		return array_values( array_unique( $slugs ) );
	}

	private static function sanitize_posted_order( $valid_slugs ) {
		$raw = array();

		if ( isset( $_POST['menu_order'] ) && is_array( $_POST['menu_order'] ) ) {
			$raw = wp_unslash( $_POST['menu_order'] );
		}

		$rows = array();

		foreach ( $valid_slugs as $index => $slug ) {
			$slug = self::sanitize_menu_slug( $slug );

			if ( ! self::is_orderable_slug( $slug ) ) {
				continue;
			}

			$order = isset( $raw[ $slug ] ) ? (int) $raw[ $slug ] : $index + 1;

			$rows[] = array(
				'slug'     => $slug,
				'order'    => max( 1, $order ),
				'fallback' => (int) $index,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['order'] === $b['order'] ) {
					return $a['fallback'] <=> $b['fallback'];
				}

				return $a['order'] < $b['order'] ? -1 : 1;
			}
		);

		$order_map = array();

		foreach ( $rows as $index => $row ) {
			$order_map[ $row['slug'] ] = $index + 1;
		}

		return $order_map;
	}

	private static function sanitize_menu_slug( $slug ) {
		return trim( sanitize_text_field( (string) $slug ) );
	}

	private static function is_orderable_slug( $slug ) {
		if ( '' === $slug ) {
			return false;
		}

		if ( 0 === strpos( $slug, 'separator' ) ) {
			return false;
		}

		return self::SETTINGS_SLUG !== $slug;
	}

	private static function is_hideable_slug( $slug ) {
		return self::is_orderable_slug( $slug ) && self::SLUG !== $slug;
	}

	private static function clean_menu_title( $title ) {
		$title = (string) $title;
		$title = preg_replace( '/<span[^>]*class=["\'][^"\']*(?:awaiting-mod|update-plugins|plugin-count|pending-count|update-count)[^"\']*["\'][^>]*>.*?<\/span>/is', '', $title );
		// Compteurs et textes pour lecteurs d'écran ajoutés par WordPress (ex. « 0 Comments in moderation »).
		$title = preg_replace( '/<span[^>]*class=["\'][^"\']*(?:screen-reader-text|count-\d+)[^"\']*["\'][^>]*>.*?<\/span>(\s*<\/span>)?/is', '', $title );
		$title = wp_strip_all_tags( $title );
		$title = html_entity_decode( $title, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$title = preg_replace( '/\s+/', ' ', $title );

		return trim( $title );
	}

	private static function admin_url_for_slug( $slug, $parent_slug = '' ) {
		$slug        = html_entity_decode( (string) $slug, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$parent_slug = html_entity_decode( (string) $parent_slug, ENT_QUOTES, get_bloginfo( 'charset' ) );

		if ( '' === $slug ) {
			return admin_url();
		}

		if ( preg_match( '#^(https?:)?//#i', $slug ) ) {
			return $slug;
		}

		if ( self::is_core_admin_file( $slug ) ) {
			return admin_url( $slug );
		}

		if ( '' !== $parent_slug && self::is_core_admin_file( $parent_slug ) ) {
			return self::admin_page_url( admin_url( $parent_slug ), $slug );
		}

		return self::admin_page_url( admin_url( 'admin.php' ), $slug );
	}

	private static function admin_page_url( $base_url, $slug ) {
		$page  = $slug;
		$query = '';

		if ( false !== strpos( $slug, '?' ) ) {
			list( $page, $query ) = explode( '?', $slug, 2 );
		} elseif ( false !== strpos( $slug, '&' ) ) {
			list( $page, $query ) = explode( '&', $slug, 2 );
		}

		$url = add_query_arg( 'page', $page, $base_url );

		if ( '' !== $query ) {
			$url .= false === strpos( $url, '?' ) ? '?' : '&';
			$url .= ltrim( $query, '&' );
		}

		return $url;
	}

	private static function is_core_admin_file( $slug ) {
		$path = wp_parse_url( $slug, PHP_URL_PATH );

		if ( ! is_string( $path ) || false === strpos( $path, '.php' ) ) {
			return false;
		}

		$path = ltrim( $path, '/' );

		return file_exists( ABSPATH . 'wp-admin/' . $path );
	}
}

Logiprof_Admin_Menu_Manager::init();

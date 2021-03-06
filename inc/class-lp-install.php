<?php
/**
 * Install and update functions
 *
 * @author  ThimPress
 * @version 1.0
 * @see     https://codex.wordpress.org/Creating_Tables_with_Plugins
 */

/**
 * Prevent loading this file directly
 */
defined( 'ABSPATH' ) || exit;

/**
 * Class LP_Install
 */
class LP_Install {

	/**
	 * Hold the file for each update
	 *
	 * @var array
	 */
	private static $_update_files = array();

	private static $_is_old_version = null;

	/**
	 * Init
	 */
	static function init() {
		add_action( 'admin_init', array( __CLASS__, 'get_update_versions' ), - 15 );
		add_action( 'admin_init', array( __CLASS__, 'include_update' ), - 10 );
		add_action( 'admin_init', array( __CLASS__, 'update_from_09' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'db_update_notices' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'update_actions' ), 5 );
		add_action( 'wp_ajax_lp_repair_database', array( __CLASS__, 'repair_database' ) );
		add_action( 'wp_ajax_lp_rollback_database', array( __CLASS__, 'rollback_database' ) );
		add_action( 'wp_ajax_learn_press_hide_upgrade_notice', array( __CLASS__, 'hide_upgrade_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'upgrade_wizard' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	static function include_update() {
		if ( !self::$_update_files ) {
			return;
		}
		$versions       = array_keys( self::$_update_files );
		$latest_version = end( $versions );
		// Update LearnPress from 0.9.x to 1.0
		if ( version_compare( learn_press_get_current_version(), $latest_version, '=' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'hide_other_notices' ), - 100 );
			learn_press_include( 'updates/' . self::$_update_files[$latest_version] );
		}
	}

	static function hide_other_notices() {
		//remove_action( 'admin_notices', 'learn_press_one_click_install_sample_data_notice' );
	}

	static function update_from_09() {

		if ( !self::_has_new_table() ) {
			//self::_create_tables();
			self::install();
		}
		if ( !get_option( 'learnpress_version' ) || !get_option( 'learn_press_currency' ) ) {
			self::_create_options();
		}
		$ask = get_transient( 'learn_press_upgrade_courses_ask_again' );
		if ( self::_need_to_update() ) {
			// Notify for administrator
			if ( empty( $ask ) && learn_press_current_user_is( 'administrator' ) ) {
				LP_Admin_Assets::enqueue_style( 'learn-press-upgrade', LP()->plugin_url( 'inc/updates/0.9/style.css' ) );
				LP_Admin_Assets::enqueue_script( 'learn-press-upgrade', LP()->plugin_url( 'inc/updates/0.9/script.js' ) );
				$upgrade_url = wp_nonce_url( admin_url( 'options-general.php?page=learn_press_upgrade_from_09' ), 'learn-press-upgrade-09' );
				$message     = sprintf( '<p>%s</p>', __( 'It seem to be you have updated LearnPress from old version and there are some courses or data is out of date and need to upgrade.', 'learnpress' ) );
				$message .= sprintf( '<div id="learn-press-confirm-abort-upgrade-course"><p><label><input type="checkbox" id="learn-press-ask-again-abort-upgrade" /> %s</label></p><p><button href="" class="button disabled" data-action="yes">%s</button> <button href="" class="button" data-action="no">%s</button> </p></div>', __( 'Do not ask again.', 'learnpress' ), __( 'Ok', 'learnpress' ), __( 'Cancel', 'learnpress' ) );
				$message .= sprintf( '<p id="learn-press-upgrade-course-actions"><a href="%s" class="button" data-action="upgrade">%s</a>&nbsp;<button class="button disabled" data-action="abort">%s</button></p>', $upgrade_url, __( 'Upgrade now', 'learnpress' ), __( 'No, thank!', 'learnpress' ) );

				LP_Admin_Notice::add( $message, 'error' );
			}

			// Notify for instructor
			if ( learn_press_current_user_is( 'instructor' ) ) {
				LP_Admin_Notice::add( sprintf( '<p>%s</p>', __( 'LearnPress has upgraded and need to upgrade the database before you can work with it. Please notify the site administrator.', 'learnpress' ) ), 'error' );
			}
		}
	}

	static function admin_menu() {
		add_dashboard_page( '', '', 'manage_options', 'learn_press_upgrade_from_09', '' );
	}

	static function hide_upgrade_notice() {
		$ask_again  = learn_press_get_request( 'ask_again' );
		$expiration = DAY_IN_SECONDS;
		if ( $ask_again == 'no' ) {
			$expiration = 0;
		}
		set_transient( 'learn_press_upgrade_courses_ask_again', $ask_again, $expiration );
		learn_press_send_json( array( 'result' => 'success', 'message' => sprintf( '<p>%s</p>', __( 'Thank for using LearnPress', 'learnpress' ) ) ) );
	}

	static function upgrade_wizard() {
		require_once LP_PLUGIN_PATH . '/inc/updates/_update-from-0.9.php';
	}

	/**
	 * Auto get update patches from inc/updates path
	 */
	static function get_update_versions() {
		if ( !$patches = get_transient( 'learnpress_update_patches' ) ) {
			$patches = array();
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( WP_Filesystem() ) {
				global $wp_filesystem;

				$list = $wp_filesystem->dirlist( LP_PLUGIN_PATH . '/inc/updates' );
				foreach ( $list as $file ) {
					if ( preg_match( '!learnpress-update-([0-9.]+).php!', $file['name'], $matches ) ) {
						$patches[$matches[1]] = $file['name'];
					}
				}
			}
			if ( $patches ) {
				ksort( $patches );
				self::$_update_files = $patches;
			}
		} else {
			self::$_update_files = $patches;
		}
	}

	/**
	 * Check version
	 */
	static function check_version() {
		if ( !defined( 'IFRAME_REQUEST' ) && ( get_option( 'learnpress_version' ) != LP()->version ) ) {
			self::install();
		}
	}

	/**
	 * Install update actions when user click update button
	 */
	static function update_actions() {
		if ( !empty( $_GET['upgrade_learnpress'] ) ) {
			self::update();
		}
	}

	/**
	 * Check for new database version and show notice
	 */
	static function db_update_notices() {
		if ( get_option( 'learnpress_db_version' ) != LP()->db_version ) {
			//LP_Admin_Notice::add( __( '<p>LearnPress ' . LP()->version . ' need to upgrade your database.</p><p><a href="' . admin_url( 'admin.php?page=learnpress_update_10' ) . '" class="button">Update Now</a></p>', 'learnpress' ) );
		}
	}

	static function install() {
		global $wpdb;
		self::_create_options();
		self::_create_tables();
		self::create_files();
		self::create_pages();

		$current_version    = get_option( 'learnpress_version' );
		$current_db_version = get_option( 'learnpress_db_version' );

		// is new install
		if ( is_null( $current_version ) && is_null( $current_db_version ) ) {

		}
		// Update version
		self::update_db_version();
		self::update_version();
		update_option( '_learn_press_flush_rewrite_rules', 'yes' );

		$sql = "DELETE a, b FROM $wpdb->options a, $wpdb->options b
			WHERE a.option_name LIKE %s
			AND a.option_name NOT LIKE %s
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < %d";
		$wpdb->query( $wpdb->prepare( $sql, $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );

	}

	static function _search_page( $type ) {
		global $wpdb;
		$query   = $wpdb->prepare( "
			SELECT ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
		", '_learn_press_page', $type );
		$page_id = $wpdb->get_var( $query );

		return $page_id;
	}

	static function create_pages() {
		global $wpdb;
		$pages = array( 'checkout', 'cart', 'profile', 'courses', 'become_a_teacher' );

		foreach ( $pages as $page ) {
			$page_id = get_option( "learn_press_{$page}_page_id" );
			if ( $page_id && get_post_type( $page_id ) == 'page' ) {
				continue;
			}
			$page_id = self::_search_page( $page );
			if ( !$page_id ) {
				// Check if page has already existed
				switch ( $page ) {
					case 'courses':
						$_lpr_settings_pages = (array) get_option( '_lpr_settings_pages' );

						if ( !empty( $_lpr_settings_pages['general'] ) ) {
							if ( !empty( $_lpr_settings_pages['general']['courses_page_id'] ) ) {
								$page_id = $_lpr_settings_pages['general']['courses_page_id'];
							}
						}
						break;
					case 'profile':
						$_lpr_settings_general = (array) get_option( '_lpr_settings_general' );
						if ( !empty( $_lpr_settings_general['set_page'] ) && $_lpr_settings_general['set_page'] == 'lpr_profile' ) {
							$page_id = $wpdb->get_var(
								$wpdb->prepare( "
									SELECT ID
										FROM $wpdb->posts p
										INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = %s AND pm.meta_value = %d
								", '_lpr_is_profile_page', 1 )
							);
						}
						break;
				}

				if ( !$page_id && wp_insert_post(
						array(
							'post_title'     => 'LP ' . ucwords( str_replace( '_', ' ', $page ) ),
							'post_status'    => 'publish',
							'post_type'      => 'page',
							'comment_status' => 'closed'
						)
					)
				) {
					$page_id = $wpdb->insert_id;
				}
			}
			if ( $page_id ) {
				update_option( "learn_press_{$page}_page_id", $page_id );
				update_post_meta( $page_id, '_learn_press_page', $page );
			}
		}
		flush_rewrite_rules();
	}

	static function create_files() {
		$upload_dir = wp_upload_dir();
		$files      = array(
			array(
				'base'    => LP_LOG_PATH,
				'file'    => '.htaccess',
				'content' => 'deny from all'
			),
			array(
				'base'    => LP_LOG_PATH,
				'file'    => 'index.html',
				'content' => ''
			)
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && !file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				if ( $file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' ) ) {
					fwrite( $file_handle, $file['content'] );
					fclose( $file_handle );
				}
			}
		}
	}

	private function _is_old_version() {
		if ( is_null( self::$_is_old_version ) ) {
			$is_old_version = get_transient( 'learn_press_is_old_version' );

			if ( empty( $is_old_version ) ) {
				if ( !get_option( 'learnpress_db_version' ) ||
					get_posts(
						array(
							'post_type'      => 'lpr_course',
							'post_status'    => 'any',
							'posts_per_page' => 1
						)
					)
				) {
					$is_old_version = 'yes';
				}
				if ( empty( $is_old_version ) ) {
					$is_old_version = 'no';
				}
				set_transient( 'learn_press_is_old_version', $is_old_version );
			}
			self::$_is_old_version = $is_old_version == 'yes';
		}
		return self::$_is_old_version;
	}

	/**
	 * Find if there is any old course and did not upgrade
	 * If a course has got a meta key like _learn_press_upgraded that means it is not upgraded
	 *
	 * @return mixed
	 */
	private static function _has_old_posts() {
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT DISTINCT p.ID, pm.meta_value as upgraded
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			WHERE post_type = %s
			HAVING upgraded IS NULL
			LIMIT 0, 1
		", '_learn_press_upgraded', 'lpr_course' );
		return $wpdb->get_row( $query );
	}

	private static function _has_new_table() {
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT COUNT(*)
			FROM information_schema.tables
			WHERE table_schema = %s
			AND table_name LIKE %s
		", DB_NAME, '%learnpress_sections%' );
		return $wpdb->get_var( $query ) ? true : false;
	}

	private static function _need_to_update() {
		return self::_has_old_posts() || self::_has_old_teacher_role();
	}

	private static function _has_old_teacher_role() {
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT um.*
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
			WHERE um.meta_value LIKE %s
			LIMIT 0, 1
		", 'wp_capabilities', '%"lpr_teacher"%' );
		return $wpdb->get_results( $query );
	}

	private static function _has_new_posts() {
		$new_post = get_posts(
			array(
				'post_type'      => 'lp_course',
				'post_status'    => 'any',
				'posts_per_page' => 1
			)
		);
		return sizeof( $new_post ) > 0;
	}

	static function update() {
		$learnpress_db_version = get_option( 'learnpress_db_version' );
		foreach ( self::$_update_files as $version => $updater ) {
			if ( version_compare( $learnpress_db_version, $version, '<' ) ) {
				include( $updater );
				self::update_db_version( $version );
			}
		}

		self::update_db_version();
	}

	static function update_db_version( $version = null ) {
		delete_option( 'learnpress_db_version' );
		add_option( 'learnpress_db_version', is_null( $version ) ? LEARNPRESS_DB_VERSION : $version );
	}

	static function update_version( $version = null ) {
		delete_option( 'learnpress_version' );
		add_option( 'learnpress_version', is_null( $version ) ? LEARNPRESS_VERSION : $version );
	}

	private static function _create_options() {
		global $wpdb;
		include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-base.php';

		$settings_classes = array(
			include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-general.php',
			include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-courses.php',
			include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-checkout.php',
			include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-profile.php',
			include_once LP_PLUGIN_PATH . '/inc/admin/settings/class-lp-settings-emails.php'
		);
		foreach ( $settings_classes as $class ) {
			if ( is_callable( array( $class, 'get_settings' ) ) ) {
				$options = $class->get_settings();
				foreach ( $options as $option ) {
					if ( isset( $option['default'] ) && isset( $option['id'] ) ) {
						$autoload = isset( $option['autoload'] ) ? (bool) $option['autoload'] : true;
						update_option( $option['id'], $option['default'], '', ( $autoload ? 'yes' : 'no' ) );
					}
				}
			}
		}
		$custom_options = array(
			'learn_press_course_base_type'   => 'custom',
			'learn_press_paypal_email'       => get_option( 'admin_email' ),
			'learn_press_paypal_enable'      => 'yes',
			'learn_press_profile_endpoints'  => 'a:4:{s:15:"profile-courses";s:7:"courses";s:15:"profile-quizzes";s:7:"quizzes";s:14:"profile-orders";s:6:"orders";s:21:"profile-order-details";s:13:"order-details";}',
			'learn_press_checkout_endpoints' => 'a:1:{s:17:"lp_order_received";s:17:"lp-order-received";}'
		);
		foreach ( $custom_options as $option_name => $option_value ) {
			if ( !get_option( $option_name ) ) {
				update_option( $option_name, maybe_unserialize( $option_value ), 'yes' );
			}
		}
		set_transient( 'learn_press_install', 'yes', 24 * 3600 );
	}

	private static function _create_tables() {
		global $wpdb;

		$wpdb->hide_errors();

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( self::_get_schema() );
	}

	private static function _get_schema() {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( !empty( $wpdb->charset ) ) {
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( !empty( $wpdb->collate ) ) {
				$collate .= " COLLATE $wpdb->collate";
			}
		}

		return "
CREATE TABLE {$wpdb->prefix}learnpress_order_itemmeta (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  learnpress_order_item_id bigint(20) unsigned NOT NULL DEFAULT '0',
  meta_key varchar(45) NOT NULL DEFAULT '',
  meta_value longtext NOT NULL,
  PRIMARY KEY (meta_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_order_items (
  order_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_item_name longtext NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (order_item_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_question_answers (
  question_answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  question_id bigint(20) unsigned NOT NULL DEFAULT '0',
  answer_data text NOT NULL,
  answer_order bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (question_answer_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_quiz_questions (
  quiz_question_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_id bigint(11) unsigned NOT NULL DEFAULT '0',
  question_id bigint(11) unsigned NOT NULL DEFAULT '0',
  question_order bigint(11) unsigned NOT NULL DEFAULT '1',
  params longtext NULL,
  PRIMARY KEY (quiz_question_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_review_logs (
  review_log_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(11) unsigned NOT NULL DEFAULT '0',
  user_id bigint(11) unsigned NOT NULL DEFAULT '0',
  message text NOT NULL,
  date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  status varchar(45) NOT NULL DEFAULT '',
  user_type varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (review_log_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_section_items (
  section_item_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  section_id bigint(11) unsigned NOT NULL DEFAULT '0',
  item_id bigint(11) unsigned NOT NULL DEFAULT '0',
  item_order bigint(11) unsigned NOT NULL DEFAULT '0',
  item_type varchar(45),
  PRIMARY KEY (section_item_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_sections (
  section_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  section_name varchar(255) NOT NULL DEFAULT '',
  section_course_id bigint(11) unsigned NOT NULL DEFAULT '0',
  section_order bigint(5) unsigned NOT NULL DEFAULT '0',
  section_description longtext NOT NULL,
  PRIMARY KEY (section_id)
) $collate;
CREATE TABLE  {$wpdb->prefix}learnpress_user_courses (
  user_course_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(11) unsigned NOT NULL DEFAULT '0',
  course_id bigint(11) unsigned NOT NULL DEFAULT '0',
  start_time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  end_time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  status varchar(45) NOT NULL DEFAULT '',
  order_id bigint(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (user_course_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_user_quizmeta (
  learnpress_user_quiz_id bigint(11) unsigned NOT NULL,
  meta_key varchar(45) NOT NULL DEFAULT '',
  meta_value text NOT NULL,
  meta_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (meta_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_user_quizzes (
  user_quiz_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(11) unsigned NOT NULL DEFAULT '0',
  quiz_id bigint(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (user_quiz_id)
) $collate;
CREATE TABLE {$wpdb->prefix}learnpress_user_lessons (
  user_lesson_id bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(11) unsigned NOT NULL,
  lesson_id bigint(11) unsigned NOT NULL,
  course_id bigint(11) DEFAULT NULL,
  start_time datetime DEFAULT NULL,
  end_time datetime DEFAULT NULL,
  status varchar(20) DEFAULT NULL,
  PRIMARY KEY (`user_lesson_id`)
)
";
	}
}

LP_Install::init();

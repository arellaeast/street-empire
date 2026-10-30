<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Install {

	public static function activate() {
		self::create_tables();
		update_option( 'se_db_version', SE_VERSION );
		// Seed defaults so the settings pages are populated on first load.
		if ( ! get_option( 'se_settings' ) ) update_option( 'se_settings', SE_Settings::defaults() );
		if ( ! get_option( 'se_labels' ) )   update_option( 'se_labels', SE_Labels::defaults() );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'se_round_reset_event' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'se_db_version' ) !== SE_VERSION ) {
			self::create_tables();
			update_option( 'se_db_version', SE_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$players = "CREATE TABLE {$wpdb->prefix}se_players (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			alias VARCHAR(60) NOT NULL DEFAULT '',
			cash BIGINT NOT NULL DEFAULT 0,
			turns INT NOT NULL DEFAULT 0,
			workers INT NOT NULL DEFAULT 0,
			muscle INT NOT NULL DEFAULT 0,
			guns INT NOT NULL DEFAULT 0,
			defense INT NOT NULL DEFAULT 0,
			score BIGINT NOT NULL DEFAULT 0,
			attacks_won INT NOT NULL DEFAULT 0,
			attacks_lost INT NOT NULL DEFAULT 0,
			crew_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_accrual DATETIME NOT NULL,
			protected_until DATETIME NULL DEFAULT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY score (score),
			KEY crew_id (crew_id)
		) $charset;";

		$crews = "CREATE TABLE {$wpdb->prefix}se_crews (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(40) NOT NULL,
			leader_user_id BIGINT UNSIGNED NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)
		) $charset;";

		$invites = "CREATE TABLE {$wpdb->prefix}se_crew_invites (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			crew_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY crew_user (crew_id, user_id),
			KEY user_id (user_id)
		) $charset;";

		$log = "CREATE TABLE {$wpdb->prefix}se_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(30) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY actor_id (actor_id),
			KEY target_id (target_id),
			KEY created (created)
		) $charset;";

		dbDelta( $players );
		dbDelta( $crews );
		dbDelta( $invites );
		dbDelta( $log );
	}
}

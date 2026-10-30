<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_se_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_se_save_labels', [ __CLASS__, 'save_labels' ] );
		add_action( 'admin_post_se_save_theme', [ __CLASS__, 'save_theme' ] );
		add_action( 'admin_post_se_delete_theme', [ __CLASS__, 'delete_theme' ] );
		add_action( 'admin_post_se_save_player', [ __CLASS__, 'save_player' ] );
		add_action( 'admin_post_se_reset_round', [ __CLASS__, 'do_reset_round' ] );
	}

	public static function menu() {
		$name = SE_Labels::raw( 'game_name' );
		add_menu_page( $name, $name, 'manage_options', 'street-empire', [ __CLASS__, 'page_settings' ], 'dashicons-money-alt', 58 );
		add_submenu_page( 'street-empire', 'Game Settings', 'Game Settings', 'manage_options', 'street-empire', [ __CLASS__, 'page_settings' ] );
		add_submenu_page( 'street-empire', 'Terminology', 'Terminology', 'manage_options', 'se-labels', [ __CLASS__, 'page_labels' ] );
		add_submenu_page( 'street-empire', 'Themes', 'Themes', 'manage_options', 'se-themes', [ __CLASS__, 'page_themes' ] );
		add_submenu_page( 'street-empire', 'Players', 'Players', 'manage_options', 'se-players', [ __CLASS__, 'page_players' ] );
	}

	/* ------------------------- Settings page ------------------------- */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = SE_Settings::all();
		$fields = [
			'Turn economy' => [
				'turns_per_tick'   => 'Turns granted per tick',
				'tick_minutes'     => 'Minutes per tick',
				'max_turns'        => 'Free-accrual turn cap',
				'starting_turns'   => 'Starting turns (new player / new round)',
				'starting_cash'    => 'Starting ' . SE_Labels::raw( 'currency' ),
			],
			'Income & recruiting' => [
				'income_per_worker'  => SE_Labels::raw( 'currency' ) . ' per ' . SE_Labels::raw( 'worker_singular' ) . ' per turn',
				'scout_find_chance'  => 'Scout success chance per turn (%)',
				'upkeep_per_muscle'  => 'Upkeep per ' . SE_Labels::raw( 'muscle_singular' ) . ' per turn',
			],
			'Prices' => [
				'price_muscle'  => SE_Labels::raw( 'muscle_singular' ) . ' price',
				'price_gun'     => SE_Labels::raw( 'gun_singular' ) . ' price',
				'price_defense' => SE_Labels::raw( 'defense_label' ) . ' price (per point)',
			],
			'Combat' => [
				'attack_turn_cost'    => 'Turn cost per ' . strtolower( SE_Labels::raw( 'attack_verb' ) ),
				'steal_cash_pct'      => '% ' . SE_Labels::raw( 'currency' ) . ' stolen on win',
				'steal_worker_pct'    => '% ' . SE_Labels::raw( 'worker_plural' ) . ' stolen on win',
				'casualty_loser_pct'  => 'Loser casualty %',
				'casualty_winner_pct' => 'Winner casualty %',
				'protection_hours'    => 'New player protection (hours)',
				'notify_on_attack'    => 'Email defender when attacked (1 = on, max 1/hr)',
			],
			'Sabotage' => [
				'sabotage_turn_cost'  => 'Turn cost per sabotage raid',
				'driveby_kill_pct'    => SE_Labels::raw( 'driveby_label' ) . ': % of target ' . SE_Labels::raw( 'muscle_plural' ) . ' killed',
				'jack_steal_pct'      => SE_Labels::raw( 'jack_label' ) . ': % of target ' . SE_Labels::raw( 'gun_plural' ) . ' stolen',
			],
			'Crews' => [
				'crew_create_cost'       => SE_Labels::raw( 'crew_label' ) . ' founding cost',
				'crew_max_members'       => 'Max members per ' . SE_Labels::raw( 'crew_label' ),
				'crew_defense_bonus_pct' => 'Defense bonus % per crewmate',
				'crew_defense_bonus_cap' => 'Defense bonus cap %',
			],
			'Monetization' => [
				'upsell_product_id'   => 'Turn-pack product ID to promote when a player runs low (0 = off)',
			],
			'Round prizes (WooCommerce coupons)' => [
				'prizes_enabled'     => 'Award coupons to top 3 at round reset (1 = on)',
				'prize_1_amount'     => '1st place coupon value',
				'prize_2_amount'     => '2nd place coupon value',
				'prize_3_amount'     => '3rd place coupon value',
				'prize_expiry_days'  => 'Coupon expiry (days)',
			],
			'Display defaults' => [
				'default_theme' => 'Default theme for players who haven\'t chosen',
				'default_text'  => 'Default text size for players who haven\'t chosen',
			],
			'Rounds' => [
				'round_reset_enabled' => 'Monthly round reset (1 = on, 0 = off)',
			],
		];
		echo '<div class="wrap"><h1>' . esc_html( SE_Labels::raw( 'game_name' ) ) . ' — Game Settings</h1>';
		if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Saved.</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'se_save_settings' );
		echo '<input type="hidden" name="action" value="se_save_settings">';
		$select_fields = [
			'default_theme' => SE_Themes::choices(),
			'default_text'  => [ 'std' => 'Standard', 'lg' => 'Large', 'xl' => 'Extra large' ],
		];
		foreach ( $fields as $section => $rows ) {
			echo '<h2>' . esc_html( $section ) . '</h2><table class="form-table">';
			foreach ( $rows as $key => $label ) {
				if ( isset( $select_fields[ $key ] ) ) {
					printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select name="%1$s" id="%1$s">', esc_attr( $key ), esc_html( $label ) );
					foreach ( $select_fields[ $key ] as $val => $text ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $s[ $key ], $val, false ), esc_html( $text ) );
					}
					echo '</select></td></tr>';
					continue;
				}
				printf(
					'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" min="0" name="%1$s" id="%1$s" value="%3$s" class="small-text"></td></tr>',
					esc_attr( $key ), esc_html( $label ), esc_attr( $s[ $key ] )
				);
			}
			echo '</table>';
		}
		submit_button( 'Save settings' );
		echo '</form>';

		// Round reset (manual).
		echo '<hr><h2>Reset round now</h2><p>Resets every player to starting state and clears the event log. This cannot be undone.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Really reset the entire round?\');">';
		wp_nonce_field( 'se_reset_round' );
		echo '<input type="hidden" name="action" value="se_reset_round">';
		submit_button( 'Reset round', 'delete' );
		echo '</form></div>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( 'se_save_settings' );
		SE_Settings::save( wp_unslash( $_POST ) );
		wp_safe_redirect( admin_url( 'admin.php?page=street-empire&updated=1' ) );
		exit;
	}

	public static function do_reset_round() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( 'se_reset_round' );
		SE_Game::reset_round();
		wp_safe_redirect( admin_url( 'admin.php?page=street-empire&updated=1' ) );
		exit;
	}

	/* ------------------------- Labels page --------------------------- */

	public static function page_labels() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$l = SE_Labels::all();
		$nice = [
			'game_name'       => 'Game name',
			'currency'        => 'Currency name',
			'currency_symbol' => 'Currency symbol',
			'worker_singular' => 'Income unit (singular)',
			'worker_plural'   => 'Income unit (plural)',
			'muscle_singular' => 'Combat unit (singular)',
			'muscle_plural'   => 'Combat unit (plural)',
			'gun_singular'    => 'Weapon (singular)',
			'gun_plural'      => 'Weapon (plural)',
			'defense_label'   => 'Defense stat name',
			'turns_label'     => 'Turns name',
			'scout_verb'      => 'Recruit action verb',
			'hustle_verb'     => 'Income action verb',
			'attack_verb'     => 'Attack action verb',
			'driveby_label'   => 'Sabotage raid 1 name (kills combat units)',
			'jack_label'      => 'Sabotage raid 2 name (steals weapons)',
			'territory_label' => 'Territory name',
			'crew_label'      => 'Alliance/group name',
			'rankings_title'  => 'Rankings page title',
			'player_noun'     => 'Player noun',
		];
		echo '<div class="wrap"><h1>Terminology</h1>';
		echo '<p>Every player-facing term in the game is set here. Rename units, currency, and verbs to retheme the whole game — no code changes needed.</p>';
		if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Saved.</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'se_save_labels' );
		echo '<input type="hidden" name="action" value="se_save_labels"><table class="form-table">';
		foreach ( $nice as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" name="%1$s" id="%1$s" value="%3$s" class="regular-text"></td></tr>',
				esc_attr( $key ), esc_html( $label ), esc_attr( $l[ $key ] )
			);
		}
		echo '</table>';
		submit_button( 'Save terminology' );
		echo '</form></div>';
	}

	public static function save_labels() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( 'se_save_labels' );
		SE_Labels::save( $_POST );
		wp_safe_redirect( admin_url( 'admin.php?page=se-labels&updated=1' ) );
		exit;
	}

	/* ------------------------- Themes page --------------------------- */

	public static function page_themes() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$edit_slug = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$editing   = $edit_slug ? SE_Themes::get( $edit_slug ) : null;
		$fields    = SE_Themes::fields();
		$values    = $editing ? $editing['colors'] : array_combine( array_keys( $fields ), array_column( $fields, 1 ) );

		echo '<div class="wrap"><h1>Color Themes</h1>';
		if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Saved.</p></div>';
		if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success"><p>Theme deleted.</p></div>';
		echo '<p>Built-in themes (Dark, Light) plus any themes you create here appear in every player\'s theme dropdown and in the site default setting. Players who selected a deleted theme fall back to the site default.</p>';

		// Existing themes list.
		echo '<h2>Themes</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>Name</th><th>Slug</th><th>Preview</th><th></th></tr></thead><tbody>';
		echo '<tr><td>Dark</td><td><code>dark</code></td><td>built-in</td><td>—</td></tr>';
		echo '<tr><td>Light (high contrast)</td><td><code>light</code></td><td>built-in</td><td>—</td></tr>';
		foreach ( SE_Themes::all_custom() as $slug => $theme ) {
			$c = $theme['colors'];
			$swatches = '';
			foreach ( [ 'bg', 'panel', 'text', 'accent', 'danger' ] as $k ) {
				$swatches .= sprintf( '<span style="display:inline-block;width:22px;height:22px;border:1px solid #ccc;vertical-align:middle;background:%s" title="%s"></span>', esc_attr( $c[ $k ] ), esc_attr( $k ) );
			}
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=se_delete_theme&slug=' . $slug ), 'se_delete_theme_' . $slug );
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td><a href="%s">Edit</a> · <a href="%s" onclick="return confirm(\'Delete this theme?\')">Delete</a></td></tr>',
				esc_html( $theme['name'] ), esc_html( $slug ), $swatches,
				esc_url( admin_url( 'admin.php?page=se-themes&edit=' . $slug ) ),
				esc_url( $del )
			);
		}
		echo '</tbody></table>';

		// Create / edit form.
		echo '<h2>' . ( $editing ? 'Edit theme: ' . esc_html( $editing['name'] ) : 'Create theme' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'se_save_theme' );
		echo '<input type="hidden" name="action" value="se_save_theme">';
		if ( $editing ) echo '<input type="hidden" name="slug" value="' . esc_attr( $edit_slug ) . '">';
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row"><label for="theme_name">Theme name</label></th><td><input type="text" name="theme_name" id="theme_name" class="regular-text" value="%s" required></td></tr>',
			$editing ? esc_attr( $editing['name'] ) : ''
		);
		foreach ( $fields as $key => $meta ) {
			printf(
				'<tr><th scope="row"><label for="color_%1$s">%2$s</label></th><td><input type="color" name="colors[%1$s]" id="color_%1$s" value="%3$s"> <code>%3$s</code></td></tr>',
				esc_attr( $key ), esc_html( $meta[0] ), esc_attr( $values[ $key ] )
			);
		}
		echo '</table><p class="description">Tip: aim for strong contrast between Background and Text, and between button colors and their text colors — the game may be played by older readers.</p>';
		submit_button( $editing ? 'Save theme' : 'Create theme' );
		echo '</form></div>';
	}

	public static function save_theme() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( 'se_save_theme' );
		$colors = isset( $_POST['colors'] ) && is_array( $_POST['colors'] ) ? wp_unslash( $_POST['colors'] ) : [];
		SE_Themes::save_theme(
			isset( $_POST['theme_name'] ) ? wp_unslash( $_POST['theme_name'] ) : '',
			$colors,
			isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : ''
		);
		wp_safe_redirect( admin_url( 'admin.php?page=se-themes&updated=1' ) );
		exit;
	}

	public static function delete_theme() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		$slug = isset( $_GET['slug'] ) ? sanitize_key( $_GET['slug'] ) : '';
		check_admin_referer( 'se_delete_theme_' . $slug );
		SE_Themes::delete( $slug );
		wp_safe_redirect( admin_url( 'admin.php?page=se-themes&deleted=1' ) );
		exit;
	}

	/* ------------------------- Players page -------------------------- */

	public static function page_players() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;

		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		if ( $edit_id ) {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SE_Player::table() . ' WHERE id = %d', $edit_id ) );
			if ( $p ) {
				echo '<div class="wrap"><h1>Edit player: ' . esc_html( $p->alias ) . '</h1>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><table class="form-table">';
				wp_nonce_field( 'se_save_player' );
				echo '<input type="hidden" name="action" value="se_save_player"><input type="hidden" name="player_id" value="' . (int) $p->id . '">';
				foreach ( [ 'cash', 'turns', 'workers', 'muscle', 'guns', 'defense' ] as $f ) {
					printf(
						'<tr><th scope="row"><label for="%1$s">%1$s</label></th><td><input type="number" min="0" name="%1$s" id="%1$s" value="%2$s"></td></tr>',
						esc_attr( $f ), esc_attr( $p->{$f} )
					);
				}
				echo '</table>';
				submit_button( 'Save player' );
				echo '</form></div>';
				return;
			}
		}

		$players = $wpdb->get_results( 'SELECT * FROM ' . SE_Player::table() . ' ORDER BY score DESC LIMIT 200' );
		echo '<div class="wrap"><h1>Players</h1>';
		if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Saved.</p></div>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Alias</th><th>User</th><th>Score</th><th>'
			. SE_Labels::get( 'currency' ) . '</th><th>' . SE_Labels::get( 'turns_label' ) . '</th><th>'
			. SE_Labels::get( 'worker_plural' ) . '</th><th>' . SE_Labels::get( 'muscle_plural' ) . '</th><th></th></tr></thead><tbody>';
		$i = 1;
		foreach ( $players as $p ) {
			$user = get_userdata( $p->user_id );
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td><a href="%s">Edit</a></td></tr>',
				$i++, esc_html( $p->alias ), $user ? esc_html( $user->user_login ) : '—',
				number_format_i18n( $p->score ), SE_Labels::money( $p->cash ),
				(int) $p->turns, (int) $p->workers, (int) $p->muscle,
				esc_url( admin_url( 'admin.php?page=se-players&edit=' . (int) $p->id ) )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function save_player() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( 'se_save_player' );
		global $wpdb;
		$id = isset( $_POST['player_id'] ) ? (int) $_POST['player_id'] : 0;
		$p  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SE_Player::table() . ' WHERE id = %d', $id ) );
		if ( $p ) {
			foreach ( [ 'cash', 'turns', 'workers', 'muscle', 'guns', 'defense' ] as $f ) {
				if ( isset( $_POST[ $f ] ) ) $p->{$f} = max( 0, (int) $_POST[ $f ] );
			}
			SE_Player::save( $p );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=se-players&updated=1' ) );
		exit;
	}
}

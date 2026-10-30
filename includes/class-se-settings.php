<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Settings {

	protected static $cache = null;

	public static function defaults() {
		return [
			// Turn economy (classic Pimp War pacing: 2 turns / 10 min, cap 200).
			'turns_per_tick'     => 2,
			'tick_minutes'       => 10,
			'max_turns'          => 200,
			'starting_turns'     => 50,
			'starting_cash'      => 2000,
			// Income & recruiting.
			'income_per_worker'  => 40,   // cash per worker per turn spent hustling
			'scout_find_chance'  => 55,   // % chance per turn spent scouting to find 1 worker
			'upkeep_per_muscle'  => 2,    // cash per muscle per hustle turn (payroll drag)
			// Prices.
			'price_muscle'       => 500,
			'price_gun'          => 350,
			'price_defense'      => 400,  // per defense point
			// Combat.
			'attack_turn_cost'   => 10,
			'steal_cash_pct'     => 20,   // % of target's cash on a win
			'steal_worker_pct'   => 10,   // % of target's workers on a win
			'casualty_loser_pct' => 15,   // % muscle the loser buries
			'casualty_winner_pct'=> 5,
			'protection_hours'   => 24,   // new player shield
			// Sabotage.
			'sabotage_turn_cost' => 6,
			'driveby_kill_pct'   => 12,   // % of target muscle killed on a drive-by win
			'jack_steal_pct'     => 15,   // % of target guns stolen on a jack win
			// Monetization & notifications.
			'upsell_product_id'  => 0,    // WooCommerce turn-pack product to promote at low turns
			'notify_on_attack'   => 1,    // email defender when attacked (max 1/hr)
			// Crews.
			'crew_create_cost'       => 10000,
			'crew_max_members'       => 10,
			'crew_defense_bonus_pct' => 3,   // +% defense per crewmate beyond yourself
			'crew_defense_bonus_cap' => 15,  // cap on the total crew bonus
			// Round prizes (WooCommerce coupons issued to top 3 at reset).
			'prizes_enabled'     => 0,
			'prize_1_amount'     => 25,   // coupon value, store currency
			'prize_2_amount'     => 10,
			'prize_3_amount'     => 5,
			'prize_expiry_days'  => 30,
			// Display defaults (users can override for themselves).
			'default_theme'      => 'dark',   // dark | light
			'default_text'       => 'std',    // std | lg | xl
			// Rounds.
			'round_reset_enabled'=> 0,    // 1 = monthly wipe via cron
		];
	}

	public static function all() {
		if ( self::$cache === null ) {
			self::$cache = wp_parse_args( (array) get_option( 'se_settings', [] ), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Non-numeric settings and their allowed values. */
	protected static function string_settings() {
		return [
			'default_theme' => class_exists( 'SE_Themes' ) ? SE_Themes::valid_slugs() : [ 'dark', 'light' ],
			'default_text'  => [ 'std', 'lg', 'xl' ],
		];
	}

	public static function save( array $input ) {
		$clean   = [];
		$strings = self::string_settings();
		foreach ( self::defaults() as $key => $default ) {
			if ( isset( $strings[ $key ] ) ) {
				$val = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : $default;
				$clean[ $key ] = in_array( $val, $strings[ $key ], true ) ? $val : $default;
				continue;
			}
			$clean[ $key ] = isset( $input[ $key ] ) ? max( 0, intval( $input[ $key ] ) ) : $default;
		}
		// Sanity floors.
		$clean['tick_minutes']   = max( 1, $clean['tick_minutes'] );
		$clean['max_turns']      = max( 10, $clean['max_turns'] );
		$clean['attack_turn_cost'] = max( 1, $clean['attack_turn_cost'] );
		update_option( 'se_settings', $clean );
		self::$cache = null;

		// Toggle monthly reset cron.
		wp_clear_scheduled_hook( 'se_round_reset_event' );
		if ( $clean['round_reset_enabled'] ) {
			// First day of next month, 00:05 site time.
			$next = strtotime( gmdate( 'Y-m-01 00:05:00', strtotime( 'first day of next month' ) ) );
			wp_schedule_single_event( $next, 'se_round_reset_event' );
		}
		return $clean;
	}
}

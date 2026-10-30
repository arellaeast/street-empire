<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Game {

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'se_log';
	}

	public static function log( $actor_id, $target_id, $type, $message ) {
		global $wpdb;
		$wpdb->insert( self::log_table(), [
			'actor_id'  => (int) $actor_id,
			'target_id' => (int) $target_id,
			'type'      => sanitize_key( $type ),
			'message'   => wp_kses_post( $message ),
			'created'   => current_time( 'mysql', true ),
		] );
	}

	public static function recent_log_for( $user_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::log_table() . ' WHERE actor_id = %d OR target_id = %d ORDER BY id DESC LIMIT %d',
			(int) $user_id, (int) $user_id, (int) $limit
		) );
	}

	/* ---------------------------------------------------------------- *
	 *  Actions. Each returns [ 'ok' => bool, 'message' => string ]     *
	 * ---------------------------------------------------------------- */

	/** Spend turns scouting for new workers. */
	public static function scout( $player, $turns ) {
		$turns = (int) $turns;
		if ( $turns < 1 || $turns > $player->turns ) {
			return [ 'ok' => false, 'message' => sprintf( 'Not enough %s.', SE_Labels::raw( 'turns_label' ) ) ];
		}
		$chance = (int) SE_Settings::get( 'scout_find_chance' );
		$found  = 0;
		for ( $i = 0; $i < $turns; $i++ ) {
			if ( wp_rand( 1, 100 ) <= $chance ) $found++;
		}
		$player->turns  -= $turns;
		$player->workers += $found;
		SE_Player::save( $player );

		$msg = $found
			? sprintf( 'You went out scouting and recruited %d %s.', $found, SE_Labels::raw( $found === 1 ? 'worker_singular' : 'worker_plural' ) )
			: 'You scouted the streets but came back empty-handed.';
		self::log( $player->user_id, 0, 'scout', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/** Spend turns collecting income from workers (minus muscle payroll). */
	public static function hustle( $player, $turns ) {
		$turns = (int) $turns;
		if ( $turns < 1 || $turns > $player->turns ) {
			return [ 'ok' => false, 'message' => sprintf( 'Not enough %s.', SE_Labels::raw( 'turns_label' ) ) ];
		}
		if ( $player->workers < 1 ) {
			return [ 'ok' => false, 'message' => sprintf( 'You have no %s working for you yet. Go %s first.', SE_Labels::raw( 'worker_plural' ), strtolower( SE_Labels::raw( 'scout_verb' ) ) ) ];
		}
		$income = $player->workers * (int) SE_Settings::get( 'income_per_worker' ) * $turns;
		$upkeep = $player->muscle * (int) SE_Settings::get( 'upkeep_per_muscle' ) * $turns;
		$net    = max( 0, $income - $upkeep );

		$player->turns -= $turns;
		$player->cash  += $net;
		SE_Player::save( $player );

		$msg = sprintf( 'Your %s brought in %s over %d %s.', SE_Labels::raw( 'worker_plural' ), SE_Labels::money( $net ), $turns, SE_Labels::raw( 'turns_label' ) );
		self::log( $player->user_id, 0, 'hustle', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/** Buy muscle / guns / defense with cash. */
	public static function buy( $player, $item, $qty ) {
		$qty = (int) $qty;
		if ( $qty < 1 ) return [ 'ok' => false, 'message' => 'Enter a quantity.' ];

		$map = [
			'muscle'  => [ 'price_muscle',  'muscle',  SE_Labels::raw( $qty === 1 ? 'muscle_singular' : 'muscle_plural' ) ],
			'gun'     => [ 'price_gun',     'guns',    SE_Labels::raw( $qty === 1 ? 'gun_singular' : 'gun_plural' ) ],
			'defense' => [ 'price_defense', 'defense', SE_Labels::raw( 'defense_label' ) ],
		];
		if ( ! isset( $map[ $item ] ) ) return [ 'ok' => false, 'message' => 'Unknown item.' ];

		list( $price_key, $field, $label ) = $map[ $item ];
		$cost = (int) SE_Settings::get( $price_key ) * $qty;
		if ( $cost > $player->cash ) {
			return [ 'ok' => false, 'message' => sprintf( 'Not enough %s — that costs %s.', SE_Labels::raw( 'currency' ), SE_Labels::money( $cost ) ) ];
		}
		$player->cash    -= $cost;
		$player->{$field} += $qty;
		SE_Player::save( $player );

		$msg = sprintf( 'Bought %d %s for %s.', $qty, $label, SE_Labels::money( $cost ) );
		self::log( $player->user_id, 0, 'buy', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/** Attack another player. */
	public static function attack( $attacker, $target_user_id ) {
		$cost = (int) SE_Settings::get( 'attack_turn_cost' );
		$defender = self::raid_guards( $attacker, $target_user_id, $cost );
		if ( is_array( $defender ) ) return $defender;

		// Attacking drops your own protection.
		$attacker->protected_until = null;
		$attacker->turns -= $cost;

		$att_power = self::power( $attacker );
		$def_power = self::power( $defender ) * ( 1 + ( $defender->defense + SE_Crews::defense_bonus( $defender ) ) / 100 );
		// Fog of war: +-20% swing.
		$def_power *= wp_rand( 80, 120 ) / 100;

		$labels = SE_Labels::all();

		if ( $att_power > $def_power ) {
			// Attacker wins.
			$stolen_cash    = (int) floor( $defender->cash * SE_Settings::get( 'steal_cash_pct' ) / 100 );
			$stolen_workers = (int) floor( $defender->workers * SE_Settings::get( 'steal_worker_pct' ) / 100 );
			$att_losses     = (int) ceil( $attacker->muscle * SE_Settings::get( 'casualty_winner_pct' ) / 100 );
			$def_losses     = (int) ceil( $defender->muscle * SE_Settings::get( 'casualty_loser_pct' ) / 100 );

			$attacker->cash    += $stolen_cash;
			$attacker->workers += $stolen_workers;
			$attacker->muscle  -= $att_losses;
			$attacker->attacks_won++;

			$defender->cash    -= $stolen_cash;
			$defender->workers -= $stolen_workers;
			$defender->muscle  -= $def_losses;
			$defender->attacks_lost++;

			SE_Player::save( $attacker );
			SE_Player::save( $defender );

			$msg = sprintf(
				'You hit %1$s and won! Took %2$s and %3$d %4$s. You lost %5$d %6$s; they lost %7$d.',
				esc_html( $defender->alias ), SE_Labels::money( $stolen_cash ), $stolen_workers,
				$labels[ $stolen_workers === 1 ? 'worker_singular' : 'worker_plural' ],
				$att_losses, $labels['muscle_plural'], $def_losses
			);
			self::log( $attacker->user_id, $defender->user_id, 'attack_win', $msg );
			self::log( $defender->user_id, $attacker->user_id, 'defended_loss', sprintf( '%s attacked your %s and won.', esc_html( $attacker->alias ), $labels['territory_label'] ) );
			self::notify_defender( $defender, sprintf( 'You were attacked by %s', $attacker->alias ), sprintf( '%s hit your %s and won. Log in to rebuild and hit back.', $attacker->alias, $labels['territory_label'] ) );
			return [ 'ok' => true, 'message' => $msg ];
		}

		// Defender holds.
		$att_losses = (int) ceil( $attacker->muscle * SE_Settings::get( 'casualty_loser_pct' ) / 100 );
		$attacker->muscle -= $att_losses;
		$attacker->attacks_lost++;
		SE_Player::save( $attacker );
		SE_Player::save( $defender );

		$msg = sprintf(
			'You hit %1$s and got pushed back. You lost %2$d %3$s.',
			esc_html( $defender->alias ), $att_losses, $labels['muscle_plural']
		);
		self::log( $attacker->user_id, $defender->user_id, 'attack_loss', $msg );
		self::log( $defender->user_id, $attacker->user_id, 'defended_win', sprintf( '%s attacked your %s — your crew held them off.', esc_html( $attacker->alias ), $labels['territory_label'] ) );
		self::notify_defender( $defender, sprintf( '%s attacked you and lost', $attacker->alias ), sprintf( '%s attacked your %s and your crew held them off. Log in to counter.', $attacker->alias, $labels['territory_label'] ) );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/** Combat power: muscle, boosted by gun coverage (guns beyond 1/muscle don't help). */
	public static function power( $p ) {
		$muscle = max( 0, (int) $p->muscle );
		if ( ! $muscle ) return 0;
		$armed_ratio = min( 1, $p->guns / $muscle );
		return $muscle * ( 1 + $armed_ratio ); // armed muscle counts double
	}

	/** Shared pre-raid guards. Returns defender row or error array. */
	protected static function raid_guards( $attacker, $target_user_id, $turn_cost ) {
		if ( $attacker->turns < $turn_cost ) {
			return [ 'ok' => false, 'message' => sprintf( 'That costs %d %s.', $turn_cost, SE_Labels::raw( 'turns_label' ) ) ];
		}
		if ( (int) $target_user_id === (int) $attacker->user_id ) {
			return [ 'ok' => false, 'message' => 'You cannot target yourself.' ];
		}
		$defender = SE_Player::get_for_user( $target_user_id, false );
		if ( ! $defender ) return [ 'ok' => false, 'message' => 'Target not found.' ];
		if ( SE_Player::is_protected( $defender ) ) {
			return [ 'ok' => false, 'message' => 'That player is under new-player protection.' ];
		}
		if ( ! empty( $attacker->crew_id ) && (int) $attacker->crew_id === (int) $defender->crew_id ) {
			return [ 'ok' => false, 'message' => sprintf( 'They are in your %s.', SE_Labels::raw( 'crew_label' ) ) ];
		}
		if ( $attacker->muscle < 1 ) {
			return [ 'ok' => false, 'message' => sprintf( 'You need %s first.', SE_Labels::raw( 'muscle_plural' ) ) ];
		}
		return $defender;
	}

	/**
	 * Sabotage raids: 'driveby' kills target muscle, 'jack' steals guns.
	 * Same power contest as an attack, no cash/worker steal, cheaper turns.
	 */
	public static function sabotage( $attacker, $target_user_id, $mode ) {
		$cost = (int) SE_Settings::get( 'sabotage_turn_cost' );
		$defender = self::raid_guards( $attacker, $target_user_id, $cost );
		if ( is_array( $defender ) ) return $defender;

		$attacker->protected_until = null;
		$attacker->turns -= $cost;

		$att_power = self::power( $attacker );
		$def_power = self::power( $defender ) * ( 1 + ( $defender->defense + SE_Crews::defense_bonus( $defender ) ) / 100 ) * ( wp_rand( 80, 120 ) / 100 );
		$labels    = SE_Labels::all();
		$action    = $mode === 'jack' ? $labels['jack_label'] : $labels['driveby_label'];

		if ( $att_power > $def_power ) {
			if ( $mode === 'jack' ) {
				$taken = (int) floor( $defender->guns * SE_Settings::get( 'jack_steal_pct' ) / 100 );
				$defender->guns -= $taken;
				$attacker->guns += $taken;
				$msg = sprintf( '%1$s on %2$s succeeded — you took %3$d %4$s.', $action, esc_html( $defender->alias ), $taken, $labels[ $taken === 1 ? 'gun_singular' : 'gun_plural' ] );
			} else {
				$killed = (int) ceil( $defender->muscle * SE_Settings::get( 'driveby_kill_pct' ) / 100 );
				$defender->muscle -= $killed;
				$msg = sprintf( '%1$s on %2$s succeeded — they lost %3$d %4$s.', $action, esc_html( $defender->alias ), $killed, $labels['muscle_plural'] );
			}
			$attacker->attacks_won++;
			SE_Player::save( $attacker );
			SE_Player::save( $defender );
			self::log( $attacker->user_id, $defender->user_id, 'attack_win', $msg );
			self::log( $defender->user_id, $attacker->user_id, 'defended_loss', sprintf( '%s hit you with a %s.', esc_html( $attacker->alias ), $action ) );
			self::notify_defender( $defender, sprintf( 'You were hit by %s', $attacker->alias ), sprintf( '%s pulled a %s on you. Log in to check the damage and hit back.', $attacker->alias, $action ) );
			return [ 'ok' => true, 'message' => $msg ];
		}

		$losses = (int) ceil( $attacker->muscle * SE_Settings::get( 'casualty_loser_pct' ) / 100 );
		$attacker->muscle -= $losses;
		$attacker->attacks_lost++;
		SE_Player::save( $attacker );
		SE_Player::save( $defender );
		$msg = sprintf( '%1$s on %2$s failed — you lost %3$d %4$s.', $action, esc_html( $defender->alias ), $losses, $labels['muscle_plural'] );
		self::log( $attacker->user_id, $defender->user_id, 'attack_loss', $msg );
		self::log( $defender->user_id, $attacker->user_id, 'defended_win', sprintf( '%s tried a %s on you and failed.', esc_html( $attacker->alias ), $action ) );
		self::notify_defender( $defender, sprintf( '%s came at you and failed', $attacker->alias ), sprintf( '%s tried a %s on you — your crew held. Log in to counter.', $attacker->alias, $action ) );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/**
	 * Email the defender they were attacked. Sent through wp_mail so any
	 * transactional-mail plugin (e.g. a Resend connector) routes it.
	 * Throttled to one email per hour per defender to stop raid-spam.
	 */
	public static function notify_defender( $defender, $subject, $body ) {
		if ( ! SE_Settings::get( 'notify_on_attack' ) ) return;
		$last = (int) get_user_meta( $defender->user_id, '_se_last_attack_mail', true );
		if ( $last && ( time() - $last ) < HOUR_IN_SECONDS ) return;

		$user = get_userdata( $defender->user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) return;

		update_user_meta( $defender->user_id, '_se_last_attack_mail', time() );
		$subject = sprintf( '[%s] %s', SE_Labels::raw( 'game_name' ), $subject );
		wp_mail( $user->user_email, $subject, $body );
	}

	/** Wipe the round: reset all players to starting state, clear log. */
	public static function reset_round() {
		global $wpdb;
		// Award prizes to the outgoing top 3 before the wipe.
		if ( class_exists( 'SE_WC' ) && class_exists( 'WooCommerce' ) ) {
			SE_WC::award_round_prizes();
		}
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . SE_Player::table() . ' SET cash = %d, turns = %d, workers = 0, muscle = 0, guns = 0,
			 defense = 0, score = 0, attacks_won = 0, attacks_lost = 0, last_accrual = %s, protected_until = %s',
			(int) SE_Settings::get( 'starting_cash' ),
			(int) SE_Settings::get( 'starting_turns' ),
			current_time( 'mysql', true ),
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS * (int) SE_Settings::get( 'protection_hours' ) )
		) );
		$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() );
		self::log( 0, 0, 'round', 'A new round has begun. Everyone starts fresh.' );

		// Re-schedule next month if enabled.
		if ( SE_Settings::get( 'round_reset_enabled' ) ) {
			$next = strtotime( gmdate( 'Y-m-01 00:05:00', strtotime( 'first day of next month' ) ) );
			wp_schedule_single_event( $next, 'se_round_reset_event' );
		}
	}
}

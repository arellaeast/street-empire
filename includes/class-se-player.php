<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Player {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'se_players';
	}

	/** Load (or create) the player row for a WP user, with turns accrued. */
	public static function get_for_user( $user_id, $create = true ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) return null;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id
		) );

		if ( ! $row && $create ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) return null;
			$now = current_time( 'mysql', true );
			$wpdb->insert( self::table(), [
				'user_id'         => $user_id,
				'alias'           => sanitize_text_field( $user->display_name ),
				'cash'            => (int) SE_Settings::get( 'starting_cash' ),
				'turns'           => (int) SE_Settings::get( 'starting_turns' ),
				'last_accrual'    => $now,
				'protected_until' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS * (int) SE_Settings::get( 'protection_hours' ) ),
				'created'         => $now,
			] );
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id
			) );
		}

		if ( $row ) {
			$row = self::accrue_turns( $row );
		}
		return $row;
	}

	/**
	 * Lazy turn accrual: no cron needed. Compute elapsed ticks since
	 * last_accrual and bank them, advancing last_accrual by whole ticks
	 * only so partial ticks aren't lost.
	 */
	public static function accrue_turns( $row ) {
		global $wpdb;
		$per_tick  = (int) SE_Settings::get( 'turns_per_tick' );
		$tick_secs = 60 * (int) SE_Settings::get( 'tick_minutes' );
		$max       = (int) SE_Settings::get( 'max_turns' );

		$last    = strtotime( $row->last_accrual . ' UTC' );
		$elapsed = time() - $last;
		if ( $elapsed < $tick_secs ) return $row;

		$ticks = (int) floor( $elapsed / $tick_secs );
		$new_turns = min( $max, (int) $row->turns + $ticks * $per_tick );
		$new_last  = gmdate( 'Y-m-d H:i:s', $last + $ticks * $tick_secs );

		// Atomic-ish guarded update: only apply if last_accrual unchanged,
		// so two parallel requests can't double-credit.
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET turns = %d, last_accrual = %s WHERE id = %d AND last_accrual = %s',
			$new_turns, $new_last, $row->id, $row->last_accrual
		) );
		if ( $updated ) {
			$row->turns = $new_turns;
			$row->last_accrual = $new_last;
		} else {
			// Lost the race — re-read.
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE id = %d', $row->id
			) );
		}
		return $row;
	}

	/** Persist a mutated player row and refresh score. */
	public static function save( $row ) {
		global $wpdb;
		$row->score = self::calc_score( $row );
		$wpdb->update( self::table(), [
			'cash'         => max( 0, (int) $row->cash ),
			'turns'        => max( 0, (int) $row->turns ),
			'workers'      => max( 0, (int) $row->workers ),
			'muscle'       => max( 0, (int) $row->muscle ),
			'guns'         => max( 0, (int) $row->guns ),
			'defense'      => max( 0, (int) $row->defense ),
			'score'        => (int) $row->score,
			'attacks_won'  => (int) $row->attacks_won,
			'attacks_lost' => (int) $row->attacks_lost,
			'protected_until' => $row->protected_until,
		], [ 'id' => (int) $row->id ] );
	}

	/** Persist including crew membership changes. */
	public static function save_with_crew( $row ) {
		global $wpdb;
		self::save( $row );
		$wpdb->update( self::table(), [ 'crew_id' => max( 0, (int) $row->crew_id ) ], [ 'id' => (int) $row->id ] );
	}

	public static function calc_score( $row ) {
		return (int) (
			$row->workers * 50
			+ $row->muscle * 20
			+ $row->guns * 10
			+ $row->defense * 5
			+ floor( $row->cash / 100 )
		);
	}

	/** Credit turns from a purchase (respects no cap — paid turns stack). */
	public static function credit_turns( $user_id, $amount ) {
		global $wpdb;
		$amount = (int) $amount;
		if ( $amount < 1 ) return false;
		$player = self::get_for_user( $user_id );
		if ( ! $player ) return false;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET turns = turns + %d WHERE id = %d',
			$amount, $player->id
		) );
		SE_Game::log( $user_id, 0, 'purchase', sprintf( 'Purchased %d %s.', $amount, SE_Labels::raw( 'turns_label' ) ) );
		return true;
	}

	public static function is_protected( $row ) {
		return $row->protected_until && strtotime( $row->protected_until . ' UTC' ) > time();
	}

	public static function rankings( $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' ORDER BY score DESC, id ASC LIMIT %d', (int) $limit
		) );
	}
}

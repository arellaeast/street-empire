<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Crews (alliances). One crew per player. Crewmates can't raid each
 * other and grant each other a defense bonus. If the leader leaves,
 * the crew disbands (keeps the model simple — no succession politics).
 */
class SE_Crews {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'se_crews';
	}

	public static function invites_table() {
		global $wpdb;
		return $wpdb->prefix . 'se_crew_invites';
	}

	public static function get( $crew_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $crew_id ) );
	}

	public static function members( $crew_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . SE_Player::table() . ' WHERE crew_id = %d ORDER BY score DESC', (int) $crew_id
		) );
	}

	public static function member_count( $crew_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . SE_Player::table() . ' WHERE crew_id = %d', (int) $crew_id
		) );
	}

	public static function is_leader( $player, $crew = null ) {
		$crew = $crew ?: self::get( $player->crew_id );
		return $crew && (int) $crew->leader_user_id === (int) $player->user_id;
	}

	/** Defense bonus %, from crewmates beyond yourself, capped. */
	public static function defense_bonus( $player ) {
		if ( empty( $player->crew_id ) ) return 0;
		$mates = self::member_count( $player->crew_id ) - 1;
		if ( $mates < 1 ) return 0;
		return min(
			(int) SE_Settings::get( 'crew_defense_bonus_cap' ),
			$mates * (int) SE_Settings::get( 'crew_defense_bonus_pct' )
		);
	}

	/* --------------------------- Actions ----------------------------- */

	public static function create( $player, $name ) {
		global $wpdb;
		if ( $player->crew_id ) return [ 'ok' => false, 'message' => sprintf( 'You are already in a %s.', SE_Labels::raw( 'crew_label' ) ) ];
		$name = sanitize_text_field( $name );
		if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 40 ) {
			return [ 'ok' => false, 'message' => 'Name must be 3–40 characters.' ];
		}
		$cost = (int) SE_Settings::get( 'crew_create_cost' );
		if ( $player->cash < $cost ) {
			return [ 'ok' => false, 'message' => sprintf( 'Founding a %s costs %s.', SE_Labels::raw( 'crew_label' ), SE_Labels::money( $cost ) ) ];
		}
		$inserted = $wpdb->insert( self::table(), [
			'name'           => $name,
			'leader_user_id' => (int) $player->user_id,
			'created'        => current_time( 'mysql', true ),
		] );
		if ( ! $inserted ) return [ 'ok' => false, 'message' => 'That name is taken.' ];

		$player->cash   -= $cost;
		$player->crew_id = (int) $wpdb->insert_id;
		SE_Player::save_with_crew( $player );

		$msg = sprintf( 'You founded the %s "%s" for %s.', SE_Labels::raw( 'crew_label' ), $name, SE_Labels::money( $cost ) );
		SE_Game::log( $player->user_id, 0, 'crew', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	public static function invite( $leader, $target_user_id ) {
		global $wpdb;
		$crew = self::get( $leader->crew_id );
		if ( ! $crew || ! self::is_leader( $leader, $crew ) ) {
			return [ 'ok' => false, 'message' => 'Only the leader can invite.' ];
		}
		if ( self::member_count( $crew->id ) >= (int) SE_Settings::get( 'crew_max_members' ) ) {
			return [ 'ok' => false, 'message' => sprintf( 'Your %s is full.', SE_Labels::raw( 'crew_label' ) ) ];
		}
		$target = SE_Player::get_for_user( (int) $target_user_id, false );
		if ( ! $target ) return [ 'ok' => false, 'message' => 'Player not found.' ];
		if ( $target->crew_id ) return [ 'ok' => false, 'message' => sprintf( 'They are already in a %s.', SE_Labels::raw( 'crew_label' ) ) ];

		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::invites_table() . ' (crew_id, user_id, created) VALUES (%d, %d, %s)',
			$crew->id, $target->user_id, current_time( 'mysql', true )
		) );
		$msg = sprintf( 'Invite sent to %s.', esc_html( $target->alias ) );
		SE_Game::log( $target->user_id, $leader->user_id, 'crew', sprintf( '%s invited you to join "%s".', esc_html( $leader->alias ), esc_html( $crew->name ) ) );
		return [ 'ok' => true, 'message' => $msg ];
	}

	public static function invites_for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT i.*, c.name FROM ' . self::invites_table() . ' i
			 JOIN ' . self::table() . ' c ON c.id = i.crew_id
			 WHERE i.user_id = %d ORDER BY i.id DESC', (int) $user_id
		) );
	}

	public static function accept( $player, $crew_id ) {
		global $wpdb;
		if ( $player->crew_id ) return [ 'ok' => false, 'message' => sprintf( 'You are already in a %s.', SE_Labels::raw( 'crew_label' ) ) ];
		$invite = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::invites_table() . ' WHERE crew_id = %d AND user_id = %d',
			(int) $crew_id, (int) $player->user_id
		) );
		if ( ! $invite ) return [ 'ok' => false, 'message' => 'No such invite.' ];
		$crew = self::get( $crew_id );
		if ( ! $crew ) return [ 'ok' => false, 'message' => 'That crew no longer exists.' ];
		if ( self::member_count( $crew->id ) >= (int) SE_Settings::get( 'crew_max_members' ) ) {
			return [ 'ok' => false, 'message' => sprintf( 'That %s is now full.', SE_Labels::raw( 'crew_label' ) ) ];
		}
		$player->crew_id = (int) $crew->id;
		SE_Player::save_with_crew( $player );
		// Clear all of this player's outstanding invites.
		$wpdb->delete( self::invites_table(), [ 'user_id' => (int) $player->user_id ] );

		$msg = sprintf( 'You joined "%s".', esc_html( $crew->name ) );
		SE_Game::log( $player->user_id, $crew->leader_user_id, 'crew', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	public static function decline( $player, $crew_id ) {
		global $wpdb;
		$wpdb->delete( self::invites_table(), [ 'user_id' => (int) $player->user_id, 'crew_id' => (int) $crew_id ] );
		return [ 'ok' => true, 'message' => 'Invite declined.' ];
	}

	/** Leaving as leader disbands the crew. */
	public static function leave( $player ) {
		global $wpdb;
		$crew = self::get( $player->crew_id );
		if ( ! $crew ) return [ 'ok' => false, 'message' => sprintf( 'You are not in a %s.', SE_Labels::raw( 'crew_label' ) ) ];

		if ( self::is_leader( $player, $crew ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . SE_Player::table() . ' SET crew_id = 0 WHERE crew_id = %d', $crew->id ) );
			$wpdb->delete( self::invites_table(), [ 'crew_id' => (int) $crew->id ] );
			$wpdb->delete( self::table(), [ 'id' => (int) $crew->id ] );
			$msg = sprintf( 'You disbanded "%s".', esc_html( $crew->name ) );
			SE_Game::log( $player->user_id, 0, 'crew', $msg );
			return [ 'ok' => true, 'message' => $msg ];
		}

		$player->crew_id = 0;
		SE_Player::save_with_crew( $player );
		$msg = sprintf( 'You left "%s".', esc_html( $crew->name ) );
		SE_Game::log( $player->user_id, $crew->leader_user_id, 'crew', $msg );
		return [ 'ok' => true, 'message' => $msg ];
	}

	public static function kick( $leader, $target_user_id ) {
		$crew = self::get( $leader->crew_id );
		if ( ! $crew || ! self::is_leader( $leader, $crew ) ) {
			return [ 'ok' => false, 'message' => 'Only the leader can kick.' ];
		}
		if ( (int) $target_user_id === (int) $leader->user_id ) {
			return [ 'ok' => false, 'message' => 'Leave the crew to disband it instead.' ];
		}
		$target = SE_Player::get_for_user( (int) $target_user_id, false );
		if ( ! $target || (int) $target->crew_id !== (int) $crew->id ) {
			return [ 'ok' => false, 'message' => 'They are not in your crew.' ];
		}
		$target->crew_id = 0;
		SE_Player::save_with_crew( $target );
		$msg = sprintf( 'Kicked %s from "%s".', esc_html( $target->alias ), esc_html( $crew->name ) );
		SE_Game::log( $target->user_id, $leader->user_id, 'crew', sprintf( 'You were removed from "%s".', esc_html( $crew->name ) ) );
		return [ 'ok' => true, 'message' => $msg ];
	}

	/** Crew standings: summed member scores. */
	public static function rankings( $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT c.id, c.name, COUNT(p.id) AS member_count, COALESCE(SUM(p.score),0) AS total_score
			 FROM ' . self::table() . ' c
			 LEFT JOIN ' . SE_Player::table() . ' p ON p.crew_id = c.id
			 GROUP BY c.id, c.name
			 ORDER BY total_score DESC
			 LIMIT %d', (int) $limit
		) );
	}
}

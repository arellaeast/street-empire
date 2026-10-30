<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every player-facing game term routes through here, so the admin can
 * rename the whole theme of the game without touching code. Default set
 * is the "Crabs in a Barrel" Chesapeake theme; swap terms in the
 * Terminology screen to retheme (e.g. back to the classic genre terms).
 */
class SE_Labels {

	protected static $cache = null;

	public static function defaults() {
		return [
			'game_name'        => 'Crabs in a Barrel',
			'currency'         => 'Clams',
			'currency_symbol'  => '$',
			'worker_singular'  => 'Crab Pot',
			'worker_plural'    => 'Crab Pots',
			'muscle_singular'  => 'Deckhand',
			'muscle_plural'    => 'Deckhands',
			'gun_singular'     => 'Trotline',
			'gun_plural'       => 'Trotlines',
			'defense_label'    => 'Harbor Watch',
			'turns_label'      => 'Tides',
			'scout_verb'       => 'Drop Pots',
			'hustle_verb'      => 'Haul In',
			'attack_verb'      => 'Raid',
			'driveby_label'    => 'Cut Their Lines',
			'jack_label'       => 'Pot Poach',
			'territory_label'  => 'Waters',
			'crew_label'       => 'Fleet',
			'rankings_title'   => 'Top of the Barrel',
			'player_noun'      => 'Crab',
		];
	}

	public static function all() {
		if ( self::$cache === null ) {
			self::$cache = wp_parse_args( (array) get_option( 'se_labels', [] ), self::defaults() );
		}
		return self::$cache;
	}

	/** Get one label, already escaped for HTML output. */
	public static function get( $key ) {
		$all = self::all();
		$val = isset( $all[ $key ] ) ? $all[ $key ] : $key;
		return esc_html( $val );
	}

	/** Raw (unescaped) value, for contexts like attribute building. */
	public static function raw( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $key;
	}

	/** Singular/plural helper. $base = 'worker' | 'muscle' | 'gun'. */
	public static function count_label( $base, $n ) {
		$key = $base . ( abs( (int) $n ) === 1 ? '_singular' : '_plural' );
		return self::get( $key );
	}

	public static function money( $amount ) {
		return esc_html( self::raw( 'currency_symbol' ) . number_format_i18n( (float) $amount ) );
	}

	public static function save( array $input ) {
		$clean = [];
		foreach ( self::defaults() as $key => $default ) {
			$val = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $default;
			$clean[ $key ] = ( $val === '' ) ? $default : $val;
		}
		update_option( 'se_labels', $clean );
		self::$cache = null;
		return $clean;
	}
}

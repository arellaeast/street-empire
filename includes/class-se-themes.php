<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin-created color themes. 'dark' and 'light' are built-ins living in
 * the stylesheet; custom themes are stored in the se_themes option and
 * printed as scoped inline CSS. Users pick any theme in their display
 * options; the admin picks the site default.
 */
class SE_Themes {

	const OPTION = 'se_themes';

	/** Editable color slots: key => [label, default (from dark theme)]. */
	public static function fields() {
		return [
			'bg'          => [ 'Background', '#0d0b10' ],
			'panel'       => [ 'Panels', '#16121d' ],
			'line'        => [ 'Borders', '#3a3050' ],
			'text'        => [ 'Text', '#f0ecf8' ],
			'dim'         => [ 'Secondary text', '#b3aac6' ],
			'accent'      => [ 'Accent / values / buttons', '#f0c05e' ],
			'accent_text' => [ 'Text on buttons', '#171205' ],
			'success'     => [ 'Success', '#8fe08f' ],
			'danger'      => [ 'Danger / attack button', '#f07575' ],
			'danger_text' => [ 'Text on danger buttons', '#1c0707' ],
		];
	}

	/** All custom themes: slug => [ 'name' => ..., 'colors' => [...] ]. */
	public static function all_custom() {
		$themes = get_option( self::OPTION, [] );
		return is_array( $themes ) ? $themes : [];
	}

	public static function get( $slug ) {
		$all = self::all_custom();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/** Every valid theme slug (built-ins + custom). */
	public static function valid_slugs() {
		return array_merge( [ 'dark', 'light' ], array_keys( self::all_custom() ) );
	}

	/** slug => display name, for dropdowns. */
	public static function choices() {
		$choices = [ 'dark' => 'Dark', 'light' => 'Light (high contrast)' ];
		foreach ( self::all_custom() as $slug => $theme ) {
			$choices[ $slug ] = $theme['name'];
		}
		return $choices;
	}

	/** Create or update a theme. Returns [ok, message, slug]. */
	public static function save_theme( $name, array $colors, $slug = '' ) {
		$name = sanitize_text_field( $name );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 40 ) {
			return [ 'ok' => false, 'message' => 'Theme name must be 2–40 characters.' ];
		}
		if ( ! $slug ) {
			$slug = sanitize_title( $name );
			if ( in_array( $slug, [ 'dark', 'light' ], true ) || isset( self::all_custom()[ $slug ] ) ) {
				$slug .= '-' . strtolower( wp_generate_password( 4, false, false ) );
			}
		}
		$slug = sanitize_key( $slug );
		if ( ! $slug ) return [ 'ok' => false, 'message' => 'Bad theme slug.' ];

		$clean = [];
		foreach ( self::fields() as $key => $meta ) {
			$hex = isset( $colors[ $key ] ) ? sanitize_hex_color( $colors[ $key ] ) : '';
			$clean[ $key ] = $hex ?: $meta[1];
		}

		$all = self::all_custom();
		$all[ $slug ] = [ 'name' => $name, 'colors' => $clean ];
		update_option( self::OPTION, $all );
		return [ 'ok' => true, 'message' => 'Theme saved.', 'slug' => $slug ];
	}

	public static function delete( $slug ) {
		$all = self::all_custom();
		unset( $all[ sanitize_key( $slug ) ] );
		update_option( self::OPTION, $all );
	}

	/** Scoped CSS for all custom themes, attached inline to the stylesheet. */
	public static function inline_css() {
		$css = '';
		foreach ( self::all_custom() as $slug => $theme ) {
			$c   = $theme['colors'];
			$sel = '.se-game.se-theme-' . $slug;
			$css .= sprintf(
				'%1$s{--se-bg:%2$s;--se-panel:%3$s;--se-line:%4$s;--se-text:%5$s;--se-dim:%6$s;--se-gold:%7$s;--se-green:%8$s;--se-red:%9$s;}',
				$sel, $c['bg'], $c['panel'], $c['line'], $c['text'], $c['dim'], $c['accent'], $c['success'], $c['danger']
			);
			$css .= sprintf( '%1$s .se-btn{background:%2$s;color:%3$s;}', $sel, $c['accent'], $c['accent_text'] );
			$css .= sprintf( '%1$s .se-btn-danger{background:%2$s;color:%3$s;}', $sel, $c['danger'], $c['danger_text'] );
			$css .= sprintf( '%1$s .se-btn-ghost{background:transparent;color:%2$s;border-color:%2$s;}', $sel, $c['accent'] );
			$css .= sprintf( '%1$s .se-upsell{background:%2$s14;}', $sel, $c['accent'] ); // 8-digit hex: ~8%% alpha
		}
		return $css;
	}
}

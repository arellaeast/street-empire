<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SE_Frontend {

	public static function init() {
		add_shortcode( 'street_empire', [ __CLASS__, 'render_game' ] );
		add_shortcode( 'street_empire_rankings', [ __CLASS__, 'render_rankings' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_se_action', [ __CLASS__, 'handle_action' ] );
	}

	public static function assets() {
		wp_register_style( 'se-game', SE_PLUGIN_URL . 'assets/se.css', [], SE_VERSION );
		$custom_css = SE_Themes::inline_css();
		if ( $custom_css ) wp_add_inline_style( 'se-game', $custom_css );
		wp_register_script( 'se-game', SE_PLUGIN_URL . 'assets/se.js', [], SE_VERSION, true );
		wp_localize_script( 'se-game', 'SEGame', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'se_action' ),
		] );
	}

	/* ------------------------- Main game UI -------------------------- */

	public static function render_game() {
		if ( ! is_user_logged_in() ) {
			wp_enqueue_style( 'se-game' );
			$cls = 'se-game se-login se-theme-' . self::default_pref( 'theme' )
				. ( self::default_pref( 'text' ) !== 'std' ? ' se-text-' . self::default_pref( 'text' ) : '' );
			return '<div class="' . esc_attr( $cls ) . '"><p>Log in to play ' . SE_Labels::get( 'game_name' ) . '.</p>'
				. '<p><a class="se-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a> '
				. '<a class="se-btn se-btn-ghost" href="' . esc_url( wp_registration_url() ) . '">Sign up</a></p></div>';
		}
		wp_enqueue_style( 'se-game' );
		wp_enqueue_script( 'se-game' );

		$player = SE_Player::get_for_user( get_current_user_id() );
		if ( ! $player ) return '<p>Could not load your game account.</p>';

		$display = self::display_prefs( get_current_user_id() );
		$classes = 'se-game se-theme-' . $display['theme'] . ( $display['text'] !== 'std' ? ' se-text-' . $display['text'] : '' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" id="se-game">
			<header class="se-head">
				<h2 class="se-title"><?php echo SE_Labels::get( 'game_name' ); ?></h2>
				<span class="se-alias">&gt; <?php echo esc_html( $player->alias ); ?><?php
					if ( SE_Player::is_protected( $player ) ) echo ' <em class="se-shield" title="New player protection">[protected]</em>';
				?></span>
			</header>

			<div class="se-display">
				<label for="se-theme">Theme</label>
				<select id="se-theme" data-se-pref="theme">
					<?php foreach ( SE_Themes::choices() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $display['theme'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="se-textsize">Text size</label>
				<select id="se-textsize" data-se-pref="text">
					<option value="std"<?php selected( $display['text'], 'std' ); ?>>Standard</option>
					<option value="lg"<?php selected( $display['text'], 'lg' ); ?>>Large</option>
					<option value="xl"<?php selected( $display['text'], 'xl' ); ?>>Extra large</option>
				</select>
			</div>

			<div class="se-stats" id="se-stats"><?php self::stats_rows( $player ); ?></div>

			<div class="se-msg" id="se-msg" role="status" aria-live="polite"></div>

			<div class="se-panels">
				<section class="se-panel">
					<h3><?php echo SE_Labels::get( 'scout_verb' ); ?></h3>
					<p class="se-hint">Spend <?php echo SE_Labels::get( 'turns_label' ); ?> to recruit <?php echo SE_Labels::get( 'worker_plural' ); ?>.</p>
					<form class="se-form" data-se-action="scout">
						<input type="number" name="turns" min="1" value="10" aria-label="Turns to spend">
						<button type="submit" class="se-btn"><?php echo SE_Labels::get( 'scout_verb' ); ?></button>
					</form>
				</section>

				<section class="se-panel">
					<h3><?php echo SE_Labels::get( 'hustle_verb' ); ?></h3>
					<p class="se-hint">Spend <?php echo SE_Labels::get( 'turns_label' ); ?> to earn <?php echo SE_Labels::get( 'currency' ); ?> from your <?php echo SE_Labels::get( 'worker_plural' ); ?>.</p>
					<form class="se-form" data-se-action="hustle">
						<input type="number" name="turns" min="1" value="10" aria-label="Turns to spend">
						<button type="submit" class="se-btn"><?php echo SE_Labels::get( 'hustle_verb' ); ?></button>
					</form>
				</section>

				<section class="se-panel">
					<h3>Market</h3>
					<form class="se-form" data-se-action="buy">
						<select name="item" aria-label="Item to buy">
							<option value="muscle"><?php echo SE_Labels::get( 'muscle_plural' ); ?> (<?php echo SE_Labels::money( SE_Settings::get( 'price_muscle' ) ); ?>)</option>
							<option value="gun"><?php echo SE_Labels::get( 'gun_plural' ); ?> (<?php echo SE_Labels::money( SE_Settings::get( 'price_gun' ) ); ?>)</option>
							<option value="defense"><?php echo SE_Labels::get( 'defense_label' ); ?> (<?php echo SE_Labels::money( SE_Settings::get( 'price_defense' ) ); ?>)</option>
						</select>
						<input type="number" name="qty" min="1" value="1" aria-label="Quantity">
						<button type="submit" class="se-btn">Buy</button>
					</form>
				</section>

				<section class="se-panel">
					<h3><?php echo SE_Labels::get( 'attack_verb' ); ?></h3>
					<p class="se-hint"><?php echo SE_Labels::get( 'attack_verb' ); ?>: <?php echo (int) SE_Settings::get( 'attack_turn_cost' ); ?> <?php echo SE_Labels::get( 'turns_label' ); ?>, steals <?php echo SE_Labels::get( 'currency' ); ?> + <?php echo SE_Labels::get( 'worker_plural' ); ?>. Sabotage: <?php echo (int) SE_Settings::get( 'sabotage_turn_cost' ); ?> <?php echo SE_Labels::get( 'turns_label' ); ?>.</p>
					<form class="se-form" data-se-action="attack">
						<select name="mode" aria-label="Raid type">
							<option value="attack"><?php echo SE_Labels::get( 'attack_verb' ); ?></option>
							<option value="driveby"><?php echo SE_Labels::get( 'driveby_label' ); ?></option>
							<option value="jack"><?php echo SE_Labels::get( 'jack_label' ); ?></option>
						</select>
						<select name="target" aria-label="Target player">
							<?php foreach ( SE_Player::rankings( 50 ) as $t ) :
								if ( (int) $t->user_id === (int) $player->user_id ) continue; ?>
								<option value="<?php echo (int) $t->user_id; ?>"><?php echo esc_html( $t->alias ); ?> (score <?php echo number_format_i18n( $t->score ); ?>)</option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="se-btn se-btn-danger">Go</button>
					</form>
				</section>
			</div>

			<?php echo self::upsell_banner( $player ); ?>

			<?php echo self::crew_panel( $player ); ?>

			<section class="se-log">
				<h3>Recent activity</h3>
				<ul id="se-log-list">
					<?php foreach ( SE_Game::recent_log_for( $player->user_id ) as $entry ) : ?>
						<li class="se-log-<?php echo esc_attr( $entry->type ); ?>"><?php echo wp_kses_post( $entry->message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Turn-pack upsell: shown when the player's turns drop below one attack.
	 * Rendered hidden otherwise so JS can reveal it after actions drain turns.
	 */
	protected static function upsell_banner( $player ) {
		$product_id = (int) SE_Settings::get( 'upsell_product_id' );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) return '';
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) return '';

		$low  = (int) $player->turns < (int) SE_Settings::get( 'attack_turn_cost' );
		$url  = $product->add_to_cart_url();
		if ( strpos( $url, 'http' ) !== 0 ) $url = get_permalink( $product_id ); // simple products return relative add-to-cart

		return sprintf(
			'<div class="se-upsell" id="se-upsell"%s>Running low on %s? <a class="se-btn" href="%s">%s — %s</a></div>',
			$low ? '' : ' hidden',
			SE_Labels::get( 'turns_label' ),
			esc_url( $url ),
			esc_html( $product->get_name() ),
			wp_kses_post( $product->get_price_html() )
		);
	}

	/** Per-user display preferences, falling back to admin-set defaults. */
	public static function display_prefs( $user_id ) {
		$saved  = (array) get_user_meta( $user_id, '_se_display', true );
		$themes = SE_Themes::valid_slugs();
		return [
			'theme' => in_array( $saved['theme'] ?? '', $themes, true ) ? $saved['theme'] : self::default_pref( 'theme' ),
			'text'  => in_array( $saved['text'] ?? '', [ 'std', 'lg', 'xl' ], true ) ? $saved['text'] : self::default_pref( 'text' ),
		];
	}

	/** Admin-configured site defaults, with hard fallbacks. */
	public static function default_pref( $which ) {
		if ( $which === 'theme' ) {
			$v = SE_Settings::get( 'default_theme' );
			return in_array( $v, SE_Themes::valid_slugs(), true ) ? $v : 'dark';
		}
		$v = SE_Settings::get( 'default_text' );
		return in_array( $v, [ 'std', 'lg', 'xl' ], true ) ? $v : 'std';
	}

	/** Crew section: membership view, or create/invites view. */
	protected static function crew_panel( $player ) {
		$label = SE_Labels::get( 'crew_label' );
		ob_start();
		echo '<section class="se-crew"><h3>' . $label . '</h3>';

		if ( $player->crew_id && ( $crew = SE_Crews::get( $player->crew_id ) ) ) {
			$is_leader = SE_Crews::is_leader( $player, $crew );
			$members   = SE_Crews::members( $crew->id );
			$bonus     = SE_Crews::defense_bonus( $player );
			printf(
				'<p class="se-hint">"%s" — %d/%d members · +%d%% %s bonus from crewmates</p>',
				esc_html( $crew->name ), count( $members ),
				(int) SE_Settings::get( 'crew_max_members' ), (int) $bonus, SE_Labels::get( 'defense_label' )
			);
			echo '<ul class="se-crew-members">';
			foreach ( $members as $m ) {
				printf(
					'<li>%s%s <span class="se-dim">(score %s)</span></li>',
					esc_html( $m->alias ),
					(int) $m->user_id === (int) $crew->leader_user_id ? ' <em class="se-shield">[leader]</em>' : '',
					number_format_i18n( $m->score )
				);
			}
			echo '</ul><div class="se-crew-actions">';

			if ( $is_leader ) {
				// Invite crewless players.
				echo '<form class="se-form" data-se-action="crew_invite"><select name="target" aria-label="Player to invite">';
				foreach ( SE_Player::rankings( 50 ) as $t ) {
					if ( $t->crew_id || (int) $t->user_id === (int) $player->user_id ) continue;
					printf( '<option value="%d">%s</option>', (int) $t->user_id, esc_html( $t->alias ) );
				}
				echo '</select><button type="submit" class="se-btn">Invite</button></form>';

				if ( count( $members ) > 1 ) {
					echo '<form class="se-form" data-se-action="crew_kick"><select name="target" aria-label="Member to kick">';
					foreach ( $members as $m ) {
						if ( (int) $m->user_id === (int) $player->user_id ) continue;
						printf( '<option value="%d">%s</option>', (int) $m->user_id, esc_html( $m->alias ) );
					}
					echo '</select><button type="submit" class="se-btn se-btn-ghost">Kick</button></form>';
				}
			}
			printf(
				'<form class="se-form" data-se-action="crew_leave" data-se-confirm="%s"><button type="submit" class="se-btn se-btn-danger">%s</button></form>',
				$is_leader ? 'Disband the ' . esc_attr( SE_Labels::raw( 'crew_label' ) ) . '? This removes everyone.' : 'Leave?',
				$is_leader ? 'Disband' : 'Leave'
			);
			echo '</div>';
		} else {
			// Not in a crew: create form + pending invites.
			printf(
				'<p class="se-hint">Found a %1$s for %2$s. Crewmates can\'t raid each other and boost each other\'s %3$s.</p>',
				$label, SE_Labels::money( SE_Settings::get( 'crew_create_cost' ) ), SE_Labels::get( 'defense_label' )
			);
			echo '<form class="se-form" data-se-action="crew_create">
				<input type="text" name="crew_name" minlength="3" maxlength="40" placeholder="Name" aria-label="' . esc_attr( SE_Labels::raw( 'crew_label' ) ) . ' name" required>
				<button type="submit" class="se-btn">Found ' . $label . '</button></form>';

			$invites = SE_Crews::invites_for_user( $player->user_id );
			if ( $invites ) {
				echo '<ul class="se-crew-invites">';
				foreach ( $invites as $inv ) {
					printf(
						'<li>Invite: <strong>%1$s</strong>
						 <form class="se-form se-inline" data-se-action="crew_accept"><input type="hidden" name="crew" value="%2$d"><button type="submit" class="se-btn">Join</button></form>
						 <form class="se-form se-inline" data-se-action="crew_decline"><input type="hidden" name="crew" value="%2$d"><button type="submit" class="se-btn se-btn-ghost">Decline</button></form>
						 </li>',
						esc_html( $inv->name ), (int) $inv->crew_id
					);
				}
				echo '</ul>';
			}
		}
		echo '</section>';
		return ob_get_clean();
	}

	protected static function stats_rows( $player ) {
		$rows = [
			SE_Labels::raw( 'turns_label' )   => number_format_i18n( $player->turns ),
			SE_Labels::raw( 'currency' )      => SE_Labels::money( $player->cash ),
			SE_Labels::raw( 'worker_plural' ) => number_format_i18n( $player->workers ),
			SE_Labels::raw( 'muscle_plural' ) => number_format_i18n( $player->muscle ),
			SE_Labels::raw( 'gun_plural' )    => number_format_i18n( $player->guns ),
			SE_Labels::raw( 'defense_label' ) => number_format_i18n( $player->defense ),
			'Score'                           => number_format_i18n( $player->score ),
		];
		foreach ( $rows as $label => $value ) {
			printf( '<div class="se-stat"><span class="se-stat-label">%s</span><span class="se-stat-value">%s</span></div>', esc_html( $label ), $value );
		}
	}

	/* -------------------------- Rankings ----------------------------- */

	public static function render_rankings() {
		wp_enqueue_style( 'se-game' );
		$classes = 'se-game se-rankings';
		if ( is_user_logged_in() ) {
			$d = self::display_prefs( get_current_user_id() );
		} else {
			$d = [ 'theme' => self::default_pref( 'theme' ), 'text' => self::default_pref( 'text' ) ];
		}
		$classes .= ' se-theme-' . $d['theme'] . ( $d['text'] !== 'std' ? ' se-text-' . $d['text'] : '' );
		$rows = SE_Player::rankings( 100 );
		ob_start();
		echo '<div class="' . esc_attr( $classes ) . '"><h2 class="se-title">' . SE_Labels::get( 'rankings_title' ) . '</h2><table><thead><tr><th>#</th><th>'
			. SE_Labels::get( 'player_noun' ) . '</th><th>Score</th><th>W</th><th>L</th></tr></thead><tbody>';
		$i = 1;
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td></tr>',
				$i++, esc_html( $r->alias ), number_format_i18n( $r->score ), (int) $r->attacks_won, (int) $r->attacks_lost
			);
		}
		echo '</tbody></table>';

		// Crew standings, if any crews exist.
		$crews = SE_Crews::rankings( 25 );
		if ( $crews ) {
			echo '<h2 class="se-title">' . SE_Labels::get( 'crew_label' ) . ' standings</h2><table><thead><tr><th>#</th><th>'
				. SE_Labels::get( 'crew_label' ) . '</th><th>Members</th><th>Total score</th></tr></thead><tbody>';
			$c = 1;
			foreach ( $crews as $crew ) {
				printf(
					'<tr><td>%d</td><td>%s</td><td>%d</td><td>%s</td></tr>',
					$c++, esc_html( $crew->name ), (int) $crew->member_count, number_format_i18n( $crew->total_score )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------- AJAX ------------------------------- */

	public static function handle_action() {
		check_ajax_referer( 'se_action', 'nonce' );
		if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

		$player = SE_Player::get_for_user( get_current_user_id() );
		if ( ! $player ) wp_send_json_error( [ 'message' => 'No game account.' ] );

		$action = isset( $_POST['game_action'] ) ? sanitize_key( $_POST['game_action'] ) : '';

		// Display preferences: save and return early (no stats refresh needed).
		if ( $action === 'display' ) {
			$prefs = self::display_prefs( get_current_user_id() );
			if ( isset( $_POST['theme'] ) ) $prefs['theme'] = sanitize_key( $_POST['theme'] );
			if ( isset( $_POST['text'] ) )  $prefs['text']  = sanitize_key( $_POST['text'] );
			// Re-validate through the same whitelist.
			$prefs = [
				'theme' => in_array( $prefs['theme'], SE_Themes::valid_slugs(), true ) ? $prefs['theme'] : 'dark',
				'text'  => in_array( $prefs['text'], [ 'std', 'lg', 'xl' ], true ) ? $prefs['text'] : 'std',
			];
			update_user_meta( get_current_user_id(), '_se_display', $prefs );
			wp_send_json_success( [ 'message' => '' ] );
		}

		switch ( $action ) {
			case 'scout':
				$result = SE_Game::scout( $player, isset( $_POST['turns'] ) ? (int) $_POST['turns'] : 0 );
				break;
			case 'hustle':
				$result = SE_Game::hustle( $player, isset( $_POST['turns'] ) ? (int) $_POST['turns'] : 0 );
				break;
			case 'buy':
				$result = SE_Game::buy(
					$player,
					isset( $_POST['item'] ) ? sanitize_key( $_POST['item'] ) : '',
					isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0
				);
				break;
			case 'attack':
				$mode   = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'attack';
				$target = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
				$result = in_array( $mode, [ 'driveby', 'jack' ], true )
					? SE_Game::sabotage( $player, $target, $mode )
					: SE_Game::attack( $player, $target );
				break;
			case 'crew_create':
				$result = SE_Crews::create( $player, isset( $_POST['crew_name'] ) ? wp_unslash( $_POST['crew_name'] ) : '' );
				break;
			case 'crew_invite':
				$result = SE_Crews::invite( $player, isset( $_POST['target'] ) ? (int) $_POST['target'] : 0 );
				break;
			case 'crew_accept':
				$result = SE_Crews::accept( $player, isset( $_POST['crew'] ) ? (int) $_POST['crew'] : 0 );
				break;
			case 'crew_decline':
				$result = SE_Crews::decline( $player, isset( $_POST['crew'] ) ? (int) $_POST['crew'] : 0 );
				break;
			case 'crew_leave':
				$result = SE_Crews::leave( $player );
				break;
			case 'crew_kick':
				$result = SE_Crews::kick( $player, isset( $_POST['target'] ) ? (int) $_POST['target'] : 0 );
				break;
			default:
				$result = [ 'ok' => false, 'message' => 'Unknown action.' ];
		}

		// Re-read for fresh stats HTML.
		$player = SE_Player::get_for_user( get_current_user_id() );
		ob_start();
		self::stats_rows( $player );
		$stats_html = ob_get_clean();

		$payload = [
			'message' => $result['message'],
			'stats'   => $stats_html,
			'turns'   => (int) $player->turns,
			'low'     => (int) $player->turns < (int) SE_Settings::get( 'attack_turn_cost' ),
		];
		$result['ok'] ? wp_send_json_success( $payload ) : wp_send_json_error( $payload );
	}
}

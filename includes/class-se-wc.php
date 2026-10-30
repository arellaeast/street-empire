<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce integration.
 *
 * Any product can be turned into a "turn pack" by setting the
 * "Game turns granted" field on the product's General tab. When an order
 * containing that product completes (or payment completes), the buyer's
 * game account is credited turns * quantity, exactly once per order.
 */
class SE_WC {

	public static function init() {
		// Product edit field.
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'product_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_field' ] );
		// Credit on completion / payment (virtual products auto-complete on payment).
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'credit_order' ] );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'credit_order' ] );
	}

	public static function product_field() {
		woocommerce_wp_text_input( [
			'id'                => '_se_turns',
			'label'             => sprintf( 'Game %s granted', SE_Labels::raw( 'turns_label' ) ),
			'description'       => 'If set, buying this product credits this many turns to the customer\'s game account (per unit purchased). Make the product Virtual so it completes automatically on payment.',
			'desc_tip'          => true,
			'type'              => 'number',
			'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
		] );
	}

	public static function save_product_field( $post_id ) {
		$val = isset( $_POST['_se_turns'] ) ? max( 0, (int) $_POST['_se_turns'] ) : 0;
		if ( $val > 0 ) {
			update_post_meta( $post_id, '_se_turns', $val );
		} else {
			delete_post_meta( $post_id, '_se_turns' );
		}
	}

	/**
	 * Round prizes: issue single-use, email-restricted, expiring
	 * WooCommerce coupons to the top 3 players and email them the code.
	 * Called by SE_Game::reset_round() before the wipe.
	 */
	public static function award_round_prizes() {
		if ( ! SE_Settings::get( 'prizes_enabled' ) || ! class_exists( 'WC_Coupon' ) ) return;

		$amounts = [
			1 => (float) SE_Settings::get( 'prize_1_amount' ),
			2 => (float) SE_Settings::get( 'prize_2_amount' ),
			3 => (float) SE_Settings::get( 'prize_3_amount' ),
		];
		$winners = SE_Player::rankings( 3 );
		$expiry_days = max( 1, (int) SE_Settings::get( 'prize_expiry_days' ) );
		$game = SE_Labels::raw( 'game_name' );

		foreach ( $winners as $i => $p ) {
			$place  = $i + 1;
			$amount = isset( $amounts[ $place ] ) ? $amounts[ $place ] : 0;
			if ( $amount <= 0 || $p->score <= 0 ) continue;

			$user = get_userdata( $p->user_id );
			if ( ! $user || ! is_email( $user->user_email ) ) continue;

			$code = sanitize_title( $game ) . '-' . strtolower( wp_generate_password( 8, false, false ) );

			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( $amount );
			$coupon->set_usage_limit( 1 );
			$coupon->set_email_restrictions( [ $user->user_email ] );
			$coupon->set_date_expires( time() + $expiry_days * DAY_IN_SECONDS );
			$coupon->set_description( sprintf( '%s round prize — #%d: %s', $game, $place, $p->alias ) );
			$coupon->save();

			wp_mail(
				$user->user_email,
				sprintf( '[%s] You finished #%d this round!', $game, $place ),
				sprintf(
					"You finished #%d on the %s leaderboard this round.\n\nYour prize: a %s store coupon.\nCode: %s\n(Single use, tied to this email, expires in %d days.)\n\nA new round has begun — everyone starts fresh. See you out there.",
					$place, $game, html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) ), $code, $expiry_days
				)
			);

			SE_Game::log( $p->user_id, 0, 'round', sprintf( 'Finished #%d last round — prize coupon emailed.', $place ) );
		}
	}

	public static function credit_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Idempotency: never credit the same order twice
		// (both hooks can fire for one order).
		if ( $order->get_meta( '_se_turns_credited' ) ) return;

		$user_id = $order->get_user_id();
		if ( ! $user_id ) return; // guest checkout can't map to a player

		$total_turns = 0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) continue;
			// Check variation first, fall back to parent.
			$turns = (int) $product->get_meta( '_se_turns' );
			if ( ! $turns && $product->get_parent_id() ) {
				$turns = (int) get_post_meta( $product->get_parent_id(), '_se_turns', true );
			}
			if ( $turns > 0 ) {
				$total_turns += $turns * max( 1, (int) $item->get_quantity() );
			}
		}

		if ( $total_turns > 0 ) {
			SE_Player::credit_turns( $user_id, $total_turns );
			$order->update_meta_data( '_se_turns_credited', $total_turns );
			$order->add_order_note( sprintf( 'Street Empire: credited %d %s to the customer\'s game account.', $total_turns, SE_Labels::raw( 'turns_label' ) ) );
			$order->save();
		}
	}
}

<?php
/**
 * Signup counts, stopped spam and recent Mailchimp errors.
 *
 * Only numbers and error messages are kept (no email addresses or names):
 * the subscribers themselves live in Mailchimp.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small counters, kept in one option that isn't autoloaded.
 */
final class Easy_Mailchimp_Newsletter_Embed_Stats {

	/**
	 * Option key.
	 */
	const OPTION = 'easy_mailchimp_newsletter_embed_stats';

	/**
	 * Days of daily counts kept.
	 */
	const DAYS = 30;

	/**
	 * Recent errors kept.
	 */
	const MAX_ERRORS = 20;

	/**
	 * Reasons a signup is stopped as spam.
	 */
	const REASONS = array( 'honeypot', 'too_fast', 'rate_limit', 'turnstile', 'origin' );

	/**
	 * Count a signup.
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	public static function signup( $form_id ) {
		$data = self::load();
		$day  = gmdate( 'Ymd' );
		$id   = (string) (int) $form_id;

		$data['signups'][ $day ][ $id ] = ( isset( $data['signups'][ $day ][ $id ] ) ? (int) $data['signups'][ $day ][ $id ] : 0 ) + 1;
		$data['totals'][ $id ]          = ( isset( $data['totals'][ $id ] ) ? (int) $data['totals'][ $id ] : 0 ) + 1;

		self::save( $data );
	}

	/**
	 * Count a signup stopped as spam.
	 *
	 * @param string $reason One of {@see REASONS}.
	 * @return void
	 */
	public static function blocked( $reason ) {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return;
		}

		$data = self::load();
		$day  = gmdate( 'Ymd' );

		$data['blocked'][ $day ][ $reason ] = ( isset( $data['blocked'][ $day ][ $reason ] ) ? (int) $data['blocked'][ $day ][ $reason ] : 0 ) + 1;

		self::save( $data );
	}

	/**
	 * Remember a failed signup, for the admin screen.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $text    What went wrong (no visitor details).
	 * @return void
	 */
	public static function error( $form_id, $text ) {
		$data = self::load();

		array_unshift(
			$data['errors'],
			array(
				'time' => time(),
				'form' => (int) $form_id,
				'text' => substr( sanitize_text_field( (string) $text ), 0, 500 ),
			)
		);

		$data['errors'] = array_slice( $data['errors'], 0, self::MAX_ERRORS );

		self::save( $data );
	}

	/**
	 * A form's signups: the last 30 days and all time.
	 *
	 * @param int $form_id Form ID.
	 * @return array{recent:int,total:int}
	 */
	public static function form_counts( $form_id ) {
		$data   = self::load();
		$id     = (string) (int) $form_id;
		$recent = 0;

		foreach ( $data['signups'] as $day ) {
			$recent += isset( $day[ $id ] ) ? (int) $day[ $id ] : 0;
		}

		return array(
			'recent' => $recent,
			'total'  => isset( $data['totals'][ $id ] ) ? (int) $data['totals'][ $id ] : 0,
		);
	}

	/**
	 * Every form's signups in the last 30 days, added up.
	 *
	 * @return int
	 */
	public static function recent_signups() {
		$total = 0;

		foreach ( self::load()['signups'] as $day ) {
			$total += array_sum( array_map( 'intval', (array) $day ) );
		}

		return $total;
	}

	/**
	 * Signups stopped in the last 30 days, by reason.
	 *
	 * @return array<string,int>
	 */
	public static function blocked_counts() {
		$out = array_fill_keys( self::REASONS, 0 );

		foreach ( self::load()['blocked'] as $day ) {
			foreach ( (array) $day as $reason => $count ) {
				if ( isset( $out[ $reason ] ) ) {
					$out[ $reason ] += (int) $count;
				}
			}
		}

		return $out;
	}

	/**
	 * Recent errors, newest first.
	 *
	 * @return array<int,array{time:int,form:int,text:string}>
	 */
	public static function errors() {
		return self::load()['errors'];
	}

	/**
	 * Forget the recent errors.
	 *
	 * @return void
	 */
	public static function clear_errors() {
		$data           = self::load();
		$data['errors'] = array();

		self::save( $data );
	}

	/**
	 * Drop a deleted form's counts.
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	public static function forget_form( $form_id ) {
		$data = self::load();
		$id   = (string) (int) $form_id;

		unset( $data['totals'][ $id ] );
		foreach ( $data['signups'] as $day => $counts ) {
			unset( $data['signups'][ $day ][ $id ] );
		}

		self::save( $data );
	}

	/**
	 * The stored counters, in a known shape.
	 *
	 * @return array{signups:array,blocked:array,totals:array,errors:array}
	 */
	private static function load() {
		$data = get_option( self::OPTION );
		$data = is_array( $data ) ? $data : array();

		foreach ( array( 'signups', 'blocked', 'totals', 'errors' ) as $key ) {
			$data[ $key ] = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
		}

		return $data;
	}

	/**
	 * Store the counters, dropping days older than {@see DAYS}.
	 *
	 * @param array $data Counters.
	 * @return void
	 */
	private static function save( array $data ) {
		$oldest = gmdate( 'Ymd', time() - ( self::DAYS - 1 ) * DAY_IN_SECONDS );

		foreach ( array( 'signups', 'blocked' ) as $key ) {
			foreach ( array_keys( $data[ $key ] ) as $day ) {
				if ( (string) $day < $oldest ) {
					unset( $data[ $key ][ $day ] );
				}
			}
		}

		update_option( self::OPTION, $data, false );
	}
}

<?php
/**
 * Optional Cloudflare Turnstile check on signups.
 *
 * The widget runs in the visitor's browser and hands the form a one-time
 * token; the server asks Cloudflare whether that token is real before the
 * address goes anywhere near Mailchimp.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turnstile helper.
 */
final class Easy_Mailchimp_Newsletter_Embed_Turnstile {

	/**
	 * Cloudflare's token check.
	 */
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * The widget script (loaded by assets/form.js only when a form is used).
	 */
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

	/**
	 * Site and secret keys: "0x4AAAA…" for real ones, "1x0000…AA" for Cloudflare's test keys.
	 */
	const KEY_PATTERN = '/^[0-9]x[A-Za-z0-9_-]{10,100}$/D';

	/**
	 * Action name the widget is rendered with.
	 */
	const ACTION = 'emne-subscribe';

	/**
	 * Whether a string looks like a Turnstile key.
	 *
	 * @param string $key Site or secret key.
	 * @return bool
	 */
	public static function valid_key( $key ) {
		return is_string( $key ) && (bool) preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Check a token with Cloudflare.
	 *
	 * @param string $secret Secret key.
	 * @param string $token  Token from the widget.
	 * @param string $ip     Visitor IP ('' to leave out).
	 * @return array{ok:bool,codes:string[],reached:bool} reached is false when Cloudflare couldn't be asked.
	 */
	public static function verify( $secret, $token, $ip = '' ) {
		$token = is_string( $token ) ? $token : '';

		if ( '' === $token || strlen( $token ) > 2048 ) {
			return array(
				'ok'      => false,
				'codes'   => array( 'missing-input-response' ),
				'reached' => true,
			);
		}

		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'ok'      => false,
				'codes'   => array( is_wp_error( $response ) ? 'network-error' : 'http-' . (int) wp_remote_retrieve_response_code( $response ) ),
				'reached' => false,
			);
		}

		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$codes = isset( $data['error-codes'] ) && is_array( $data['error-codes'] ) ? array_map( 'sanitize_key', array_filter( $data['error-codes'], 'is_string' ) ) : array();

		return array(
			'ok'      => is_array( $data ) && true === ( isset( $data['success'] ) ? $data['success'] : false ),
			'codes'   => array_slice( $codes, 0, 5 ),
			'reached' => is_array( $data ),
		);
	}

	/**
	 * Check a secret key by sending Cloudflare a dummy token: a good secret
	 * gets "invalid-input-response" back, a bad one "invalid-input-secret".
	 *
	 * @param string $secret Secret key.
	 * @return string ok, bad_secret or unreachable.
	 */
	public static function test_secret( $secret ) {
		$result = self::verify( $secret, 'emne-key-test' );

		if ( ! $result['reached'] ) {
			return 'unreachable';
		}

		if ( $result['ok'] || in_array( 'invalid-input-response', $result['codes'], true ) ) {
			return 'ok';
		}

		return 'bad_secret';
	}
}

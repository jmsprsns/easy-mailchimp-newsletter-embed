<?php
/**
 * Handles a signup: checks it's a real person on this site, then hands the
 * address to Mailchimp.
 *
 * Runs through admin-ajax.php rather than the REST API, because security and
 * speed plugins often switch the REST API off for visitors. The visitor
 * only sends a form ID; which audience, tags and opt-in type apply comes from
 * that form's saved settings, never from the request.
 *
 * Checks, cheapest first: real POST from this site, the hidden bot field, how
 * long the form was on screen, the address and name, the per-visitor and
 * per-address limits, then (when it's on) Cloudflare Turnstile.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signup handler.
 */
final class Easy_Mailchimp_Newsletter_Embed_Subscribe {

	/**
	 * The admin-ajax action.
	 */
	const ACTION = 'emne_subscribe';

	/**
	 * Fewest milliseconds a form can be on screen before it's sent.
	 */
	const MIN_MS = 1500;

	/**
	 * Signups allowed per email address per hour (each one can send a confirmation email).
	 */
	const EMAIL_LIMIT = 3;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Messages visitors can see.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	private static function message( $key ) {
		$messages = array(
			'error'       => __( 'Sorry, something went wrong. Please try again in a moment.', 'easy-mailchimp-newsletter-embed' ),
			'unavailable' => __( 'This signup form isn\'t available right now.', 'easy-mailchimp-newsletter-embed' ),
			'email'       => __( 'Please enter a valid email address.', 'easy-mailchimp-newsletter-embed' ),
			'name'        => __( 'Please enter your name.', 'easy-mailchimp-newsletter-embed' ),
			'name_bad'    => __( 'Please enter just your name.', 'easy-mailchimp-newsletter-embed' ),
			'consent'     => __( 'Please tick the box to agree before subscribing.', 'easy-mailchimp-newsletter-embed' ),
			'too_fast'    => __( 'That was quick! Please wait a moment and try again.', 'easy-mailchimp-newsletter-embed' ),
			'rate_limit'  => __( 'There have been too many signups from here recently. Please try again later.', 'easy-mailchimp-newsletter-embed' ),
			'turnstile'   => __( 'Please complete the spam check and try again.', 'easy-mailchimp-newsletter-embed' ),
			'too_many'    => __( 'This email address has joined a lot of mailing lists recently, so Mailchimp has paused new signups for it. Please try again later.', 'easy-mailchimp-newsletter-embed' ),
		);

		return isset( $messages[ $key ] ) ? $messages[ $key ] : $messages['error'];
	}

	/**
	 * Handle a signup request and reply with JSON.
	 *
	 * @return void
	 */
	public static function handle() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			self::reply( false, self::message( 'error' ), 405 );
		}

		// Another site's page can't sign its visitors up (their IPs would dodge the limits).
		if ( ! self::same_origin() ) {
			Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'origin' );
			self::reply( false, self::message( 'error' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public signup form on cacheable pages: no nonce; checked by origin, bot traps, limits and optional Turnstile.
		$form   = Easy_Mailchimp_Newsletter_Embed_Forms::get( absint( self::post( 'form_id', 12 ) ) );
		$plugin = Easy_Mailchimp_Newsletter_Embed_Plugin::instance();

		if ( ! $form || '' === $form['list_id'] || ! $plugin->is_connected() ) {
			self::reply( false, self::message( 'unavailable' ), 400 );
		}

		// Bots fill in every field. Let them think it worked so they move on.
		if ( '' !== self::post( 'emne_xf', 200 ) ) {
			Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'honeypot' );
			self::reply( true, Easy_Mailchimp_Newsletter_Embed_Forms::success_message( $form ) );
		}

		// How long the form was on screen, measured by the page's script.
		$elapsed = self::post( 'emne_t', 12 );
		if ( ! ctype_digit( $elapsed ) || (int) $elapsed < self::MIN_MS ) {
			Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'too_fast' );
			self::reply( false, self::message( 'too_fast' ), 400 );
		}

		$email = sanitize_email( self::post( 'email', 254 ) );
		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			self::reply( false, self::message( 'email' ), 400 );
		}

		$first = 'none' === $form['name_field'] ? '' : self::name( self::post( 'fname', 200 ) );
		$last  = 'first_last' === $form['name_field'] ? self::name( self::post( 'lname', 200 ) ) : '';

		if ( false === $first || false === $last ) {
			self::reply( false, self::message( 'name_bad' ), 400 );
		}

		// One "Name" box: the first word is the first name, the rest the last name.
		if ( 'full' === $form['name_field'] && false !== strpos( $first, ' ' ) ) {
			list( $first, $last ) = explode( ' ', $first, 2 );
		}
		if ( $form['name_required'] && 'none' !== $form['name_field'] && ( '' === $first || ( 'first_last' === $form['name_field'] && '' === $last ) ) ) {
			self::reply( false, self::message( 'name' ), 400 );
		}

		if ( '' !== $form['consent'] && '1' !== self::post( 'consent', 1 ) ) {
			self::reply( false, self::message( 'consent' ), 400 );
		}

		$ip    = Easy_Mailchimp_Newsletter_Embed_Visitor::ip();
		$limit = (int) $plugin->get_setting( 'rate_limit' );

		// Per visitor, only for public addresses: a private one means a proxy
		// we can't see past, and every visitor would share its limit.
		if ( $limit > 0 && Easy_Mailchimp_Newsletter_Embed_Visitor::is_public( $ip ) && ! self::allow( 'ip', $ip, $limit ) ) {
			Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'rate_limit' );
			self::reply( false, self::message( 'rate_limit' ), 429 );
		}

		// Per address, so nobody can flood one inbox with confirmation emails.
		if ( ! self::allow( 'email', self::inbox( $email ), self::EMAIL_LIMIT ) ) {
			Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'rate_limit' );
			self::reply( false, self::message( 'rate_limit' ), 429 );
		}

		if ( $plugin->turnstile_on() ) {
			$check = Easy_Mailchimp_Newsletter_Embed_Turnstile::verify( $plugin->turnstile_secret(), self::post( 'cf-turnstile-response', 2048 ), $ip );

			if ( ! $check['ok'] ) {
				if ( ! $check['reached'] ) {
					/* translators: %s: error code. */
					Easy_Mailchimp_Newsletter_Embed_Stats::error( $form['id'], sprintf( __( 'Couldn\'t reach Cloudflare to check a Turnstile token (%s), so the signup was refused.', 'easy-mailchimp-newsletter-embed' ), implode( ', ', $check['codes'] ) ) );
				} elseif ( array_intersect( $check['codes'], array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
					Easy_Mailchimp_Newsletter_Embed_Stats::error( $form['id'], __( 'Cloudflare rejected the Turnstile secret key, so every signup is being refused. Check the keys on the Spam protection tab.', 'easy-mailchimp-newsletter-embed' ) );
				}
				Easy_Mailchimp_Newsletter_Embed_Stats::blocked( 'turnstile' );
				self::reply( false, self::message( 'turnstile' ), 400 );
			}
		}
		// phpcs:enable

		$result = Easy_Mailchimp_Newsletter_Embed_Mailchimp::subscribe(
			$plugin->api_key(),
			$form['list_id'],
			$email,
			array(
				'FNAME' => $first,
				'LNAME' => $last,
			),
			$form['tags'],
			(bool) $form['double_optin'],
			Easy_Mailchimp_Newsletter_Embed_Visitor::is_public( $ip ) ? $ip : ''
		);

		switch ( $result['status'] ) {
			case 'added':
			case 'pending':
			case 'exists':
				if ( 'exists' !== $result['status'] ) {
					Easy_Mailchimp_Newsletter_Embed_Stats::signup( $form['id'] );
				}

				/**
				 * Fires after a successful signup.
				 *
				 * @param string $email  Email address.
				 * @param array  $form   Form settings.
				 * @param string $status added, pending (confirmation email sent) or exists (already on the audience).
				 */
				do_action( 'easy_mailchimp_newsletter_embed_subscribed', $email, $form, $result['status'] );

				// Already on the list gets the same answer, so the form can't be used to look addresses up.
				self::reply( true, Easy_Mailchimp_Newsletter_Embed_Forms::success_message( $form ), 200, (string) $form['redirect'] );
				break;

			case 'invalid_email':
				self::reply( false, self::message( 'email' ), 400 );
				break;

			case 'too_many':
				self::reply( false, self::message( 'too_many' ), 400 );
				break;

			default:
				Easy_Mailchimp_Newsletter_Embed_Stats::error( $form['id'], $result['detail'] );
				self::reply( false, self::message( 'error' ), 502 );
		}
	}

	/**
	 * Whether the request came from a page on this site. Browsers send
	 * Origin (and Sec-Fetch-Site) with every POST; a request without either
	 * isn't from a browser, so it can't be a forged cross-site one.
	 *
	 * @return bool
	 */
	private static function same_origin() {
		$fetch_site = strtolower( self::server( 'HTTP_SEC_FETCH_SITE' ) );

		if ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) {
			return false;
		}

		$origin = self::server( 'HTTP_ORIGIN' );
		if ( '' === $origin ) {
			$origin = self::server( 'HTTP_REFERER' );
		}

		if ( '' === $origin ) {
			return true;
		}

		$host = self::host_of( $origin );

		if ( '' === $host ) {
			return false;
		}

		$allowed = array_filter(
			array(
				self::host_of( home_url() ),
				self::host_of( site_url() ),
				strtolower( self::server( 'HTTP_HOST' ) ),
			)
		);

		return in_array( $host, $allowed, true );
	}

	/**
	 * "host" or "host:port" of a URL, lowercased, default ports dropped.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		return $port && 80 !== $port && 443 !== $port ? $host . ':' . $port : $host;
	}

	/**
	 * Sliding one-hour limit, counted in a transient keyed by a salted hash
	 * (so the IP or address itself isn't stored).
	 *
	 * @param string $kind  ip or email.
	 * @param string $value The IP or address.
	 * @param int    $limit Allowed per hour.
	 * @return bool False when the limit is used up (this attempt isn't counted).
	 */
	private static function allow( $kind, $value, $limit ) {
		$key  = 'emne_rl_' . $kind . '_' . substr( hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) ), 0, 24 );
		$now  = time();
		$hits = get_transient( $key );
		$hits = array_values(
			array_filter(
				is_array( $hits ) ? $hits : array(),
				function ( $time ) use ( $now ) {
					return (int) $time > $now - HOUR_IN_SECONDS;
				}
			)
		);

		if ( count( $hits ) >= $limit ) {
			return false;
		}

		$hits[] = $now;
		set_transient( $key, $hits, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * The inbox an address delivers to, for the per-address limit: "+tags"
	 * dropped everywhere, and dots too at Gmail, so j.o.e+1@gmail.com and
	 * joe+2@gmail.com count against the same inbox.
	 *
	 * @param string $email Valid address.
	 * @return string
	 */
	private static function inbox( $email ) {
		$email = strtolower( $email );
		$at    = strrpos( $email, '@' );
		$local = substr( $email, 0, $at );
		$host  = substr( $email, $at + 1 );
		$plus  = strpos( $local, '+' );

		if ( false !== $plus && $plus > 0 ) {
			$local = substr( $local, 0, $plus );
		}

		if ( 'gmail.com' === $host || 'googlemail.com' === $host ) {
			$local = str_replace( '.', '', $local );
			$host  = 'gmail.com';
		}

		return $local . '@' . $host;
	}

	/**
	 * A name field, cleaned: plain text, at most 50 characters. Links and
	 * email addresses are refused, because spammers put them in the name so
	 * Mailchimp's confirmation email carries their message.
	 *
	 * @param string $raw Raw value.
	 * @return string|false False when it isn't a plausible name.
	 */
	private static function name( $raw ) {
		$name = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $raw ) ) );

		if ( preg_match( '#(://|www\.|@|\.[a-z]{2,10}/)#i', $name ) ) {
			return false;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );

		return $length > 50 ? false : $name;
	}

	/**
	 * A posted value as a string ('' for anything else), capped in length.
	 *
	 * @param string $key Field.
	 * @param int    $max Longest accepted value; longer ones become ''.
	 * @return string
	 */
	private static function post( $key, $max ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See handle(); every caller cleans the value.
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return strlen( $value ) > $max * 4 ? '' : trim( $value );
	}

	/**
	 * A $_SERVER value as a string.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function server( $key ) {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? substr( wp_unslash( $_SERVER[ $key ] ), 0, 2000 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
	}

	/**
	 * Send the JSON reply and stop.
	 *
	 * @param bool   $ok       Whether the signup went through.
	 * @param string $message  Message for the visitor.
	 * @param int    $status   HTTP status.
	 * @param string $redirect Where to send the visitor next ('' to stay).
	 * @return void
	 */
	private static function reply( $ok, $message, $status = 200, $redirect = '' ) {
		wp_send_json(
			array(
				'ok'       => (bool) $ok,
				'message'  => $message,
				'redirect' => $ok ? $redirect : '',
			),
			$status
		);
	}
}

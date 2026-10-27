<?php
/**
 * Talks to the Mailchimp Marketing API (v3): the account name, its audiences,
 * and adding subscribers.
 *
 * Every request goes to {data center}.api.mailchimp.com, where the data
 * center comes from the end of the API key ("…-us21") and must match a strict
 * pattern, so a crafted key can never point a request at another host.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mailchimp API client.
 */
final class Easy_Mailchimp_Newsletter_Embed_Mailchimp {

	/**
	 * A Mailchimp API key: 32 hex characters, a dash and the data center.
	 */
	const KEY_PATTERN = '/^[0-9a-f]{32}-(us[0-9]{1,3})$/D';

	/**
	 * An audience (list) ID, as Mailchimp writes them (e.g. "a1b2c3d4e5").
	 */
	const LIST_PATTERN = '/^[0-9a-z]{1,32}$/D';

	/**
	 * Option caching the account name and its audiences (not autoloaded).
	 */
	const ACCOUNT_OPTION = 'easy_mailchimp_newsletter_embed_account';

	/**
	 * Most audiences listed (Mailchimp's paid plans allow a few hundred at most).
	 */
	const MAX_LISTS = 1000;

	/**
	 * The data center an API key belongs to ("us21").
	 *
	 * @param string $key API key.
	 * @return string '' when it isn't a valid key.
	 */
	public static function data_center( $key ) {
		return is_string( $key ) && preg_match( self::KEY_PATTERN, $key, $m ) ? $m[1] : '';
	}

	/**
	 * A key with all but its last few characters hidden ("••••3f9a-us21").
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask( $key ) {
		$dc = self::data_center( $key );

		return '' === $dc ? '' : str_repeat( '•', 8 ) . substr( $key, 28, 4 ) . '-' . $dc;
	}

	/**
	 * Fetch the account name and every audience, for the settings screen.
	 *
	 * @param string $key API key.
	 * @return array{name:string,lists:array<string,array{name:string,count:int,double_optin:bool}>,checked:int}|WP_Error
	 */
	public static function fetch_account( $key ) {
		$root = self::request( $key, 'GET', '', null, array( 'fields' => 'account_name' ) );

		if ( 200 !== $root['code'] ) {
			return new WP_Error( 'emne_account', self::error_text( $root ) );
		}

		$lists  = array();
		$offset = 0;

		do {
			$page = self::request(
				$key,
				'GET',
				'lists',
				null,
				array(
					'fields' => 'lists.id,lists.name,lists.stats.member_count,lists.double_optin,total_items',
					'count'  => 100,
					'offset' => $offset,
				)
			);

			if ( 200 !== $page['code'] ) {
				return new WP_Error( 'emne_lists', self::error_text( $page ) );
			}

			$rows = isset( $page['data']['lists'] ) && is_array( $page['data']['lists'] ) ? $page['data']['lists'] : array();
			foreach ( $rows as $row ) {
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';
				if ( ! preg_match( self::LIST_PATTERN, $id ) ) {
					continue;
				}
				$lists[ $id ] = array(
					'name'         => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : $id,
					'count'        => isset( $row['stats']['member_count'] ) ? (int) $row['stats']['member_count'] : 0,
					'double_optin' => ! empty( $row['double_optin'] ),
				);
			}

			$offset += 100;
			$total   = isset( $page['data']['total_items'] ) ? (int) $page['data']['total_items'] : 0;
		} while ( $rows && $offset < $total && $offset < self::MAX_LISTS );

		return array(
			'name'    => isset( $root['data']['account_name'] ) ? sanitize_text_field( (string) $root['data']['account_name'] ) : '',
			'lists'   => $lists,
			'checked' => time(),
		);
	}

	/**
	 * The cached account details.
	 *
	 * @return array{name:string,lists:array,checked:int}
	 */
	public static function account() {
		$stored = get_option( self::ACCOUNT_OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'name'    => isset( $stored['name'] ) ? (string) $stored['name'] : '',
			'lists'   => isset( $stored['lists'] ) && is_array( $stored['lists'] ) ? $stored['lists'] : array(),
			'checked' => isset( $stored['checked'] ) ? (int) $stored['checked'] : 0,
		);
	}

	/**
	 * Cache the account details.
	 *
	 * @param array $account From {@see fetch_account()}, or an empty array to forget them.
	 * @return void
	 */
	public static function save_account( array $account ) {
		if ( ! $account ) {
			delete_option( self::ACCOUNT_OPTION );
			return;
		}

		update_option( self::ACCOUNT_OPTION, $account, false );
	}

	/**
	 * The audiences: ID => {name, count, double_optin}.
	 *
	 * @return array<string,array{name:string,count:int,double_optin:bool}>
	 */
	public static function lists() {
		$account = self::account();

		return $account['lists'];
	}

	/**
	 * An audience's name, or its ID when it isn't in the cached list.
	 *
	 * @param string $list_id Audience ID.
	 * @return string
	 */
	public static function list_name( $list_id ) {
		$lists = self::lists();

		return isset( $lists[ $list_id ]['name'] ) ? $lists[ $list_id ]['name'] : (string) $list_id;
	}

	/**
	 * Add someone to an audience.
	 *
	 * New addresses are created with the form's tags. Addresses already on
	 * the audience are never edited (anyone could otherwise rename a known
	 * subscriber): subscribed and pending ones only get the form's tags, and
	 * unsubscribed or archived ones are asked to confirm again, which Mailchimp
	 * requires anyway.
	 *
	 * @param string   $key          API key.
	 * @param string   $list_id      Audience ID.
	 * @param string   $email        Email address (already validated).
	 * @param string[] $merge        Merge fields, e.g. FNAME => 'Ana'.
	 * @param string[] $tags         Tag names.
	 * @param bool     $double_optin Whether new subscribers confirm by email first.
	 * @param string   $ip           Visitor IP, sent as the signup IP ('' to leave out).
	 * @return array{status:string,detail:string} status is added, pending, exists,
	 *               invalid_email, too_many, forgotten or error; detail is for admins.
	 */
	public static function subscribe( $key, $list_id, $email, array $merge, array $tags, $double_optin, $ip = '' ) {
		if ( ! preg_match( self::LIST_PATTERN, (string) $list_id ) ) {
			return self::result( 'error', __( 'The form has no valid audience selected.', 'easy-mailchimp-newsletter-embed' ) );
		}

		$hash = md5( strtolower( $email ) );
		$body = array(
			'email_address' => $email,
			'status'        => $double_optin ? 'pending' : 'subscribed',
		);

		$merge = array_filter( array_map( 'strval', $merge ), 'strlen' );
		if ( $merge ) {
			$body['merge_fields'] = $merge;
		}
		if ( $tags ) {
			$body['tags'] = array_values( $tags );
		}
		if ( '' !== $ip ) {
			$body['ip_signup'] = $ip;
		}

		$path = 'lists/' . $list_id . '/members';
		$res  = self::request( $key, 'POST', $path, $body, array( 'skip_merge_validation' => 'true' ) );

		// An audience without the FNAME/LNAME fields: add the address without them.
		if ( 400 === $res['code'] && $merge && false !== stripos( self::field( $res, 'detail' ), 'merge field' ) ) {
			unset( $body['merge_fields'] );
			$res = self::request( $key, 'POST', $path, $body, array( 'skip_merge_validation' => 'true' ) );
		}

		if ( 200 === $res['code'] ) {
			return self::result( 'pending' === self::field( $res, 'status' ) ? 'pending' : 'added' );
		}

		if ( 400 === $res['code'] && 'Member Exists' === self::field( $res, 'title' ) ) {
			return self::existing( $key, $list_id, $hash, $tags, $double_optin );
		}

		return self::classify( $res );
	}

	/**
	 * Handle an address that's already on the audience.
	 *
	 * @param string   $key          API key.
	 * @param string   $list_id      Audience ID.
	 * @param string   $hash         MD5 of the lowercased address.
	 * @param string[] $tags         Tag names.
	 * @param bool     $double_optin Whether new subscribers confirm by email first.
	 * @return array{status:string,detail:string}
	 */
	private static function existing( $key, $list_id, $hash, array $tags, $double_optin ) {
		$path   = 'lists/' . $list_id . '/members/' . $hash;
		$member = self::request( $key, 'GET', $path, null, array( 'fields' => 'status' ) );

		if ( 200 !== $member['code'] ) {
			return self::classify( $member );
		}

		$status = self::field( $member, 'status' );
		$result = 'exists';

		if ( 'cleaned' === $status ) {
			return self::result( 'invalid_email', __( 'This address bounced before, so Mailchimp won\'t add it again.', 'easy-mailchimp-newsletter-embed' ) );
		}

		if ( in_array( $status, array( 'unsubscribed', 'archived', 'transactional' ), true ) ) {
			// Mailchimp only lets people who unsubscribed back in through a confirmation email.
			$new    = 'unsubscribed' === $status || $double_optin ? 'pending' : 'subscribed';
			$update = self::request( $key, 'PATCH', $path, array( 'status' => $new ) );

			if ( 200 !== $update['code'] ) {
				return self::classify( $update );
			}

			$result = 'pending' === $new ? 'pending' : 'added';
		} elseif ( ! in_array( $status, array( 'subscribed', 'pending' ), true ) ) {
			/* translators: %s: status Mailchimp reported. */
			return self::result( 'error', sprintf( __( 'Mailchimp reported an unexpected status for an existing contact: %s', 'easy-mailchimp-newsletter-embed' ), sanitize_key( $status ) ) );
		}

		if ( $tags ) {
			$tag_rows = array();
			foreach ( $tags as $tag ) {
				$tag_rows[] = array(
					'name'   => $tag,
					'status' => 'active',
				);
			}
			self::request( $key, 'POST', $path . '/tags', array( 'tags' => $tag_rows ) );
		}

		return self::result( $result );
	}

	/**
	 * Turn a failed response into a result.
	 *
	 * @param array $res Response from {@see request()}.
	 * @return array{status:string,detail:string}
	 */
	private static function classify( array $res ) {
		$title  = self::field( $res, 'title' );
		$detail = self::field( $res, 'detail' );

		if ( 400 === $res['code'] ) {
			if ( 'Forgotten Email Not Subscribed' === $title ) {
				return self::result( 'forgotten', self::error_text( $res ) );
			}
			if ( false !== stripos( $detail, 'signed up to a lot of lists' ) ) {
				return self::result( 'too_many', self::error_text( $res ) );
			}
			if ( 'Invalid Resource' === $title && false !== stripos( $detail, 'email' ) ) {
				return self::result( 'invalid_email', self::error_text( $res ) );
			}
		}

		return self::result( 'error', self::error_text( $res ) );
	}

	/**
	 * A result array.
	 *
	 * @param string $status Result status.
	 * @param string $detail Details for admins.
	 * @return array{status:string,detail:string}
	 */
	private static function result( $status, $detail = '' ) {
		return array(
			'status' => $status,
			'detail' => $detail,
		);
	}

	/**
	 * A readable explanation of a failed request, for admins. Email addresses
	 * Mailchimp quotes back are blanked out, so they never land in the error log.
	 *
	 * @param array $res Response from {@see request()}.
	 * @return string
	 */
	public static function error_text( array $res ) {
		if ( 0 === $res['code'] ) {
			/* translators: %s: connection error. */
			$text = sprintf( __( 'Couldn\'t reach Mailchimp: %s', 'easy-mailchimp-newsletter-embed' ), $res['error'] );
		} elseif ( 401 === $res['code'] || 403 === $res['code'] ) {
			$text = __( 'Mailchimp rejected the API key. It may have been deleted or disabled; connect again with a new key.', 'easy-mailchimp-newsletter-embed' );
		} elseif ( 404 === $res['code'] ) {
			$text = __( 'Mailchimp couldn\'t find that audience. It may have been deleted; pick another one in the form\'s settings.', 'easy-mailchimp-newsletter-embed' );
		} else {
			$parts = array_filter( array( self::field( $res, 'title' ), self::field( $res, 'detail' ) ) );
			$text  = $parts ? implode( ': ', $parts ) : __( 'Mailchimp returned an error.', 'easy-mailchimp-newsletter-embed' );

			if ( isset( $res['data']['errors'] ) && is_array( $res['data']['errors'] ) ) {
				foreach ( array_slice( $res['data']['errors'], 0, 3 ) as $error ) {
					if ( is_array( $error ) && isset( $error['field'], $error['message'] ) && is_scalar( $error['field'] ) && is_scalar( $error['message'] ) ) {
						$text .= ' (' . $error['field'] . ': ' . $error['message'] . ')';
					}
				}
			}

			/* translators: %d: HTTP status code. */
			$text .= ' ' . sprintf( __( '[HTTP %d]', 'easy-mailchimp-newsletter-embed' ), $res['code'] );
		}

		// Capped first so the pattern can't run long on a huge reply.
		$text = preg_replace( '/[^\s@<>"\'()]+@[^\s@<>"\'()]+/', '[email]', substr( $text, 0, 1000 ) );

		return substr( sanitize_text_field( $text ), 0, 500 );
	}

	/**
	 * A string field from a response body.
	 *
	 * @param array  $res   Response from {@see request()}.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function field( array $res, $field ) {
		return isset( $res['data'][ $field ] ) && is_scalar( $res['data'][ $field ] ) ? (string) $res['data'][ $field ] : '';
	}

	/**
	 * One API request.
	 *
	 * @param string     $key    API key.
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /3.0/.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query arguments.
	 * @return array{code:int,data:array,error:string} code 0 when the request failed.
	 */
	private static function request( $key, $method, $path, $body = null, array $query = array() ) {
		$dc = self::data_center( $key );

		if ( '' === $dc ) {
			return array(
				'code'  => 0,
				'data'  => array(),
				'error' => __( 'No valid API key is saved.', 'easy-mailchimp-newsletter-embed' ),
			);
		}

		$url = 'https://' . $dc . '.api.mailchimp.com/3.0/' . $path . ( $query ? '?' . http_build_query( $query, '', '&' ) : '' );

		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'user-agent'  => 'Easy Mailchimp Newsletter Embed/' . EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION . ' (WordPress)',
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( 'emne:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'data'  => array(),
				'error' => $response->get_error_message(),
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'data'  => is_array( $data ) ? $data : array(),
			'error' => '',
		);
	}
}

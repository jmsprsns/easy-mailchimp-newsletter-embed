<?php
/**
 * The visitor's IP address, for the signup rate limit and Mailchimp's
 * signup record, worked out so a bot can't fake its way past the limit.
 *
 * Headers like X-Forwarded-For and CF-Connecting-IP are just text any client
 * can send, so they're only believed when the connection itself comes from
 * Cloudflare (checked against Cloudflare's published ranges) or from a proxy
 * on the server's own private network. Without that, every visitor of a site
 * behind Cloudflare would share a handful of Cloudflare addresses and use up
 * each other's signups.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visitor IP helper.
 */
final class Easy_Mailchimp_Newsletter_Embed_Visitor {

	/**
	 * Cloudflare's edge ranges, from https://www.cloudflare.com/ips/.
	 */
	const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Private, loopback, link-local and carrier-grade NAT ranges.
	 */
	const PRIVATE_RANGES = array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '::1/128', 'fc00::/7', 'fe80::/10' );

	/**
	 * The visitor's IP address.
	 *
	 * @return string '' when there's no valid address.
	 */
	public static function ip() {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );
		$ip     = $remote;

		if ( '' !== $remote ) {
			if ( self::in_any( $remote, self::CLOUDFLARE ) ) {
				$cf = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
				$ip = '' !== $cf ? $cf : $remote;
			} elseif ( self::in_any( $remote, self::PRIVATE_RANGES ) ) {
				$ip = self::from_forwarded( $remote );
			}
		}

		/**
		 * Filter the visitor IP used for the signup rate limit and sent to Mailchimp.
		 *
		 * @param string $ip     Detected address.
		 * @param string $remote The connection's own address (REMOTE_ADDR).
		 */
		return self::valid_ip( (string) apply_filters( 'easy_mailchimp_newsletter_embed_visitor_ip', $ip, $remote ) );
	}

	/**
	 * Whether an address is publicly routable (worth sending to Mailchimp).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public( $ip ) {
		return '' !== self::valid_ip( $ip )
			&& ! self::in_any( $ip, self::PRIVATE_RANGES )
			&& (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Behind a proxy on the server's private network: the address that proxy
	 * added, read right to left so a client can't slip in a fake one.
	 *
	 * @param string $remote REMOTE_ADDR.
	 * @return string
	 */
	private static function from_forwarded( $remote ) {
		$value = self::server( 'HTTP_X_FORWARDED_FOR' );

		if ( '' === $value ) {
			return $remote;
		}

		$hops = array_slice( array_map( 'trim', explode( ',', $value ) ), -20 );

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = self::valid_ip( $hops[ $i ] );
			if ( '' === $hop ) {
				break;
			}
			if ( ! self::in_any( $hop, self::PRIVATE_RANGES ) ) {
				if ( self::in_any( $hop, self::CLOUDFLARE ) ) {
					$cf = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
					return '' !== $cf ? $cf : $hop;
				}
				return $hop;
			}
		}

		return $remote;
	}

	/**
	 * Whether an IP is in any of a set of ranges.
	 *
	 * @param string   $ip     IP address.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	private static function in_any( $ip, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP falls inside a CIDR range.
	 *
	 * @param string $ip    IP address.
	 * @param string $range CIDR range.
	 * @return bool
	 */
	private static function in_range( $ip, $range ) {
		$parts  = explode( '/', $range, 2 );
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "no match".
		$sn_bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_bin || false === $sn_bin || strlen( $ip_bin ) !== strlen( $sn_bin ) ) {
			return false;
		}

		$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $ip_bin ) * 8;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $sn_bin, 0, $bytes ) ) {
			return false;
		}

		if ( $rest > 0 ) {
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $sn_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A trimmed, valid IP address, or ''.
	 *
	 * @param mixed $ip Candidate.
	 * @return string
	 */
	private static function valid_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		return '' !== $ip && strlen( $ip ) <= 45 && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * A $_SERVER value as a string.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function server( $key ) {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP by the caller.
	}
}

<?php
/**
 * The signup forms: storage, defaults, cleaning, and which popup (if any)
 * belongs on the current page.
 *
 * All forms live in one option, so the front end reads them with the rest of
 * the autoloaded options and never queries the database for them.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form storage.
 */
final class Easy_Mailchimp_Newsletter_Embed_Forms {

	/**
	 * Option key: {next: int, forms: {id: form}}.
	 */
	const OPTION = 'easy_mailchimp_newsletter_embed_forms';

	/**
	 * Most forms a site can have.
	 */
	const MAX_FORMS = 100;

	/**
	 * Themes: id => Lucide icon.
	 */
	const THEMES = array(
		'light'   => 'sun',
		'dark'    => 'moon',
		'minimal' => 'minus',
		'bold'    => 'square',
		'soft'    => 'cloud',
		'plain'   => 'type',
	);

	/**
	 * Choice fields: key => allowed values (the first is the default).
	 */
	const CHOICES = array(
		'name_field'     => array( 'first', 'first_last', 'full', 'none' ),
		'theme'          => array( 'light', 'dark', 'minimal', 'bold', 'soft', 'plain' ),
		'layout'         => array( 'stacked', 'inline' ),
		'align'          => array( 'left', 'center' ),
		'popup_trigger'  => array( 'delay', 'scroll', 'exit', 'click' ),
		'popup_position' => array( 'center', 'corner' ),
		'popup_where'    => array( 'all', 'posts', 'pages', 'home', 'paths' ),
		'popup_devices'  => array( 'all', 'desktop', 'mobile' ),
		'popup_visitors' => array( 'all', 'logged_out' ),
	);

	/**
	 * Default accent color.
	 */
	const ACCENT = '#2563eb';

	/**
	 * Settings for a new form.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'id'             => 0,
			'name'           => '',
			'list_id'        => '',
			'double_optin'   => 1,
			'tags'           => array(),
			'name_field'     => 'first',
			'name_required'  => 0,
			'show_labels'    => 0,
			'heading'        => __( 'Join our newsletter', 'easy-mailchimp-newsletter-embed' ),
			'description'    => __( 'Get our latest posts and updates by email. No spam, and you can unsubscribe any time.', 'easy-mailchimp-newsletter-embed' ),
			'email_label'    => __( 'Email address', 'easy-mailchimp-newsletter-embed' ),
			'name_label'     => __( 'First name', 'easy-mailchimp-newsletter-embed' ),
			'last_label'     => __( 'Last name', 'easy-mailchimp-newsletter-embed' ),
			'button'         => __( 'Subscribe', 'easy-mailchimp-newsletter-embed' ),
			'success'        => '',
			'consent'        => '',
			'note'           => '',
			'redirect'       => '',
			'theme'          => 'light',
			'accent'         => self::ACCENT,
			'layout'         => 'stacked',
			'align'          => 'left',
			'popup'          => 0,
			'popup_trigger'  => 'delay',
			'popup_delay'    => 8,
			'popup_scroll'   => 50,
			'popup_position' => 'center',
			'popup_where'    => 'all',
			'popup_paths'    => '',
			'popup_exclude'  => '',
			'popup_devices'  => 'all',
			'popup_visitors' => 'all',
			'popup_days'     => 7,
		);
	}

	/**
	 * Translated theme names and one-line descriptions.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function theme_labels() {
		return array(
			'light'   => array( __( 'Light', 'easy-mailchimp-newsletter-embed' ), __( 'White card, soft shadow', 'easy-mailchimp-newsletter-embed' ) ),
			'dark'    => array( __( 'Dark', 'easy-mailchimp-newsletter-embed' ), __( 'Near-black card, light text', 'easy-mailchimp-newsletter-embed' ) ),
			'minimal' => array( __( 'Minimal', 'easy-mailchimp-newsletter-embed' ), __( 'No box, underlined fields', 'easy-mailchimp-newsletter-embed' ) ),
			'bold'    => array( __( 'Bold', 'easy-mailchimp-newsletter-embed' ), __( 'Filled with your accent color', 'easy-mailchimp-newsletter-embed' ) ),
			'soft'    => array( __( 'Soft', 'easy-mailchimp-newsletter-embed' ), __( 'Tinted panel, rounded pills', 'easy-mailchimp-newsletter-embed' ) ),
			'plain'   => array( __( 'Plain', 'easy-mailchimp-newsletter-embed' ), __( 'Unstyled: uses your theme', 'easy-mailchimp-newsletter-embed' ) ),
		);
	}

	/**
	 * The success message a form shows (its own, or the default for its opt-in type).
	 *
	 * @param array $form Form.
	 * @return string
	 */
	public static function success_message( array $form ) {
		if ( '' !== trim( (string) $form['success'] ) ) {
			return (string) $form['success'];
		}

		return $form['double_optin']
			? __( 'Almost done! Check your inbox and click the link to confirm your subscription.', 'easy-mailchimp-newsletter-embed' )
			: __( 'Thanks for subscribing! You\'re on the list.', 'easy-mailchimp-newsletter-embed' );
	}

	/**
	 * Every form, by ID (oldest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$stored = self::stored();
		$out    = array();

		foreach ( $stored['forms'] as $id => $form ) {
			if ( is_array( $form ) && (int) $id > 0 ) {
				$out[ (int) $id ] = array_merge( wp_parse_args( $form, self::defaults() ), array( 'id' => (int) $id ) );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * One form.
	 *
	 * @param int $id Form ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		$forms = self::all();

		return isset( $forms[ (int) $id ] ) ? $forms[ (int) $id ] : null;
	}

	/**
	 * Save a form (already cleaned by {@see sanitize()}). A form without an ID is added.
	 *
	 * @param array $form Form.
	 * @return int The form's ID, or 0 when the limit is reached.
	 */
	public static function save( array $form ) {
		$stored = self::stored();
		$id     = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( $id <= 0 || ! isset( $stored['forms'][ $id ] ) ) {
			if ( count( $stored['forms'] ) >= self::MAX_FORMS ) {
				return 0;
			}
			$id              = max( 1, (int) $stored['next'] );
			$stored['next']  = $id + 1;
			$form['created'] = time();
		}

		$form['id'] = $id;
		if ( '' === trim( (string) $form['name'] ) ) {
			/* translators: %d: form ID. */
			$form['name'] = sprintf( __( 'Signup form %d', 'easy-mailchimp-newsletter-embed' ), $id );
		}

		$stored['forms'][ $id ] = $form;
		update_option( self::OPTION, $stored, true );

		return $id;
	}

	/**
	 * Delete a form.
	 *
	 * @param int $id Form ID.
	 * @return bool Whether it existed.
	 */
	public static function delete( $id ) {
		$stored = self::stored();
		$id     = (int) $id;

		if ( ! isset( $stored['forms'][ $id ] ) ) {
			return false;
		}

		unset( $stored['forms'][ $id ] );
		update_option( self::OPTION, $stored, true );
		Easy_Mailchimp_Newsletter_Embed_Stats::forget_form( $id );

		return true;
	}

	/**
	 * Copy a form, popup switched off so two popups don't compete.
	 *
	 * @param int $id Form ID.
	 * @return int New form's ID (0 if it couldn't be copied).
	 */
	public static function duplicate( $id ) {
		$form = self::get( $id );

		if ( ! $form ) {
			return 0;
		}

		$form['id']    = 0;
		$form['popup'] = 0;
		/* translators: %s: name of the form that was copied. */
		$form['name'] = substr( sprintf( __( '%s (copy)', 'easy-mailchimp-newsletter-embed' ), $form['name'] ), 0, 100 );

		return self::save( $form );
	}

	/**
	 * Clean submitted form settings. Values come from the editor (already
	 * unslashed); anything missing falls back to $base.
	 *
	 * @param array $in   Raw values.
	 * @param array $base Current form (or defaults).
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in, array $base ) {
		$base = wp_parse_args( $base, self::defaults() );
		$text = function ( $key, $max ) use ( $in, $base ) {
			$value = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : (string) $base[ $key ];
			return self::cut( sanitize_text_field( $value ), $max );
		};
		$html = function ( $key, $max ) use ( $in, $base ) {
			$value = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : (string) $base[ $key ];
			return self::cut( trim( self::kses( $value ) ), $max );
		};
		$flag = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$num  = function ( $key, $min, $max ) use ( $in, $base ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $base[ $key ];
			return max( $min, min( $max, $value ) );
		};

		$out = array(
			'id'            => (int) $base['id'],
			'name'          => $text( 'name', 100 ),
			'list_id'       => '',
			'double_optin'  => $flag( 'double_optin' ),
			'tags'          => self::tags( isset( $in['tags'] ) && is_string( $in['tags'] ) ? $in['tags'] : '' ),
			'name_required' => $flag( 'name_required' ),
			'show_labels'   => $flag( 'show_labels' ),
			'heading'       => $text( 'heading', 200 ),
			'description'   => $html( 'description', 1000 ),
			'email_label'   => $text( 'email_label', 60 ),
			'name_label'    => $text( 'name_label', 60 ),
			'last_label'    => $text( 'last_label', 60 ),
			'button'        => $text( 'button', 60 ),
			'success'       => $text( 'success', 300 ),
			'consent'       => $html( 'consent', 500 ),
			'note'          => $html( 'note', 500 ),
			'redirect'      => '',
			'accent'        => self::accent( isset( $in['accent'] ) && is_string( $in['accent'] ) ? $in['accent'] : '' ),
			'popup'         => $flag( 'popup' ),
			'popup_delay'   => $num( 'popup_delay', 0, 600 ),
			'popup_scroll'  => $num( 'popup_scroll', 1, 100 ),
			'popup_paths'   => self::paths( isset( $in['popup_paths'] ) && is_string( $in['popup_paths'] ) ? $in['popup_paths'] : '' ),
			'popup_exclude' => self::paths( isset( $in['popup_exclude'] ) && is_string( $in['popup_exclude'] ) ? $in['popup_exclude'] : '' ),
			'popup_days'    => $num( 'popup_days', 0, 365 ),
		);

		foreach ( self::CHOICES as $key => $allowed ) {
			$value       = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : (string) $base[ $key ];
			$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $allowed[0];
		}

		$list = isset( $in['list_id'] ) && is_string( $in['list_id'] ) ? trim( $in['list_id'] ) : '';
		if ( preg_match( Easy_Mailchimp_Newsletter_Embed_Mailchimp::LIST_PATTERN, $list ) ) {
			$out['list_id'] = $list;
		}

		$redirect = isset( $in['redirect'] ) && is_string( $in['redirect'] ) ? trim( $in['redirect'] ) : '';
		if ( '' !== $redirect ) {
			$out['redirect'] = self::cut( esc_url_raw( $redirect, array( 'http', 'https' ) ), 500 );
		}

		// Required text falls back to the defaults rather than an empty label or button.
		$defaults = self::defaults();
		foreach ( array( 'email_label', 'name_label', 'last_label', 'button' ) as $key ) {
			if ( '' === $out[ $key ] ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}

		if ( 'full' === $out['name_field'] && $defaults['name_label'] === $out['name_label'] ) {
			$out['name_label'] = __( 'Name', 'easy-mailchimp-newsletter-embed' );
		}

		if ( isset( $base['created'] ) ) {
			$out['created'] = (int) $base['created'];
		}

		return $out;
	}

	/**
	 * The current page's popup form, if one is switched on and its page rules
	 * match. With several, the oldest form wins.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function popup_for_request() {
		if ( is_admin() || is_feed() || is_embed() || is_robots() || is_trackback() || is_customize_preview() ) {
			return null;
		}

		// Never between a shopper and their order.
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
			return null;
		}

		$path = self::request_path();

		foreach ( self::all() as $form ) {
			if ( ! $form['popup'] || '' === $form['list_id'] ) {
				continue;
			}
			if ( 'logged_out' === $form['popup_visitors'] && is_user_logged_in() ) {
				continue;
			}
			if ( self::path_matches( $path, $form['popup_exclude'] ) ) {
				continue;
			}
			if ( self::page_matches( $form, $path ) ) {
				return $form;
			}
		}

		return null;
	}

	/**
	 * Whether a form's "Show on" rule matches the current page.
	 *
	 * @param array  $form Form.
	 * @param string $path Request path.
	 * @return bool
	 */
	private static function page_matches( array $form, $path ) {
		switch ( $form['popup_where'] ) {
			case 'posts':
				return is_singular( 'post' );
			case 'pages':
				return is_page();
			case 'home':
				return is_front_page();
			case 'paths':
				return self::path_matches( $path, $form['popup_paths'] );
			default:
				return true;
		}
	}

	/**
	 * Whether a path matches any line of a path list ("/blog/*", "/about").
	 * Case and a trailing slash don't matter; * matches anything.
	 *
	 * @param string $path  Request path.
	 * @param string $lines One pattern per line.
	 * @return bool
	 */
	public static function path_matches( $path, $lines ) {
		$path = strtolower( untrailingslashit( $path ) );
		$path = '' === $path ? '/' : $path;

		foreach ( preg_split( '/\R/', (string) $lines, -1, PREG_SPLIT_NO_EMPTY ) as $pattern ) {
			$pattern = strtolower( untrailingslashit( trim( $pattern ) ) );
			$pattern = '' === $pattern ? '/' : $pattern;

			if ( false === strpos( $pattern, '*' ) ? $pattern === $path : self::glob( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Wildcard match where * stands for any run of characters. Done by hand
	 * rather than as a regex, so a visitor's long URL can't make a pattern
	 * backtrack for ages (at most pattern length x path length steps).
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Path.
	 * @return bool
	 */
	private static function glob( $pattern, $subject ) {
		$plen = strlen( $pattern );
		$slen = strlen( $subject );
		$p    = 0;
		$s    = 0;
		$star = -1;
		$mark = 0;

		while ( $s < $slen ) {
			if ( $p < $plen && '*' === $pattern[ $p ] ) {
				$star = $p++;
				$mark = $s;
			} elseif ( $p < $plen && $pattern[ $p ] === $subject[ $s ] ) {
				++$p;
				++$s;
			} elseif ( -1 !== $star ) {
				$p = $star + 1;
				$s = ++$mark;
			} else {
				return false;
			}
		}

		while ( $p < $plen && '*' === $pattern[ $p ] ) {
			++$p;
		}

		return $p === $plen;
	}

	/**
	 * The path of the current request ("/blog/my-post/").
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output.
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		// Real page paths are short; capping keeps wildcard matching quick.
		return is_string( $path ) && '' !== $path ? substr( rawurldecode( $path ), 0, 500 ) : '/';
	}

	/**
	 * Clean a list of paths: one per line, each starting with "/", full URLs
	 * cut down to their path, runs of * collapsed, at most five * per line.
	 *
	 * @param string $raw Raw text.
	 * @return string
	 */
	private static function paths( $raw ) {
		$out = array();

		foreach ( preg_split( '/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( preg_match( '#^https?://#i', $line ) ) {
				$line = (string) wp_parse_url( $line, PHP_URL_PATH );
			}

			$line = preg_replace( '/\*+/', '*', $line );
			$line = preg_replace( '/\s+/', '', $line );

			if ( '' === $line ) {
				continue;
			}
			if ( '/' !== $line[0] && '*' !== $line[0] ) {
				$line = '/' . $line;
			}
			if ( substr_count( $line, '*' ) > 5 ) {
				continue;
			}

			$out[] = self::cut( $line, 200 );
		}

		return implode( "\n", array_slice( array_values( array_unique( $out ) ), 0, 50 ) );
	}

	/**
	 * Clean a comma-separated tag list: at most 10 tags of 100 characters.
	 *
	 * @param string $raw Raw text.
	 * @return string[]
	 */
	private static function tags( $raw ) {
		$out = array();

		foreach ( explode( ',', $raw ) as $tag ) {
			$tag = self::cut( trim( sanitize_text_field( $tag ) ), 100 );
			if ( '' !== $tag && ! in_array( strtolower( $tag ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $tag;
			}
		}

		return array_slice( $out, 0, 10 );
	}

	/**
	 * A #rrggbb color (the default when it isn't one).
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function accent( $raw ) {
		$color = sanitize_hex_color( trim( $raw ) );

		if ( ! $color ) {
			return self::ACCENT;
		}

		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}

		return strtolower( $color );
	}

	/**
	 * Allow links and simple emphasis in the description, consent and note.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function kses( $html ) {
		return wp_kses(
			$html,
			array(
				'a'      => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
			)
		);
	}

	/**
	 * Cut a string to a number of characters, multibyte-safe.
	 *
	 * @param string $value Value.
	 * @param int    $max   Characters.
	 * @return string
	 */
	private static function cut( $value, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $max ) : substr( (string) $value, 0, $max );
	}

	/**
	 * The stored option, in a known shape.
	 *
	 * @return array{next:int,forms:array}
	 */
	private static function stored() {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'next'  => isset( $stored['next'] ) ? max( 1, (int) $stored['next'] ) : 1,
			'forms' => isset( $stored['forms'] ) && is_array( $stored['forms'] ) ? $stored['forms'] : array(),
		);
	}
}

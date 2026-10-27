<?php
/**
 * Main plugin orchestrator: owns the global settings and boots each part.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Easy_Mailchimp_Newsletter_Embed_Plugin {

	/**
	 * Option key holding the global settings (connection and spam protection).
	 */
	const OPTION = 'easy_mailchimp_newsletter_embed_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Easy_Mailchimp_Newsletter_Embed_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Easy_Mailchimp_Newsletter_Embed_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		if ( is_admin() ) {
			new Easy_Mailchimp_Newsletter_Embed_Admin( $this );
		}

		Easy_Mailchimp_Newsletter_Embed_Render::init();
		Easy_Mailchimp_Newsletter_Embed_Subscribe::init();

		add_action( 'admin_init', array( $this, 'privacy_policy_text' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'api_key'          => '',
			'turnstile'        => 0,
			'turnstile_site'   => '',
			'turnstile_secret' => '',
			'turnstile_look'   => 'auto',
			'rate_limit'       => 10,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Change some settings, keeping the rest.
	 *
	 * @param array<string,mixed> $changes Setting => value (already clean).
	 * @return void
	 */
	public function update_settings( array $changes ) {
		$settings = array_intersect_key( array_merge( $this->get_settings(), $changes ), self::defaults() );

		update_option( self::OPTION, $settings );
	}

	/**
	 * The Mailchimp API key, decrypted.
	 *
	 * @return string '' when there's none (or the site's salts changed).
	 */
	public function api_key() {
		return Easy_Mailchimp_Newsletter_Embed_Crypto::decrypt( (string) $this->get_setting( 'api_key' ) );
	}

	/**
	 * Whether a Mailchimp account is connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== Easy_Mailchimp_Newsletter_Embed_Mailchimp::data_center( $this->api_key() );
	}

	/**
	 * The Turnstile secret key, decrypted.
	 *
	 * @return string
	 */
	public function turnstile_secret() {
		return Easy_Mailchimp_Newsletter_Embed_Crypto::decrypt( (string) $this->get_setting( 'turnstile_secret' ) );
	}

	/**
	 * Whether forms have to pass Cloudflare Turnstile: switched on, with both keys.
	 *
	 * @return bool
	 */
	public function turnstile_on() {
		return (bool) $this->get_setting( 'turnstile' )
			&& '' !== (string) $this->get_setting( 'turnstile_site' )
			&& '' !== $this->turnstile_secret();
	}

	/**
	 * Suggested text for Settings > Privacy.
	 *
	 * @return void
	 */
	public function privacy_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'When you sign up for our newsletter, the email address and name you enter are sent to Mailchimp (Intuit Inc.), which stores our mailing list and sends our emails. Your IP address and the time you signed up are sent with them as a record of your consent. You can unsubscribe at any time with the link at the bottom of every email.', 'easy-mailchimp-newsletter-embed' ) . '</p>';

		if ( $this->turnstile_on() ) {
			$text .= '<p>' . esc_html__( 'Our signup forms use Cloudflare Turnstile to keep out spam bots. Turnstile runs in your browser and sends Cloudflare technical information about your browser and connection to check that you are a person.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
		}

		wp_add_privacy_policy_content( __( 'Easy Mailchimp Newsletter Embed', 'easy-mailchimp-newsletter-embed' ), wp_kses_post( $text ) );
	}
}

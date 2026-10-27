<?php
/**
 * Plugin Name:       Easy Mailchimp Newsletter Embed
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Mailchimp signup forms you can embed with a shortcode, block or PHP snippet, or show as a popup. Multiple forms, six themes and optional Cloudflare Turnstile.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easy-mailchimp-newsletter-embed
 * Update URI:        https://github.com/jmsprsns/easy-mailchimp-newsletter-embed
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$easy_mailchimp_newsletter_embed_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION', $easy_mailchimp_newsletter_embed_headers['version'] );
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_REPO', $easy_mailchimp_newsletter_embed_headers['repo'] );
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_FILE', __FILE__ );
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_URL', plugin_dir_url( __FILE__ ) );

unset( $easy_mailchimp_newsletter_embed_headers );

require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-icons.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-updater.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-menu.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-feedback.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-crypto.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-forms.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-mailchimp.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-turnstile.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-visitor.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-stats.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-render.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-subscribe.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-admin.php';
require_once EASY_MAILCHIMP_NEWSLETTER_EMBED_DIR . 'includes/class-easy-mailchimp-newsletter-embed-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Easy_Mailchimp_Newsletter_Embed_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Easy_Mailchimp_Newsletter_Embed_Plugin
 */
function easy_mailchimp_newsletter_embed_plugin() {
	return Easy_Mailchimp_Newsletter_Embed_Plugin::instance();
}
add_action( 'plugins_loaded', 'easy_mailchimp_newsletter_embed_plugin' );

if ( ! function_exists( 'easy_mailchimp_embed' ) ) {
	/**
	 * Print a signup form from a theme template:
	 * <?php if ( function_exists( 'easy_mailchimp_embed' ) ) { easy_mailchimp_embed( 2 ); } ?>
	 *
	 * @param int  $form_id Form ID from Content Powered > Easy Mailchimp Newsletter Embed.
	 * @param bool $display False to return the markup instead of printing it.
	 * @return string The markup ('' when the form doesn't exist).
	 */
	function easy_mailchimp_embed( $form_id, $display = true ) {
		$html = Easy_Mailchimp_Newsletter_Embed_Render::embed( (int) $form_id );

		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in the renderer.
		}

		return $html;
	}
}

<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Subscribers live in Mailchimp and aren't touched.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'easy_mailchimp_newsletter_embed_settings' );
delete_option( 'easy_mailchimp_newsletter_embed_forms' );
delete_option( 'easy_mailchimp_newsletter_embed_account' );
delete_option( 'easy_mailchimp_newsletter_embed_stats' );

// Rate-limit counters and admin notices (they'd expire on their own within an hour).
global $wpdb;
$easy_mailchimp_newsletter_embed_like = array(
	$wpdb->esc_like( '_transient_emne_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_emne_' ) . '%',
);
foreach ( $easy_mailchimp_newsletter_embed_like as $easy_mailchimp_newsletter_embed_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $easy_mailchimp_newsletter_embed_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup on uninstall.
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-easy-mailchimp-newsletter-embed' );

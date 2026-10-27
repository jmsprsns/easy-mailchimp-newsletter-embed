<?php
/**
 * Front end: the form markup (shortcode, block, PHP snippet, popup) and its assets.
 *
 * The markup holds nothing per visitor, so pages with a form can be cached.
 * Everything the script needs rides along in a data-emne attribute rather
 * than an inline script, so script optimizers that delay or move inline
 * scripts can't separate a form from its settings.
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form renderer.
 */
final class Easy_Mailchimp_Newsletter_Embed_Render {

	/**
	 * Shortcode tag: [easy_mailchimp_embed id="2"].
	 */
	const SHORTCODE = 'easy_mailchimp_embed';

	/**
	 * Block name.
	 */
	const BLOCK = 'easy-mailchimp-newsletter-embed/form';

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'emne-form';

	/**
	 * Forms printed so far on this page, so each gets unique IDs.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * IDs of forms already shown inline on this page.
	 *
	 * @var int[]
	 */
	private static $shown = array();

	/**
	 * The popup form for this page, worked out once.
	 *
	 * @var array|null|false False until worked out.
	 */
	private static $popup = false;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_popup' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_data' ) );
	}

	/**
	 * Register the assets and the block.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_style( self::HANDLE, EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/form.css', array(), EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION );
		wp_register_script( self::HANDLE, EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/form.js', array(), EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION, true );

		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'emne-block',
			EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
			EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION,
			true
		);

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'editor_script'   => 'emne-block',
				'editor_style'    => self::HANDLE,
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'formId'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * The forms list and labels the block editor needs.
	 *
	 * @return void
	 */
	public static function block_editor_data() {
		$forms = array();

		foreach ( Easy_Mailchimp_Newsletter_Embed_Forms::all() as $form ) {
			$forms[] = array(
				'id'   => (int) $form['id'],
				'name' => (string) $form['name'],
			);
		}

		$data = array(
			'forms'    => $forms,
			'adminUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . Easy_Mailchimp_Newsletter_Embed_Admin::PAGE ) : '',
			'i18n'     => array(
				'title'       => __( 'Mailchimp Signup Form', 'easy-mailchimp-newsletter-embed' ),
				'description' => __( 'A newsletter signup form from Easy Mailchimp Newsletter Embed.', 'easy-mailchimp-newsletter-embed' ),
				'form'        => __( 'Signup form', 'easy-mailchimp-newsletter-embed' ),
				'choose'      => __( 'Choose a form', 'easy-mailchimp-newsletter-embed' ),
				'pick'        => __( 'Pick which signup form to show here.', 'easy-mailchimp-newsletter-embed' ),
				'none'        => __( 'There are no signup forms yet. Create one under Content Powered > Easy Mailchimp Newsletter Embed.', 'easy-mailchimp-newsletter-embed' ),
				'create'      => __( 'Create a form', 'easy-mailchimp-newsletter-embed' ),
				'edit'        => __( 'Edit this form', 'easy-mailchimp-newsletter-embed' ),
			),
		);

		wp_add_inline_script( 'emne-block', 'window.emneBlock = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Load the styles in <head> when it's already clear a form will be on
	 * the page (a popup, or a shortcode or block in the post), so the form
	 * never flashes unstyled. Other forms load them when they're printed.
	 *
	 * @return void
	 */
	public static function enqueue_early() {
		$post = is_singular() ? get_post() : null;

		if ( self::popup_form() || ( $post && ( has_shortcode( $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post ) ) ) ) {
			self::enqueue();
		}
	}

	/**
	 * Queue the form's style and script.
	 *
	 * @return void
	 */
	public static function enqueue() {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * [easy_mailchimp_embed id="2"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, self::SHORTCODE );

		return self::embed( absint( $atts['id'] ) );
	}

	/**
	 * The block's output.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$id    = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;
		$extra = isset( $attributes['className'] ) && is_string( $attributes['className'] ) ? $attributes['className'] : '';

		return self::embed( $id, $extra );
	}

	/**
	 * An inline form by ID.
	 *
	 * @param int    $id      Form ID.
	 * @param string $classes Extra classes for the wrapper.
	 * @return string '' when the form can't be shown (admins see why instead).
	 */
	public static function embed( $id, $classes = '' ) {
		$form = Easy_Mailchimp_Newsletter_Embed_Forms::get( $id );

		if ( ! $form ) {
			/* translators: %d: form ID. */
			return self::admin_note( sprintf( __( 'Signup form #%d doesn\'t exist. Check the ID under Content Powered > Easy Mailchimp Newsletter Embed.', 'easy-mailchimp-newsletter-embed' ), $id ) );
		}

		if ( '' === $form['list_id'] || ! Easy_Mailchimp_Newsletter_Embed_Plugin::instance()->is_connected() ) {
			/* translators: %s: form name. */
			return self::admin_note( sprintf( __( 'The signup form "%s" isn\'t connected to a Mailchimp audience yet, so visitors don\'t see it.', 'easy-mailchimp-newsletter-embed' ), $form['name'] ) );
		}

		self::enqueue();
		self::$shown[] = (int) $form['id'];

		return self::form( $form, 'embed', $classes );
	}

	/**
	 * A note only admins see where a form can't be shown.
	 *
	 * @param string $text Note.
	 * @return string
	 */
	private static function admin_note( $text ) {
		if ( ! current_user_can( 'manage_options' ) || is_feed() ) {
			return '';
		}

		return '<p class="emne-admin-note" style="padding:10px 14px;border:1px dashed #d63638;border-radius:6px;color:#8a2424;background:#fcf0f1;font-size:14px">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The popup form for this page.
	 *
	 * @return array|null
	 */
	private static function popup_form() {
		if ( false === self::$popup ) {
			self::$popup = Easy_Mailchimp_Newsletter_Embed_Plugin::instance()->is_connected() && ! self::builder_preview()
				? Easy_Mailchimp_Newsletter_Embed_Forms::popup_for_request()
				: null;
		}

		return self::$popup;
	}

	/**
	 * Whether an editor is looking at a page builder's preview, where a popup
	 * would get in the way. Only believed for people who can edit posts, so a
	 * query arg can't strip the popup from a cached page.
	 *
	 * @return bool
	 */
	private static function builder_preview() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		foreach ( array( 'elementor-preview', 'fl_builder', 'et_fb', 'bricks', 'ct_builder', 'vc_editable', 'tve', 'brizy-edit-iframe' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks for presence.
				return true;
			}
		}

		return false;
	}

	/**
	 * Print this page's popup at the end of the page. Skipped when the same
	 * form is already on the page.
	 *
	 * @return void
	 */
	public static function print_popup() {
		$form = self::popup_form();

		if ( ! $form || in_array( (int) $form['id'], self::$shown, true ) ) {
			return;
		}

		self::enqueue();

		echo self::popup( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * A popup around a form.
	 *
	 * @param array $form Form.
	 * @return string
	 */
	public static function popup( array $form ) {
		$corner = 'corner' === $form['popup_position'];
		$config = array(
			'id'      => (int) $form['id'],
			'trigger' => $form['popup_trigger'],
			'delay'   => (int) $form['popup_delay'],
			'scroll'  => (int) $form['popup_scroll'],
			'days'    => (int) $form['popup_days'],
			'devices' => $form['popup_devices'],
			'modal'   => ! $corner,
		);
		$inner  = self::form( $form, 'popup' );
		$label  = '' !== $form['heading'] ? $form['heading'] : $form['name'];

		$classes = array(
			'emne-popup',
			'emne-popup-' . ( $corner ? 'corner' : 'center' ),
			'emne-popup-theme-' . $form['theme'],
			'inline' === $form['layout'] ? 'emne-popup-wide' : '',
		);

		$html  = '<div class="' . esc_attr( implode( ' ', array_filter( $classes ) ) ) . '" id="emne-popup-' . (int) $form['id'] . '" style="' . esc_attr( self::style_vars( $form['accent'] ) ) . '" data-emne-popup="' . esc_attr( wp_json_encode( $config ) ) . '" hidden>';
		$html .= $corner ? '' : '<div class="emne-popup-backdrop" data-emne-close></div>';
		$html .= '<div class="emne-popup-dialog" role="dialog"' . ( $corner ? '' : ' aria-modal="true"' ) . ' aria-label="' . esc_attr( $label ) . '" tabindex="-1">';
		$html .= '<button type="button" class="emne-popup-close" data-emne-close aria-label="' . esc_attr__( 'Close', 'easy-mailchimp-newsletter-embed' ) . '">';
		$html .= '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
		$html .= '</button>' . $inner . '</div></div>';

		return $html;
	}

	/**
	 * The form itself.
	 *
	 * In the admin preview it's drawn with a <div> instead of a <form> and
	 * without names or "required", so it can sit inside the settings form
	 * without being submitted or blocking the Save button.
	 *
	 * @param array  $form    Form.
	 * @param string $context embed, popup or preview.
	 * @param string $extra   Extra wrapper classes.
	 * @return string
	 */
	public static function form( array $form, $context, $extra = '' ) {
		++self::$count;

		$plugin  = Easy_Mailchimp_Newsletter_Embed_Plugin::instance();
		$preview = 'preview' === $context;
		$uid     = 'emne-' . (int) $form['id'] . '-' . self::$count;
		$classes = array(
			'emne',
			'emne-theme-' . $form['theme'],
			'plain' === $form['theme'] ? '' : 'emne-styled',
			'emne-layout-' . $form['layout'],
			'emne-align-' . $form['align'],
			$form['show_labels'] ? 'emne-labels' : 'emne-no-labels',
			'emne-in-' . $context,
		);

		foreach ( preg_split( '/\s+/', (string) $extra, -1, PREG_SPLIT_NO_EMPTY ) as $name ) {
			$classes[] = sanitize_html_class( $name );
		}

		$attrs = ' id="' . esc_attr( $uid ) . '" class="' . esc_attr( implode( ' ', array_filter( $classes ) ) ) . '" style="' . esc_attr( self::style_vars( $form['accent'] ) ) . '"';

		if ( ! $preview ) {
			$config = array(
				'id'       => (int) $form['id'],
				'url'      => admin_url( 'admin-ajax.php', 'relative' ),
				'redirect' => (string) $form['redirect'],
				'msg'      => array(
					'email'   => __( 'Please enter a valid email address.', 'easy-mailchimp-newsletter-embed' ),
					'name'    => __( 'Please enter your name.', 'easy-mailchimp-newsletter-embed' ),
					'consent' => __( 'Please tick the box to agree before subscribing.', 'easy-mailchimp-newsletter-embed' ),
					'check'   => __( 'Please complete the spam check and try again.', 'easy-mailchimp-newsletter-embed' ),
					'error'   => __( 'Sorry, something went wrong. Please try again in a moment.', 'easy-mailchimp-newsletter-embed' ),
					'sending' => __( 'Subscribing…', 'easy-mailchimp-newsletter-embed' ),
				),
			);

			if ( $plugin->turnstile_on() ) {
				$config['ts'] = array(
					'key'    => (string) $plugin->get_setting( 'turnstile_site' ),
					'src'    => Easy_Mailchimp_Newsletter_Embed_Turnstile::SCRIPT_URL,
					'look'   => 'always' === $plugin->get_setting( 'turnstile_look' ) ? 'always' : 'interaction-only',
					'theme'  => 'dark' === $form['theme'] ? 'dark' : 'light',
					'action' => Easy_Mailchimp_Newsletter_Embed_Turnstile::ACTION,
				);
			}

			$attrs .= ' data-emne="' . esc_attr( wp_json_encode( $config ) ) . '"';
		}

		$tag  = $preview ? 'div' : 'form';
		$html = '<div' . $attrs . '>';

		if ( $preview ) {
			$html .= '<div class="emne-form">';
		} else {
			$html .= '<form class="emne-form" method="post" action="' . esc_url( admin_url( 'admin-ajax.php', 'relative' ) ) . '" novalidate>';
		}

		if ( '' !== $form['heading'] ) {
			$html .= '<p class="emne-heading">' . esc_html( $form['heading'] ) . '</p>';
		}
		if ( '' !== $form['description'] ) {
			$html .= '<p class="emne-desc">' . Easy_Mailchimp_Newsletter_Embed_Forms::kses( $form['description'] ) . '</p>';
		}

		$html .= '<div class="emne-fields">';

		if ( 'none' !== $form['name_field'] ) {
			$html .= self::field( $form, $uid, 'fname', $form['name_label'], 'full' === $form['name_field'] ? 'name' : 'given-name', $preview );
			if ( 'first_last' === $form['name_field'] ) {
				$html .= self::field( $form, $uid, 'lname', $form['last_label'], 'family-name', $preview );
			}
		}

		$html .= self::field( $form, $uid, 'email', $form['email_label'], 'email', $preview );
		$html .= '<div class="emne-field emne-field-submit"><button class="emne-button" type="' . ( $preview ? 'button' : 'submit' ) . '">' . esc_html( $form['button'] ) . '</button></div>';
		$html .= '</div>';

		if ( '' !== $form['consent'] ) {
			$html .= '<label class="emne-consent"><input type="checkbox"' . ( $preview ? '' : ' name="consent" value="1" required' ) . '> <span>' . Easy_Mailchimp_Newsletter_Embed_Forms::kses( $form['consent'] ) . '</span></label>';
		}

		if ( ! $preview ) {
			// Turnstile goes here when it's on; a hidden field only bots fill in.
			$html .= '<div class="emne-ts"></div>';
			$html .= '<div class="emne-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'easy-mailchimp-newsletter-embed' ) . ' <input type="text" name="emne_xf" value="" tabindex="-1" autocomplete="off"></label></div>';
		}

		$html .= '<p class="emne-message" role="status" aria-live="polite"></p>';

		if ( '' !== $form['note'] ) {
			$html .= '<p class="emne-note">' . Easy_Mailchimp_Newsletter_Embed_Forms::kses( $form['note'] ) . '</p>';
		}

		if ( ! $preview ) {
			$html .= '<noscript><p class="emne-message is-error">' . esc_html__( 'Please turn on JavaScript to subscribe.', 'easy-mailchimp-newsletter-embed' ) . '</p></noscript>';
		}

		$html .= '</' . $tag . '></div>';

		return $html;
	}

	/**
	 * One labelled input.
	 *
	 * @param array  $form         Form.
	 * @param string $uid          Form instance ID.
	 * @param string $name         Field name: fname, lname or email.
	 * @param string $label        Label.
	 * @param string $autocomplete Autocomplete token.
	 * @param bool   $preview      Admin preview.
	 * @return string
	 */
	private static function field( array $form, $uid, $name, $label, $autocomplete, $preview ) {
		$email    = 'email' === $name;
		$required = $email || $form['name_required'];
		$id       = $uid . '-' . $name;

		if ( $form['show_labels'] ) {
			$placeholder = $email ? __( 'you@example.com', 'easy-mailchimp-newsletter-embed' ) : '';
		} else {
			$placeholder = $label;
		}

		$input  = '<input class="emne-input" id="' . esc_attr( $id ) . '" type="' . ( $email ? 'email' : 'text' ) . '"';
		$input .= $preview ? '' : ' name="' . esc_attr( $name ) . '"';
		$input .= ' autocomplete="' . esc_attr( $autocomplete ) . '" maxlength="' . ( $email ? 254 : 50 ) . '"';
		$input .= $email ? ' inputmode="email" spellcheck="false" autocapitalize="off"' : '';
		$input .= '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
		$input .= $required && ! $preview ? ' required' : '';
		$input .= '>';

		return '<div class="emne-field emne-field-' . esc_attr( $name ) . '"><label class="emne-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . $input . '</div>';
	}

	/**
	 * CSS custom properties for a form's accent color.
	 *
	 * @param string $accent #rrggbb.
	 * @return string
	 */
	public static function style_vars( $accent ) {
		$accent = preg_match( '/^#[0-9a-f]{6}$/D', (string) $accent ) ? $accent : Easy_Mailchimp_Newsletter_Embed_Forms::ACCENT;
		$rgb    = array_map( 'hexdec', str_split( substr( $accent, 1 ), 2 ) );
		$light  = ( $rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114 ) / 1000 >= 165;
		$soft   = vsprintf(
			'#%02x%02x%02x',
			array_map(
				function ( $c ) {
					return (int) round( $c * 0.12 + 255 * 0.88 );
				},
				$rgb
			)
		);

		return '--emne-accent:' . $accent
			. ';--emne-on-accent:' . ( $light ? '#111827' : '#ffffff' )
			. ';--emne-soft:' . $soft
			. ';--emne-ring:rgba(' . implode( ',', $rgb ) . ',.28)'
			. ';--emne-bold-btn:' . ( $light ? '#111827' : '#ffffff' )
			. ';--emne-bold-btn-text:' . ( $light ? '#ffffff' : $accent );
	}
}

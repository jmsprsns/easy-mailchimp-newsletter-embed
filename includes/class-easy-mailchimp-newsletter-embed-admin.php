<?php
/**
 * Admin side: the Content Powered > Easy Mailchimp Newsletter Embed page
 * (signup forms, the form editor, the Mailchimp connection, spam protection
 * and help) and the Plugins-screen link.
 *
 * Every change goes through admin-post.php with its own nonce and the
 * manage_options check, then redirects back with a notice kept in a per-user
 * transient (never in the URL, where its text could be spoofed).
 *
 * @package Easy_Mailchimp_Newsletter_Embed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Easy_Mailchimp_Newsletter_Embed_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'easy-mailchimp-newsletter-embed';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'mail-plus';

	/**
	 * Tabs: id => icon.
	 */
	const TABS = array(
		'forms'     => 'layout-template',
		'mailchimp' => 'simpleicons/mailchimp',
		'spam'      => 'shield-check',
		'help'      => 'life-buoy',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'emne_admin';

	/**
	 * Capability for everything here.
	 */
	const CAP = 'manage_options';

	/**
	 * Plugin instance.
	 *
	 * @var Easy_Mailchimp_Newsletter_Embed_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Easy_Mailchimp_Newsletter_Embed_Plugin $plugin Plugin instance.
	 */
	public function __construct( Easy_Mailchimp_Newsletter_Embed_Plugin $plugin ) {
		$this->plugin = $plugin;

		Easy_Mailchimp_Newsletter_Embed_Menu::init();
		Easy_Mailchimp_Newsletter_Embed_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		foreach ( array( 'save_form', 'delete_form', 'duplicate_form', 'connect', 'disconnect', 'refresh', 'save_spam', 'clear_errors' ) as $action ) {
			add_action( 'admin_post_emne_' . $action, array( $this, 'handle_' . $action ) );
		}

		add_action( 'wp_ajax_emne_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_emne_test_turnstile', array( $this, 'ajax_test_turnstile' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( EASY_MAILCHIMP_NEWSLETTER_EMBED_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html__( 'Settings', 'easy-mailchimp-newsletter-embed' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Easy_Mailchimp_Newsletter_Embed_Icons::get( self::ICON, 17, 'easy-mailchimp-newsletter-embed-menu-icon' );
		$menu_title = $icon . '<span class="easy-mailchimp-newsletter-embed-menu-label">' . esc_html__( 'Easy Mailchimp Newsletter Embed', 'easy-mailchimp-newsletter-embed' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Easy_Mailchimp_Newsletter_Embed_Menu::SLUG,
			__( 'Easy Mailchimp Newsletter Embed', 'easy-mailchimp-newsletter-embed' ),
			$menu_title,
			self::CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' emne-settings-page' : $classes;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="easy-mailchimp-newsletter-embed-menu-icon-style">'
			. '#adminmenu a:has(>.easy-mailchimp-newsletter-embed-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .easy-mailchimp-newsletter-embed-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .easy-mailchimp-newsletter-embed-menu-icon,#adminmenu a:hover .easy-mailchimp-newsletter-embed-menu-icon,#adminmenu a:focus .easy-mailchimp-newsletter-embed-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts (and the form themes, for the preview).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'emne-admin', EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/admin.css', array(), EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION );
		wp_enqueue_style( 'emne-form-preview', EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/form.css', array(), EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION );
		wp_enqueue_script( 'emne-admin', EASY_MAILCHIMP_NEWSLETTER_EMBED_URL . 'assets/admin.js', array(), EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION, true );

		wp_localize_script(
			'emne-admin',
			'emne_admin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'turnstileSrc' => Easy_Mailchimp_Newsletter_Embed_Turnstile::SCRIPT_URL,
				'i18n'         => array(
					'copied'        => __( 'Copied', 'easy-mailchimp-newsletter-embed' ),
					'copyFailed'    => __( 'Press Ctrl+C to copy', 'easy-mailchimp-newsletter-embed' ),
					'confirmDelete' => __( 'Delete this signup form? Pages that show it will show nothing instead. This can\'t be undone.', 'easy-mailchimp-newsletter-embed' ),
					'confirmDisc'   => __( 'Disconnect Mailchimp? Every signup form stops showing until you connect again.', 'easy-mailchimp-newsletter-embed' ),
					'unsaved'       => __( 'You have unsaved changes.', 'easy-mailchimp-newsletter-embed' ),
					'testing'       => __( 'Loading the Turnstile widget…', 'easy-mailchimp-newsletter-embed' ),
					'solve'         => __( 'Complete the widget above, if it asks.', 'easy-mailchimp-newsletter-embed' ),
					'checking'      => __( 'Checking the token with Cloudflare…', 'easy-mailchimp-newsletter-embed' ),
					'noSiteKey'     => __( 'Enter the site key first.', 'easy-mailchimp-newsletter-embed' ),
					'widgetError'   => __( 'The widget couldn\'t start. Check the site key, and that this domain is in the widget\'s hostname list in Cloudflare.', 'easy-mailchimp-newsletter-embed' ),
					'scriptError'   => __( 'Couldn\'t load Turnstile from Cloudflare. A browser extension may be blocking it.', 'easy-mailchimp-newsletter-embed' ),
					'failed'        => __( 'The test couldn\'t finish. Please try again.', 'easy-mailchimp-newsletter-embed' ),
				),
			)
		);
	}

	/**
	 * Link to the page (or one of its tabs).
	 *
	 * @param array $args Extra query args, e.g. tab and edit.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Easy_Mailchimp_Newsletter_Embed_Icons::get( $name, $size );
	}

	/**
	 * The tab being shown.
	 *
	 * @return string
	 */
	private function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'forms';
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'forms'     => __( 'Signup Forms', 'easy-mailchimp-newsletter-embed' ),
			'mailchimp' => __( 'Mailchimp', 'easy-mailchimp-newsletter-embed' ),
			'spam'      => __( 'Spam Protection', 'easy-mailchimp-newsletter-embed' ),
			'help'      => __( 'Help', 'easy-mailchimp-newsletter-embed' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}


	/**
	 * Queue a notice for the next page load (this user only).
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Text.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		$key     = 'emne_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array( $type, $message );
		set_transient( $key, array_slice( $notices, -5 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and forget the queued notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$key     = 'emne_notices_' . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
				continue;
			}
			$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $notice[1] ) );
		}
	}

	/**
	 * Stop unless the user may manage the plugin and the nonce is right.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'easy-mailchimp-newsletter-embed' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Go back to the page.
	 *
	 * @param array $args Query args.
	 * @return void
	 */
	private static function back( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}


	/**
	 * Save a form from the editor.
	 *
	 * @return void
	 */
	public function handle_save_form() {
		self::guard( 'emne_save_form' );
		// phpcs:disable WordPress.Security.NonceVerification -- Checked in guard().

		$id   = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$base = $id ? Easy_Mailchimp_Newsletter_Embed_Forms::get( $id ) : Easy_Mailchimp_Newsletter_Embed_Forms::defaults();

		if ( ! $base ) {
			self::notice( 'error', __( 'That signup form no longer exists.', 'easy-mailchimp-newsletter-embed' ) );
			self::back( array( 'tab' => 'forms' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned field by field in Forms::sanitize().
		$input = isset( $_POST['emne'] ) && is_array( $_POST['emne'] ) ? wp_unslash( $_POST['emne'] ) : array();
		$form  = Easy_Mailchimp_Newsletter_Embed_Forms::sanitize( $input, $base );
		$saved = Easy_Mailchimp_Newsletter_Embed_Forms::save( $form );

		if ( ! $saved ) {
			/* translators: %d: most forms allowed. */
			self::notice( 'error', sprintf( __( 'A site can have up to %d signup forms. Delete one to add another.', 'easy-mailchimp-newsletter-embed' ), Easy_Mailchimp_Newsletter_Embed_Forms::MAX_FORMS ) );
			self::back( array( 'tab' => 'forms' ) );
		}

		self::notice( 'success', $id ? __( 'Signup form saved.', 'easy-mailchimp-newsletter-embed' ) : __( 'Signup form created. Add it to your site with the shortcode, the block or as a popup.', 'easy-mailchimp-newsletter-embed' ) );

		if ( '' === $form['list_id'] ) {
			self::notice( 'warning', __( 'Pick a Mailchimp audience for this form. Until it has one, visitors don\'t see it.', 'easy-mailchimp-newsletter-embed' ) );
		}

		if ( $form['popup'] ) {
			foreach ( Easy_Mailchimp_Newsletter_Embed_Forms::all() as $other ) {
				if ( $other['popup'] && $other['id'] < $saved ) {
					/* translators: %s: name of the other form. */
					self::notice( 'info', sprintf( __( '"%s" is also a popup and is older, so it wins on pages where both would show.', 'easy-mailchimp-newsletter-embed' ), $other['name'] ) );
					break;
				}
			}
		}

		// phpcs:enable
		self::back(
			array(
				'tab'  => 'forms',
				'edit' => $saved,
			)
		);
	}

	/**
	 * Delete a form.
	 *
	 * @return void
	 */
	public function handle_delete_form() {
		self::guard( 'emne_delete_form' );

		$id   = isset( $_REQUEST['form'] ) ? absint( wp_unslash( $_REQUEST['form'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		$form = Easy_Mailchimp_Newsletter_Embed_Forms::get( $id );

		if ( $form && Easy_Mailchimp_Newsletter_Embed_Forms::delete( $id ) ) {
			/* translators: %s: form name. */
			self::notice( 'success', sprintf( __( 'Deleted "%s".', 'easy-mailchimp-newsletter-embed' ), $form['name'] ) );
		}

		self::back( array( 'tab' => 'forms' ) );
	}

	/**
	 * Copy a form.
	 *
	 * @return void
	 */
	public function handle_duplicate_form() {
		self::guard( 'emne_duplicate_form' );

		$id     = isset( $_REQUEST['form'] ) ? absint( wp_unslash( $_REQUEST['form'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		$new_id = Easy_Mailchimp_Newsletter_Embed_Forms::duplicate( $id );

		if ( ! $new_id ) {
			self::notice( 'error', __( 'That form couldn\'t be copied.', 'easy-mailchimp-newsletter-embed' ) );
			self::back( array( 'tab' => 'forms' ) );
		}

		self::notice( 'success', __( 'Form copied. The copy\'s popup is switched off.', 'easy-mailchimp-newsletter-embed' ) );
		self::back(
			array(
				'tab'  => 'forms',
				'edit' => $new_id,
			)
		);
	}

	/**
	 * Connect a Mailchimp account with an API key.
	 *
	 * @return void
	 */
	public function handle_connect() {
		self::guard( 'emne_connect' );

		$key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().

		if ( '' === Easy_Mailchimp_Newsletter_Embed_Mailchimp::data_center( $key ) ) {
			self::notice( 'error', __( 'That doesn\'t look like a Mailchimp API key. It\'s 32 letters and numbers, then a dash and a data center such as us21.', 'easy-mailchimp-newsletter-embed' ) );
			self::back( array( 'tab' => 'mailchimp' ) );
		}

		$account = Easy_Mailchimp_Newsletter_Embed_Mailchimp::fetch_account( $key );

		if ( is_wp_error( $account ) ) {
			/* translators: %s: error from Mailchimp. */
			self::notice( 'error', sprintf( __( 'Couldn\'t connect: %s', 'easy-mailchimp-newsletter-embed' ), $account->get_error_message() ) );
			self::back( array( 'tab' => 'mailchimp' ) );
		}

		$stored = Easy_Mailchimp_Newsletter_Embed_Crypto::encrypt( $key );
		if ( '' === $stored ) {
			self::notice( 'error', __( 'The key couldn\'t be stored securely on this server (libsodium is missing), so it wasn\'t saved.', 'easy-mailchimp-newsletter-embed' ) );
			self::back( array( 'tab' => 'mailchimp' ) );
		}

		$this->plugin->update_settings( array( 'api_key' => $stored ) );
		Easy_Mailchimp_Newsletter_Embed_Mailchimp::save_account( $account );

		self::notice(
			'success',
			sprintf(
				/* translators: 1: Mailchimp account name, 2: number of audiences. */
				_n( 'Connected to %1$s. Found %2$d audience.', 'Connected to %1$s. Found %2$d audiences.', count( $account['lists'] ), 'easy-mailchimp-newsletter-embed' ),
				'' !== $account['name'] ? $account['name'] : __( 'Mailchimp', 'easy-mailchimp-newsletter-embed' ),
				count( $account['lists'] )
			)
		);

		self::back( array( 'tab' => Easy_Mailchimp_Newsletter_Embed_Forms::all() ? 'mailchimp' : 'forms' ) );
	}

	/**
	 * Forget the API key and account.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		self::guard( 'emne_disconnect' );

		$this->plugin->update_settings( array( 'api_key' => '' ) );
		Easy_Mailchimp_Newsletter_Embed_Mailchimp::save_account( array() );

		self::notice( 'success', __( 'Mailchimp is disconnected and the API key is deleted from this site. Signup forms are hidden until you connect again.', 'easy-mailchimp-newsletter-embed' ) );
		self::back( array( 'tab' => 'mailchimp' ) );
	}

	/**
	 * Reload the audiences.
	 *
	 * @return void
	 */
	public function handle_refresh() {
		self::guard( 'emne_refresh' );

		$account = Easy_Mailchimp_Newsletter_Embed_Mailchimp::fetch_account( $this->plugin->api_key() );

		if ( is_wp_error( $account ) ) {
			/* translators: %s: error from Mailchimp. */
			self::notice( 'error', sprintf( __( 'Couldn\'t refresh: %s', 'easy-mailchimp-newsletter-embed' ), $account->get_error_message() ) );
		} else {
			Easy_Mailchimp_Newsletter_Embed_Mailchimp::save_account( $account );
			/* translators: %d: number of audiences. */
			self::notice( 'success', sprintf( _n( 'Audiences refreshed: %d found.', 'Audiences refreshed: %d found.', count( $account['lists'] ), 'easy-mailchimp-newsletter-embed' ), count( $account['lists'] ) ) );
		}

		self::back( array( 'tab' => 'mailchimp' ) );
	}

	/**
	 * Save the spam protection settings.
	 *
	 * @return void
	 */
	public function handle_save_spam() {
		self::guard( 'emne_save_spam' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Checked in guard(); each value is validated below.
		$limit  = isset( $_POST['rate_limit'] ) && is_numeric( $_POST['rate_limit'] ) ? (int) $_POST['rate_limit'] : 10;
		$site   = isset( $_POST['turnstile_site'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['turnstile_site'] ) ) ) : '';
		$secret = isset( $_POST['turnstile_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['turnstile_secret'] ) ) ) : '';
		$look   = isset( $_POST['turnstile_look'] ) && 'always' === $_POST['turnstile_look'] ? 'always' : 'auto';
		$on     = empty( $_POST['turnstile'] ) ? 0 : 1;
		$forget = ! empty( $_POST['turnstile_forget'] );
		// phpcs:enable

		$changes = array(
			'rate_limit'     => max( 0, min( 100, $limit ) ),
			'turnstile'      => $on,
			'turnstile_look' => $look,
		);

		if ( '' === $site || Easy_Mailchimp_Newsletter_Embed_Turnstile::valid_key( $site ) ) {
			$changes['turnstile_site'] = $site;
		} else {
			self::notice( 'error', __( 'That Turnstile site key doesn\'t look right, so it wasn\'t saved. Copy it from the widget\'s page in Cloudflare.', 'easy-mailchimp-newsletter-embed' ) );
		}

		if ( '' !== $secret ) {
			if ( ! Easy_Mailchimp_Newsletter_Embed_Turnstile::valid_key( $secret ) ) {
				self::notice( 'error', __( 'That Turnstile secret key doesn\'t look right, so it wasn\'t saved.', 'easy-mailchimp-newsletter-embed' ) );
			} else {
				$test = Easy_Mailchimp_Newsletter_Embed_Turnstile::test_secret( $secret );
				if ( 'bad_secret' === $test ) {
					self::notice( 'error', __( 'Cloudflare doesn\'t recognise that secret key, so it wasn\'t saved.', 'easy-mailchimp-newsletter-embed' ) );
				} else {
					$changes['turnstile_secret'] = Easy_Mailchimp_Newsletter_Embed_Crypto::encrypt( $secret );
					if ( 'unreachable' === $test ) {
						self::notice( 'warning', __( 'The secret key was saved, but this server couldn\'t reach Cloudflare to check it. If that keeps happening, signups will fail while Turnstile is on.', 'easy-mailchimp-newsletter-embed' ) );
					}
				}
			}
		} elseif ( $forget ) {
			$changes['turnstile_secret'] = '';
		}

		$this->plugin->update_settings( $changes );

		if ( $changes['turnstile'] && ! $this->plugin->turnstile_on() ) {
			self::notice( 'warning', __( 'Turnstile is switched on but needs both keys before forms use it. Until then, forms work without it.', 'easy-mailchimp-newsletter-embed' ) );
		}

		self::notice( 'success', __( 'Spam protection settings saved.', 'easy-mailchimp-newsletter-embed' ) );
		self::back( array( 'tab' => 'spam' ) );
	}

	/**
	 * Forget the recent errors.
	 *
	 * @return void
	 */
	public function handle_clear_errors() {
		self::guard( 'emne_clear_errors' );

		Easy_Mailchimp_Newsletter_Embed_Stats::clear_errors();
		self::back( array( 'tab' => 'mailchimp' ) );
	}

	/**
	 * Draw the editor's preview from its current (unsaved) values.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}

		$id   = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$base = $id ? Easy_Mailchimp_Newsletter_Embed_Forms::get( $id ) : null;
		$base = $base ? $base : Easy_Mailchimp_Newsletter_Embed_Forms::defaults();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned field by field in Forms::sanitize().
		$input = isset( $_POST['emne'] ) && is_array( $_POST['emne'] ) ? wp_unslash( $_POST['emne'] ) : array();
		$form  = Easy_Mailchimp_Newsletter_Embed_Forms::sanitize( $input, $base );

		wp_send_json_success( array( 'html' => Easy_Mailchimp_Newsletter_Embed_Render::form( $form, 'preview' ) ) );
	}

	/**
	 * Check a token from the Turnstile widget on the Spam Protection tab
	 * against the secret key (the one typed in, or the saved one).
	 *
	 * @return void
	 */
	public function ajax_test_turnstile() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against Turnstile's key pattern / sent only to Cloudflare.
		$secret = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
		$token  = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		// phpcs:enable

		if ( '' === $secret ) {
			$secret = $this->plugin->turnstile_secret();
		}

		if ( ! Easy_Mailchimp_Newsletter_Embed_Turnstile::valid_key( $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the secret key too (or save one first).', 'easy-mailchimp-newsletter-embed' ) ) );
		}

		$check = Easy_Mailchimp_Newsletter_Embed_Turnstile::verify( $secret, $token );

		if ( $check['ok'] ) {
			wp_send_json_success( array( 'message' => __( 'Both keys work: Cloudflare accepted the widget\'s token with this secret key.', 'easy-mailchimp-newsletter-embed' ) ) );
		}

		if ( ! $check['reached'] ) {
			$message = __( 'This server couldn\'t reach Cloudflare to check the token. Signups would fail with Turnstile on until it can.', 'easy-mailchimp-newsletter-embed' );
		} elseif ( in_array( 'invalid-input-secret', $check['codes'], true ) ) {
			$message = __( 'Cloudflare doesn\'t recognise the secret key.', 'easy-mailchimp-newsletter-embed' );
		} elseif ( in_array( 'timeout-or-duplicate', $check['codes'], true ) ) {
			$message = __( 'That token expired. Run the test again.', 'easy-mailchimp-newsletter-embed' );
		} else {
			$message = __( 'Cloudflare rejected the token. The site key and secret key probably belong to different widgets.', 'easy-mailchimp-newsletter-embed' );
		}

		wp_send_json_error( array( 'message' => $message . ' (' . implode( ', ', $check['codes'] ) . ')' ) );
	}


	/**
	 * Render the page: a header with the tabs, then WordPress's two-column
	 * box layout (content on the left, status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tab  = $this->tab();
		$edit = 'forms' === $tab && isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		?>
		<div class="emne-header">
			<div class="emne-header-title">
				<span class="emne-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="emne-version"><?php echo esc_html( 'v' . EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION ); ?></span>
			</div>
			<nav class="emne-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'easy-mailchimp-newsletter-embed' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $id ) ) ); ?>" class="emne-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap emne-wrap">
			<hr class="wp-header-end">
			<?php
			$this->render_notices();

			if ( '' !== $edit ) {
				$this->render_editor( $edit );
			} else {
				$this->render_tab( $tab );
			}
			?>
		</div>
		<?php
	}

	/**
	 * One of the tabs (not the editor), in the two-column layout.
	 *
	 * @param string $tab Tab ID.
	 * @return void
	 */
	private function render_tab( $tab ) {
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<?php
					switch ( $tab ) {
						case 'mailchimp':
							$this->render_connection();
							$this->render_audiences();
							$this->render_errors();
							break;
						case 'spam':
							$this->render_spam();
							break;
						case 'help':
							$this->render_help();
							break;
						default:
							$this->render_forms();
					}
					?>
				</div>
				<div id="postbox-container-1" class="postbox-container">
					<?php
					if ( 'spam' === $tab ) {
						$this->render_spam_save_box();
					}
					$this->render_status_box();
					$this->render_updates_box();
					?>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Open a box.
	 *
	 * @param string $icon  Icon.
	 * @param string $title Title.
	 * @param string $extra_class Extra class.
	 * @param string $extra       HTML for the right side of the header (already escaped).
	 * @return void
	 */
	private static function box_start( $icon, $title, $extra_class = '', $extra = '' ) {
		?>
		<div class="postbox emne-box <?php echo esc_attr( $extra_class ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php echo esc_html( $title ); ?></span>
				</h2>
				<?php
				if ( '' !== $extra ) {
					echo '<div class="emne-box-actions">' . $extra . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
				}
				?>
			</div>
			<div class="inside">
		<?php
	}

	/**
	 * Close a box.
	 *
	 * @return void
	 */
	private static function box_end() {
		echo '</div></div>';
	}

	/**
	 * A callout line.
	 *
	 * @param string $icon      Icon.
	 * @param string $html      Text (already escaped).
	 * @param string $modifier  Extra class: is-warning, is-success.
	 * @return void
	 */
	private static function callout( $icon, $html, $modifier = '' ) {
		echo '<div class="emne-callout ' . esc_attr( $modifier ) . '">' . self::icon( $icon, 18 ) . '<div>' . $html . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG and caller-escaped HTML.
	}

	/**
	 * A text field with a copy button.
	 *
	 * @param string $value Text to copy.
	 * @param string $label Accessible label.
	 * @return string
	 */
	private static function copy_field( $value, $label ) {
		return '<span class="emne-copy-field"><code>' . esc_html( $value ) . '</code><button type="button" class="button button-small emne-copy" data-copy="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $label ) . '">' . self::icon( 'copy', 14 ) . '<span>' . esc_html__( 'Copy', 'easy-mailchimp-newsletter-embed' ) . '</span></button></span>';
	}

	/**
	 * The shortcode for a form.
	 *
	 * @param int $id Form ID.
	 * @return string
	 */
	private static function shortcode( $id ) {
		return '[' . Easy_Mailchimp_Newsletter_Embed_Render::SHORTCODE . ' id="' . (int) $id . '"]';
	}


	/**
	 * The list of forms.
	 *
	 * @return void
	 */
	private function render_forms() {
		$forms     = Easy_Mailchimp_Newsletter_Embed_Forms::all();
		$connected = $this->plugin->is_connected();
		$new_url   = self::url(
			array(
				'tab'  => 'forms',
				'edit' => 'new',
			)
		);

		if ( ! $connected ) {
			self::callout(
				'plug-zap',
				'<strong>' . esc_html__( 'Connect your Mailchimp account first.', 'easy-mailchimp-newsletter-embed' ) . '</strong> '
				. esc_html__( 'It takes a minute: paste an API key from Mailchimp and pick which audience each form adds people to.', 'easy-mailchimp-newsletter-embed' )
				. ' <a class="button button-primary" href="' . esc_url( self::url( array( 'tab' => 'mailchimp' ) ) ) . '">' . esc_html__( 'Connect Mailchimp', 'easy-mailchimp-newsletter-embed' ) . '</a>',
				'is-warning emne-callout-top'
			);
		}

		$add = '<a class="button button-primary" href="' . esc_url( $new_url ) . '">' . self::icon( 'plus', 16 ) . esc_html__( 'Add form', 'easy-mailchimp-newsletter-embed' ) . '</a>';

		self::box_start( 'layout-template', __( 'Signup forms', 'easy-mailchimp-newsletter-embed' ), '', $forms ? $add : '' );

		if ( ! $forms ) {
			?>
			<div class="emne-empty">
				<span class="emne-empty-icon"><?php echo self::icon( 'mail-plus', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h3><?php esc_html_e( 'No signup forms yet', 'easy-mailchimp-newsletter-embed' ); ?></h3>
				<p><?php esc_html_e( 'Create a form, pick its Mailchimp audience and a theme, then put it on your site with a block, a shortcode or a PHP snippet, or show it as a popup. Make as many as you like, each going to its own audience.', 'easy-mailchimp-newsletter-embed' ); ?></p>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( $new_url ); ?>"><?php echo self::icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Create your first form', 'easy-mailchimp-newsletter-embed' ); ?></a></p>
			</div>
			<?php
			self::box_end();
			return;
		}

		$themes = Easy_Mailchimp_Newsletter_Embed_Forms::theme_labels();
		?>
		<table class="widefat striped emne-table emne-forms-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Form', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Audience', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shows as', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col" class="emne-num"><?php esc_html_e( 'Signups, 30 days', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'easy-mailchimp-newsletter-embed' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $forms as $form ) : ?>
					<?php
					$edit_url = self::url(
						array(
							'tab'  => 'forms',
							'edit' => $form['id'],
						)
					);
					$counts   = Easy_Mailchimp_Newsletter_Embed_Stats::form_counts( $form['id'] );
					?>
					<tr>
						<td class="column-primary">
							<span class="emne-swatch emne-swatch-<?php echo esc_attr( $form['theme'] ); ?>" style="<?php echo esc_attr( Easy_Mailchimp_Newsletter_Embed_Render::style_vars( $form['accent'] ) ); ?>" title="<?php echo esc_attr( $themes[ $form['theme'] ][0] ); ?>"></span>
							<strong><a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $form['name'] ); ?></a></strong>
							<span class="emne-muted">#<?php echo (int) $form['id']; ?> · <?php echo esc_html( $themes[ $form['theme'] ][0] ); ?></span>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'easy-mailchimp-newsletter-embed' ); ?></a> | </span>
								<span class="duplicate"><a href="<?php echo esc_url( $this->action_url( 'duplicate_form', $form['id'] ) ); ?>"><?php esc_html_e( 'Duplicate', 'easy-mailchimp-newsletter-embed' ); ?></a> | </span>
								<span class="trash"><a class="emne-delete" href="<?php echo esc_url( $this->action_url( 'delete_form', $form['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'easy-mailchimp-newsletter-embed' ); ?></a></span>
							</div>
						</td>
						<td>
							<?php if ( '' === $form['list_id'] ) : ?>
								<span class="emne-tag is-bad"><?php esc_html_e( 'No audience', 'easy-mailchimp-newsletter-embed' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( Easy_Mailchimp_Newsletter_Embed_Mailchimp::list_name( $form['list_id'] ) ); ?>
								<span class="emne-muted"><?php echo $form['double_optin'] ? esc_html__( 'Double opt-in', 'easy-mailchimp-newsletter-embed' ) : esc_html__( 'Single opt-in', 'easy-mailchimp-newsletter-embed' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $form['popup'] ) : ?>
								<span class="emne-tag is-good"><?php echo self::icon( 'app-window', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Popup', 'easy-mailchimp-newsletter-embed' ); ?></span>
								<span class="emne-muted"><?php echo esc_html( $this->popup_summary( $form ) ); ?></span>
							<?php else : ?>
								<span class="emne-tag is-none"><?php esc_html_e( 'Embed only', 'easy-mailchimp-newsletter-embed' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="emne-num">
							<strong><?php echo esc_html( number_format_i18n( $counts['recent'] ) ); ?></strong>
							<?php /* translators: %s: all-time signups. */ ?>
							<span class="emne-muted"><?php echo esc_html( sprintf( __( '%s all time', 'easy-mailchimp-newsletter-embed' ), number_format_i18n( $counts['total'] ) ) ); ?></span>
						</td>
						<td><?php echo self::copy_field( self::shortcode( $form['id'] ), __( 'Copy shortcode', 'easy-mailchimp-newsletter-embed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::box_end();

		$this->render_ways_box();
	}

	/**
	 * Nonce-protected link to a form action.
	 *
	 * @param string $action duplicate_form or delete_form.
	 * @param int    $id     Form ID.
	 * @return string
	 */
	private function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'emne_' . $action,
					'form'   => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'emne_' . $action
		);
	}

	/**
	 * "After 8 seconds, on every page" for the forms list.
	 *
	 * @param array $form Form.
	 * @return string
	 */
	private function popup_summary( array $form ) {
		switch ( $form['popup_trigger'] ) {
			case 'scroll':
				/* translators: %d: percent of the page. */
				$when = sprintf( __( 'At %d%% scrolled', 'easy-mailchimp-newsletter-embed' ), $form['popup_scroll'] );
				break;
			case 'exit':
				$when = __( 'On exit intent', 'easy-mailchimp-newsletter-embed' );
				break;
			case 'click':
				$when = __( 'When a link is clicked', 'easy-mailchimp-newsletter-embed' );
				break;
			default:
				/* translators: %d: seconds. */
				$when = sprintf( _n( 'After %d second', 'After %d seconds', $form['popup_delay'], 'easy-mailchimp-newsletter-embed' ), $form['popup_delay'] );
		}

		$where = array(
			'all'   => __( 'every page', 'easy-mailchimp-newsletter-embed' ),
			'posts' => __( 'blog posts', 'easy-mailchimp-newsletter-embed' ),
			'pages' => __( 'pages', 'easy-mailchimp-newsletter-embed' ),
			'home'  => __( 'the homepage', 'easy-mailchimp-newsletter-embed' ),
			'paths' => __( 'chosen URLs', 'easy-mailchimp-newsletter-embed' ),
		);

		/* translators: 1: when it opens, 2: where. */
		return sprintf( __( '%1$s, on %2$s', 'easy-mailchimp-newsletter-embed' ), $when, $where[ $form['popup_where'] ] );
	}

	/**
	 * How to put a form on the site.
	 *
	 * @return void
	 */
	private function render_ways_box() {
		self::box_start( 'sparkles', __( 'Putting a form on your site', 'easy-mailchimp-newsletter-embed' ) );
		$ways = array(
			array( 'blocks', __( 'Block', 'easy-mailchimp-newsletter-embed' ), __( 'In the block editor, add the "Mailchimp Signup Form" block and pick a form.', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'brackets', __( 'Shortcode', 'easy-mailchimp-newsletter-embed' ), __( 'Paste the form\'s shortcode into a post, a page, a Shortcode block, a widget or a page builder\'s shortcode element.', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'code-xml', __( 'PHP', 'easy-mailchimp-newsletter-embed' ), __( 'In a theme template, call easy_mailchimp_embed( ID ). The form editor shows the exact line.', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'app-window', __( 'Popup', 'easy-mailchimp-newsletter-embed' ), __( 'Switch on "Show as a popup" in a form and choose when and where it opens. Nothing to paste.', 'easy-mailchimp-newsletter-embed' ) ),
		);
		?>
		<div class="emne-ways">
			<?php foreach ( $ways as $way ) : ?>
				<div class="emne-way">
					<span class="emne-way-icon"><?php echo self::icon( $way[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<strong><?php echo esc_html( $way[1] ); ?></strong>
					<span><?php echo esc_html( $way[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		self::box_end();
	}


	/**
	 * The form editor.
	 *
	 * @param string $edit Form ID, or "new".
	 * @return void
	 */
	private function render_editor( $edit ) {
		$id   = 'new' === $edit ? 0 : absint( $edit );
		$form = $id ? Easy_Mailchimp_Newsletter_Embed_Forms::get( $id ) : Easy_Mailchimp_Newsletter_Embed_Forms::defaults();

		if ( ! $form ) {
			self::callout( 'circle-alert', esc_html__( 'That signup form doesn\'t exist. It may have been deleted.', 'easy-mailchimp-newsletter-embed' ) . ' <a href="' . esc_url( self::url( array( 'tab' => 'forms' ) ) ) . '">' . esc_html__( 'Back to all forms', 'easy-mailchimp-newsletter-embed' ) . '</a>', 'is-warning' );
			return;
		}

		$back = self::url( array( 'tab' => 'forms' ) );
		?>
		<div class="emne-editor-head">
			<a class="emne-back" href="<?php echo esc_url( $back ); ?>"><?php echo self::icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'All forms', 'easy-mailchimp-newsletter-embed' ); ?></a>
			<h2><?php echo $id ? esc_html( $form['name'] ) : esc_html__( 'New signup form', 'easy-mailchimp-newsletter-embed' ); ?></h2>
		</div>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="emne-editor" data-form-id="<?php echo (int) $id; ?>">
			<input type="hidden" name="action" value="emne_save_form">
			<input type="hidden" name="form_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'emne_save_form' ); ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						$this->editor_basics( $form );
						$this->editor_fields( $form );
						$this->editor_text( $form );
						$this->editor_style( $form );
						$this->editor_popup( $form );
						?>
					</div>
					<div id="postbox-container-1" class="postbox-container">
						<div class="emne-sidebar">
							<?php $this->editor_save_box( $form ); ?>
							<?php $this->editor_preview_box( $form ); ?>
						</div>
						<?php $this->editor_embed_box( $form ); ?>
					</div>
				</div>
				<br class="clear">
			</div>
		</form>
		<?php
	}

	/**
	 * Name attribute for a form setting.
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	private static function name( $key ) {
		return 'emne[' . $key . ']';
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $attrs     Extra attributes for the row (already escaped).
	 * @return void
	 */
	private static function row( $label, $content, $label_for = '', $attrs = '' ) {
		echo '<tr' . $attrs . '><th scope="row">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Attribute that shows a row only when a control has a value.
	 *
	 * @param string $key   Setting the row depends on.
	 * @param string $value Value(s) that show it, comma-separated ('' = checked).
	 * @return string
	 */
	private static function depends( $key, $value = '' ) {
		return ' data-emne-depends="' . esc_attr( $key ) . '" data-emne-value="' . esc_attr( $value ) . '"';
	}

	/**
	 * Text input.
	 *
	 * @param array  $form        Form.
	 * @param string $key         Setting.
	 * @param int    $max         Max length.
	 * @param string $placeholder Placeholder.
	 * @param string $size        Input class.
	 * @return void
	 */
	private static function text( array $form, $key, $max, $placeholder = '', $size = 'regular-text' ) {
		$value = 'tags' === $key ? implode( ', ', (array) $form['tags'] ) : (string) $form[ $key ];

		printf(
			'<input type="%1$s" class="%2$s" id="emne-%3$s" name="%4$s" value="%5$s" maxlength="%6$d" placeholder="%7$s">',
			'redirect' === $key ? 'url' : 'text',
			esc_attr( $size ),
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( $value ),
			(int) $max,
			esc_attr( $placeholder )
		);
	}

	/**
	 * Textarea.
	 *
	 * @param array  $form        Form.
	 * @param string $key         Setting.
	 * @param int    $max         Max length.
	 * @param string $placeholder Placeholder.
	 * @param int    $rows        Rows.
	 * @return void
	 */
	private static function textarea( array $form, $key, $max, $placeholder = '', $rows = 3 ) {
		printf(
			'<textarea class="large-text" id="emne-%1$s" name="%2$s" rows="%3$d" maxlength="%4$d" placeholder="%5$s">%6$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $rows,
			(int) $max,
			esc_attr( $placeholder ),
			esc_textarea( (string) $form[ $key ] )
		);
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param array  $form  Form.
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private static function checkbox( array $form, $key, $label, $desc = '' ) {
		printf(
			'<label class="emne-check"><input type="checkbox" id="emne-%1$s" name="%2$s" value="1" %3$s> <span>%4$s</span></label>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $form[ $key ], false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Radio buttons in a column.
	 *
	 * @param array  $form    Form.
	 * @param string $key     Setting.
	 * @param array  $options value => label.
	 * @return void
	 */
	private static function radios( array $form, $key, array $options ) {
		echo '<fieldset class="emne-radios">';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				checked( $value, $form[ $key ], false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Select box.
	 *
	 * @param array  $form    Form.
	 * @param string $key     Setting.
	 * @param array  $options value => label.
	 * @return void
	 */
	private static function select( array $form, $key, array $options ) {
		printf( '<select id="emne-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( self::name( $key ) ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) $value, (string) $form[ $key ], false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Number input.
	 *
	 * @param array  $form   Form.
	 * @param string $key    Setting.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private static function number( array $form, $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="emne-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $form[ $key ],
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Editor: audience, opt-in, tags.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_basics( array $form ) {
		$lists     = Easy_Mailchimp_Newsletter_Embed_Mailchimp::lists();
		$connected = $this->plugin->is_connected();

		self::box_start( 'simpleicons/mailchimp', __( 'Mailchimp', 'easy-mailchimp-newsletter-embed' ) );
		echo '<table class="form-table" role="presentation">';

		self::row(
			__( 'Form name', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'name', 100, __( 'e.g. Footer signup', 'easy-mailchimp-newsletter-embed' ) );
				echo '<p class="description">' . esc_html__( 'Only you see this, to tell your forms apart.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-name'
		);

		self::row(
			__( 'Audience', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form, $lists, $connected ) {
				if ( ! $connected ) {
					echo '<p class="emne-muted">' . esc_html__( 'Connect Mailchimp to choose an audience.', 'easy-mailchimp-newsletter-embed' ) . ' <a href="' . esc_url( self::url( array( 'tab' => 'mailchimp' ) ) ) . '">' . esc_html__( 'Connect now', 'easy-mailchimp-newsletter-embed' ) . '</a></p>';
					echo '<input type="hidden" name="' . esc_attr( self::name( 'list_id' ) ) . '" value="' . esc_attr( $form['list_id'] ) . '">';
					return;
				}

				$options = array( '' => __( '— Choose an audience —', 'easy-mailchimp-newsletter-embed' ) );
				foreach ( $lists as $list_id => $list ) {
					/* translators: 1: audience name, 2: number of contacts. */
					$options[ $list_id ] = sprintf( _n( '%1$s (%2$s contact)', '%1$s (%2$s contacts)', (int) $list['count'], 'easy-mailchimp-newsletter-embed' ), $list['name'], number_format_i18n( (int) $list['count'] ) );
				}
				if ( '' !== $form['list_id'] && ! isset( $options[ $form['list_id'] ] ) ) {
					/* translators: %s: audience ID. */
					$options[ $form['list_id'] ] = sprintf( __( 'Missing audience (%s)', 'easy-mailchimp-newsletter-embed' ), $form['list_id'] );
				}

				self::select( $form, 'list_id', $options );
				echo '<p class="description">' . esc_html__( 'The Mailchimp audience (list) new subscribers are added to.', 'easy-mailchimp-newsletter-embed' ) . ' <a href="' . esc_url( self::url( array( 'tab' => 'mailchimp' ) ) ) . '">' . esc_html__( 'Don\'t see it? Refresh audiences', 'easy-mailchimp-newsletter-embed' ) . '</a></p>';
			},
			'emne-list_id'
		);

		self::row(
			__( 'Confirmation', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::checkbox( $form, 'double_optin', __( 'Ask new subscribers to confirm by email (double opt-in)', 'easy-mailchimp-newsletter-embed' ), __( 'Recommended. Mailchimp emails a confirmation link first, which keeps fake and mistyped addresses off your list and is expected in much of Europe.', 'easy-mailchimp-newsletter-embed' ) );
			}
		);

		self::row(
			__( 'Tags', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'tags', 1100, __( 'e.g. website, footer-form', 'easy-mailchimp-newsletter-embed' ) );
				echo '<p class="description">' . esc_html__( 'Optional, comma-separated. Added to everyone who signs up through this form, so you can tell where they came from or start an automation.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-tags'
		);

		echo '</table>';
		self::box_end();
	}

	/**
	 * Editor: which fields and their labels.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_fields( array $form ) {
		self::box_start( 'text-cursor-input', __( 'Fields', 'easy-mailchimp-newsletter-embed' ) );
		echo '<table class="form-table" role="presentation">';

		self::row(
			__( 'Name', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::radios(
					$form,
					'name_field',
					array(
						'first'      => __( 'First name', 'easy-mailchimp-newsletter-embed' ),
						'first_last' => __( 'First and last name', 'easy-mailchimp-newsletter-embed' ),
						'full'       => __( 'One "Name" box (split into first and last)', 'easy-mailchimp-newsletter-embed' ),
						'none'       => __( 'No name, just the email address', 'easy-mailchimp-newsletter-embed' ),
					)
				);
			}
		);

		self::row(
			__( 'Required', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::checkbox( $form, 'name_required', __( 'Make the name required', 'easy-mailchimp-newsletter-embed' ) );
			},
			'',
			self::depends( 'name_field', 'first,first_last,full' )
		);

		self::row(
			__( 'Labels', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::checkbox( $form, 'show_labels', __( 'Show labels above the fields', 'easy-mailchimp-newsletter-embed' ), __( 'Off: the label sits inside each empty field as a placeholder, which is more compact. Screen readers get the label either way.', 'easy-mailchimp-newsletter-embed' ) );
			}
		);

		self::row(
			__( 'Email label', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'email_label', 60 );
			},
			'emne-email_label'
		);

		self::row(
			__( 'Name label', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'name_label', 60 );
			},
			'emne-name_label',
			self::depends( 'name_field', 'first,first_last,full' )
		);

		self::row(
			__( 'Last name label', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'last_label', 60 );
			},
			'emne-last_label',
			self::depends( 'name_field', 'first_last' )
		);

		echo '</table>';
		self::box_end();
	}

	/**
	 * Editor: heading, description, button and messages.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_text( array $form ) {
		self::box_start( 'type', __( 'Text', 'easy-mailchimp-newsletter-embed' ) );
		echo '<table class="form-table" role="presentation">';

		self::row(
			__( 'Heading', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'heading', 200, '', 'large-text' );
				echo '<p class="description">' . esc_html__( 'Leave empty for no heading.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-heading'
		);

		self::row(
			__( 'Description', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::textarea( $form, 'description', 1000 );
				echo '<p class="description">' . esc_html__( 'Links, bold and italics are allowed.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-description'
		);

		self::row(
			__( 'Button', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'button', 60 );
			},
			'emne-button'
		);

		self::row(
			__( 'Success message', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				$defaults = array_merge( $form, array( 'success' => '' ) );
				self::text( $form, 'success', 300, Easy_Mailchimp_Newsletter_Embed_Forms::success_message( $defaults ), 'large-text' );
				echo '<p class="description">' . esc_html__( 'Shown in place of the fields after a signup. Leave empty for the default, which matches the confirmation setting.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-success'
		);

		self::row(
			__( 'Consent checkbox', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::textarea( $form, 'consent', 500, __( 'e.g. I agree to receive emails and accept the <a href="/privacy-policy/">privacy policy</a>.', 'easy-mailchimp-newsletter-embed' ), 2 );
				echo '<p class="description">' . esc_html__( 'Optional. With text here, visitors must tick a box with this label before they can subscribe.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-consent'
		);

		self::row(
			__( 'Small print', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::textarea( $form, 'note', 500, __( 'e.g. We respect your privacy. Unsubscribe at any time.', 'easy-mailchimp-newsletter-embed' ), 2 );
			},
			'emne-note'
		);

		self::row(
			__( 'After signing up', 'easy-mailchimp-newsletter-embed' ),
			function () use ( $form ) {
				self::text( $form, 'redirect', 500, home_url( '/thank-you/' ), 'large-text' );
				echo '<p class="description">' . esc_html__( 'Optional. Send subscribers to this page (a thank-you page or a download) instead of showing the success message.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			},
			'emne-redirect'
		);

		echo '</table>';
		self::box_end();
	}

	/**
	 * Editor: theme, color, layout and alignment.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_style( array $form ) {
		self::box_start( 'palette', __( 'Style', 'easy-mailchimp-newsletter-embed' ) );
		?>
		<div class="emne-themes" style="<?php echo esc_attr( Easy_Mailchimp_Newsletter_Embed_Render::style_vars( $form['accent'] ) ); ?>">
			<?php foreach ( Easy_Mailchimp_Newsletter_Embed_Forms::theme_labels() as $theme => $label ) : ?>
				<label class="emne-theme-card">
					<input type="radio" name="<?php echo esc_attr( self::name( 'theme' ) ); ?>" value="<?php echo esc_attr( $theme ); ?>" <?php checked( $theme, $form['theme'] ); ?>>
					<span class="emne-mini emne-mini-<?php echo esc_attr( $theme ); ?>" aria-hidden="true">
						<span class="emne-mini-line"></span>
						<span class="emne-mini-line is-short"></span>
						<span class="emne-mini-row"><span class="emne-mini-input"></span><span class="emne-mini-btn"></span></span>
					</span>
					<span class="emne-theme-text">
						<strong><?php echo esc_html( $label[0] ); ?></strong>
						<span><?php echo esc_html( $label[1] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<table class="form-table" role="presentation">
			<?php
			self::row(
				__( 'Accent color', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					?>
					<span class="emne-color">
						<input type="color" id="emne-accent-picker" value="<?php echo esc_attr( $form['accent'] ); ?>" aria-label="<?php esc_attr_e( 'Pick a color', 'easy-mailchimp-newsletter-embed' ); ?>">
						<input type="text" class="small-text code" id="emne-accent" name="<?php echo esc_attr( self::name( 'accent' ) ); ?>" value="<?php echo esc_attr( $form['accent'] ); ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}">
					</span>
					<p class="description"><?php esc_html_e( 'The button color (and the whole box in the Bold theme). Text on it switches between white and dark by itself.', 'easy-mailchimp-newsletter-embed' ); ?></p>
					<?php
				},
				'emne-accent'
			);

			self::row(
				__( 'Layout', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::radios(
						$form,
						'layout',
						array(
							'stacked' => __( 'Stacked: fields on top of each other, full-width button', 'easy-mailchimp-newsletter-embed' ),
							'inline'  => __( 'In a row: fields and button side by side (wraps on phones)', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				}
			);

			self::row(
				__( 'Text alignment', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::radios(
						$form,
						'align',
						array(
							'left'   => __( 'Left', 'easy-mailchimp-newsletter-embed' ),
							'center' => __( 'Centered', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				}
			);
		?>
		</table>
		<?php
		self::box_end();
	}

	/**
	 * Editor: popup settings.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_popup( array $form ) {
		self::box_start( 'app-window', __( 'Popup', 'easy-mailchimp-newsletter-embed' ) );
		?>
		<label class="emne-master">
			<input type="checkbox" id="emne-popup" name="<?php echo esc_attr( self::name( 'popup' ) ); ?>" value="1" <?php checked( 1, (int) $form['popup'] ); ?>>
			<span><?php esc_html_e( 'Show this form as a popup', 'easy-mailchimp-newsletter-embed' ); ?></span>
		</label>
		<p class="emne-intro" data-emne-depends="popup" data-emne-value="" data-emne-invert="1"><?php esc_html_e( 'Off: the form only appears where you put its block, shortcode or PHP snippet.', 'easy-mailchimp-newsletter-embed' ); ?></p>
		<table class="form-table" role="presentation" data-emne-depends="popup" data-emne-value="">
			<?php
			self::row(
				__( 'Opens', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::radios(
						$form,
						'popup_trigger',
						array(
							'delay'  => __( 'After a few seconds on the page', 'easy-mailchimp-newsletter-embed' ),
							'scroll' => __( 'After scrolling part of the way down', 'easy-mailchimp-newsletter-embed' ),
							'exit'   => __( 'When the visitor moves to leave (exit intent, desktop only)', 'easy-mailchimp-newsletter-embed' ),
							'click'  => __( 'Only when a button or link is clicked', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				}
			);

			self::row(
				__( 'Delay', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::number( $form, 'popup_delay', 0, 600, __( 'seconds', 'easy-mailchimp-newsletter-embed' ) );
				},
				'emne-popup_delay',
				self::depends( 'popup_trigger', 'delay' )
			);

			self::row(
				__( 'Scrolled', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::number( $form, 'popup_scroll', 1, 100, __( '% of the page', 'easy-mailchimp-newsletter-embed' ) );
				},
				'emne-popup_scroll',
				self::depends( 'popup_trigger', 'scroll' )
			);

			self::row(
				__( 'Open it from a link', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					if ( $form['id'] ) {
						echo self::copy_field( '#emne-popup-' . (int) $form['id'], __( 'Copy link', 'easy-mailchimp-newsletter-embed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field().
						/* translators: %s: CSS class name. */
						echo '<p class="description">' . esc_html( sprintf( __( 'Use this as the URL of any link or button (or give it the CSS class %s) and clicking it opens the popup, whichever option is chosen above. It works on pages where the popup is set to show.', 'easy-mailchimp-newsletter-embed' ), 'emne-open-' . (int) $form['id'] ) ) . '</p>';
					} else {
						echo '<p class="description">' . esc_html__( 'Save the form to get a link that opens it.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
					}
				}
			);

			self::row(
				__( 'Show on', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::select(
						$form,
						'popup_where',
						array(
							'all'   => __( 'Every page', 'easy-mailchimp-newsletter-embed' ),
							'posts' => __( 'Blog posts only', 'easy-mailchimp-newsletter-embed' ),
							'pages' => __( 'Pages only', 'easy-mailchimp-newsletter-embed' ),
							'home'  => __( 'The homepage only', 'easy-mailchimp-newsletter-embed' ),
							'paths' => __( 'Only these URLs…', 'easy-mailchimp-newsletter-embed' ),
						)
					);
					echo '<div class="emne-sub" data-emne-depends="popup_where" data-emne-value="paths">';
					self::textarea( $form, 'popup_paths', 4000, "/blog/*\n/free-guide/", 3 );
					echo '<p class="description">' . esc_html__( 'One URL path per line. * matches anything, so /blog/* covers every page under /blog/.', 'easy-mailchimp-newsletter-embed' ) . '</p></div>';
					echo '<p class="description">' . esc_html__( 'Never on WooCommerce cart, checkout or account pages, or on a page where this form is already embedded.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
				},
				'emne-popup_where'
			);

			self::row(
				__( 'Never on', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::textarea( $form, 'popup_exclude', 4000, "/contact/\n/shop/*", 2 );
					echo '<p class="description">' . esc_html__( 'Optional. URL paths to leave out, in the same format.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
				},
				'emne-popup_exclude'
			);

			self::row(
				__( 'Position', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::radios(
						$form,
						'popup_position',
						array(
							'center' => __( 'Center of the screen, page dimmed behind it', 'easy-mailchimp-newsletter-embed' ),
							'corner' => __( 'Bottom corner, page still usable (less intrusive)', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				}
			);

			self::row(
				__( 'Devices', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::select(
						$form,
						'popup_devices',
						array(
							'all'     => __( 'Phones and computers', 'easy-mailchimp-newsletter-embed' ),
							'desktop' => __( 'Computers and tablets only', 'easy-mailchimp-newsletter-embed' ),
							'mobile'  => __( 'Phones only', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				},
				'emne-popup_devices'
			);

			self::row(
				__( 'Visitors', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::select(
						$form,
						'popup_visitors',
						array(
							'all'        => __( 'Everyone', 'easy-mailchimp-newsletter-embed' ),
							'logged_out' => __( 'Only visitors who aren\'t logged in', 'easy-mailchimp-newsletter-embed' ),
						)
					);
				},
				'emne-popup_visitors'
			);

			self::row(
				__( 'After closing', 'easy-mailchimp-newsletter-embed' ),
				function () use ( $form ) {
					self::number( $form, 'popup_days', 0, 365, __( 'days before it shows again', 'easy-mailchimp-newsletter-embed' ) );
					echo '<p class="description">' . esc_html__( 'People who subscribe through any of your forms never see popups again on that browser.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
				},
				'emne-popup_days'
			);
			?>
		</table>
		<?php
		self::box_end();
	}

	/**
	 * Editor sidebar: Save.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_save_box( array $form ) {
		self::box_start( 'save', __( 'Save', 'easy-mailchimp-newsletter-embed' ), 'emne-save-box' );
		if ( $form['id'] ) {
			$counts = Easy_Mailchimp_Newsletter_Embed_Stats::form_counts( $form['id'] );
			/* translators: 1: signups in 30 days, 2: all-time signups. */
			echo '<p>' . esc_html( sprintf( __( '%1$s signups in the last 30 days, %2$s all time.', 'easy-mailchimp-newsletter-embed' ), number_format_i18n( $counts['recent'] ), number_format_i18n( $counts['total'] ) ) ) . '</p>';
		}
		submit_button( $form['id'] ? __( 'Save Form', 'easy-mailchimp-newsletter-embed' ) : __( 'Create Form', 'easy-mailchimp-newsletter-embed' ), 'primary large', 'submit', false );
		if ( $form['id'] ) {
			echo '<p class="emne-save-links"><a href="' . esc_url( $this->action_url( 'duplicate_form', $form['id'] ) ) . '">' . esc_html__( 'Duplicate', 'easy-mailchimp-newsletter-embed' ) . '</a><a class="emne-delete emne-danger" href="' . esc_url( $this->action_url( 'delete_form', $form['id'] ) ) . '">' . esc_html__( 'Delete', 'easy-mailchimp-newsletter-embed' ) . '</a></p>';
		}
		self::box_end();
	}

	/**
	 * Editor sidebar: live preview.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_preview_box( array $form ) {
		self::box_start( 'eye', __( 'Preview', 'easy-mailchimp-newsletter-embed' ), 'emne-preview-box' );
		echo '<div class="emne-preview" aria-live="off">' . Easy_Mailchimp_Newsletter_Embed_Render::form( $form, 'preview' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo '<p class="emne-preview-note">' . esc_html__( 'Updates as you edit. On your site, the Plain and Minimal themes take on your theme\'s fonts and colors.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
		self::box_end();
	}

	/**
	 * Editor sidebar: how to embed this form.
	 *
	 * @param array $form Form.
	 * @return void
	 */
	private function editor_embed_box( array $form ) {
		self::box_start( 'code-xml', __( 'Add it to your site', 'easy-mailchimp-newsletter-embed' ), 'emne-embed-box' );

		if ( ! $form['id'] ) {
			echo '<p class="emne-muted">' . esc_html__( 'Create the form to get its shortcode and PHP snippet.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			self::box_end();
			return;
		}

		$id  = (int) $form['id'];
		$php = '<?php if ( function_exists( \'easy_mailchimp_embed\' ) ) { easy_mailchimp_embed( ' . $id . ' ); } ?>';
		?>
		<p class="emne-embed-label"><?php echo self::icon( 'blocks', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Block', 'easy-mailchimp-newsletter-embed' ); ?></p>
		<p class="emne-muted"><?php esc_html_e( 'Add the "Mailchimp Signup Form" block and pick this form.', 'easy-mailchimp-newsletter-embed' ); ?></p>
		<p class="emne-embed-label"><?php echo self::icon( 'brackets', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Shortcode', 'easy-mailchimp-newsletter-embed' ); ?></p>
		<?php echo self::copy_field( self::shortcode( $id ), __( 'Copy shortcode', 'easy-mailchimp-newsletter-embed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?>
		<p class="emne-embed-label"><?php echo self::icon( 'code-xml', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'PHP (theme templates)', 'easy-mailchimp-newsletter-embed' ); ?></p>
		<?php echo self::copy_field( $php, __( 'Copy PHP snippet', 'easy-mailchimp-newsletter-embed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?>
		<?php
		self::box_end();
	}


	/**
	 * Connect / disconnect.
	 *
	 * @return void
	 */
	private function render_connection() {
		$key       = $this->plugin->api_key();
		$connected = '' !== Easy_Mailchimp_Newsletter_Embed_Mailchimp::data_center( $key );
		$account   = Easy_Mailchimp_Newsletter_Embed_Mailchimp::account();

		self::box_start( 'plug-zap', __( 'Connection', 'easy-mailchimp-newsletter-embed' ) );

		if ( $connected ) :
			?>
			<div class="emne-connected">
				<span class="emne-connected-icon"><?php echo self::icon( 'simpleicons/mailchimp', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<div>
					<strong><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo '' !== $account['name'] ? esc_html( sprintf( /* translators: %s: Mailchimp account name. */ __( 'Connected to %s', 'easy-mailchimp-newsletter-embed' ), $account['name'] ) ) : esc_html__( 'Connected', 'easy-mailchimp-newsletter-embed' ); ?></strong>
					<span class="emne-muted">
						<?php
						/* translators: 1: masked API key, 2: data center. */
						echo esc_html( sprintf( __( 'API key %1$s · data center %2$s', 'easy-mailchimp-newsletter-embed' ), Easy_Mailchimp_Newsletter_Embed_Mailchimp::mask( $key ), Easy_Mailchimp_Newsletter_Embed_Mailchimp::data_center( $key ) ) );
						?>
					</span>
				</div>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="emne-inline-form">
					<input type="hidden" name="action" value="emne_disconnect">
					<?php wp_nonce_field( 'emne_disconnect' ); ?>
					<button type="submit" class="button emne-disconnect"><?php esc_html_e( 'Disconnect', 'easy-mailchimp-newsletter-embed' ); ?></button>
				</form>
			</div>
			<details class="emne-details">
				<summary><?php esc_html_e( 'Use a different API key', 'easy-mailchimp-newsletter-embed' ); ?></summary>
				<?php $this->render_key_form( true ); ?>
			</details>
			<?php
		else :
			?>
			<p class="emne-intro"><?php esc_html_e( 'Connect with a Mailchimp API key. It\'s stored encrypted, only ever sent to Mailchimp, and never shown again in full.', 'easy-mailchimp-newsletter-embed' ); ?></p>
			<?php $this->render_key_form( false ); ?>
			<ol class="emne-steps">
				<li>
					<?php
					printf(
						/* translators: %s: link to Mailchimp's API keys page. */
						esc_html__( 'In Mailchimp, open %s (profile icon > Profile > Extras > API keys).', 'easy-mailchimp-newsletter-embed' ),
						'<a href="https://admin.mailchimp.com/account/api/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'API keys', 'easy-mailchimp-newsletter-embed' ) . '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Click "Create A Key", name it after this website, and copy it. Mailchimp only shows it once.', 'easy-mailchimp-newsletter-embed' ); ?></li>
				<li><?php esc_html_e( 'Paste it above and click Connect. Your audiences load right away.', 'easy-mailchimp-newsletter-embed' ); ?></li>
			</ol>
			<?php
		endif;

		self::box_end();
	}

	/**
	 * The API key form.
	 *
	 * @param bool $replace Whether a key is already saved.
	 * @return void
	 */
	private function render_key_form( $replace ) {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="emne-key-form">
			<input type="hidden" name="action" value="emne_connect">
			<?php wp_nonce_field( 'emne_connect' ); ?>
			<label class="screen-reader-text" for="emne-api-key"><?php esc_html_e( 'Mailchimp API key', 'easy-mailchimp-newsletter-embed' ); ?></label>
			<input type="password" id="emne-api-key" name="api_key" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21" required>
			<button type="submit" class="button button-primary"><?php echo self::icon( 'plug', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo $replace ? esc_html__( 'Replace key', 'easy-mailchimp-newsletter-embed' ) : esc_html__( 'Connect', 'easy-mailchimp-newsletter-embed' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The audiences table.
	 *
	 * @return void
	 */
	private function render_audiences() {
		if ( ! $this->plugin->is_connected() ) {
			return;
		}

		$account = Easy_Mailchimp_Newsletter_Embed_Mailchimp::account();
		$forms   = Easy_Mailchimp_Newsletter_Embed_Forms::all();
		$refresh = '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="emne-inline-form"><input type="hidden" name="action" value="emne_refresh">' . wp_nonce_field( 'emne_refresh', '_wpnonce', true, false ) . '<button type="submit" class="button">' . self::icon( 'refresh-cw', 15 ) . esc_html__( 'Refresh', 'easy-mailchimp-newsletter-embed' ) . '</button></form>';

		self::box_start( 'users', __( 'Audiences', 'easy-mailchimp-newsletter-embed' ), '', $refresh );

		if ( ! $account['lists'] ) {
			echo '<p class="emne-intro">' . esc_html__( 'This Mailchimp account has no audiences yet. Create one in Mailchimp (Audience > All contacts), then click Refresh.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
			self::box_end();
			return;
		}
		?>
		<table class="widefat striped emne-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Audience', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Audience ID', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col" class="emne-num"><?php esc_html_e( 'Contacts', 'easy-mailchimp-newsletter-embed' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used by', 'easy-mailchimp-newsletter-embed' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $account['lists'] as $list_id => $list ) : ?>
					<?php
					$users = array();
					foreach ( $forms as $form ) {
						if ( $form['list_id'] === (string) $list_id ) {
							$users[] = '<a href="' . esc_url(
								self::url(
									array(
										'tab'  => 'forms',
										'edit' => $form['id'],
									)
								)
							) . '">' . esc_html( $form['name'] ) . '</a>';
						}
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $list['name'] ); ?></strong></td>
						<td><code><?php echo esc_html( $list_id ); ?></code></td>
						<td class="emne-num"><?php echo esc_html( number_format_i18n( (int) $list['count'] ) ); ?></td>
						<td><?php echo $users ? implode( ', ', $users ) : '<span class="emne-muted">' . esc_html__( 'No forms', 'easy-mailchimp-newsletter-embed' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $account['checked'] ) : ?>
			<p class="emne-muted emne-small">
				<?php
				/* translators: %s: time since the audiences were loaded, e.g. "5 mins". */
				echo esc_html( sprintf( __( 'Loaded from Mailchimp %s ago. Contact counts update when you refresh.', 'easy-mailchimp-newsletter-embed' ), human_time_diff( $account['checked'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php
		self::box_end();
	}

	/**
	 * Recent failed signups.
	 *
	 * @return void
	 */
	private function render_errors() {
		$errors = Easy_Mailchimp_Newsletter_Embed_Stats::errors();

		if ( ! $errors ) {
			return;
		}

		$forms = Easy_Mailchimp_Newsletter_Embed_Forms::all();
		$clear = '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="emne-inline-form"><input type="hidden" name="action" value="emne_clear_errors">' . wp_nonce_field( 'emne_clear_errors', '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html__( 'Clear', 'easy-mailchimp-newsletter-embed' ) . '</button></form>';

		self::box_start( 'triangle-alert', __( 'Recent problems', 'easy-mailchimp-newsletter-embed' ), 'emne-errors-box', $clear );
		echo '<p class="emne-intro">' . esc_html__( 'Signups that didn\'t go through because of a problem on the Mailchimp or server side (visitors saw a "try again" message). Email addresses aren\'t kept.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
		?>
		<table class="widefat striped emne-table">
			<tbody>
				<?php foreach ( $errors as $error ) : ?>
					<tr>
						<td class="emne-when">
							<?php
							/* translators: %s: time ago, e.g. "5 mins". */
							echo esc_html( sprintf( __( '%s ago', 'easy-mailchimp-newsletter-embed' ), human_time_diff( (int) $error['time'] ) ) );
							?>
							<span class="emne-muted"><?php echo esc_html( isset( $forms[ $error['form'] ] ) ? $forms[ $error['form'] ]['name'] : '#' . (int) $error['form'] ); ?></span>
						</td>
						<td><?php echo esc_html( $error['text'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::box_end();
	}


	/**
	 * Spam protection settings (one form; the Save box is in the sidebar).
	 *
	 * @return void
	 */
	private function render_spam() {
		$plugin  = $this->plugin;
		$blocked = Easy_Mailchimp_Newsletter_Embed_Stats::blocked_counts();
		$tiles   = array(
			array( 'eye-off', $blocked['honeypot'], __( 'Filled the hidden field', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'timer', $blocked['too_fast'], __( 'Sent too fast', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'gauge', $blocked['rate_limit'], __( 'Over the limits', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'simpleicons/cloudflare', $blocked['turnstile'], __( 'Failed Turnstile', 'easy-mailchimp-newsletter-embed' ) ),
			array( 'globe-lock', $blocked['origin'], __( 'From another site', 'easy-mailchimp-newsletter-embed' ) ),
		);
		$secret  = '' !== $plugin->turnstile_secret();
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="emne-spam-form">
			<input type="hidden" name="action" value="emne_save_spam">
			<?php wp_nonce_field( 'emne_save_spam' ); ?>

			<?php self::box_start( 'shield-check', __( 'Built-in protection', 'easy-mailchimp-newsletter-embed' ) ); ?>
			<p class="emne-intro"><?php esc_html_e( 'Always on, invisible to visitors, and enough for most sites. Signups stopped in the last 30 days:', 'easy-mailchimp-newsletter-embed' ); ?></p>
			<div class="emne-stats">
				<?php foreach ( $tiles as $tile ) : ?>
					<div class="emne-stat">
						<span class="emne-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<span class="emne-stat-num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
						<span class="emne-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<ul class="emne-checklist">
				<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'Hidden bot field.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'People never see it; bots fill it in and are quietly turned away.', 'easy-mailchimp-newsletter-embed' ); ?></span></li>
				<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'Speed check.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Forms sent within a second and a half of appearing, or without the page\'s script, are refused.', 'easy-mailchimp-newsletter-embed' ); ?></span></li>
				<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'This site only.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Other websites can\'t send signups through your forms.', 'easy-mailchimp-newsletter-embed' ); ?></span></li>
				<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'No links in names.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Spammers hide links in the name field so Mailchimp\'s confirmation email carries them; those signups are refused.', 'easy-mailchimp-newsletter-embed' ); ?></span></li>
				<li><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><strong><?php esc_html_e( 'One inbox, three tries an hour.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Nobody can flood someone else\'s inbox with confirmation emails.', 'easy-mailchimp-newsletter-embed' ); ?></span></li>
			</ul>
			<table class="form-table" role="presentation">
				<?php
				self::row(
					__( 'Per visitor', 'easy-mailchimp-newsletter-embed' ),
					function () use ( $plugin ) {
						printf(
							'<input type="number" class="small-text" id="emne-rate-limit" name="rate_limit" value="%d" min="0" max="100" step="1"> %s',
							(int) $plugin->get_setting( 'rate_limit' ),
							esc_html__( 'signups per hour from one IP address', 'easy-mailchimp-newsletter-embed' )
						);
						echo '<p class="description">' . esc_html__( '0 turns this limit off. Behind Cloudflare the visitor\'s real IP is used; behind a proxy this site can\'t see past, the limit is skipped rather than shared by every visitor.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
					},
					'emne-rate-limit'
				);
				?>
			</table>
			<?php self::box_end(); ?>

			<?php self::box_start( 'simpleicons/cloudflare', __( 'Cloudflare Turnstile', 'easy-mailchimp-newsletter-embed' ), 'emne-turnstile-box' ); ?>
			<p class="emne-intro"><?php esc_html_e( 'Optional. If spam signups still get through, Turnstile adds Cloudflare\'s free, privacy-friendly human check to every form. Most visitors never see a puzzle. It\'s loaded only when someone clicks into a form.', 'easy-mailchimp-newsletter-embed' ); ?></p>
			<label class="emne-master">
				<input type="checkbox" id="emne-turnstile" name="turnstile" value="1" <?php checked( 1, (int) $plugin->get_setting( 'turnstile' ) ); ?>>
				<span><?php esc_html_e( 'Require Turnstile on every signup form', 'easy-mailchimp-newsletter-embed' ); ?></span>
			</label>
			<table class="form-table" role="presentation">
				<?php
				self::row(
					__( 'Site key', 'easy-mailchimp-newsletter-embed' ),
					function () use ( $plugin ) {
						printf(
							'<input type="text" class="regular-text code" id="emne-ts-site" name="turnstile_site" value="%s" maxlength="110" autocomplete="off" spellcheck="false" placeholder="0x4AAAAAAA…">',
							esc_attr( (string) $plugin->get_setting( 'turnstile_site' ) )
						);
					},
					'emne-ts-site'
				);

				self::row(
					__( 'Secret key', 'easy-mailchimp-newsletter-embed' ),
					function () use ( $secret ) {
						printf(
							'<input type="password" class="regular-text code" id="emne-ts-secret" name="turnstile_secret" value="" maxlength="110" autocomplete="new-password" spellcheck="false" placeholder="%s">',
							esc_attr( $secret ? __( 'Saved. Leave empty to keep it.', 'easy-mailchimp-newsletter-embed' ) : '0x4AAAAAAA…' )
						);
						if ( $secret ) {
							echo '<label class="emne-check emne-forget"><input type="checkbox" name="turnstile_forget" value="1"> <span>' . esc_html__( 'Delete the saved secret key', 'easy-mailchimp-newsletter-embed' ) . '</span></label>';
						}
						echo '<p class="description">' . esc_html__( 'Stored encrypted and only ever sent to Cloudflare.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
					},
					'emne-ts-secret'
				);

				self::row(
					__( 'Widget', 'easy-mailchimp-newsletter-embed' ),
					function () use ( $plugin ) {
						$look = 'always' === $plugin->get_setting( 'turnstile_look' ) ? 'always' : 'auto';
						echo '<fieldset class="emne-radios">';
						echo '<label><input type="radio" name="turnstile_look" value="auto" ' . checked( 'auto', $look, false ) . '> ' . esc_html__( 'Only show it when Cloudflare needs the visitor to click (recommended)', 'easy-mailchimp-newsletter-embed' ) . '</label>';
						echo '<label><input type="radio" name="turnstile_look" value="always" ' . checked( 'always', $look, false ) . '> ' . esc_html__( 'Always show the "Verify you are human" box', 'easy-mailchimp-newsletter-embed' ) . '</label>';
						echo '</fieldset>';
					}
				);

				self::row(
					__( 'Test', 'easy-mailchimp-newsletter-embed' ),
					function () {
						echo '<button type="button" class="button" id="emne-ts-test">' . self::icon( 'flask-conical', 15 ) . esc_html__( 'Test the keys', 'easy-mailchimp-newsletter-embed' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG and escaped text.
						echo '<div id="emne-ts-test-widget"></div><p id="emne-ts-test-result" class="emne-test-result" role="status"></p>';
						echo '<p class="description">' . esc_html__( 'Runs the widget here with the site key, then checks its token with the secret key, so you know both work before visitors depend on them.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
					}
				);
				?>
			</table>
			<details class="emne-details">
				<summary><?php esc_html_e( 'How to get the keys (free)', 'easy-mailchimp-newsletter-embed' ); ?></summary>
				<ol class="emne-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to the Cloudflare dashboard. */
							esc_html__( 'Log in to the %s (a free account is fine; your site doesn\'t need to use Cloudflare otherwise) and open Turnstile.', 'easy-mailchimp-newsletter-embed' ),
							'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Cloudflare dashboard', 'easy-mailchimp-newsletter-embed' ) . '</a>'
						);
						?>
					</li>
					<li>
						<?php
						/* translators: %s: this site's domain. */
						printf( esc_html__( 'Click "Add widget", name it, and add %s as a hostname. Widget mode "Managed" is right for most sites.', 'easy-mailchimp-newsletter-embed' ), '<code>' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>' );
						?>
					</li>
					<li><?php esc_html_e( 'Copy the site key and secret key into the boxes above, tick "Require Turnstile", save, and run the test.', 'easy-mailchimp-newsletter-embed' ); ?></li>
				</ol>
			</details>
			<?php self::box_end(); ?>
		</form>
		<?php
	}

	/**
	 * Sidebar Save box for the Spam Protection tab (submits the main form).
	 *
	 * @return void
	 */
	private function render_spam_save_box() {
		self::box_start( 'save', __( 'Save', 'easy-mailchimp-newsletter-embed' ), 'emne-save-box' );
		echo '<p>' . esc_html__( 'Changes apply to every signup form.', 'easy-mailchimp-newsletter-embed' ) . '</p>';
		echo '<button type="submit" form="emne-spam-form" class="button button-primary button-large">' . esc_html__( 'Save Changes', 'easy-mailchimp-newsletter-embed' ) . '</button>';
		self::box_end();
	}


	/**
	 * Help.
	 *
	 * @return void
	 */
	private function render_help() {
		self::box_start( 'rocket', __( 'Getting started', 'easy-mailchimp-newsletter-embed' ) );
		?>
		<ol class="emne-steps emne-steps-big">
			<li><strong><?php esc_html_e( 'Connect Mailchimp.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'On the Mailchimp tab, paste an API key. Your audiences load right away.', 'easy-mailchimp-newsletter-embed' ); ?></li>
			<li><strong><?php esc_html_e( 'Create a form.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Pick its audience, its fields and a theme. The preview updates as you go.', 'easy-mailchimp-newsletter-embed' ); ?></li>
			<li><strong><?php esc_html_e( 'Put it on your site.', 'easy-mailchimp-newsletter-embed' ); ?></strong> <?php esc_html_e( 'Use the block, the shortcode or the PHP snippet, or switch on its popup. Make more forms for other audiences or places.', 'easy-mailchimp-newsletter-embed' ); ?></li>
		</ol>
		<?php
		self::box_end();

		$faq = array(
			array(
				__( 'Do people who are already subscribed get an error?', 'easy-mailchimp-newsletter-embed' ),
				__( 'No. They see the same success message as anyone else, so the form can\'t be used to find out who is on your list. Their details in Mailchimp aren\'t changed; they only get the form\'s tags. People who unsubscribed before are sent a confirmation email to rejoin, as Mailchimp requires.', 'easy-mailchimp-newsletter-embed' ),
			),
			array(
				__( 'Where are subscribers stored?', 'easy-mailchimp-newsletter-embed' ),
				__( 'Only in Mailchimp. This plugin keeps signup counts and recent error messages, never email addresses or names. Its text for your privacy policy is under Settings > Privacy.', 'easy-mailchimp-newsletter-embed' ),
			),
			array(
				__( 'Does it work with page caching?', 'easy-mailchimp-newsletter-embed' ),
				__( 'Yes. The forms hold nothing personal and need no login nonce, so cached pages work, and signups go through admin-ajax.php, which caches never store.', 'easy-mailchimp-newsletter-embed' ),
			),
			array(
				__( 'Two popups match the same page. Which one shows?', 'easy-mailchimp-newsletter-embed' ),
				__( 'The older form (lower ID). A page that already has a form embedded skips that form\'s popup.', 'easy-mailchimp-newsletter-embed' ),
			),
			array(
				__( 'Can I track signups in Google Analytics?', 'easy-mailchimp-newsletter-embed' ),
				__( 'With Google Tag Manager on the page, each signup pushes an "emne_subscribe" event (with emneFormId) to the dataLayer, ready for a GA4 tag. Without it, set a thank-you page under "After signing up" and track that page.', 'easy-mailchimp-newsletter-embed' ),
			),
		);

		self::box_start( 'circle-question-mark', __( 'Questions', 'easy-mailchimp-newsletter-embed' ) );
		echo '<div class="emne-faq">';
		foreach ( $faq as $item ) {
			echo '<details><summary>' . esc_html( $item[0] ) . '</summary><p>' . esc_html( $item[1] ) . '</p></details>';
		}
		echo '</div>';
		self::box_end();

		self::box_start( 'code-xml', __( 'For developers', 'easy-mailchimp-newsletter-embed' ) );
		?>
		<table class="widefat striped emne-table emne-dev">
			<tbody>
				<tr><td><code>easy_mailchimp_embed( $id, $display = true )</code></td><td><?php esc_html_e( 'Prints (or returns) a form in a template.', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
				<tr><td><code>[easy_mailchimp_embed id="2"]</code></td><td><?php esc_html_e( 'The shortcode.', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
				<tr><td><code>easy_mailchimp_newsletter_embed_subscribed</code></td><td><?php esc_html_e( 'Action after a signup: ($email, $form, $status), where status is added, pending or exists.', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
				<tr><td><code>easy_mailchimp_newsletter_embed_visitor_ip</code></td><td><?php esc_html_e( 'Filter the visitor IP used for the rate limit, e.g. behind your own proxy.', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
				<tr><td><code>emne:subscribed</code></td><td><?php esc_html_e( 'Browser event on the form\'s wrapper after a signup (bubbles; event.detail.formId).', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
				<tr><td><code>#emne-popup-2</code> / <code>.emne-open-2</code></td><td><?php esc_html_e( 'A link to the hash, or any element with the class, opens form 2\'s popup.', 'easy-mailchimp-newsletter-embed' ); ?></td></tr>
			</tbody>
		</table>
		<?php
		self::box_end();
	}


	/**
	 * Status checklist.
	 *
	 * @return void
	 */
	private function render_status_box() {
		$plugin  = $this->plugin;
		$account = Easy_Mailchimp_Newsletter_Embed_Mailchimp::account();
		$forms   = Easy_Mailchimp_Newsletter_Embed_Forms::all();
		$ready   = count(
			array_filter(
				$forms,
				function ( $form ) {
					return '' !== $form['list_id'];
				}
			)
		);
		$popups  = array_filter(
			$forms,
			function ( $form ) {
				return $form['popup'] && '' !== $form['list_id'];
			}
		);
		$popup   = reset( $popups );
		$signups = Easy_Mailchimp_Newsletter_Embed_Stats::recent_signups();
		$status  = array(
			array( $plugin->is_connected(), $plugin->is_connected() ? ( '' !== $account['name'] ? sprintf( /* translators: %s: account name. */ __( 'Connected to %s', 'easy-mailchimp-newsletter-embed' ), $account['name'] ) : __( 'Mailchimp connected', 'easy-mailchimp-newsletter-embed' ) ) : __( 'Mailchimp not connected', 'easy-mailchimp-newsletter-embed' ) ),
			/* translators: 1: forms with an audience, 2: all forms. */
			array( $ready > 0, $forms ? sprintf( _n( '%1$d of %2$d form ready', '%1$d of %2$d forms ready', count( $forms ), 'easy-mailchimp-newsletter-embed' ), $ready, count( $forms ) ) : __( 'No signup forms yet', 'easy-mailchimp-newsletter-embed' ) ),
			/* translators: %s: form name. */
			array( (bool) $popup, $popup ? sprintf( __( 'Popup: %s', 'easy-mailchimp-newsletter-embed' ), $popup['name'] ) : __( 'No popup', 'easy-mailchimp-newsletter-embed' ) ),
			array( true, __( 'Built-in spam protection on', 'easy-mailchimp-newsletter-embed' ) ),
			array( $plugin->turnstile_on(), $plugin->turnstile_on() ? __( 'Cloudflare Turnstile on', 'easy-mailchimp-newsletter-embed' ) : __( 'Cloudflare Turnstile off', 'easy-mailchimp-newsletter-embed' ) ),
			/* translators: %s: number of signups. */
			array( $signups > 0, sprintf( _n( '%s signup in the last 30 days', '%s signups in the last 30 days', $signups, 'easy-mailchimp-newsletter-embed' ), number_format_i18n( $signups ) ) ),
		);

		self::box_start( 'activity', __( 'Status', 'easy-mailchimp-newsletter-embed' ) );
		?>
		<ul class="emne-checklist">
			<?php foreach ( $status as $item ) : ?>
				<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
		self::box_end();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( EASY_MAILCHIMP_NEWSLETTER_EMBED_REPO ) );
		$check_url  = Easy_Mailchimp_Newsletter_Embed_Updater::check_now_url();

		self::box_start( 'refresh-cw', __( 'Updates', 'easy-mailchimp-newsletter-embed' ), 'emne-updates-box' );
		?>
		<p>
			<?php
			/* translators: %s: installed plugin version. */
			printf( esc_html__( 'Installed version: %s', 'easy-mailchimp-newsletter-embed' ), '<strong>' . esc_html( EASY_MAILCHIMP_NEWSLETTER_EMBED_VERSION ) . '</strong>' );
			?>
		</p>

		<?php if ( ! Easy_Mailchimp_Newsletter_Embed_Updater::has_repo() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: header name, 2: main plugin file. */
					esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'easy-mailchimp-newsletter-embed' ),
					'<code>Update URI</code>',
					'<code>' . esc_html( plugin_basename( EASY_MAILCHIMP_NEWSLETTER_EMBED_FILE ) ) . '</code>'
				);
				?>
			</p>
		<?php elseif ( Easy_Mailchimp_Newsletter_Embed_Updater::is_dev_copy() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: repo, 2: constant name. */
					esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'easy-mailchimp-newsletter-embed' ),
					'<a href="' . esc_url( EASY_MAILCHIMP_NEWSLETTER_EMBED_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
					'<code>EASY_MAILCHIMP_NEWSLETTER_EMBED_DEV_MODE</code>'
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: repo. */
					esc_html__( 'Updates come from the latest release of %s.', 'easy-mailchimp-newsletter-embed' ),
					'<a href="' . esc_url( EASY_MAILCHIMP_NEWSLETTER_EMBED_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
				);
				?>
			</p>
			<?php if ( $check_url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'easy-mailchimp-newsletter-embed' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		self::box_end();
	}
}

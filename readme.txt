=== Easy Mailchimp Newsletter Embed ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: mailchimp, newsletter, signup form, email, popup
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mailchimp signup forms you can embed with a shortcode, block or PHP snippet, or show as a popup. Multiple forms, six themes and optional Cloudflare Turnstile.

== Description ==

A dead simple way to grow your Mailchimp list. Connect your account with an API key, make a signup form (name and email, or just email), pick a theme, and put it anywhere: a block, a shortcode, a line of PHP in your theme, or a popup that opens on its own.

* **As many forms as you like**, each sending people to its own Mailchimp audience with its own tags, fields, text and look.
* **Six themes**: Light, Dark, Minimal, Bold, Soft, and Plain (which takes on your theme's own styles). Pick an accent color and a stacked or one-row layout, and watch the live preview as you edit.
* **Popups** that open after a few seconds, after scrolling, on exit intent, or only when a link is clicked. Center or corner, every page or just some, phones or computers, and a "don't show again for X days" setting. People who subscribe never see them again.
* **Double opt-in** by default, plus optional tags, a consent checkbox, small print and a thank-you page redirect.
* **Spam protection built in**: a hidden bot field, a speed check, per-visitor and per-inbox limits, and no links allowed in names. If spam still gets through, switch on **Cloudflare Turnstile** (free) and test your keys right from the settings page.
* **Private and safe**: subscribers are stored only in Mailchimp. The API key is stored encrypted and never shown again. People already on your list see the same message as new subscribers, and their details are never changed by the form.
* **Works with page caching** and with plugins that switch the REST API off.

Find it under Content Powered > Easy Mailchimp Newsletter Embed in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= For developers =

* `easy_mailchimp_embed( $id, $display = true )` prints (or returns) a form.
* `[easy_mailchimp_embed id="2"]` is the shortcode.
* Action `easy_mailchimp_newsletter_embed_subscribed` ( $email, $form, $status ) runs after each signup.
* Filter `easy_mailchimp_newsletter_embed_visitor_ip` changes the IP used for the rate limit.
* In the browser, an `emne:subscribed` event bubbles from the form, and an `emne_subscribe` event is pushed to the Google Tag Manager dataLayer.
* A link to `#emne-popup-2`, or any element with the class `emne-open-2`, opens form 2's popup.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners. Mailchimp is a trademark of The Rocket Science Group LLC; this plugin isn't made or endorsed by Mailchimp.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `easy-mailchimp-newsletter-embed` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Easy Mailchimp Newsletter Embed, open the Mailchimp tab and paste an API key (in Mailchimp: profile icon > Profile > Extras > API keys > Create A Key).
4. On the Signup Forms tab, click "Add form", pick an audience and a theme, and save.
5. Add the "Mailchimp Signup Form" block to a page, paste the form's shortcode, or switch on its popup.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Do I need a paid Mailchimp plan? =

No. API keys and audiences work on every Mailchimp plan, including the free one.

= What happens when someone who's already subscribed signs up again? =

They see the normal success message and their details in Mailchimp stay as they were (they only get the form's tags). People who unsubscribed earlier are sent a confirmation email to rejoin, which Mailchimp requires.

= Is Cloudflare Turnstile required? =

No. The built-in protection is enough for most sites. Turnstile is there for when bots still get through; your site doesn't need to use Cloudflare otherwise.

= Does it store email addresses on my site? =

No. Subscribers go straight to Mailchimp. The plugin keeps only signup counts and recent error messages (with addresses removed). Suggested privacy policy text is under Settings > Privacy.

== Changelog ==

See https://github.com/jmsprsns/easy-mailchimp-newsletter-embed/releases for the full release history.

= 1.0.1 =
* The Updates box no longer overflows on development copies.
* Update-screen icon matches the plugin's color on contentpowered.com.

= 1.0.0 =
* First release.

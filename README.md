# Easy Mailchimp Newsletter Embed

Mailchimp signup forms you can embed with a shortcode, block or PHP snippet, or show as a popup. Make as many forms as you like, each going to its own audience with its own tags, fields and text, in one of six themes (Light, Dark, Minimal, Bold, Soft and Plain). Optional Cloudflare Turnstile for sites that still get spam signups.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## How it works

- **Connection** (`class-easy-mailchimp-newsletter-embed-mailchimp.php`). Mailchimp Marketing API v3 with an API key, stored encrypted with libsodium (`class-easy-mailchimp-newsletter-embed-crypto.php`, key from the site's auth salts). Requests only ever go to `{dc}.api.mailchimp.com`, where the data center comes from the key and must match `us` plus digits, so a crafted key can't point a request elsewhere. Audiences are cached in `easy_mailchimp_newsletter_embed_account` and refreshed from the Mailchimp tab.
- **Forms** (`class-easy-mailchimp-newsletter-embed-forms.php`) live in one autoloaded option, `easy_mailchimp_newsletter_embed_forms`, so the front end never queries for them. The same class decides which popup (if any) belongs on a page; URL rules use a hand-written wildcard matcher, not a regex, so a visitor's long URL can't make it backtrack.
- **Front end** (`class-easy-mailchimp-newsletter-embed-render.php`, `assets/form.js`, `assets/form.css`). Shortcode `[easy_mailchimp_embed id="2"]`, the `easy-mailchimp-newsletter-embed/form` block (`assets/block.js`, no build step) and `easy_mailchimp_embed( 2 )` all draw the same markup. Settings ride along in a `data-emne` attribute instead of an inline script, so script optimizers can't separate a form from its settings. The CSS loads in `<head>` when a popup or a shortcode/block in the post is known up front, otherwise where the form prints. Turnstile's script is only loaded when someone clicks into a form.
- **Signups** (`class-easy-mailchimp-newsletter-embed-subscribe.php`) go to `admin-ajax.php?action=emne_subscribe`, because security and speed plugins often switch the REST API off for visitors. The visitor sends only a form ID; the audience, tags and opt-in type come from that form's settings. Checks, cheapest first: real POST from this site (Origin, Referer, Sec-Fetch-Site), a hidden bot field, time on screen, address and name (no links in names), 3 signups per inbox per hour (Gmail dots and `+tags` count as one inbox), a per-visitor limit (10 an hour by default; the real IP behind Cloudflare, skipped behind a proxy it can't see past, see `class-easy-mailchimp-newsletter-embed-visitor.php`), then Turnstile when it's on.
- **Existing subscribers** get the same success message as new ones (the form can't be used to look addresses up), and their details are never edited: subscribed and pending ones only get the form's tags, unsubscribed or archived ones get a confirmation email to rejoin.
- **Stats** (`class-easy-mailchimp-newsletter-embed-stats.php`): daily signup and blocked-spam counts for 30 days plus the last 20 Mailchimp errors, with email addresses replaced by `[email]`. No subscriber data is stored on the site.

### Hooks

- `easy_mailchimp_newsletter_embed_subscribed` (action): `$email, $form, $status` after each signup (`added`, `pending` or `exists`).
- `easy_mailchimp_newsletter_embed_visitor_ip` (filter): the IP used for the rate limit and sent to Mailchimp as the signup IP.
- In the browser: an `emne:subscribed` event bubbles from the form (`event.detail.formId`), and `{event: 'emne_subscribe', emneFormId}` is pushed to `dataLayer` when it exists. `window.emneInit()` sets up forms added to the page later.

## Automatic updates

Updates come from https://github.com/jmsprsns/easy-mailchimp-newsletter-embed through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `easy-mailchimp-newsletter-embed.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `easy-mailchimp-newsletter-embed.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'EASY_MAILCHIMP_NEWSLETTER_EMBED_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
easy-mailchimp-newsletter-embed.php   Plugin header, constants, boot, easy_mailchimp_embed()
uninstall.php                         Deletes the plugin's options and transients when it's deleted
readme.txt                            WordPress readme; feeds the "View details" popup
includes/
  class-...-plugin.php                Main singleton, global settings, privacy policy text
  class-...-admin.php                 The settings page: forms, editor, Mailchimp, spam, help
  class-...-forms.php                 Form storage, cleaning, popup page rules
  class-...-mailchimp.php             Mailchimp API client
  class-...-subscribe.php             The public signup handler and its checks
  class-...-render.php                Form and popup markup, shortcode, block, assets
  class-...-turnstile.php             Cloudflare Turnstile token checks
  class-...-visitor.php               Visitor IP (Cloudflare-aware, spoof-proof)
  class-...-stats.php                 Signup counts, stopped spam, recent errors
  class-...-crypto.php                Encrypts the API key and Turnstile secret
  class-...-menu.php                  Shared top-level "Content Powered" menu
  class-...-feedback.php              Shared Submit Feedback and Report a Bug pages
  class-...-updater.php               GitHub update checks (PUC wiring)
  class-...-icons.php                 Inline Lucide and Simple Icons helper
assets/
  form.css, form.js                   Front-end themes, signups and popups
  block.js                            The block editor block
  admin.css, admin.js                 Loaded only on the settings page
  icon.svg                            Icon on the update screens
  content-powered.svg                 Sumo logo for the Content Powered menu
icons/                                The Lucide and Simple Icons this plugin uses (plus LICENSE)
plugin-update-checker/                Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-easy-mailchimp-newsletter-embed-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-easy-mailchimp-newsletter-embed-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/` (copy more in from the full set when you need them). Print one inline with:

```php
echo Easy_Mailchimp_Newsletter_Embed_Icons::get( 'package', 20 );           // name, size in px
echo Easy_Mailchimp_Newsletter_Embed_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.

=== YoRo AI Core ===
Contributors: yoohw
Tags: woocommerce, ai, operations, orders, automation
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

YoRo AI Core connects WooCommerce stores to YoRo AI API for order operations, workflows, customer email drafting, and admin suggestions.

== Description ==

YoRo AI Core is the WordPress plugin runtime for YoRo AI.

The plugin does not accept direct third-party AI provider keys. AI routing, workflow planning, customer email composition, and AI suggestions are handled by YoRo AI API through a site API key.

Core capabilities:

* Route admin commands through YoRo AI API.
* Search and inspect WooCommerce orders.
* Prepare guarded order actions with preview and confirmation flows.
* Draft customer emails through YoRo AI API.
* Execute supported workflow steps locally in WooCommerce.
* Track usage and plan capability data returned by YoRo AI API.

== Requirements ==

* WordPress 6.4 or newer.
* PHP 7.4 or newer.
* WooCommerce installed and active.
* A YoRo AI API base URL.
* A site API key issued by YoRo AI API.

== Installation ==

1. Upload the `yoro-ai-core` plugin folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Make sure WooCommerce is installed and active.
4. Open `YoRo AI > Settings`.
5. Enter the YoRo AI API base URL and site API key.
6. Click `Test YoRo AI API Connection`.
7. Save settings after the connection is verified.

== Configuration ==

The preferred configuration path is the plugin settings screen.

For locked production configuration, define constants before WordPress loads plugins:

`
define( 'YORO_AI_API_URL', 'https://your-yoro-ai-api.example/api/v1' );
define( 'YORO_AI_API_SITE_KEY', 'your-site-api-key' );
`

Never commit real site API keys to source control.

== Frequently Asked Questions ==

= Does this plugin use direct AI provider keys? =

No. The plugin connects only to YoRo AI API. Provider keys and prompts are managed by YoRo AI API.

= What happens if WooCommerce is not active? =

YoRo AI Core will not boot its runtime. Administrators will see a notice explaining that WooCommerce is required.

= Can development test files be excluded from release packages? =

Yes. The repository includes `.distignore` rules for omitting development docs and debug test fixtures from release packages.

== Changelog ==

= 0.1.5 =
* Routed AI features through YoRo AI API.
* Removed direct third-party AI key support from the plugin.
* Removed plugin-side prompt builder classes.
* Added WooCommerce dependency guard.
* Added release packaging rules.

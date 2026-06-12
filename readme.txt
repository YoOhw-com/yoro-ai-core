=== YoRo AI Core ===
Contributors: yoohw
Tags: woocommerce, ai, operations, orders, automation
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

YoRo AI Core connects stores to YoRo AI API for fully AI-first chat, structured store context, approved action plans, local execution, and customer email drafting.

== Description ==

YoRo AI Core is the WordPress plugin runtime for YoRo AI.

The plugin does not accept direct third-party AI provider keys. Conversation reasoning, multilingual semantic routing, action planning, memory, debug traces, capability boundaries, and customer email composition are handled by YoRo AI API through a site API key.

The plugin is intentionally not the conversation brain. It provides structured store data, exposes an action catalog, renders structured assistant responses, executes approved actions locally, and reports execution events back to YoRo AI API.

Core capabilities:

* Send YoRo Chat messages to YoRo AI API conversation brain.
* Provide structured store context, WooCommerce settings, statuses, and action capabilities.
* Search and inspect store orders.
* Prepare guarded order actions with preview and confirmation flows.
* Draft customer emails through YoRo AI API.
* Execute approved action-plan steps locally in the store.
* Report action execution events back to YoRo AI API for conversation history and debug.

== Requirements ==

* WordPress 6.4 or newer.
* PHP 7.4 or newer.
* Store commerce plugin installed and active.
* A YoRo AI API base URL.
* A site API key issued by YoRo AI API.

== Installation ==

1. Upload the `yoro-ai-core` plugin folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Make sure the store commerce plugin is installed and active.
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

= What happens if the store commerce plugin is not active? =

YoRo AI Core will not boot its runtime. Administrators will see a notice explaining that the store commerce plugin is required.

= Can development test files be excluded from release packages? =

Yes. The repository includes `.distignore` rules for omitting development docs and debug test fixtures from release packages.

== Changelog ==

= 0.1.6 =
* Restored Vietnamese response copy for YoRo AI API routed order searches.
* Added language context to YoRo AI API requests.
* Localized the orders list UI when YoRo detects Vietnamese commands.

= 0.1.5 =
* Routed AI features through YoRo AI API.
* Removed direct third-party AI key support from the plugin.
* Removed plugin-side prompt builder classes.
* Added store dependency guard.
* Added release packaging rules.

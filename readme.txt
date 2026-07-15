=== YoRo AI Core ===
Contributors: yoohw
Tags: woocommerce, ai, operations, orders, automation
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to YoRo AI API for guarded store chat, action previews, local execution, and customer email drafting.

== Description ==

YoRo AI Core is the WordPress and WooCommerce runtime for YoRo AI.

The plugin does not accept direct third-party AI provider keys. Conversation reasoning, multilingual semantic routing, action planning, memory, debug traces, capability boundaries, and customer email composition are handled by YoRo AI API through a site API key.

The plugin is intentionally not the conversation brain. It provides structured WooCommerce store data, exposes an action catalog, renders structured assistant responses, executes approved actions locally, and reports execution events back to YoRo AI API.

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
define( 'YORO_AI_API_URL', 'https://your-yoro-ai-api-service.example/api/v1' );
define( 'YORO_AI_API_SITE_KEY', 'your-site-api-key' );
`

Never commit real site API keys to source control.

== External Services ==

YoRo AI Core connects to the YoRo AI API URL configured by an administrator. No external request is made until a YoRo AI API URL and site key are configured, or an administrator starts the YoRo account connection flow from the settings screen.

The configured YoRo AI API service is used to understand chat requests, create guarded action plans, draft customer emails, connect and sync optional shop mailboxes, store conversation history, and return structured responses to WordPress.

Depending on the request, the plugin may send the site URL, plugin version, language settings, WooCommerce settings, order status data, order details, product data, customer names and email addresses, customer email content, mailbox connection details entered by an administrator, chat instructions, approved action metadata, and execution results to the configured YoRo AI API service.

YoRo AI service information is available at https://yorohq.com/.

YoRo AI Terms of Service: https://yorohq.com/terms/

YoRo AI Privacy Policy: https://yorohq.com/privacy/

The site owner is responsible for configuring a YoRo AI API endpoint that matches their account or hosting arrangement.

== Privacy ==

YoRo AI Core stores its service URL, saved site key option, connection status, local action history, safety settings, email templates, and email draft metadata in the WordPress database. The plugin also adds suggested privacy policy text in WordPress under Settings > Privacy when available.

== Frequently Asked Questions ==

= Does this plugin use direct AI provider keys? =

No. The plugin connects only to YoRo AI API. Provider keys and prompts are managed by YoRo AI API.

= What happens if WooCommerce is not active? =

YoRo AI Core will not boot its runtime. Administrators will see a notice explaining that WooCommerce is required.

= Can development test files be excluded from release packages? =

Yes. The repository includes `.distignore` rules for omitting development docs, generated archives, and debug test fixtures from release packages.

== Changelog ==

= 0.1.8 =
* Completed the AI-first conversation bridge and structured action contract cleanup.
* Simplified mailbox thread views around the customer conversation and reply composer.
* Added release smoke coverage for the finalized mailbox thread surface.

= 0.1.7 =
* Added WordPress.org submission metadata, privacy policy text, and clearer external service documentation.
* Removed the development API URL default from public builds.
* Removed the internal developer-request email action from the public action catalog.
* Hardened YoRo account connection redirects to the configured API host.
* Tightened release package exclusion rules.

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

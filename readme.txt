=== YoRo Connector for WordPress ===
Contributors: yoohw
Tags: wordpress, ai, content, site-management, woocommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to YoRo for guarded site inspection, content management, local execution, and optional WooCommerce assistance.

== Description ==

YoRo Connector for WordPress is the local WordPress runtime for YoRo. WooCommerce is an optional module.

The plugin does not accept direct third-party AI provider keys. Conversation reasoning, multilingual semantic routing, action planning, memory, debug traces, capability boundaries, and customer email composition are handled by YoRo AI API through a site API key.

The plugin is intentionally not the conversation brain. It provides structured WordPress data, exposes a capability catalog, renders structured assistant responses, executes approved actions locally, and reports execution events back to YoRo. When WooCommerce is active, existing store-assistant capabilities continue to load.

Core capabilities:

* Send YoRo Chat messages to YoRo AI API conversation brain.
* Provide WordPress site, content, taxonomy, comment, media, plugin, theme, and update context.
* Execute guarded content workflows with optimistic versions, confirmation, and idempotency.
* Expose policy-gated WordPress administration actions that require a recent backup attestation.
* Provide structured WooCommerce settings, statuses, and store capabilities when WooCommerce is active.
* Search and inspect store orders.
* Prepare guarded order actions with preview and confirmation flows.
* Draft customer emails through YoRo AI API.
* Execute approved action-plan steps locally in the store.
* Report action execution events back to YoRo AI API for conversation history and debug.

== Requirements ==

* WordPress 6.4 or newer.
* PHP 7.4 or newer.
* A YoRo AI API base URL.
* A site API key issued by YoRo AI API.

WooCommerce is optional and is required only for YoRo store-management features.

== Installation ==

1. Upload the `yoro-ai-core` plugin folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Open `YoRo AI > Settings`.
4. Start the YoRo account connection flow or enter the YoRo API base URL and site key.
5. Click `Test YoRo AI API Connection`.
6. Save settings after the connection is verified.

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

Depending on the granted capability and request, the plugin may send the site URL, plugin version, language settings, WordPress site and update metadata, content, taxonomies, comments, media metadata, installed plugin and theme metadata, acting-user permissions, approved action metadata, and execution results. If WooCommerce is active and a store capability is used, WooCommerce settings, orders, products, customer information, and email content may also be sent.

YoRo AI service information is available at https://yorohq.com/.

YoRo AI Terms of Service: https://yorohq.com/terms/

YoRo AI Privacy Policy: https://yorohq.com/privacy/

The site owner is responsible for configuring a YoRo AI API endpoint that matches their account or hosting arrangement.

== Privacy ==

YoRo Connector for WordPress stores its service URL, encrypted site credential, connection and module status, local action history, safety settings, idempotency records, and optional email draft metadata in the WordPress database. The plugin also adds suggested privacy policy text in WordPress under Settings > Privacy when available.

== Frequently Asked Questions ==

= Does this plugin use direct AI provider keys? =

No. The plugin connects only to YoRo AI API. Provider keys and prompts are managed by YoRo AI API.

= What happens if WooCommerce is not active? =

The WordPress connector, content, media, comment, and site-management runtime continues to work. WooCommerce-specific actions are simply omitted from the capability manifest.

= Can AI clients run arbitrary code or change any WordPress option? =

No. The connector does not expose arbitrary PHP, SQL, WP-CLI, file editing, or unrestricted option writes. Every operation must exist in the current capability manifest and pass WordPress capability checks.

= Can development test files be excluded from release packages? =

Yes. The repository includes `.distignore` rules for omitting development docs, generated archives, and debug test fixtures from release packages.

== Changelog ==

= 0.2.2 =
* Drain up to five connector deliveries within a bounded 45-second runtime tick.

= 0.2.1 =
* Omit unavailable WooCommerce-only context fields on plain WordPress sites.

= 0.2.0 =
* Made WordPress the primary connector and WooCommerce an optional module.
* Added WordPress site, health, update, taxonomy, revision, media, plugin, theme, and user capabilities.
* Added guarded content lifecycle and bounded administration actions.
* Added module discovery while preserving legacy WooCommerce-keyed connections.

= 0.1.9 =
* Added canonical WooCommerce capability manifest and context-pack publication.
* Added the gated pull connector runtime with fenced leases, actor mapping, execution journaling, and canonical result reporting.
* Added connector contract smoke coverage while keeping production live cutover disabled.

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

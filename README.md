# Twee MCP

**Twee MCP** is a Model Context Protocol (MCP) server for WordPress and WooCommerce. AI clients such as Claude, Claude Code, and ChatGPT connect to it over OAuth 2.1 and work with the site's posts, terms, media, ACF blocks, menus, comments, products, and orders through the permissions of the WordPress user who approved the connection.

The plugin also connects the site to an AI model of its own (OpenAI, OpenRouter, Anthropic, or an experimental ChatGPT plan sign-in) and uses it for batch jobs: excerpts, content, SEO metadata, Alt text, featured images, categories, and product copy.

## Features

### 1. MCP Server

- Streamable HTTP endpoint at `/wp-json/straps/v1/mcp` with JSON-RPC 2.0 and protocol version `2025-06-18`.
- Supports `initialize`, `ping`, `tools/list`, `tools/call`, and `prompts/list`.
- Each tool carries `readOnlyHint` and `destructiveHint` annotations, so clients can ask before writing or deleting.
- Results are compact JSON to keep token usage low. Image previews are returned as small MCP image blocks.
- Tool groups switch off by themselves when the plugin they need is inactive, for example the WooCommerce groups.
- Modules, such as WooCommerce or Activity Log, and single tools can be switched off on the **Agent Tools** screen, and the whole server can be turned off on the **Agent Access** screen.

### 2. OAuth 2.1 Authorization

- Discovery metadata at `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`, and `/.well-known/openid-configuration`. Many hosts answer `/.well-known/` themselves, so the same documents are served under `/wp-json/straps/v1/` and after the MCP URL, and the issuer is `/wp-json/straps/v1`. The 401 reply of the MCP endpoint points there.
- Dynamic client registration (RFC 7591) at `/register`, a WordPress consent screen at `/authorize`, and token exchange at `/token`.
- Authorization code flow with PKCE (`S256`) and rotating refresh tokens. The `offline_access` scope is advertised, so ChatGPT keeps its refresh token and stays connected.
- Client IDs are stateless signed payloads. Tokens are stored hashed in the `twcp_sessions` user meta.
- Access tokens last 1 hour, refresh tokens 90 days, and authorization codes 5 minutes.
- Redirect hosts are limited to an allowlist. The default list is `claude.ai`, `claude.com`, `anthropic.com`, `chatgpt.com`, and `openai.com`.
- Connected agents and active sessions are listed on the **Agent Access** screen, where they can be revoked.

### 3. Permissions

- Connecting requires the `edit_posts` capability (`TWCP_CAPABILITY`).
- Every tool call is checked against the capabilities of the connected user. Publishing needs the post type's `publish_posts` capability. Comments need `moderate_comments`, menus `edit_theme_options`, orders `edit_shop_orders`, and coupons `edit_shop_coupons`.
- Some tools have their own capability on top, for example `get_sales_summary` with `view_woocommerce_reports`, and `generate_block_descriptions` and the Activity Log tools with `manage_options`.

### 4. AI Provider

The **AI Provider** screen sets up the AI service the plugin itself uses to write content.

- Providers: **OpenAI**, **OpenRouter**, **Anthropic**, and **ChatGPT Plan (Experimental)**.
- Separate text and vision models. Images are scaled down to a chosen edge size before they are sent, to keep vision costs low.
- Model lists load from the provider's API and are cached for a day.
- During generation, the model can call the read-only tools `get_products`, `get_terms`, and `get_posts` to look up products, categories, posts, and links the prompt does not give. The prompt names them, and the content, similar post, term, and full product description generators offer them. Private tools are never offered to it.
- OpenAI and ChatGPT Plan requests use the Responses API, and the system prompts open with the shared context, so it stays in the provider's prompt cache across jobs. OpenAI responses are stored, so each lookup round continues the previous response with only the tool results and the model keeps its reasoning. Plan responses cannot be stored, so their encrypted reasoning is sent back instead. OpenRouter uses Chat Completions, and Anthropic the Messages API with cached system prompts and tools.
- **ChatGPT Plan** signs in with a ChatGPT account and spends that account's plan limits instead of an API key. OpenAI only returns the sign-in to `http://127.0.0.1:1455/auth/callback`. The page there does not load, which is expected: copy its full address from the browser and paste it into the plugin. No local server is needed. OpenAI offers plan usage to open-source tools and local personal projects. A public or client site needs approval through the [OpenAI interest form](https://openai.com/form/sign-in-with-chatgpt-interest/).

### 5. Batch Automation

The **Automation** screen lists what needs attention and runs batch jobs in small slices. Each text job has a mode:

- **Fill empty:** only items that lack the value. This is the default.
- **Refine existing:** polishes the current values and keeps their meaning, facts, and length.
- **Rewrite if poor:** the model rates the current values of each slice, rewrites the poor ones, and keeps the rest.
- **Rewrite all:** writes every value from scratch.

All modes fill empty items. Jobs that assign images, categories, cross-sells, or block descriptions only offer **Fill empty** and **Redo all**. Until an API key is added or a ChatGPT account is connected, the screen shows a setup notice and the jobs and AI bulk actions stay locked.

- **Estimate:** each card shows a rough number of AI requests and tokens for the items left in the chosen mode.
- **Preview:** below the instructions of a card, **Preview** runs the job on one item of its scope and mode, with the instructions and the Shared Context as they are on the screen, unsaved edits included. It shows the current value, the model's reply, and the prompt sent, and saves nothing, so prompts can be tuned before a run.
- **Combined requests:** Short Excerpts, SEO Titles & Meta Descriptions, term SEO tags, and short product descriptions write the 5 items of a slice with one request that returns JSON keyed by ID. An item the reply misses sends its own request.
- **Undo Last Run:** before a job changes an item, it saves the item's current values. Undo puts them back. Starting a new run replaces the saved values.
- **Background runs:** when Action Scheduler is available (it ships with WooCommerce), jobs run on the server as the user who started them, so the page can be closed. The screen follows their progress. Without it, jobs run while the page stays open, and a running job carries on when the screen is opened again. The `twcp_batch_background` filter turns background runs off.

- **Content Automation:** short excerpts, post content, SEO titles and descriptions, featured images, categories, term SEO, and term descriptions.
  - **Term descriptions** fill the short description WordPress shows on category, tag, and brand archives.
  - **Full term descriptions** and **term FAQs** write to an ACF field on terms, chosen on the card. Full descriptions go to a WYSIWYG or textarea field. FAQs go to a repeater with a text question and a text, textarea, or WYSIWYG answer. These cards appear when such a field exists.
  - **Internal links:** full content and similar posts get a list of approved links: the post's categories and tags, related posts in the same categories, and optionally top-level pages and store categories. The model can also look up other posts and terms. Any other link it writes is removed and its text kept. The **Internal Links** panel sets the sources and the maximum number of links.
- **Media Automation:** Alt text from the vision model.
- **Store Automation:** product descriptions, short descriptions, and cross-sells (WooCommerce only).
- **Site Setup:** AI descriptions of the ACF page builder layouts, which help agents choose the right blocks.

Users with `edit_others_posts` can run the content jobs, `upload_files` the media job, and `edit_others_products` the store jobs. The rest of the screens need `manage_options`.

### 6. Prompts & Block Conventions

- Administrators edit the instructions for every generator on the **Automation** screen, below the progress of the job that runs with them, such as the excerpt prompt on the Short Excerpts card and the copy rules with the full product description prompt. Prompts no job uses, such as the similar post prompt of `create_similar_post`, stay in their section. Each prompt saves on its own and can be reset to its default.
- The default prompts follow the Google Search, Bing Webmaster, and Merchant Center guidance: distinct, descriptive titles of 50 to 60 characters, meta descriptions of 120 to 155 characters that summarize the page, descriptive headings, specific facts instead of hype, no prices or shipping terms in product descriptions, and the language of the content. Lengths and style live only in the prompts, so an edited prompt is not overruled by the request.
- The **Shared Context** at the top of the Automation screen is built by default from the site name, tagline, main categories, and whether WooCommerce is active. It's added to every prompt, so describe the business, audience, and tone there. **Generate from website** drafts a 5 to 6 sentence profile from the site name, tagline, home page, main categories, and recent posts or products; review it, then save.
- **Target Keywords** in the Shared Context list the search phrases the site wants to rank for, one per line. They follow the site context in every prompt, which asks the model to work them in where they fit, and `get_schema` hands them to agents.
- The focus keywords of a post, product, or term, from Rank Math or else Yoast SEO, go into the prompts that write its excerpt, content, SEO tags, descriptions, and FAQ, with the main one first. `get_posts` and `get_terms` return them as `focus_keyword`.
- The **Blocks** screen keeps the block conventions that `get_block_types` hands to agents. The conventions explain page structure, layout options, query layouts, repeaters, and text formats of the theme's ACF blocks.

## Tools

| Group | Tools |
|---|---|
| Content & Posts | `get_posts`, `get_schema`, `create_post`, `edit_post`, `create_similar_post`, `trash_post` |
| Taxonomies | `get_terms`, `create_term`, `edit_term`, `assign_terms` |
| Comments | `get_comments`, `moderate_comment`, `reply_comment` |
| Media Library | `get_images`, `preview_images`, `edit_media`, `import_media_image`, `assign_media_image`, `check_media_alt_text`, `build_gallery_shortcode` |
| Stock Images | `search_stock_images` |
| ACF Blocks | `get_block_types`, `get_custom_fields`, `get_blocks`, `create_block`, `edit_block`, `remove_block`, `generate_block_descriptions` (only when ACF Pro is active) |
| Site & Menus | `get_site_info`, `get_users`, `get_menus`, `add_menu_item`, `remove_menu_item` |
| WooCommerce Products | `get_products`, `create_product`, `edit_product`, `get_product_variations`, `save_product_variation`, `select_related_products`, `generate_product_description`, `generate_product_short_description` |
| WooCommerce Orders & Coupons | `get_orders`, `update_order`, `get_sales_summary`, `get_coupons`, `save_coupon` |
| Activity Log | `get_activity_summary`, `get_brand_activity`, `get_user_activity`, `get_item_history`, `get_activity_events`, `get_login_activity`, `get_activity_event_types` (only when [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) is active) |
| Discovery & Search | `search`, `fetch` (compatible with ChatGPT connectors) |

`trash_post` moves content to the Trash and never deletes it permanently. `remove_block` and `remove_menu_item` are marked as destructive.

`get_posts` and `get_products` filter by a term ID alone, which finds its own taxonomy, and refuse post types the site does not have. `get_products` also sorts by `price` and `popularity` (total sales). Products return their attributes in the format `create_product` and `edit_product` take: the name, the `pa_` taxonomy of a store-wide attribute, and the option names. A store-wide attribute is matched by its name, such as "Color" or `pa_color`, so its terms are linked and missing ones created. `attributes` replaces the whole list. The order tools refuse a status the store does not have and list the ones it has. The generation tools say whether they wrote new text (`generated`) and pass on the model's error when they fail.

`get_comments` lists product reviews along with comments, and setting the status a comment already has succeeds. `edit_term` and `assign_media_image` find the taxonomy from the term when it is omitted.

The Activity Log tools read the events WP Activity Log records, shop managers by default:

- `get_activity_summary` lists, per user, the items published, those published for the first time (`published_new`), the price updates (saves that changed a product's prices), the active days, sessions, and time. Users who published most come first. For each post type, such as products and materials, it counts the items opened, edited, created, published, trashed, repriced, and given other terms, and the saves. It also counts term changes, media uploads, and logins. `tracked_minutes` is the measured editing time (see Activity Tracking below). Active time adds the gaps between logged actions up to 15 minutes, so work outside WordPress is not counted. It is a lower bound, best for comparing people. `span_hours`, from the first to the last action of each day, is the upper bound. Logins of one account working at the same time show up as `parallel_sessions`, a sign of a shared account.
- `get_brand_activity` groups the items people worked on by any taxonomy, such as `product_brand`, `product_cat`, or `pa_collection`, with the users behind each group. It uses the items' current terms. Items of a type without that taxonomy are matched by the name of their top-level term, so materials in a collection named after a brand count for that brand.
- `get_user_activity` shows one person's days, login sessions, and the items they worked on with their brand or other terms.
- `get_item_history` shows who opened, changed, or published one product, material, or page, with the old and new values of each change.
- `get_activity_events` lists events newest first, filtered by user, role, item, event ID, or action, with plain-text messages and details.
- `get_login_activity` reports logins, failed logins, IP addresses, devices, and sessions signed in now, plus failed logins with unknown usernames.
- `get_activity_event_types` lists the event types the plugin records, whether each is enabled, and how often each was logged.

The brand, user, and item reports add `tracked_minutes` too.

### Activity Tracking

On the Agent Tools screen, Activity Tracking logs what WP Activity Log does not record, for the roles you choose, as WP Activity Log events, so it keeps them, applies its retention, and shows them in its log. It is off until you pick a role, and needs WP Activity Log.

- **Editing time.** Every wp-admin screen counts the seconds its tab is in front and was used in the last two minutes. It logs them as WP Activity Log event 99102 when the tab is hidden or closed, and every ten minutes on long visits, with the item or screen. The reports add these seconds up as `tracked_minutes`. Only the tab in front counts, but two devices used at once both do. Work outside wp-admin is not counted.
- **Term changes.** Changes of brands, tags, material collections, and other terms of an item are logged as WP Activity Log event 99100, with the terms before and after. Categories, attributes, and prices are left to WP Activity Log, which logs them already.
- **Custom prices.** Changes of the meta fields named under Price Fields, such as price tables a theme keeps in meta, are logged as event 99101 with the numbers before and after. They count as price updates, like WooCommerce price changes.

Let the people you track know about it.

`get_users` in Site & Menus finds users by name, email, login, or role. It needs the `list_users` capability. It and the Activity Log tools that report on people are never used by the plugin's own model lookups, because they return personal data.

## Installation

- Use PHP 8.0 or newer. The plugin is tested with WordPress 7.1 and WooCommerce 11.2. ACF Pro is needed for the block tools and WooCommerce for the store tools.
- Download `twee-mcp-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-mcp/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**, then activate the plugin. To install from the repository instead, copy the contents of its `plugin/` directory into `wp-content/plugins/twee-mcp/`, so `index.php` is directly inside that directory.
- New releases appear under **Dashboard → Updates** like any other plugin update. The `Update URI` header sends the update check to the GitHub releases, which are checked at most every 12 hours.
- Open **AI Tools → AI Provider**, choose a provider, and enter an API key or sign in with ChatGPT. Keys come from the [OpenAI](https://platform.openai.com/api-keys), [Anthropic](https://platform.claude.com/settings/keys), or [OpenRouter](https://openrouter.ai/settings/keys) API keys page; the screen links each provider's page and getting started guide.
- Open **AI Tools → Agent Access** for the server URL and the steps for each client.
- If the site runs behind Cloudflare, add a WAF rule that skips the security checks for `/wp-json/straps/v1/`, `/.well-known/`, `/register`, `/authorize`, and `/token`. The **Agent Access** screen has the exact expression.

## Connecting AI Clients

### Claude (Web & Desktop App)

- Open **Customize → Connectors** from the Claude sidebar, then **Add → Add custom connector**.
- Paste the server URL, for example `https://example.com/wp-json/straps/v1/mcp`.
- Sign in to WordPress on the authorization screen and click **Allow**.

### Claude Code

```bash
claude mcp add --transport http straps https://example.com/wp-json/straps/v1/mcp
```

Then run `/mcp` inside Claude Code and choose to authenticate.

### ChatGPT

- Open [chatgpt.com/plugins](https://chatgpt.com/plugins) in a browser (the apps cannot add servers) and click **Add → Add custom MCP server**.
- Enter a name, paste the server URL, and leave **OAuth** and the advanced settings as they are.
- Tick **I understand and want to continue**, click **Create as a plugin** and then **Connect**, sign in to WordPress, and click **Allow**.
- In a chat, type `@` and pick the plugin.

ChatGPT discovers the OAuth settings and registers itself. Business and Enterprise workspaces need an admin to allow plugins with MCP servers.

The `search` and `fetch` tools follow the ChatGPT connector format.

## Configuration

Settings live on the **AI Tools** screens. Constants in `wp-config.php` are optional fallbacks.

- `TWCP_OPENAI_API_KEY` - OpenAI API key when none is saved.
- `TWCP_ANTHROPIC_API_KEY` - Anthropic API key when none is saved.
- `TWCP_OPENROUTER_API_KEY` - OpenRouter API key when none is saved.
- `INSTANT_IMAGES_UNSPLASH_KEY`, `INSTANT_IMAGES_PIXABAY_KEY`, `INSTANT_IMAGES_PEXELS_KEY`, `INSTANT_IMAGES_GIPHY_KEY` - Stock photo keys when none are saved. The keys of the Instant Images plugin are used as well. Openverse and Wikimedia Commons need no key.
- `TWCP_REDIRECT_HOSTS` - Default OAuth redirect host allowlist, one host per line.

## Development

The repository keeps the plugin apart from its tests, so the `plugin/` directory can be copied to a site as it is.

```
twee-mcp/
├── plugin/            The WordPress plugin
│   ├── assets/        SCSS, compiled CSS, and JavaScript
│   ├── includes/      Server, OAuth, models, the batch engine, admin, and the tool registry
│   ├── modules/       Tools and integrations, one folder per module
│   ├── parts/         Admin screen templates
│   └── index.php      Plugin header and loader
├── bin/release.php    Builds the release ZIP and publishes GitHub releases
├── tests/             PHPUnit integration tests (core/ and tools/)
├── composer.json
└── phpunit.xml
```

### Modules

Tools and integrations live in modules, one folder each. The plugin ships `wp` (posts, terms, comments, media, the site, and search, with their batch jobs, prompts, and bulk actions), `activity` (WP Activity Log), `blocks` (ACF Pro), `images` (stock photo providers), and `woo` (WooCommerce) in `plugin/modules/`. The core runs the MCP server, OAuth, the model, the batch engine, and the admin screens, and takes everything else from the hooks of the modules, so it never calls a module's functions.

A project keeps modules of its own outside the plugin, so updates leave them alone. A theme or plugin adds their folder with the `twcp_module_dirs` filter, which runs on `after_setup_theme`:

```php
add_filter('twcp_module_dirs', function(array $dirs): array {
	$dirs[] = get_stylesheet_directory() . '/twcp-modules';

	return $dirs;
});
```

The plugin loads the `module.php` of every folder there and in `plugin/modules/`, `wp` first, as the others build on it. A module name keeps the first folder found with it. `module.php` loads the other files of its module, registers the module, and adds its tools; its admin templates go in its `parts/` folder, where `twcp_template()` finds them, and `twcp_module_url()` gives the URL of its scripts and styles.

Modules other than `wp` can be switched off under Modules on the Agent Tools screen, or with the `twcp_disabled_modules` option, a list of module folders. A module that is switched off is not loaded, so its tools, settings, screens, and jobs are gone. The screen names each module from the `Module` and `Description` lines in the header of its `module.php`, which it reads without loading the module.

```
twcp-modules/reviews/
├── module.php     Loads reviews.php, registers the module, and adds its tools
├── reviews.php    The tool callables and the module's hooks
└── parts/         Admin templates
```

A module registers itself with the `twcp_modules` filter, naming the plugin it needs and whether that plugin is running. Its tool groups are available while it is active, and other modules check it with `twcp_module_active()`:

```php
/**
 * Module: Reviews
 * Description: Product reviews and their ratings.
 */

require_once __DIR__ . '/reviews.php';

add_filter('twcp_modules', function(array $modules): array {
	$modules['reviews'] = ['plugin' => 'WooCommerce', 'active' => class_exists('WooCommerce')];

	return $modules;
});
```

### Hooks

A filter is named after the function that applies it, so `twcp_batch_types()` returns what the `twcp_batch_types` filter gives. Registries are keyed by name with named fields, and those shown in order set a `position`.

- `twcp_module_dirs` and `twcp_modules` - Folders of project modules, and the modules with the plugin each needs.
- `twcp_tools_groups` - Tool groups of the Agent Tools screen and the MCP server, see Adding Tools.
- `twcp_admin_tabs` and `twcp_admin_data` - Tabs of the AI Tools menu, or parts of other tabs, and the data of their templates. The tabs of the plugin are at 10, 20, 40, 50, and 60; the Blocks tab of the `blocks` module is at 30 and the Images tab of the `images` module at 35.
- `twcp_batch_types`, `twcp_batch_sections`, `twcp_batch_overview`, and `twcp_batch_actions` - Batch jobs, see Adding Batch Jobs, sections of the Automation screen, overview items `[label, count, url]`, and list-table bulk actions `[label, run, type, ids]` by screen ID. The `twcp_batch_section` action adds settings below the cards of a section, such as the Internal Links form of the `wp` module under Content Automation. The sections of the plugin are at 10 (content) and 20 (media); the `woo` module adds Store Automation at 30 and the `blocks` module Site Setup at 40.
- `twcp_prompt_groups`, `twcp_prompt_registry`, and the `twcp_prompt_fields` action - Prompt groups, prompts, and extra settings saved with a prompt. A prompt shows on the cards of the jobs that list it in `prompts`, or else in the `section` of its group; the shared context of the plugin has no section and shows above the sections. Each prompt saves in its own form, so a setting a module shows with a prompt is registered in the settings group named after the prompt's option. Groups are at 10 (shared context), 20 (posts and pages), 30 (categories and tags), and 40 (images) of the `wp` module, and 25 (store) of the `woo` module.
- `twcp_prompt_examples` - Groups of the copy-ready Agent Prompts on the Agent Access tab, at 10 (posts and pages) and 20 (categories and media) of the `wp` module, and 30 (store) of the `woo` module. The filter also gets the site name and URL for the prompt texts.
- `twcp_site_catalog` and `twcp_site_facts` - The taxonomy and post type that tell prompts what the site is about, with a line on the business, and what the profile written for the shared context starts from. The `woo` module names products, and the `wp` module adds the home page.
- `twcp_post_links` and `twcp_post_link_sources` - Approved internal links of generated content and the sources of the Internal Links panel, such as the store categories of the `woo` module.
- `twcp_post_category_taxonomy`, `twcp_post_blocks`, `twcp_post_assign_image`, `twcp_term_format`, and `twcp_media_assign` - The category taxonomy of a post type, page builder blocks of posts and terms, featured images found outside the Media Library, the reply of the term tools, and image targets of `assign_media_image`.

### Adding Tools

A module adds a group of tools with the `twcp_tools_groups` filter in its `module.php`. The `position` sets where the group appears on the Agent Tools screen: the groups of the bundled modules are at 10 (content) to 110 (search). The `hint` is sent to AI clients once for the group, unless a tool sets its own:

```php
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['reviews'] = [
		'label'    => __('Reviews', 'twee-mcp'),
		'position' => 85,
		'module'   => 'reviews',  // Without its module or plugin, the group is left out and shown as unavailable
		'hint'     => 'Read reviews with get_reviews.',
		'tools'    => [
			'get_reviews' => [
				'description' => 'Read product reviews with their rating and author.',
				'callable'    => 'my_reviews_query',
				'parameters'  => ['product_id' => ['type' => 'integer', 'description' => 'Only reviews of this product.']],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
});
```

Every tool needs a user with `edit_posts`, and read-only tools need no more. A tool that changes content gets the post or term its first ID argument names and checks that the user can edit it, and publish it when the arguments ask for a published status. A tool sets who may run it on its own:

- `access` - A function of the arguments that returns an error message, or null to allow the call, for a tool that creates items or checks more than one. `twcp_tools_access_target()` and `twcp_tools_access_create()` cover the usual cases.
- `capability` - A capability the user needs on top of `edit_posts`; the callable checks single objects.
- `public` - Anyone may run it, as it only returns what the user may see, like `search` and `fetch`.

A tool can also set `private` to keep it out of the plugin's own model lookups, and `required` for its required parameters.

### Adding Stock Image Providers

Each stock photo provider is a file in `plugin/modules/images/providers/`, which the `images` module loads, and adds itself with the `twcp_image_providers` filter. The search tool, the Images screen, and the API key settings list the providers from that filter, so a provider can be added or removed on its own, from the folder or from another plugin:

```php
add_filter('twcp_image_providers', function(array $providers): array {
	$providers['example'] = [
		'name'        => 'Example Photos',
		'description' => 'Photos from the Example library.',
		'position'    => 70,
		'search'      => 'example_search',
		'key'         => [
			'label' => 'Example API Key',
			'url'   => 'https://example.com/api',
			'link'  => 'Get an Example API Key',
			'help'  => 'Create an app and copy its key into this field.',
		],
	];

	return $providers;
});
```

`search` gets the query, `['per_page' => int, 'page' => int, 'orientation' => 'landscape'|'portrait'|'square'|'']`, and the key, and returns the images in the format of `search_stock_images`. `twcp_image_get()` gets the JSON reply of an API, and `twcp_image_proxy()` searches the Instant Images proxy, which serves Unsplash, Pixabay, Pexels, and Openverse without a key. Without `key`, the provider needs none. A search of all providers takes the results of each in turn and includes a provider with a key only when it has one. `all` set to `false` leaves a provider out of it, as Giphy is, and `'fallback'` includes it only when the providers before it found too few images, as Wikimedia Commons is. Providers are at 10 (Unsplash), 20 (Pixabay), 30 (Pexels), 40 (Openverse), 50 (Wikimedia Commons), and 60 (Giphy).

### Adding Batch Jobs

A module declares a job with its card, the post type and field it fills, and the function that fills one item, as the jobs of the `wp` and `woo` modules do. The plugin does the rest: the capability and unit come from the post type; the query finds the published items whose field is empty, or all of them when rewriting; and the field is backed up for Undo and shown in the log. `field` is `post:` with a column of the posts table, or `meta:` with a meta key:

```php
add_filter('twcp_batch_types', function(array $types): array {
	$types['review_summary'] = [
		'title'       => __('Review Summaries', 'twee-mcp'),
		'description' => __('Sums up the reviews of products without a summary.', 'twee-mcp'),
		'button'      => __('Summarize Reviews', 'twee-mcp'),
		'section'     => 'store',                  // Content when left out
		'post_type'   => 'product',
		'field'       => 'meta:_review_summary',
		'run'         => 'my_review_summary',      // Gets the post ID and whether to rewrite; returns the new text, or false
		'cost'        => [1, 3000],                // Model requests and tokens per item, for the estimate
	];

	return $types;
});
```

`run` can also return `true`, which logs the new value, or `['success' => bool, 'message' => string]` for its own log line, and `prompts` lists the options of the system prompts it runs with, whose editors its card shows. Without `post_type`, the job fills the post type chosen on its card, which offers every post type, or those listed in `scopes`. A job with `terms` runs over taxonomy terms, and its `field` is `term:description` or `term_meta:` with a meta key; with `targets`, a function returning ACF term fields, it fills the one chosen on its card.

A job can also set:

- `combine` - Send the model requests of a slice as one request, for jobs that make one text request per item.
- `slice` (5 by default), `cost` (`[1, 2000]` by default), `capability`, and `unit`.
- `text` - What it writes, which enables the refine and rewrite modes. Without it, `redo` names the mode that redoes all items, or is `false` for a job that only fills.
- `fields` - The fields it may change for Undo, or a function of the item returning them.
- `args` - A function of `(bool $override, array $types)` returning the `WP_Query` or `get_terms` arguments of its items in the post types or taxonomies of its scope, or `query` and `total` for items that are neither.
- `current` - A function of the item returning the text it would replace.
- `restored` - Called after Undo.

A job for a section of its own adds the section with `twcp_batch_sections`:

```php
add_filter('twcp_batch_sections', function(array $sections): array {
	$sections['reviews'] = [
		'label'       => __('Review Automation', 'twee-mcp'),
		'description' => __('Summaries and replies for product reviews.', 'twee-mcp'),
		'position'    => 35,
	];

	return $sections;
});
```

### Tests

The integration suite boots WordPress with `wp-phpunit/wp-phpunit` and runs against a disposable database.

- Run `composer install`.
- Create an empty `wordpress_test` database. The suite wipes it on every run. The connection settings are in `phpunit.xml` and `tests/config.php`.
- WordPress core is loaded from four levels above `tests/`, for example `D:\Work\` for `D:\Work\wp-content\plugins\twee-mcp\tests\`.
- WooCommerce tests run when WooCommerce is found next to this repository in `wp-content/plugins/woocommerce/`, or in the folder set by the `WOOCOMMERCE_DIR` environment variable. Without it they are skipped.
- ACF term field tests run when ACF Pro is found in `wp-content/plugins/advanced-custom-fields-pro/`, or in the folder set by `ACF_DIR`.
- The `sample` module in `tests/modules/` is loaded like a project module, and `ModulesTest` runs the plugin again with each module switched off, in a PHPUnit run of its own.
- Run the suite with `composer test`, which collects coverage with the `pcov` extension, or without coverage:

```bash
php vendor/phpunit/phpunit/phpunit --no-coverage
```

The WordPress test library supports PHPUnit up to 9.6, so the suite stays on PHPUnit 9.

### Releases

Sites update from the ZIP attached to the latest GitHub release, so every release needs one. The scripts build it from the `plugin/` directory at `HEAD`.

- Bump the `Version` header and `TWCP_VERSION` in `plugin/index.php`, then commit and push.
- Run `composer zip` to build `build/twee-mcp-{version}.zip` without publishing it.
- Run `composer release` to build the ZIP and publish release `v{version}`, titled with the same tag. The notes list the commits since the previous release, or pass a Markdown file with `composer release -- notes.md`.

The release script needs the [GitHub CLI](https://cli.github.com), signed in with `gh auth login`. It stops when the two versions differ, the working tree has changes, `HEAD` is not pushed, or the release already exists.

### Working on a Site

Link the `plugin/` directory into a site instead of copying it. On Windows, run in an elevated prompt or with Developer Mode on:

```bash
cmd /c mklink /D "Z:\work\upweb\wp-content\plugins\twee-mcp" "D:\Work\wp-content\plugins\twee-mcp\plugin"
```

A linked plugin folder is never offered an update, so an update cannot replace the working copy.

## About

Author: Andrii Toniievych

Contact: [toniyevych@gmail.com](mailto:toniyevych@gmail.com)

Feel free to contact me if you have any questions.

## Contribution

* Fork this repository
* Commit your changes
* Push it to the branch
* Create the new pull request

## License

**Twee MCP** is released under the MIT License. See [LICENSE.md](LICENSE.md).

Note: The "About" section in `README.md` and the author (`@author`) notice in the file-headers shall not be edited or deleted without permission. Thank you!

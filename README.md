# Twee MCP

**Twee MCP** is an MCP server for WordPress and WooCommerce. AI clients such as Claude, Claude Code, and ChatGPT connect to it with OAuth and work with posts, terms, media, ACF blocks, menus, comments, products, and orders. They can do only what the WordPress user who approved the connection can do.

The plugin can also connect the site to an AI model (OpenAI, OpenRouter, Anthropic, or a ChatGPT plan) and use it to write excerpts, content, SEO tags, Alt text, categories, featured images, and product copy in bulk.

## Installation

- Use PHP 8.0 or newer. The plugin is tested with WordPress 7.1 and WooCommerce 11.2. The block tools need ACF Pro, and the store tools need WooCommerce.
- Download `twee-mcp-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-mcp/releases/latest), upload it in **Plugins → Add New → Upload Plugin**, and activate it. New releases show up in **Dashboard → Updates**.
- Open **AI Tools → Connection**. For the AI jobs, choose a provider and add an API key or sign in with ChatGPT. Below that is the server URL.
- The **AI Tools → Help** tab has the steps for each client, the Cloudflare rule, and tips on using the jobs and the chat, in plain English for editors and shop managers.
- Behind Cloudflare, add a WAF rule that skips the security checks for `/wp-json/straps/v1/`, `/.well-known/`, `/register`, `/authorize`, and `/token`. The **Help** tab shows the exact rule.

## Connecting AI Clients

### Claude (Web and Desktop App)

- Open **Customize → Connectors** in the Claude sidebar, then **Add → Add custom connector**.
- Paste the server URL, such as `https://example.com/wp-json/straps/v1/mcp`.
- Sign in to WordPress and click **Allow**.

### Claude Code

```bash
claude mcp add --transport http straps https://example.com/wp-json/straps/v1/mcp
```

Then run `/mcp` in Claude Code and choose to sign in.

### ChatGPT

- Open [chatgpt.com/plugins](https://chatgpt.com/plugins) in a browser (the apps cannot add servers) and click **Add → Add custom MCP server**.
- Enter a name and the server URL, and leave **OAuth** and the advanced settings as they are.
- Tick **I understand and want to continue**, click **Create as a plugin**, then **Connect**. Sign in to WordPress and click **Allow**.
- In a chat, type `@` and pick the plugin.

Business and Enterprise workspaces need an admin to allow plugins with MCP servers.

## Features

### MCP Server

- The endpoint is `/wp-json/straps/v1/mcp` (Streamable HTTP, protocol `2025-06-18`).
- Tools are marked as read-only or destructive, so clients can ask before they change or delete anything.
- Single tools and whole modules can be switched off on the **Tools** screen, and the server on the **Connection** screen. Tools that need an inactive plugin, such as WooCommerce, switch off by themselves.

### Sign-in and Permissions

- OAuth 2.1 with PKCE, client registration, and a WordPress consent screen. Access tokens last an hour and refresh tokens 90 days.
- Clients can return only to allowed hosts: `claude.ai`, `claude.com`, `anthropic.com`, `chatgpt.com`, and `openai.com` by default.
- Connecting needs `edit_posts`. Each tool call is checked against the user's capabilities, such as `publish_posts` to publish, `moderate_comments` for comments, and `edit_shop_orders` for orders.
- Connected agents and their sessions are listed on the **Connection** screen. Changing the user's password signs them all out.

### AI Model

- OpenAI, OpenRouter, Anthropic, or ChatGPT Plan (experimental), with separate text and vision models.
- While it writes, the model can look up posts, terms, and products with the read-only tools `get_posts`, `get_terms`, and `get_products`.
- **ChatGPT Plan** uses the limits of a ChatGPT plan instead of an API key. After sign-in, OpenAI sends the browser to `http://127.0.0.1:1455/auth/callback`. That page does not load, which is expected: copy its address into the plugin. OpenAI allows this for open-source tools and personal projects. A public or client site needs approval through the [OpenAI interest form](https://openai.com/form/sign-in-with-chatgpt-interest/).

### Automation

The **Automation** screen shows what needs attention and runs jobs in small batches:

- **Content:** excerpts, post content, SEO titles and descriptions, featured images, categories, term SEO tags, and term descriptions. Long term descriptions and FAQs go to ACF term fields when the site has them.
- **Media:** Alt text from the vision model.
- **Store:** product descriptions, short descriptions, and cross-sells.
- **Site Setup:** descriptions of the ACF page builder blocks, which help agents choose blocks.

Text jobs can fill empty values, refine existing ones, rewrite only the poor ones, or rewrite all. Each card shows a cost estimate and can preview one item without saving it. **Undo Last Run** puts back what the last run changed. With Action Scheduler (part of WooCommerce), jobs run on the server, so the page can be closed. The list screens offer the same jobs as bulk actions.

Generated content links only to approved pages: the post's categories and tags, related posts, and, if chosen, top-level pages and store categories. Other links are removed. The **Internal Links** panel sets the sources and the number of links.

Content jobs need `edit_others_posts`, the media job `upload_files`, and the store jobs `edit_others_products`. The other screens need `manage_options`.

### Prompts

- Each prompt can be edited on the card of the job that uses it, and reset to its default. The output format, language, and examples are added automatically and cannot be edited, so the replies can always be saved.
- The default prompts follow the Google, Bing, and Merchant Center guidance on titles, descriptions, and product copy.
- The **Shared Context** describes the business, audience, and tone, and goes into every prompt. **Generate from website** drafts it from the site. **Target Keywords** lists the search phrases to work in.
- Focus keywords from Rank Math or Yoast SEO go into the prompts of their post, product, or term.
- The **Blocks** screen keeps the block rules that `get_block_types` gives to agents.

## Tools

| Group | Tools |
|---|---|
| Content & Posts | `get_posts`, `get_schema`, `create_post`, `edit_post`, `create_similar_post`, `trash_post` |
| Taxonomies | `get_terms`, `create_term`, `edit_term`, `assign_terms` |
| Comments | `get_comments`, `moderate_comment`, `reply_comment` |
| Media Library | `get_images`, `preview_images`, `edit_media`, `import_media_image`, `assign_media_image`, `check_media_alt_text`, `build_gallery_shortcode` |
| Stock Images | `search_stock_images` |
| ACF Blocks | `get_block_types`, `get_custom_fields`, `get_blocks`, `create_block`, `edit_block`, `remove_block`, `generate_block_descriptions` (ACF Pro) |
| Site & Menus | `get_site_info`, `get_users`, `get_menus`, `add_menu_item`, `remove_menu_item` |
| WooCommerce Products | `get_products`, `create_product`, `edit_product`, `get_product_variations`, `save_product_variation`, `select_related_products`, `generate_product_description`, `generate_product_short_description` |
| WooCommerce Orders & Coupons | `get_orders`, `update_order`, `get_sales_summary`, `get_coupons`, `save_coupon` |
| Activity Log | `get_activity_summary`, `get_brand_activity`, `get_user_activity`, `get_item_history`, `get_activity_events`, `get_login_activity`, `get_activity_event_types` ([WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)) |
| Discovery & Search | `search`, `fetch` (for ChatGPT connectors) |

- `trash_post` moves content to the Trash and never deletes it.
- The Activity Log tools report who did what and for how long: per user, per brand or other term, per item, and per login. Active time is a lower bound, as it counts only the gaps of up to 15 minutes between logged actions.
- `get_users` and the Activity Log tools about people return personal data, so the plugin's own model never uses them.

### Activity Tracking

For the roles chosen on the **Tools** screen, the plugin logs what WP Activity Log does not, as WP Activity Log events:

- **Editing time** (event 99102): the seconds a wp-admin tab is in front and in use. Reports show it as `tracked_minutes`.
- **Term changes** (event 99100): brands, tags, and other terms of an item, before and after.
- **Custom prices** (event 99101): changes of the meta fields set under Price Fields.

It is off until a role is chosen. Let the people you track know about it.

## Configuration

Settings are on the **AI Tools** screens. These `wp-config.php` constants are used when no setting is saved:

- `TWCP_OPENAI_API_KEY`, `TWCP_ANTHROPIC_API_KEY`, `TWCP_OPENROUTER_API_KEY` - API keys.
- `INSTANT_IMAGES_UNSPLASH_KEY`, `INSTANT_IMAGES_PIXABAY_KEY`, `INSTANT_IMAGES_PEXELS_KEY`, `INSTANT_IMAGES_GIPHY_KEY` - Stock photo keys, shared with the Instant Images plugin. Openverse and Wikimedia Commons need no key.
- `TWCP_REDIRECT_HOSTS` - Allowed OAuth redirect hosts, one per line.

## Development

The `plugin/` folder is the plugin, so it can be copied to a site as it is.

```
twee-mcp/
├── plugin/            The WordPress plugin
│   ├── assets/        SCSS, CSS, and JavaScript
│   ├── includes/      Server, OAuth, model, batch jobs, admin screens, and tools
│   ├── modules/       Tools and integrations, one folder per module
│   ├── parts/         Admin templates
│   └── index.php      Plugin header and loader
├── bin/release.php    Builds and publishes releases
├── tests/             PHPUnit tests
├── composer.json
└── phpunit.xml
```

### Modules

Each module is a folder with a `module.php`. The plugin ships these in `plugin/modules/`:

- `wp` - posts, terms, comments, media, the site, and search, with their jobs, prompts, and bulk actions. It is always on.
- `activity` - WP Activity Log reports and tracking.
- `blocks` - ACF Pro blocks.
- `images` - stock photo providers.
- `woo` - WooCommerce products, orders, and coupons.

The core runs the server, OAuth, the model, the batch engine, and the admin screens. It gets everything else from module hooks.

A theme or plugin can add a folder of its own modules, which plugin updates leave alone:

```php
add_filter('twcp_module_dirs', function(array $dirs): array {
	$dirs[] = get_stylesheet_directory() . '/twcp-modules';

	return $dirs;
});
```

Modules load on `after_setup_theme`, with `wp` first. When two folders have a module with the same name, the first one wins. `module.php` loads the module's other files and adds its hooks. Templates go in the module's `parts/` folder, and `twcp_module_url()` gives the URL of a module file.

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

The `Module` and `Description` lines name the module on the **Tools** screen, where it can be switched off. The `twcp_disabled_modules` option keeps the list of switched-off modules. `twcp_module_active()` tells whether a module is loaded and its plugin is running.

### Hooks

A filter has the name of the function that applies it: `twcp_batch_types()` returns the `twcp_batch_types` filter. Lists are keyed by name, and a `position` sets their order.

- `twcp_module_dirs`, `twcp_modules` - Module folders, and the modules with the plugin each needs.
- `twcp_tools_groups` - Tool groups. See Adding Tools.
- `twcp_admin_tabs`, `twcp_admin_data` - Admin tabs and the data of their templates.
- `twcp_batch_types`, `twcp_batch_sections` - Batch jobs and the sections of the Automation screen. See Adding Batch Jobs.
- `twcp_batch_overview`, `twcp_batch_actions` - Overview items `[label, count, url]`, and bulk actions `[label, run, type, ids]` by screen.
- `twcp_batch_section` (action) - Settings below the cards of a section, such as Internal Links.
- `twcp_batch_background` - Return `false` to run jobs only while the page is open.
- `twcp_prompt_groups`, `twcp_prompt_registry`, `twcp_prompt_fields` (action) - Prompts, their groups, and settings saved with a prompt. A prompt's `format` function returns its output format, language, and examples, which are added to it whether it was edited or not.
- `twcp_prompt_examples` - Copy-ready prompts on the **Connection** screen.
- `twcp_site_catalog`, `twcp_site_facts` - What prompts and the site profile are told about the site.
- `twcp_post_links`, `twcp_post_link_sources` - Internal links and their sources.
- `twcp_post_category_taxonomy` - The category taxonomy of a post type, such as `product_cat` for products.
- `twcp_post_blocks`, `twcp_term_format` - Page builder blocks of posts, and the reply of the term tools.
- `twcp_post_assign_image`, `twcp_media_assign` - Images found outside the Media Library, and the targets of `assign_media_image`.
- `twcp_image_providers` - Stock photo providers.

### Adding Tools

```php
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['reviews'] = [
		'label'    => __('Reviews', 'twee-mcp'),
		'position' => 85,         // The bundled groups are at 10 to 110
		'module'   => 'reviews',  // The group is unavailable when its module is not active
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

The callable gets the arguments, already checked against `parameters`, and returns an array, which the client gets as JSON. To report a problem, it returns `['error' => message]`:

```php
function my_reviews_query(array $args): array
{
	$product_id = $args['product_id'] ?? 0;
	if ($product_id and get_post_type($product_id) !== 'product') {
		return ['error' => 'No product with this ID.'];
	}

	$reviews = [];
	foreach (get_comments(['type' => 'review', 'status' => 'approve', 'post_id' => $product_id, 'number' => 20]) as $review) {
		$reviews[] = [
			'id'      => (int) $review->comment_ID,
			'product' => (int) $review->comment_post_ID,
			'author'  => $review->comment_author,
			'rating'  => (int) get_comment_meta($review->comment_ID, 'rating', true),
			'text'    => $review->comment_content,
		];
	}

	return ['reviews' => $reviews];
}
```

Every tool needs a user with `edit_posts`, and that is enough for read-only tools. A tool that changes content checks that the user can edit the post or term named by its first ID argument. A tool can change this:

- `access` - A function of the arguments that returns an error message, or null to allow the call. `twcp_tools_access_target()` and `twcp_tools_access_create()` cover the usual cases.
- `capability` - A capability the user needs as well.
- `public` - Anyone can run it, as it only returns what the user can see.

`private` keeps a tool away from the plugin's own model, and `required` lists the required parameters.

### Adding Batch Jobs

A job names its card, the field it fills, and the function that fills one item. The plugin finds the items, backs them up for Undo, and logs the changes:

```php
add_filter('twcp_batch_types', function(array $types): array {
	$types['review_summary'] = [
		'title'       => __('Review Summaries', 'twee-mcp'),
		'description' => __('Sums up the reviews of products without a summary.', 'twee-mcp'),
		'button'      => __('Summarize Reviews', 'twee-mcp'),
		'section'     => 'store',                  // Content by default
		'post_type'   => 'product',                // Without it, the card offers a choice of post types
		'field'       => 'meta:_review_summary',   // Or post:column, term:description, or term_meta:key
		'run'         => 'my_review_summary',      // Gets the ID and whether to redo it; returns the new text or false
		'cost'        => [1, 3000],                // Model requests and tokens per item, for the estimate
	];

	return $types;
});
```

Other keys a job can set:

- `terms` - Run over terms instead of posts.
- `targets` - A function that returns the ACF term fields the job can fill. The card offers a choice.
- `text` - What it writes, which turns on the refine and rewrite modes. Without it, `redo` names the mode that redoes all items, or is `false`.
- `prompts` - The prompts it uses, shown on its card.
- `combine` - Send one request for a whole batch instead of one per item.
- `slice` (5 by default), `scopes`, `capability`, and `unit`.
- `fields` - The fields to back up for Undo, or a function of the item that returns them.
- `args` - A function of `(bool $override, array $types)` that returns the `WP_Query` or `get_terms` arguments, or `query` and `total` for other items.
- `current` - A function of the item that returns the text it would replace.
- `restored` - Runs after Undo.

A new section of the Automation screen is added with `twcp_batch_sections`:

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

### Adding Stock Image Providers

Each provider is a file in `plugin/modules/images/providers/`, or a plugin, that adds itself with `twcp_image_providers`:

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

`search` gets the query, `['per_page' => int, 'page' => int, 'orientation' => 'landscape'|'portrait'|'square'|'']`, and the key, and returns images in the format of `search_stock_images`. Leave out `key` when the provider needs none. Set `all` to `false` to leave it out of searches of all providers, or to `'fallback'` to use it only when the others found too few images.

### Tests

The tests boot WordPress and run against a test database.

- Run `composer install`.
- Create an empty `wordpress_test` database. Every run wipes it. The connection settings are in `phpunit.xml` and `tests/config.php`.
- WordPress is loaded from four folders above `tests/`, such as `D:\Work\` for `D:\Work\wp-content\plugins\twee-mcp\tests\`.
- WooCommerce and ACF Pro tests run when the plugins are in `wp-content/plugins/`, or in the folders set by the `WOOCOMMERCE_DIR` and `ACF_DIR` environment variables. Otherwise they are skipped.
- Run `composer test` for coverage with `pcov`, or run without it:

```bash
php vendor/phpunit/phpunit/phpunit --no-coverage
```

The WordPress test library supports PHPUnit up to 9.6, so the tests stay on PHPUnit 9.

### Releases

Sites update from the ZIP attached to the latest GitHub release.

- Bump the `Version` header and `TWCP_VERSION` in `plugin/index.php`, then commit and push.
- Run `composer zip` to build `build/twee-mcp-{version}.zip` only.
- Run `composer release` to build the ZIP and publish release `v{version}`. Pass release notes with `composer release -- notes.md`; otherwise, the notes list the commits since the last release.

The release script needs the [GitHub CLI](https://cli.github.com), signed in with `gh auth login`. It stops when the versions differ, there are uncommitted changes, `HEAD` is not pushed, or the release exists.

### Working on a Site

Link the `plugin/` folder into a site instead of copying it. On Windows, run this in an admin prompt or with Developer Mode on:

```bash
cmd /c mklink /D "Z:\work\upweb\wp-content\plugins\twee-mcp" "D:\Work\wp-content\plugins\twee-mcp\plugin"
```

WordPress never offers an update for a linked plugin folder, so an update cannot replace the working copy.

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

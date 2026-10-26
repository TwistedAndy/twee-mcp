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
- Single tools can be disabled on the **Agent Tools** screen, and the whole server can be turned off on the **Agent Access** screen.

### 2. OAuth 2.1 Authorization

- Discovery metadata at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`.
- Dynamic client registration (RFC 7591) at `/register`, a WordPress consent screen at `/authorize`, and token exchange at `/token`.
- Authorization code flow with PKCE (`S256`) and rotating refresh tokens.
- Client IDs are stateless signed payloads. Tokens are stored hashed in the `twcp_sessions` user meta.
- Access tokens last 1 hour, refresh tokens 90 days, and authorization codes 5 minutes.
- Redirect hosts are limited to an allowlist. The default list is `claude.ai`, `claude.com`, `anthropic.com`, `chatgpt.com`, and `openai.com`.
- Connected agents and active sessions are listed on the **Agent Access** screen, where they can be revoked.

### 3. Permissions

- Connecting requires the `edit_posts` capability (`TWCP_CAPABILITY`).
- Every tool call is checked against the capabilities of the connected user. Publishing needs the post type's `publish_posts` capability. Comments need `moderate_comments`, menus `edit_theme_options`, orders `edit_shop_orders`, and coupons `edit_shop_coupons`.
- Some tools have their own capability on top, for example `get_sales_summary` with `view_woocommerce_reports` and `generate_block_descriptions` with `manage_options`.

### 4. AI Provider

The **AI Provider** screen sets up the AI service the plugin itself uses to write content.

- Providers: **OpenAI**, **OpenRouter**, **Anthropic**, and **ChatGPT Plan (Experimental)**.
- Separate text and vision models. Images are scaled down to a chosen edge size before they are sent, to keep vision costs low.
- Model lists load from the provider's API and are cached for a day.
- During generation, the model can call read-only tools such as `get_posts` to look up internal links, products, and terms. Private tools are never offered to it.
- **ChatGPT Plan** signs in with a ChatGPT account and spends that account's plan limits instead of an API key. OpenAI only returns the sign-in to `http://127.0.0.1:1455/auth/callback`. The page there does not load, which is expected: copy its full address from the browser and paste it into the plugin. No local server is needed. OpenAI offers plan usage to open-source tools and local personal projects. A public or client site needs approval through the [OpenAI interest form](https://openai.com/form/sign-in-with-chatgpt-interest/).

### 5. Batch Automation

The **Automation** screen lists what needs attention and runs batch jobs in small slices. Each text job has a mode:

- **Fill empty:** only items that lack the value. This is the default.
- **Refine existing:** polishes the current values and keeps their meaning, facts, and length.
- **Rewrite if poor:** the model rates the current values of each slice, rewrites the poor ones, and keeps the rest.
- **Rewrite all:** writes every value from scratch.

All modes fill empty items. Jobs that assign images, categories, cross-sells, or block descriptions only offer **Fill empty** and **Redo all**. Until an API key is added or a ChatGPT account is connected, the screen shows a setup notice and the jobs and AI bulk actions stay locked.

- **Estimate:** each card shows a rough number of AI requests and tokens for the items left in the chosen mode.
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

- The **Prompts** screen holds the instructions for every generator, grouped into site context, content, store, terms, and media. Each prompt can be reset to its default.
- The default site context is built from the site name, tagline, main categories, and whether WooCommerce is active. It's added to every prompt, so describe the business, audience, and tone there. **Generate from website** drafts a 5 to 6 sentence profile from the site name, tagline, home page, main categories, and recent posts or products; review it, then save.
- The **Library** screen keeps the block conventions that `get_block_types` hands to agents. The conventions explain page structure, layout options, query layouts, repeaters, and text formats of the theme's ACF blocks.

## Tools

| Group | Tools |
|---|---|
| Content & Posts | `get_posts`, `get_schema`, `create_post`, `edit_post`, `create_similar_post`, `trash_post` |
| Taxonomies | `get_terms`, `create_term`, `edit_term`, `assign_terms` |
| Comments | `get_comments`, `moderate_comment`, `reply_comment` |
| Media Library | `get_images`, `preview_images`, `edit_media`, `assign_media_image`, `build_gallery_shortcode` |
| Stock Images | `search_stock_images`, `import_media_image`, `check_media_alt_text` |
| ACF Blocks | `get_block_types`, `get_custom_fields`, `get_blocks`, `create_block`, `edit_block`, `remove_block`, `generate_block_descriptions` |
| Site & Menus | `get_site_info`, `get_menus`, `add_menu_item`, `remove_menu_item` |
| WooCommerce Products | `get_products`, `create_product`, `edit_product`, `get_product_variations`, `save_product_variation`, `select_related_products`, `generate_product_description`, `generate_product_short_description` |
| WooCommerce Orders & Coupons | `get_orders`, `update_order`, `get_sales_summary`, `get_coupons`, `save_coupon` |
| Discovery & Search | `search`, `fetch` (compatible with ChatGPT connectors) |

`trash_post` moves content to the Trash and never deletes it permanently. `remove_block` and `remove_menu_item` are marked as destructive.

## Installation

- Use PHP 8.0 or newer. The plugin is tested with WordPress 7.1 and WooCommerce 11.2. ACF Pro is needed for the block tools and WooCommerce for the store tools.
- Download `twee-mcp-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-mcp/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**, then activate the plugin. To install from the repository instead, copy the contents of its `plugin/` directory into `wp-content/plugins/twee-mcp/`, so `index.php` is directly inside that directory.
- New releases appear under **Dashboard → Updates** like any other plugin update. The `Update URI` header sends the update check to the GitHub releases, which are checked at most every 12 hours.
- Open **AI Tools → AI Provider**, choose a provider, and enter an API key or sign in with ChatGPT.
- Open **AI Tools → Agent Access** for the server URL and the steps for each client.
- If the site runs behind Cloudflare, add a WAF rule that skips the security checks for `/wp-json/straps/v1/mcp`, `/.well-known/oauth-*`, `/register`, `/authorize`, and `/token`. The **Agent Access** screen has the exact expression.

## Connecting AI Clients

### Claude (Web & Desktop App)

- Open **Settings → Connectors**, then **Add connector → Add custom connector**.
- Paste the server URL, for example `https://example.com/wp-json/straps/v1/mcp`.
- Sign in to WordPress on the authorization screen and click **Allow**.

### Claude Code

```bash
claude mcp add --transport http straps https://example.com/wp-json/straps/v1/mcp
```

Then run `/mcp` inside Claude Code and choose to authenticate.

### ChatGPT

- Open **Plugins** from the ChatGPT sidebar, then **Add → Add custom MCP server**.
- Paste the server URL and keep **OAuth** as the authentication method. ChatGPT discovers the OAuth endpoints and registers itself.
- Confirm the trust warning, click **Create as a plugin**, then sign in to WordPress and click **Allow**.

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
│   ├── includes/      Server, OAuth, models, batch jobs, admin, and tools/
│   ├── parts/         Admin screen templates
│   └── index.php      Plugin header and loader
├── bin/release.php    Builds the release ZIP and publishes GitHub releases
├── tests/             PHPUnit integration tests (core/ and tools/)
├── composer.json
└── phpunit.xml
```

### Tests

The integration suite boots WordPress with `wp-phpunit/wp-phpunit` and runs against a disposable database.

- Run `composer install`.
- Create an empty `wordpress_test` database. The suite wipes it on every run. The connection settings are in `phpunit.xml` and `tests/config.php`.
- WordPress core is loaded from four levels above `tests/`, for example `D:\Work\` for `D:\Work\wp-content\plugins\twee-mcp\tests\`.
- WooCommerce tests run when WooCommerce is found next to this repository in `wp-content/plugins/woocommerce/`, or in the folder set by the `WOOCOMMERCE_DIR` environment variable. Without it they are skipped.
- ACF term field tests run when ACF Pro is found in `wp-content/plugins/advanced-custom-fields-pro/`, or in the folder set by `ACF_DIR`.
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

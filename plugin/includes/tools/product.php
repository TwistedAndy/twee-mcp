<?php
/**
 * WooCommerce Products Tools & Content Generation
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * MCP tools for WooCommerce products
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_product_tools(): array
{
	$product_params = twcp_product_parameters();

	return [
		'get_products'                       => [
			'description' => 'Read WooCommerce products with prices, SKU, stock, attributes, term links, featured image, and gallery IDs. Filter by category/taxonomy, text, SKU, stock status, low stock, or ID.',
			'callable'    => 'twcp_product_query',
			'parameters'  => [
				'id'           => ['type' => 'integer', 'description' => 'Single product ID.'],
				'sku'          => ['type' => 'string', 'description' => 'Filter by product SKU.'],
				'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
				'stock_below'  => ['type' => 'integer', 'description' => 'Only stock-managed products with fewer units than this.'],
				'search'      => ['type' => 'string', 'description' => 'Keyword search.'],
				'post_status' => ['type' => 'string', 'description' => 'Status: "publish", "draft", etc. (default "publish").'],
				'taxonomy'    => ['type' => 'string', 'description' => 'Filter taxonomy.'],
				'term_id'     => ['type' => 'integer', 'description' => 'Filter term ID.'],
				'limit'       => ['type' => 'integer', 'description' => 'Number of products (default 20, max 50).'],
				'offset'      => ['type' => 'integer', 'description' => 'Pagination offset.'],
				'order_by'    => ['type' => 'string', 'description' => 'Sort field.'],
				'order'       => ['type' => 'string', 'description' => '"ASC" or "DESC".'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'create_product'                     => [
			'description' => 'Create a WooCommerce product using its CRUD API, with prices, SKU, stock, attributes, categories, images, and SEO metadata. Defaults to draft.',
			'callable'    => 'twcp_product_create',
			'required'    => ['name'],
			'parameters'  => $product_params,
			'annotations' => ['readOnlyHint' => false],
		],
		'edit_product'                       => [
			'description' => 'Edit an existing WooCommerce product or variation. Supports prices, stock, attributes, images, gallery IDs, SEO title, and SEO meta description. Omitted fields are preserved.',
			'callable'    => 'twcp_product_edit',
			'required'    => ['product_id'],
			'parameters'  => ['product_id' => ['type' => 'integer', 'minimum' => 1]] + $product_params,
			'annotations' => ['readOnlyHint' => false],
		],
		'generate_product_description'       => [
			'description' => 'Generate comprehensive HTML product description with technical specifications, materials, and internal links for a WooCommerce product.',
			'callable'    => 'twcp_product_desc_tool',
			'required'    => ['product_id'],
			'parameters'  => [
				'product_id' => ['type' => 'integer', 'description' => 'WooCommerce product ID.'],
				'regenerate' => ['type' => 'boolean', 'description' => 'Force regeneration even if description already exists (default false).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'generate_product_short_description' => [
			'description' => 'Generate concise, high-CTR short product description (excerpt) within 130-155 characters for a WooCommerce product and update SEO meta tags.',
			'callable'    => 'twcp_product_short_tool',
			'required'    => ['product_id'],
			'parameters'  => [
				'product_id' => ['type' => 'integer', 'description' => 'WooCommerce product ID.'],
				'regenerate' => ['type' => 'boolean', 'description' => 'Force regeneration even if short description already exists (default false).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'get_product_variations'             => [
			'description' => 'List the variations of a variable WooCommerce product with their attributes, SKU, prices, stock, and image.',
			'callable'    => 'twcp_product_variations',
			'required'    => ['product_id'],
			'parameters'  => [
				'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Variable product ID.'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'save_product_variation'             => [
			'description' => 'Create a variation of a variable WooCommerce product, or edit one by variation_id. Attributes map variation attribute names to option values, e.g. {"Color": "Black"}. Omitted fields are preserved.',
			'callable'    => 'twcp_product_variation_save',
			'required'    => ['product_id'],
			'parameters'  => [
				'product_id'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Parent variable product ID.'],
				'variation_id'   => ['type' => 'integer', 'description' => 'Existing variation ID to edit.'],
				'attributes'     => ['type' => 'object', 'description' => 'Attribute name => option value; required for new variations.'],
				'status'         => ['type' => 'string', 'enum' => ['publish', 'private']],
				'sku'            => ['type' => 'string'],
				'regular_price'  => ['type' => 'string'],
				'sale_price'     => ['type' => 'string'],
				'manage_stock'   => ['type' => 'boolean'],
				'stock_quantity' => ['type' => 'integer'],
				'stock_status'   => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
				'weight'         => ['type' => 'string'],
				'description'    => ['type' => 'string'],
				'image_id'       => ['type' => 'integer'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'select_related_products'            => [
			'description' => 'Select complementary, alternative, or cross-sell products for a given WooCommerce product from the same category or brand using AI merchandising analysis.',
			'callable'    => 'twcp_product_select_related',
			'required'    => ['product_id'],
			'parameters'  => [
				'product_id' => ['type' => 'integer', 'description' => 'WooCommerce product ID.'],
				'limit'      => ['type' => 'integer', 'description' => 'Max number of related products to select (default 4, max 10).'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
	];
}


/**
 * Check if WooCommerce is active
 *
 * @return bool
 */
function twcp_product_active(): bool
{
	return (bool) apply_filters('twcp_product_active', class_exists('WooCommerce'));
}


/**
 * Get product parameter schema for tool registrations
 *
 * @return array
 */
function twcp_product_parameters(): array
{
	return [
		'name'               => ['type' => 'string'],
		'slug'               => ['type' => 'string'],
		'type'               => ['type' => 'string', 'enum' => ['simple', 'variable', 'grouped', 'external']],
		'status'             => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
		'description'        => ['type' => 'string'],
		'short_description'  => ['type' => 'string'],
		'seo_title'          => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
		'seo_description'    => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
		'sku'                => ['type' => 'string'],
		'regular_price'      => ['type' => 'string'],
		'sale_price'         => ['type' => 'string'],
		'manage_stock'       => ['type' => 'boolean'],
		'stock_quantity'     => ['type' => 'integer'],
		'stock_status'       => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
		'weight'             => ['type' => 'string'],
		'category_ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
		'tag_ids'            => ['type' => 'array', 'items' => ['type' => 'integer']],
		'image_id'           => ['type' => 'integer'],
		'gallery_image_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
		'cross_sell_ids'     => ['type' => 'array', 'items' => ['type' => 'integer']],
		'upsell_ids'         => ['type' => 'array', 'items' => ['type' => 'integer']],
		'default_attributes' => ['type' => 'object'],
		'attributes'         => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'required'   => ['name'],
				'properties' => [
					'name'      => ['type' => 'string'],
					'options'   => ['type' => 'array', 'items' => ['type' => 'string']],
					'position'  => ['type' => 'integer'],
					'visible'   => ['type' => 'boolean'],
					'variation' => ['type' => 'boolean'],
				],
			],
		],
	];
}


/**
 * Query WooCommerce products
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_query(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$args['post_type'] = 'product';

	if (!empty($args['sku'])) {
		$product_id = wc_get_product_id_by_sku(sanitize_text_field($args['sku']));
		if ($product_id) {
			$args['id'] = $product_id;
		} else {
			return ['posts' => []];
		}
	}

	return twcp_post_query($args);
}


/**
 * Create a simple WooCommerce product
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_create(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$type = sanitize_key($args['type'] ?? 'simple');
	$product = match ($type) {
		'variable' => new WC_Product_Variable(),
		'grouped' => new WC_Product_Grouped(),
		'external' => new WC_Product_External(),
		default => new WC_Product_Simple(),
	};

	return twcp_product_persist($product, $args);
}


/**
 * Edit an existing WooCommerce product
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_edit(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$product_id = absint($args['product_id'] ?? 0);
	if (!$product_id) {
		return ['error' => 'Product ID is required'];
	}

	$product = wc_get_product($product_id);
	if (!($product instanceof WC_Product)) {
		return ['error' => 'Product not found: ' . $product_id];
	}

	return twcp_product_persist($product, $args);
}


/**
 * Save product fields via WC CRUD API
 *
 * @param WC_Product $product
 * @param array      $args
 *
 * @return array
 */
function twcp_product_persist(WC_Product $product, array $args): array
{
	$attributes = [];

	foreach ($args['attributes'] ?? [] as $position => $data) {
		if (empty($data['name'])) {
			continue;
		}

		$name = sanitize_text_field($data['name']);
		$attribute = new WC_Product_Attribute();
		$taxonomy_id = wc_attribute_taxonomy_id_by_name($name);

		if ($taxonomy_id) {
			$taxonomy_name = wc_attribute_taxonomy_name($name);
			$term_ids = [];

			foreach ($data['options'] ?? [] as $term_name) {
				$term = get_term_by('name', sanitize_text_field($term_name), $taxonomy_name);
				if (!$term) {
					$created = wp_insert_term(sanitize_text_field($term_name), $taxonomy_name);
					if (!is_wp_error($created)) {
						$term_ids[] = (int) $created['term_id'];
					}
				} else {
					$term_ids[] = (int) $term->term_id;
				}
			}

			$attribute->set_id($taxonomy_id);
			$attribute->set_name($taxonomy_name);
			$attribute->set_options($term_ids);
		} else {
			$attribute->set_options(array_map('sanitize_text_field', $data['options'] ?? []));
		}
		$attribute->set_name($name);
		$attribute->set_position($position);
		$attribute->set_visible($data['visible'] ?? true);
		$attribute->set_variation($data['variation'] ?? false);
		$attributes[] = $attribute;
	}

	try {
		if (!$product->get_id()) {
			$product->set_status('draft');
		}

		foreach (twcp_product_parameters() as $field => $schema) {
			if (!array_key_exists($field, $args) or in_array($field, ['type', 'attributes', 'seo_title', 'seo_description'], true)) {
				continue;
			}

			$value = $args[$field];
			if (in_array($field, ['description', 'short_description'], true)) {
				$value = wp_kses_post($value);
			} elseif ($schema['type'] === 'string') {
				$value = sanitize_text_field($value);
			}

			$product->{'set_' . $field}($value);
		}

		if (isset($args['attributes'])) {
			$product->set_attributes($attributes);
		}

		$product->save();
		twcp_post_update_seo($product->get_id(), $args);

		return ['success' => true, 'product' => twcp_post_format_details(get_post($product->get_id()))];
	} catch (WC_Data_Exception $error) {
		return ['error' => $error->getMessage()];
	}
}


/**
 * Variable product for variation tools
 *
 * @param int $product_id
 *
 * @return WC_Product_Variable|string Product or error message
 */
function twcp_product_variable(int $product_id)
{
	if (!twcp_product_active()) {
		return 'WooCommerce is not active';
	}

	$product = wc_get_product($product_id);
	if (!($product instanceof WC_Product_Variable)) {
		return 'Variable product not found: ' . $product_id;
	}

	return $product;
}


/**
 * Format a product variation for MCP responses
 *
 * @param WC_Product_Variation $variation
 *
 * @return array
 */
function twcp_product_variation_format(WC_Product_Variation $variation): array
{
	return [
		'id'             => $variation->get_id(),
		'status'         => $variation->get_status(),
		'attributes'     => $variation->get_attributes(),
		'sku'            => $variation->get_sku(),
		'regular_price'  => $variation->get_regular_price(),
		'sale_price'     => $variation->get_sale_price(),
		'manage_stock'   => $variation->get_manage_stock(),
		'stock_quantity' => $variation->get_stock_quantity(),
		'stock_status'   => $variation->get_stock_status(),
		'weight'         => $variation->get_weight(),
		'image_id'       => $variation->get_image_id(),
	];
}


/**
 * Action/Tool: List the variations of a variable product
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_variations(array $args): array
{
	$product = twcp_product_variable(absint($args['product_id'] ?? 0));
	if (is_string($product)) {
		return ['error' => $product];
	}

	$variations = array_filter(array_map('wc_get_product', $product->get_children()));

	return [
		'product_id' => $product->get_id(),
		'attributes' => array_map(fn($attribute) => $attribute->get_options(), array_filter($product->get_attributes(), fn($attribute) => $attribute->get_variation())),
		'variations' => array_values(array_map('twcp_product_variation_format', $variations)),
	];
}


/**
 * Action/Tool: Create or edit a product variation
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_variation_save(array $args): array
{
	$product = twcp_product_variable(absint($args['product_id'] ?? 0));
	if (is_string($product)) {
		return ['error' => $product];
	}

	if (!empty($args['variation_id'])) {
		$variation = wc_get_product(absint($args['variation_id']));
		if (!($variation instanceof WC_Product_Variation) or $variation->get_parent_id() !== $product->get_id()) {
			return ['error' => 'Variation not found for this product.'];
		}
	} else {
		if (empty($args['attributes'])) {
			return ['error' => 'Attributes are required for a new variation.'];
		}
		$variation = new WC_Product_Variation();
		$variation->set_parent_id($product->get_id());
	}

	// Map attribute labels and names to the parent's variation attribute keys
	if (isset($args['attributes'])) {
		$allowed = array_filter($product->get_attributes(), fn($attribute) => $attribute->get_variation());
		$values = [];
		foreach ((array) $args['attributes'] as $name => $value) {
			$key = sanitize_title($name);
			$match = $allowed[$key] ?? $allowed['pa_' . $key] ?? null;
			if (!$match) {
				return ['error' => 'Unknown variation attribute: ' . $name . '. Available: ' . implode(', ', array_keys($allowed))];
			}

			$value = sanitize_text_field((string) $value);
			if ($match->is_taxonomy()) {
				$term = get_term_by('name', $value, $match->get_name()) ? : get_term_by('slug', sanitize_title($value), $match->get_name());
				if (!$term or !in_array($term->term_id, $match->get_options(), true)) {
					return ['error' => sprintf('"%s" is not an option of %s.', $value, $name)];
				}
				$value = $term->slug;
			}
			$values[sanitize_title($match->get_name())] = $value;
		}
		$variation->set_attributes($values);
	}

	try {
		foreach (['status', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'weight', 'description', 'image_id'] as $field) {
			if (array_key_exists($field, $args)) {
				$value = $args[$field];
				if ($field === 'description') {
					$value = wp_kses_post($value);
				} elseif (is_string($value)) {
					$value = sanitize_text_field($value);
				}
				$variation->{'set_' . $field}($value);
			}
		}
		$variation->save();
	} catch (WC_Data_Exception $error) {
		return ['error' => $error->getMessage()];
	}

	return ['success' => true, 'variation' => twcp_product_variation_format(wc_get_product($variation->get_id()))];
}


/**
 * Get product description copywriting settings
 *
 * @return array
 */
function twcp_product_settings(): array
{
	$defaults = [
		'max_links'    => 6,
		'custom_rules' => "Avoid using the word StrapsCo.\nHighlight quick-release spring bars and hardware quality.\nUse natural descriptive anchor text for internal links.",
	];

	$saved = get_option('twcp_product_settings', get_option('twcp_product_desc_settings', []));

	return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
}


/**
 * Default built-in system prompt for full product descriptions (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_full_default(): string
{
	$site_name = get_bloginfo('name') ? : 'Ele Straps';
	$settings = twcp_product_settings();
	$max_links = (int) $settings['max_links'] ? : 6;

	return <<<PROMPT
You are a master horological copywriter and e-commerce conversion specialist crafting premium, informative product descriptions for {$site_name}.

CORE PRINCIPLES & GUIDELINES:

1. TARGET AUDIENCE & TONE:
   - Passionate watch enthusiasts, collectors, and everyday timepiece owners seeking superior quality, comfort, and aesthetics.
   - Tone: Authoritative, refined, engaging, and trustworthy.

2. STRUCTURE OF THE DESCRIPTION (Clean Semantic HTML):
   - Opening Hook: 1-2 compelling paragraphs highlighting the strap's aesthetic character, leather/material origin, and transformation of the watch's presence.
   - Design & Craftsmanship: Deep dive into the stitching technique, edge finishing, taper proportions, and hardware durability.
   - Fitment & Compatibility: Clear details on lug width, buckle sizing, spring bar mechanism (especially quick-release), and recommended timepieces.
   - Key Specifications Summary: An unordered list (<ul>) with key specs (Material, Lug Width, Buckle Width, Length, Hardware Finish, Thickness).

3. STRAP & ACCESSORY EXPERTISE:
   - Emphasize genuine material properties (e.g. top-grain Italian leather, FKM rubber resilience, 316L stainless steel hardware).
   - Detail comfort, wrist conformity, break-in period, and sweat resistance where relevant.

4. NEGATIVE CONSTRAINTS (STRICT):
   - NEVER use the word "StrapsCo" anywhere in the text or metadata.
   - NEVER use emojis in headings, body copy, or lists.
   - Avoid cheesy marketing clichés (e.g. "look no further", "game changer", "elevate your wrist game").

5. SEO & CONVERSION FOCUS:
   - Naturally integrate high-volume search keywords (e.g. "quick-release watch strap", "vintage leather band", "replacement watch bracelet").
   - Cross-referencing compatible watch models and complementary straps or accessories using natural anchor text.

6. INTERNAL LINKING RULES:
   - Embed between 3 and {$max_links} links using ONLY the approved links provided in the request.
   - Never invent or fabricate URLs not provided in the prompt.
   - Do not output naked URLs; all links must be wrapped in valid <a> tags with contextually natural anchor text.
   - Distribute links evenly across paragraphs; avoid grouping links together in a single sentence.

7. COMPLIANCE & TONE:
   - Maintain a refined, knowledgeable, and horology-literate tone (avoid excessive hyperbole like "revolutionary" or "jaw-dropping").
   - Output must be clean, valid HTML ready for direct publication.
PROMPT;
}


/**
 * Active system prompt for product descriptions (user configured or default)
 *
 * @return string
 */
function twcp_prompt_full(): string
{
	$system = twcp_prompt('twcp_system_prompt_product', 'twcp_prompt_full_default');

	$settings = twcp_product_settings();
	if (!empty($settings['custom_rules'])) {
		$rules = trim($settings['custom_rules']);
		if (strpos($system, $rules) === false) {
			$system .= "\n\nCUSTOM STORE GUIDELINES:\n" . $rules;
		}
	}

	return $system;
}


/**
 * Default built-in system prompt for Short Product Description / excerpt generation (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_short_default(): string
{
	$store_name = get_bloginfo('name') ? : 'Ele Straps';

	return <<<PROMPT
You are an expert e-commerce search marketing copywriter crafting concise, high-CTR product summary excerpts for {$store_name}, a premier retailer of watch straps, bands, deployant clasps, and horological accessories.

CORE OBJECTIVE:
Generate a compelling, concise product short description (excerpt) designed for Google Search meta snippets and WooCommerce catalog listings.

REQUIREMENTS:
1. STRICT CHARACTER LIMIT: Strictly between 130 and 155 characters (including spaces).
2. STYLE: Engaging, benefits-driven, and action-oriented for watch collectors and casual shoppers alike.
3. CONTENT: State the strap material, key design highlight (e.g. quick-release bars, waterproof, vintage contrast stitch, deployant clasp), and primary timepiece compatibility.
4. FORMAT: Plain text only. No HTML tags, no quotes, no markdown, and no hashtags.
5. NEGATIVE CONSTRAINT: Never use the word "StrapsCo".
PROMPT;
}


/**
 * Gather all technical attributes, brand, and categories assigned to a product
 *
 * @param WC_Product $product
 *
 * @return array
 */
function twcp_product_gather_specs(WC_Product $product): array
{
	$product_id = $product->get_id();
	$attributes = [];

	// Gather all product attributes assigned to the product
	$product_attributes = $product->get_attributes();
	if (is_array($product_attributes)) {
		foreach ($product_attributes as $key => $attr_obj) {
			if (is_a($attr_obj, 'WC_Product_Attribute')) {
				$attr_label = wc_attribute_label($attr_obj->get_name(), $product);
				$values = [];
				if ($attr_obj->is_taxonomy()) {
					$terms = wp_get_post_terms($product_id, $attr_obj->get_name());
					if (!is_wp_error($terms) and !empty($terms)) {
						foreach ($terms as $t) {
							$values[] = $t->name;
						}
					}
				} else {
					$opts = $attr_obj->get_options();
					$values = is_array($opts) ? $opts : array_map('trim', explode('|', (string) $opts));
				}
				if (!empty($values)) {
					$attributes[] = $attr_label . ': ' . implode(', ', array_unique($values));
				}
			}
		}
	}

	// Product Categories
	$cats = wp_get_post_terms($product_id, 'product_cat');
	if (!is_wp_error($cats) and !empty($cats)) {
		$attributes[] = 'Category: ' . implode(', ', wp_list_pluck($cats, 'name'));
	}

	// Product Brands
	$brands = wp_get_post_terms($product_id, 'product_brand');
	if (!is_wp_error($brands) and !empty($brands)) {
		$attributes[] = 'Brand: ' . implode(', ', wp_list_pluck($brands, 'name'));
	}

	return $attributes;
}


/**
 * Generate a full HTML product description with approved internal links
 *
 * @param int  $product_id
 * @param bool $regenerate
 *
 * @return bool
 */
function twcp_product_full(int $product_id, bool $regenerate = false): bool
{
	if (!twcp_product_active()) {
		return false;
	}

	$product = wc_get_product($product_id);
	if (!($product instanceof WC_Product)) {
		return false;
	}

	$current_desc = $product->get_description();
	if (!empty($current_desc) and !$regenerate) {
		return true;
	}

	$settings = twcp_product_settings();
	$name = $product->get_name();
	$attributes = twcp_product_gather_specs($product);
	$cats = wp_get_post_terms($product_id, 'product_cat');
	$brands = wp_get_post_terms($product_id, 'product_brand');

	// Gather approved internal links from all assigned categories, brands, and attribute terms
	$allowed_links = [];

	if (!empty($cats) and !is_wp_error($cats)) {
		foreach ($cats as $cat) {
			$link = get_term_link($cat);
			if (!is_wp_error($link)) {
				$allowed_links[] = '<a href="' . esc_url($link) . '">' . esc_html($cat->name) . '</a>';
			}
		}
	}

	if (!empty($brands) and !is_wp_error($brands)) {
		foreach ($brands as $b) {
			$link = get_term_link($b);
			if (!is_wp_error($link)) {
				$allowed_links[] = '<a href="' . esc_url($link) . '">' . esc_html($b->name) . '</a>';
			}
		}
	}

	foreach ($product->get_attributes() as $attr_obj) {
		if (is_a($attr_obj, 'WC_Product_Attribute') and $attr_obj->is_taxonomy()) {
			$terms = wp_get_post_terms($product_id, $attr_obj->get_name());
			if (!is_wp_error($terms) and !empty($terms)) {
				foreach (array_slice($terms, 0, 3) as $t) {
					$link = get_term_link($t);
					if (!is_wp_error($link)) {
						$allowed_links[] = '<a href="' . esc_url($link) . '">' . esc_html($t->name) . '</a>';
					}
				}
			}
		}
	}

	if (!empty($settings['include_related_products']) and function_exists('wc_get_related_products')) {
		$related_ids = wc_get_related_products($product_id, 3);
		foreach ($related_ids as $rel_id) {
			$rel_title = get_the_title($rel_id);
			$rel_url = get_permalink($rel_id);
			if ($rel_title and $rel_url) {
				$allowed_links[] = '<a href="' . esc_url($rel_url) . '">' . esc_html($rel_title) . '</a>';
			}
		}
	}

	$user_prompt = "Generate a full HTML product description for the following item:\n\n";
	$user_prompt .= "Product Title: {$name}\n\n";

	if ($attributes) {
		$user_prompt .= "Product Technical Specifications:\n" . implode("\n", $attributes) . "\n\n";
	}

	if (!empty($current_desc)) {
		$user_prompt .= "Current Full Description to review and improve/rewrite:\n" . substr(wp_strip_all_tags($current_desc), 0, 2500) . "\n\n";
	}

	$short_current = $product->get_short_description();
	if (!empty($short_current)) {
		$user_prompt .= "Current Summary Context: " . wp_strip_all_tags($short_current) . "\n\n";
	}

	if ($allowed_links) {
		$user_prompt .= "Approved Permalinks & Categories for Natural Internal Linking (use up to " . ((int) $settings['max_links'] ? : 6) . "):\n";
		$user_prompt .= implode("\n", array_unique($allowed_links)) . "\n\n";
	}

	$user_prompt .= "Remember: Output only clean HTML without enclosing markdown blocks.";

	$system = twcp_prompt_full();
	// Approved links and specs are in the prompt; lookups only verify product and term facts
	$long_content = twcp_model_request($user_prompt, $system, [], ['temperature' => 0.5, 'read_tools' => ['get_products', 'get_terms', 'get_posts']]);

	if (!empty($long_content)) {
		$product->set_description(wp_kses_post($long_content));
		$product->update_meta_data('_twcp_ai_content', 1);
		$product->save();

		return true;
	}

	return false;
}


/**
 * Generate concise, high-CTR short product description (excerpt)
 *
 * @param int  $product_id
 * @param bool $regenerate
 *
 * @return bool
 */
function twcp_product_short(int $product_id, bool $regenerate = false): bool
{
	if (!twcp_product_active()) {
		return false;
	}

	$product = wc_get_product($product_id);
	if (!($product instanceof WC_Product)) {
		return false;
	}

	$current_short = $product->get_short_description();
	if (!empty($current_short) and !$regenerate) {
		return true;
	}

	$name = $product->get_name();
	$attributes = twcp_product_gather_specs($product);

	$short_user_prompt = "Product Name: {$name}\n";
	if (!empty($current_short)) {
		$short_user_prompt .= "Current Short Description to review and improve/rewrite: \"{$current_short}\"\n";
	}
	if ($attributes) {
		$short_user_prompt .= "Attributes: " . implode(', ', array_slice($attributes, 0, 4)) . "\n";
	}

	$short_system = twcp_prompt('twcp_system_prompt_product_short', 'twcp_prompt_short_default');
	$short_content = twcp_model_request($short_user_prompt, $short_system, [], ['temperature' => 0.4]);

	if (!empty($short_content)) {
		$short_clean = sanitize_text_field(trim($short_content, "\"' \t\n\r"));
		$product->set_short_description($short_clean);
		$product->update_meta_data('rank_math_description', $short_clean);
		update_post_meta($product_id, '_yoast_wpseo_metadesc', $short_clean);
		update_post_meta($product_id, 'rank_math_description', $short_clean);
		$product->save();

		return true;
	}

	return false;
}


/**
 * MCP tool wrapper: generate full product description
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_desc_tool(array $args): array
{
	if (empty($args['product_id'])) {
		return ['error' => 'product_id parameter is required'];
	}

	$product_id = absint($args['product_id']);
	$regenerate = !empty($args['regenerate']);
	$success = twcp_product_full($product_id, $regenerate);

	if (!$success) {
		return ['error' => 'Failed to generate product description for product ID ' . $product_id];
	}

	$product = wc_get_product($product_id);

	return [
		'success'     => true,
		'product_id'  => $product_id,
		'description' => $product ? $product->get_description() : '',
	];
}


/**
 * MCP tool wrapper: generate short product description
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_short_tool(array $args): array
{
	if (empty($args['product_id'])) {
		return ['error' => 'product_id parameter is required'];
	}

	$product_id = absint($args['product_id']);
	$regenerate = !empty($args['regenerate']);
	$success = twcp_product_short($product_id, $regenerate);

	if (!$success) {
		return ['error' => 'Failed to generate short description for product ID ' . $product_id];
	}

	$product = wc_get_product($product_id);

	return [
		'success'           => true,
		'product_id'        => $product_id,
		'short_description' => $product ? $product->get_short_description() : '',
	];
}


/**
 * System prompt for selecting related/cross-sell products
 *
 * @return string
 */
function twcp_prompt_related(): string
{
	$site_name = get_bloginfo('name') ? : 'Ele Straps';

	return <<<PROMPT
You are a senior e-commerce merchandising specialist for {$site_name}, a premier retailer of watch straps, bands, and accessories.

OBJECTIVE:
Given a target product and a candidate list of other products in the same category or brand, select the top complementary, alternative, or cross-sell products that maximize customer satisfaction, cart size, and average order value.

OUTPUT FORMAT:
Return strictly a valid JSON object without surrounding markdown blocks:
{
  "selected_ids": [123, 456, 789],
  "recommendations": [
    { "id": 123, "reason": "Explanation of why this complements the target product" }
  ]
}
PROMPT;
}


/**
 * Select complementary or cross-sell products for a given WooCommerce product (read-only).
 *
 * @param array $args
 *
 * @return array
 */
function twcp_product_select_related(array $args): array
{
	if (empty($args['product_id'])) {
		return ['error' => 'product_id parameter is required'];
	}

	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$product_id = absint($args['product_id']);
	$product = wc_get_product($product_id);
	if (!($product instanceof WC_Product)) {
		return ['error' => 'Product not found: ' . $product_id];
	}

	$limit = isset($args['limit']) ? min(10, max(1, (int) $args['limit'])) : 4;

	// Get categories & brands
	$cat_ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
	$brand_ids = wp_get_post_terms($product_id, 'product_brand', ['fields' => 'ids']);

	$query_args = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 25,
		'exclude'        => [$product_id],
	];

	if (!empty($cat_ids) and !is_wp_error($cat_ids)) {
		$query_args['tax_query'] = [
			[
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cat_ids,
			],
		];
	}

	$candidate_posts = get_posts($query_args);
	if (empty($candidate_posts) and !empty($brand_ids) and !is_wp_error($brand_ids)) {
		$query_args['tax_query'] = [
			[
				'taxonomy' => 'product_brand',
				'field'    => 'term_id',
				'terms'    => $brand_ids,
			],
		];
		$candidate_posts = get_posts($query_args);
	}

	if (empty($candidate_posts)) {
		unset($query_args['tax_query']);
		$candidate_posts = get_posts($query_args);
	}

	$candidates = [];
	foreach ($candidate_posts as $cp) {
		$cprod = wc_get_product($cp->ID);
		if (!$cprod instanceof WC_Product) {
			continue;
		}
		$candidates[$cp->ID] = [
			'id'        => $cp->ID,
			'title'     => $cp->post_title,
			'price'     => $cprod->get_price(),
			'permalink' => get_permalink($cp->ID),
			'image'     => wp_get_attachment_image_url($cprod->get_image_id(), 'medium') ? : null,
		];
	}

	if (empty($candidates)) {
		return [
			'product_id'        => $product_id,
			'product_name'      => $product->get_name(),
			'selected_ids'      => [],
			'selected_products' => [],
		];
	}

	// Build prompt for AI model with candidates
	$system = twcp_prompt_related();

	$candidate_lines = [];
	foreach ($candidates as $cid => $cinfo) {
		$candidate_lines[] = "- ID {$cid}: \"{$cinfo['title']}\" (Price: \${$cinfo['price']})";
	}

	$user_prompt = "Target Product:\n";
	$user_prompt .= "- ID {$product_id}: \"{$product->get_name()}\"\n";
	$user_prompt .= "- Price: \${$product->get_price()}\n\n";
	$user_prompt .= "Available Candidate Products in Same Category/Brand:\n";
	$user_prompt .= implode("\n", $candidate_lines) . "\n\n";
	$user_prompt .= "Select up to {$limit} top complementary or cross-sell products. Return valid JSON only.";

	// Every candidate is listed in the prompt, so no lookup tools are needed
	$model_response = twcp_model_request($user_prompt, $system, [], ['temperature' => 0.3]);
	$selected_ids = [];
	$recommendations = [];

	$decoded = twcp_model_response($model_response);
	if (is_array($decoded)) {
		if (!empty($decoded['selected_ids']) and is_array($decoded['selected_ids'])) {
			$selected_ids = array_map('intval', $decoded['selected_ids']);
		}
		if (!empty($decoded['recommendations']) and is_array($decoded['recommendations'])) {
			$recommendations = $decoded['recommendations'];
		}
	}

	if (empty($selected_ids)) {
		$selected_ids = array_slice(array_keys($candidates), 0, $limit);
	}

	$selected_ids = array_slice(array_unique(array_filter($selected_ids, function($id) use ($candidates) {
		return isset($candidates[$id]);
	})), 0, $limit);

	$selected_products = [];
	foreach ($selected_ids as $sid) {
		$prod_data = $candidates[$sid];
		$reason = '';
		foreach ($recommendations as $rec) {
			if (isset($rec['id']) and (int) $rec['id'] === $sid) {
				$reason = $rec['reason'] ?? '';
				break;
			}
		}
		$prod_data['reason'] = $reason ? : 'Complementary collection accessory in the same category.';
		$selected_products[] = $prod_data;
	}

	return [
		'product_id'        => $product_id,
		'product_name'      => $product->get_name(),
		'selected_ids'      => $selected_ids,
		'selected_products' => $selected_products,
	];
}

<?php
/**
 * WooCommerce Products Tools & Content Generation
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


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
		'length'             => ['type' => 'string'],
		'width'              => ['type' => 'string'],
		'height'             => ['type' => 'string'],
		'category_ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
		'tag_ids'            => ['type' => 'array', 'items' => ['type' => 'integer']],
		'image_id'           => ['type' => 'integer'],
		'gallery_image_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
		'cross_sell_ids'     => ['type' => 'array', 'items' => ['type' => 'integer']],
		'upsell_ids'         => ['type' => 'array', 'items' => ['type' => 'integer']],
		'default_attributes' => ['type' => 'object'],
		'attributes'         => [
			'type'        => 'array',
			'description' => 'Replaces all attributes of the product, so include the ones to keep, as get_products returns them. A name of a store-wide attribute, such as "Color" or "pa_color", links its terms and creates missing ones.',
			'items'       => [
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

	if (!empty($args['id']) and !in_array(get_post_type(absint($args['id'])), ['product', 'product_variation'], true)) {
		return ['error' => 'Product not found: ' . absint($args['id'])];
	}

	return twcp_post_query($args);
}


/**
 * Whether the current user can create a product with the status the create_product tool names
 *
 * @param array $input
 *
 * @return string|null Error message, or null when allowed
 */
function twcp_product_create_access(array $input): ?string
{
	return twcp_tools_access_create('product', $input['status'] ?? 'draft');
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
		$options = array_map('sanitize_text_field', (array) ($data['options'] ?? []));
		$attribute = new WC_Product_Attribute();
		$taxonomy_id = wc_attribute_taxonomy_id_by_name($name);

		// A store-wide attribute is saved under its pa_ taxonomy, which WooCommerce needs to read it back
		if ($taxonomy_id) {
			$taxonomy = wc_attribute_taxonomy_name_by_id($taxonomy_id);
			$term_ids = [];

			foreach ($options as $option) {
				$term = get_term_by('name', $option, $taxonomy) ? : get_term_by('slug', sanitize_title($option), $taxonomy);
				if ($term) {
					$term_ids[] = (int) $term->term_id;
				} else {
					$created = wp_insert_term($option, $taxonomy);
					if (!is_wp_error($created)) {
						$term_ids[] = (int) $created['term_id'];
					}
				}
			}

			$attribute->set_id($taxonomy_id);
			$attribute->set_name($taxonomy);
			$attribute->set_options($term_ids);
		} else {
			$attribute->set_name($name);
			$attribute->set_options($options);
		}
		$attribute->set_position((int) ($data['position'] ?? $position));
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

	$attributes = [];
	foreach (twcp_post_attributes($product) as $attribute) {
		if ($attribute['variation']) {
			$attributes[$attribute['name']] = $attribute['options'];
		}
	}

	return [
		'product_id' => $product->get_id(),
		'attributes' => $attributes,
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
		foreach (['status', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'weight', 'length', 'width', 'height', 'description', 'image_id'] as $field) {
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
	$saved = get_option('twcp_product_settings', get_option('twcp_product_desc_settings', []));
	$saved = is_array($saved) ? $saved : [];

	// Empty guidelines fall back to the built-in text, which the settings form saves as empty
	if (trim((string) ($saved['custom_rules'] ?? '')) === '') {
		unset($saved['custom_rules']);
	}

	return array_merge(twcp_product_settings_default(), $saved);
}


/**
 * Default product description copywriting settings
 *
 * @return array
 */
function twcp_product_settings_default(): array
{
	return [
		'max_links'    => 6,
		'custom_rules' => 'Use natural descriptive anchor text for internal links.',
	];
}


/**
 * Default built-in system prompt for full product descriptions (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_full_default(): string
{
	$site_name = twcp_site_name();
	$settings = twcp_product_settings();
	$max_links = (int) $settings['max_links'] ? : 6;

	return <<<PROMPT
You are a senior e-commerce copywriter writing product descriptions for {$site_name} that help shoppers decide and help search engines understand the product, following Google's and Bing's guidance for product pages and Google Merchant Center's rules for product descriptions.

CORE PRINCIPLES & GUIDELINES:

1. TARGET AUDIENCE & TONE:
   - The customers described in the store profile, from first-time buyers to experienced ones comparing options.
   - Tone: Knowledgeable, clear, specific, and trustworthy. Write for people first, not for search engines.

2. STRUCTURE OF THE DESCRIPTION (Clean Semantic HTML):
   - Opening: 1 to 2 paragraphs that name the product type and say what it is, who it is for, and its main benefit. The first sentence must make sense on its own.
   - Then 2 to 4 short sections under H2 or H3 headings that name their topic (e.g. "Materials and Build", "Sizing and Fit"), never vague labels like "Overview". Never use an H1.
   - Cover what it is made of or how it works, what sets it apart, how it is used, what it works with, sizing or variants, and care, where the product data supports it.
   - Key Specifications: An unordered list (<ul>) with the attribute names and values from the product data.
   - Keep paragraphs short and each sentence on one point. Use lists for specifications and key points, not for every line.

3. ACCURACY & SPECIFICITY:
   - Prefer measurable facts (dimensions, materials, capacity, compatibility) to general praise, and support each claim with a detail from the product data.
   - Never invent specifications, materials, certifications, compatibility, reviews, or awards. When a detail is unknown, leave it out instead of guessing.
   - Write original copy instead of repeating the manufacturer's wording or the description of a similar product.

4. NEGATIVE CONSTRAINTS (STRICT):
   - No prices, discounts, sale dates, shipping, delivery times, returns, or guarantees: they change and belong in their own product fields.
   - No emojis, decorative symbols, ALL CAPS, or exclamation marks.
   - No clichés or unsupported hype (e.g. "look no further", "game changer", "revolutionary", "next-gen").

5. SEARCH TERMS:
   - Use the product type and the terms a shopper would search for naturally, along with close synonyms. Never repeat a phrase to stuff keywords.

6. INTERNAL LINKING RULES:
   - Embed between 3 and {$max_links} links using ONLY the approved links provided in the request.
   - Never invent or fabricate URLs not provided in the prompt.
   - Do not output naked URLs; all links must be wrapped in valid <a> tags with descriptive anchor text that names the linked page.
   - Distribute links evenly across paragraphs; avoid grouping links together in a single sentence.

7. OUTPUT:
   - Clean, valid HTML ready for direct publication, in the language of the product data.
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
	$store_name = twcp_site_name();

	return <<<PROMPT
You are an e-commerce copywriter writing short product descriptions for {$store_name}. The text appears next to the price on the product page and in catalog listings, and SEO plugins often use it as the meta description.

REQUIREMENTS:
1. LENGTH: 120 to 155 characters, including spaces, in one or two sentences.
2. CONTENT: Name the product type first, then its most useful distinguishing facts (brand, material, size, or key feature) and who or what it is for, using only details from the product data.
3. STYLE: Specific and factual, written for shoppers. Use the main search term once where it reads naturally. No keyword lists, hype, or clichés.
4. EXCLUDE: Prices, discounts, shipping, guarantees, ALL CAPS, emojis, and exclamation marks.
5. FORMAT: Plain text only, with no HTML tags, quotes, markdown, or hashtags, in the language of the product data.
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

	foreach (twcp_post_attributes($product) as $attribute) {
		if ($attribute['options']) {
			$attributes[] = $attribute['name'] . ': ' . implode(', ', array_unique($attribute['options']));
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
	$user_prompt .= "Product Title: {$name}\n";
	$user_prompt .= twcp_focus_note($product_id) . "\n";

	if ($attributes) {
		$user_prompt .= "Product Technical Specifications:\n" . implode("\n", $attributes) . "\n\n";
	}

	if (!empty($current_desc)) {
		$user_prompt .= twcp_rewrite_note('full description', substr(wp_strip_all_tags($current_desc), 0, 2500));
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
	$short_user_prompt .= twcp_focus_note($product_id);
	if (!empty($current_short)) {
		$short_user_prompt .= twcp_rewrite_note('short description', "\"{$current_short}\"");
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
		$product->update_meta_data('_yoast_wpseo_metadesc', $short_clean);
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
	$product = twcp_product_active() ? wc_get_product(absint($args['product_id'] ?? 0)) : null;
	if (!($product instanceof WC_Product)) {
		return ['error' => 'Product not found: ' . absint($args['product_id'] ?? 0)];
	}

	$regenerate = !empty($args['regenerate']);
	$generated = $regenerate || $product->get_description() === '';
	if (!twcp_product_full($product->get_id(), $regenerate)) {
		return ['error' => 'Could not generate the description: ' . (twcp_model_last_error() ? : 'the model returned no text.')];
	}

	return [
		'success'     => true,
		'product_id'  => $product->get_id(),
		'generated'   => $generated,
		'description' => wc_get_product($product->get_id())->get_description(),
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
	$product = twcp_product_active() ? wc_get_product(absint($args['product_id'] ?? 0)) : null;
	if (!($product instanceof WC_Product)) {
		return ['error' => 'Product not found: ' . absint($args['product_id'] ?? 0)];
	}

	$regenerate = !empty($args['regenerate']);
	$generated = $regenerate || $product->get_short_description() === '';
	if (!twcp_product_short($product->get_id(), $regenerate)) {
		return ['error' => 'Could not generate the short description: ' . (twcp_model_last_error() ? : 'the model returned no text.')];
	}

	return [
		'success'           => true,
		'product_id'        => $product->get_id(),
		'generated'         => $generated,
		'short_description' => wc_get_product($product->get_id())->get_short_description(),
	];
}


/**
 * System prompt for selecting related/cross-sell products
 *
 * @return string
 */
function twcp_prompt_related(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior e-commerce merchandising specialist for {$site_name}.

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

	$currency = get_woocommerce_currency();
	$candidate_lines = [];
	foreach ($candidates as $cid => $cinfo) {
		$candidate_lines[] = "- ID {$cid}: \"{$cinfo['title']}\" (Price: {$cinfo['price']} {$currency})";
	}

	$user_prompt = "Target Product:\n";
	$user_prompt .= "- ID {$product_id}: \"{$product->get_name()}\"\n";
	$user_prompt .= "- Price: {$product->get_price()} {$currency}\n\n";
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

	$note = [];
	if (empty($selected_ids)) {
		$selected_ids = array_slice(array_keys($candidates), 0, $limit);
		$error = twcp_model_last_error();
		$note = ['note' => 'The model gave no answer' . ($error ? ' (' . $error . ')' : '') . ', so these are the first products of the same category.'];
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
		$prod_data['reason'] = $reason;
		$selected_products[] = $prod_data;
	}

	return [
		'product_id'        => $product_id,
		'product_name'      => $product->get_name(),
		'selected_ids'      => $selected_ids,
		'selected_products' => $selected_products,
	] + $note;
}


/**
 * Store section of the Automation screen, after content and media
 */
add_filter('twcp_batch_sections', function(array $sections): array {
	$sections['store'] = [
		'label'       => __('Store Automation', 'twee-mcp'),
		'description' => __('Product copy and merchandising for WooCommerce.', 'twee-mcp'),
		'position'    => 30,
	];

	return $sections;
});


/**
 * Product copy and cross-sell jobs on the Automation screen
 */
add_filter('twcp_batch_types', function(array $types): array {
	if (!twcp_product_active()) {
		return $types;
	}

	// Products move out of the default product category, like posts
	if (isset($types['categories'])) {
		$types['categories']['scopes'][] = 'product';
	}

	$types['products_short'] = [
		'title'       => __('Short Product Descriptions', 'twee-mcp'),
		'description' => __('Writes 120 to 155 character summaries that double as meta descriptions.', 'twee-mcp'),
		'button'      => __('Generate Short Descriptions', 'twee-mcp'),
		'section'     => 'store',
		'post_type'   => 'product',
		'field'       => 'post:post_excerpt',
		'run'         => 'twcp_product_short',
		'combine'     => true,
		'prompts'     => ['twcp_system_prompt_product_short'],
		'text'        => 'short product description (120 to 155 characters)',
		'cost'        => [1, 2500],
		'restored'    => 'wc_delete_product_transients',
	];

	$types['products'] = [
		'title'       => __('Full Product Descriptions', 'twee-mcp'),
		'description' => __('Writes HTML descriptions with specifications and internal links.', 'twee-mcp'),
		'button'      => __('Generate Full Descriptions', 'twee-mcp'),
		'section'     => 'store',
		'post_type'   => 'product',
		'field'       => 'post:post_content',
		'run'         => 'twcp_product_full',
		'prompts'     => ['twcp_system_prompt_product'],
		'text'        => 'full product description',
		'slice'       => 3,
		'cost'        => [2, 9000],
		'restored'    => 'wc_delete_product_transients',
	];

	$types['cross_sells'] = [
		'title'       => __('Cross-sells', 'twee-mcp'),
		'description' => __('Links up to 4 complementary products from the same category or brand to products without cross-sells.', 'twee-mcp'),
		'button'      => __('Link Cross-sells', 'twee-mcp'),
		'section'     => 'store',
		'post_type'   => 'product',
		'field'       => 'meta:_crosssell_ids',
		'run'         => 'twcp_product_batch_cross_sells',
		'slice'       => 3,
		'cost'        => [1, 3000],
		'restored'    => 'wc_delete_product_transients',
	];

	return $types;
});


/**
 * Stock and copy gaps in the overview of the Automation screen
 */
add_filter('twcp_batch_overview', function(array $items): array {
	if (twcp_product_active() and current_user_can('edit_others_products')) {
		$stock = function(array $meta): int {
			return (int) (new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => $meta]))->found_posts;
		};
		$low = (int) get_option('woocommerce_notify_low_stock_amount', 2);

		$items['out_of_stock'] = [
			'label' => __('Products out of stock', 'twee-mcp'),
			'count' => $stock([['key' => '_stock_status', 'value' => 'outofstock']]),
			'url'   => admin_url('edit.php?post_type=product&stock_status=outofstock'),
		];
		$items['low_stock'] = [
			'label' => sprintf(__('Products with %d or fewer in stock', 'twee-mcp'), $low),
			'count' => $stock([['key' => '_manage_stock', 'value' => 'yes'], ['key' => '_stock', 'value' => $low, 'compare' => '<=', 'type' => 'NUMERIC'], ['key' => '_stock_status', 'value' => 'instock']]),
			'url'   => admin_url('edit.php?post_type=product&stock_status=instock'),
		];
		$items['products_short'] = [
			'label' => __('Products without short descriptions', 'twee-mcp'),
			'count' => twcp_batch_total('products_short'),
			'url'   => '',
		];
	}
	if (twcp_product_active() and current_user_can('edit_shop_orders')) {
		$hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$items['orders'] = [
			'label' => __('Orders processing or on hold', 'twee-mcp'),
			'count' => (int) wc_orders_count('processing') + (int) wc_orders_count('on-hold'),
			'url'   => admin_url($hpos ? 'admin.php?page=wc-orders&status=wc-processing' : 'edit.php?post_type=shop_order&post_status=wc-processing'),
		];
	}

	return $items;
});


/**
 * Category and product copy bulk actions on the Products screen
 */
add_filter('twcp_batch_actions', function(array $screens): array {
	if (!twcp_product_active()) {
		return $screens;
	}

	$screens['edit-product']['twcp_categories'] = [
		'label' => __('Assign Categories', 'twee-mcp'),
		'run'   => 'twcp_post_assign_categories',
	];
	$screens['edit-product']['twcp_short'] = [
		'label' => __('Generate Short Product Descriptions', 'twee-mcp'),
		'run'   => 'twcp_product_short',
		'type'  => 'products_short',
	];
	$screens['edit-product']['twcp_short_regen'] = [
		'label' => __('Regenerate Short Product Descriptions', 'twee-mcp'),
		'run'   => function(int $id) {
			return twcp_product_short($id, true);
		},
	];
	$screens['edit-product']['twcp_full'] = [
		'label' => __('Generate Full Product Descriptions', 'twee-mcp'),
		'run'   => 'twcp_product_full',
		'type'  => 'products',
	];
	$screens['edit-product']['twcp_full_regen'] = [
		'label' => __('Regenerate Full Product Descriptions', 'twee-mcp'),
		'run'   => function(int $id) {
			return twcp_product_full($id, true);
		},
	];

	return $screens;
});


/**
 * Store categories with the most products as a source of internal links
 */
add_filter('twcp_post_link_sources', function(array $sources): array {
	if (twcp_product_active()) {
		$sources['products'] = __('Store categories with the most products', 'twee-mcp');
	}

	return $sources;
});

add_filter('twcp_post_links', function(array $links, WP_Post $post, array $sources): array {
	if (in_array('products', $sources, true) and twcp_product_active()) {
		$categories = get_terms(['taxonomy' => 'product_cat', 'number' => 5, 'orderby' => 'count', 'order' => 'DESC', 'exclude' => [(int) get_option('default_product_cat')]]);
		foreach (is_wp_error($categories) ? [] : $categories as $term) {
			$link = get_term_link($term);
			if (!is_wp_error($link)) {
				$links[$link] = $term->name;
			}
		}
	}

	return $links;
}, 10, 3);


/**
 * Product categories, which come after the brands of WooCommerce
 */
add_filter('twcp_post_category_taxonomy', function(string $taxonomy, string $post_type): string {
	return $post_type === 'product' ? 'product_cat' : $taxonomy;
}, 10, 2);


/**
 * Products and their categories tell prompts what a store is about
 */
add_filter('twcp_site_catalog', function(array $catalog): array {
	if (twcp_product_active()) {
		$catalog = [
			'taxonomy'  => 'product_cat',
			'post_type' => 'product',
			'items'     => 'products',
			'about'     => 'It sells products in an online store built on WooCommerce.',
		];
	}

	return $catalog;
});


/**
 * Process a single product for AI-selected cross-sells
 */
function twcp_product_batch_cross_sells(int $id, bool $override = false): array
{
	$product = wc_get_product($id);
	if (!$product) {
		return ['success' => false, 'message' => sprintf(__('Product #%d not found.', 'twee-mcp'), $id)];
	}
	if (!$override and $product->get_cross_sell_ids()) {
		return ['success' => true, 'message' => sprintf(__('Kept the existing cross-sells of "%s".', 'twee-mcp'), $product->get_name())];
	}

	$related = twcp_product_select_related(['product_id' => $id, 'limit' => 4]);
	if (empty($related['selected_ids'])) {
		return ['success' => false, 'message' => sprintf(__('No cross-sell candidates for "%s" (ID: %d).', 'twee-mcp'), $product->get_name(), $id)];
	}

	$product->set_cross_sell_ids($related['selected_ids']);
	$product->save();

	return ['success' => true, 'message' => sprintf(__('Linked cross-sells for "%s": %s', 'twee-mcp'), $product->get_name(), mb_strimwidth(implode(', ', array_column($related['selected_products'], 'title')), 0, 80, '...'))];
}


/**
 * Store prompts in the Store Automation section
 */
add_filter('twcp_prompt_groups', function(array $groups): array {
	$groups['store'] = [
		'label'       => __('WooCommerce Store', 'twee-mcp'),
		'description' => __('Product copy and the store rules appended to full product descriptions.', 'twee-mcp'),
		'position'    => 25,
		'section'     => 'store',
	];

	return $groups;
});

add_filter('twcp_prompt_registry', function(array $prompts): array {
	return $prompts + [
		'twcp_system_prompt_product'       => [
			'group'       => 'store',
			'label'       => __('Full Product Description', 'twee-mcp'),
			'description' => __('HTML product descriptions with specifications, materials, fitment, and internal links.', 'twee-mcp'),
			'default'     => 'twcp_prompt_full_default',
			'used_by'     => ['generate_product_description', __('Batch: Product descriptions', 'twee-mcp'), __('Bulk actions', 'twee-mcp')],
			'rows'        => 6,
		],
		'twcp_system_prompt_product_short' => [
			'group'       => 'store',
			'label'       => __('Short Product Description', 'twee-mcp'),
			'description' => __('Product excerpts of 120 to 155 characters that double as meta descriptions.', 'twee-mcp'),
			'default'     => 'twcp_prompt_short_default',
			'used_by'     => ['generate_product_short_description', __('Batch: Short descriptions', 'twee-mcp'), __('Bulk actions', 'twee-mcp')],
			'rows'        => 5,
		],
	];
});


/**
 * Product copy rules below the full product description prompt, saved with it
 */
add_action('twcp_prompt_fields', function(string $option) {
	if ($option === 'twcp_system_prompt_product') {
		echo twcp_template('product_rules', ['product_settings' => twcp_product_settings()]);
	}
});


/**
 * Register the product copy rules, saved with the prompts
 */
add_action('admin_init', 'twcp_product_admin_init');

/**
 * Register the settings of the product copy rules
 *
 * @return void
 */
function twcp_product_admin_init(): void
{
	register_setting('twcp_system_prompt_product', 'twcp_product_settings', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];
			$clean = [];
			$clean['max_links'] = isset($input['max_links']) ? min(15, max(0, (int) $input['max_links'])) : 6;
			$rules = sanitize_textarea_field((string) ($input['custom_rules'] ?? ''));

			// Guidelines equal to the built-in text are stored empty, so a newer default still applies
			$clean['custom_rules'] = $rules === twcp_product_settings_default()['custom_rules'] ? '' : $rules;

			return $clean;
		},
		'default'           => [],
	]);
}


/**
 * Assign media images to products as their featured or gallery images
 */
add_filter('twcp_media_assign', function($result, string $type, int $attachment_id, int $target_id, string $assign_as, array $target) {
	if ($type !== 'product') {
		return $result;
	}

	if (!$target_id) {
		return ['error' => 'Missing product ID.'];
	}

	if (!twcp_module_active('woo')) {
		return ['error' => 'WooCommerce is not active.'];
	}

	$product = wc_get_product($target_id);
	if (!$product) {
		return ['error' => 'Product not found.'];
	}
	if (!in_array($assign_as, ['featured', 'gallery', 'both'], true)) {
		return ['error' => 'Unknown product image role.'];
	}

	$args = ['product_id' => $target_id];
	if ($assign_as !== 'gallery') {
		$args['image_id'] = $attachment_id;
	}
	if ($assign_as !== 'featured') {
		$args['gallery_image_ids'] = array_values(array_unique([
			...$product->get_gallery_image_ids(),
			$attachment_id,
		]));
	}

	$result = twcp_product_edit($args);
	if (isset($result['error'])) {
		return $result;
	}

	return [
		'success'       => true,
		'type'          => 'product',
		'product_id'    => $target_id,
		'product_name'  => $product->get_name(),
		'assign_as'     => $assign_as,
		'attachment_id' => $attachment_id,
		'message'       => 'Product image assigned.',
	];
}, 10, 6);

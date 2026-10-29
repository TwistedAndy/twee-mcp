<?php
/**
 * Template part: Help tab
 *
 * Plain English steps for people new to AI: connecting a provider for the Automation jobs, connecting Claude or
 * ChatGPT to the site, using both well, the technical setup, and common problems.
 *
 * @var string $server_url
 * @var bool   $can_manage     Whether the user can open the Connection and Tools tabs
 * @var string $connection_url
 * @var array  $providers      Provider => [ name, keys_url ]
 * @var array  $active         Module => whether it is loaded and its plugin is running
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$key_links = [];
foreach ($providers as $provider) {
	if (!empty($provider['keys_url'])) {
		$key_links[] = '<a href="' . esc_url($provider['keys_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($provider['name']) . '</a>';
	}
}

$guides = [
	'provider' => [
		[
			'title' => __('With an API key (best for most websites)', 'twee-mcp'),
			'steps' => [
				sprintf(__('Sign in on the API keys page of your provider: %s. Add a payment method or some credit there.', 'twee-mcp'), implode(', ', $key_links)),
				__('Click <strong>Create new key</strong>, name it after this website, and copy it. Some providers show it only once.', 'twee-mcp'),
				__('In WordPress, open <strong>AI Tools → Connection</strong> and choose the same company under <strong>AI Provider</strong>.', 'twee-mcp'),
				__('Paste the key into <strong>API Key</strong> and click <strong>Save API Settings</strong>.', 'twee-mcp'),
				__('Click <strong>Test Connection</strong>. A success message means it works.', 'twee-mcp'),
			],
		],
		[
			'title' => __('With your ChatGPT Plus or Pro plan (experimental)', 'twee-mcp'),
			'steps' => [
				__('Under <strong>AI Provider</strong>, choose <strong>ChatGPT Plan (Experimental)</strong> and click <strong>Sign in with ChatGPT</strong>.', 'twee-mcp'),
				__('Sign in and approve in the tab that opens.', 'twee-mcp'),
				__('The tab ends on an error, such as "This site can\'t be reached". That is normal: copy the whole address from its address bar.', 'twee-mcp'),
				__('Back in WordPress, paste it into the box under the third step and click <strong>Complete Sign-in</strong>.', 'twee-mcp'),
				__('Click <strong>Test Connection</strong>.', 'twee-mcp'),
			],
		],
	],
	'agents'   => [
		[
			'title' => __('Claude (website or desktop app)', 'twee-mcp'),
			'steps' => [
				__('In <a href="https://claude.ai" target="_blank" rel="noopener noreferrer">claude.ai</a> or the Claude app, open <strong>Customize → Connectors</strong> in the sidebar.', 'twee-mcp'),
				__('Click <strong>Add → Add custom connector</strong>.', 'twee-mcp'),
				__('Type a name, such as the name of your website, paste the address, and click <strong>Continue</strong>.', 'twee-mcp'),
				__('On the WordPress page that opens, sign in if it asks and click <strong>Allow</strong>.', 'twee-mcp'),
				__('Start a new chat and ask Claude to do something on your website.', 'twee-mcp'),
			],
		],
		[
			'title' => __('ChatGPT', 'twee-mcp'),
			'steps' => [
				__('On a computer, open <a href="https://chatgpt.com/plugins" target="_blank" rel="noopener noreferrer">chatgpt.com/plugins</a> and click <strong>Add → Add custom MCP server</strong>. The phone and desktop apps cannot do this.', 'twee-mcp'),
				__('Type a name and paste the address. Leave <strong>OAuth</strong> and the other settings as they are.', 'twee-mcp'),
				__('Tick <strong>I understand and want to continue</strong> and click <strong>Create as a plugin</strong>.', 'twee-mcp'),
				__('Click <strong>Connect</strong>, sign in to WordPress if it asks, and click <strong>Allow</strong>.', 'twee-mcp'),
				__('In a new chat, type <strong>@</strong>, pick your website, and type your request.', 'twee-mcp'),
			],
		],
	],
];

$tips = [
	__('Automation', 'twee-mcp') => [
		__('<strong>Preview first.</strong> <strong>Preview</strong> on a card shows what the AI writes for one item, without saving it.', 'twee-mcp'),
		__('<strong>Start with Fill empty.</strong> It fills only what is missing. The other modes change texts you already have.', 'twee-mcp'),
		__('<strong>Check the results.</strong> If you do not like them, <strong>Undo Last Run</strong> brings back the old texts.', 'twee-mcp'),
		__('<strong>Describe your business.</strong> The <strong>Shared Context</strong> at the top of Automation goes into every request. An administrator can edit it.', 'twee-mcp'),
		__('<strong>Pick items by hand.</strong> On the Posts, Products, or Media list, tick items and choose an AI action under <strong>Bulk actions</strong>.', 'twee-mcp'),
	],
	__('Safety', 'twee-mcp')     => [
		__('Claude and ChatGPT can do only what your WordPress account can do.', 'twee-mcp'),
		__('They move posts and products to the Trash and cannot delete them for good, but they can remove blocks and menu items.', 'twee-mcp'),
		__('To disconnect, remove the connector in Claude or ChatGPT. Changing your WordPress password disconnects every AI app at once.', 'twee-mcp'),
		__('Keep your API key secret. If someone else gets it, delete it on the website of your provider and create a new one.', 'twee-mcp'),
	],
];

// What Claude and ChatGPT can do, by the module whose tools do it
$abilities = [
	'wp'       => [
		__('<strong>Pages and posts:</strong> find, edit, or write them, and make a copy on a new topic.', 'twee-mcp'),
		__('<strong>Categories and tags:</strong> create and describe them, and sort posts into them.', 'twee-mcp'),
		__('<strong>Images:</strong> write Alt text and set featured images.', 'twee-mcp'),
		__('<strong>Comments:</strong> find new ones, approve them, and reply.', 'twee-mcp'),
		__('<strong>Menus:</strong> add or remove menu items.', 'twee-mcp'),
	],
	'images'   => [__('<strong>Stock photos:</strong> find free photos and add them to the Media Library.', 'twee-mcp')],
	'blocks'   => [__('<strong>Page sections:</strong> add, change, or remove the blocks of a page.', 'twee-mcp')],
	'woo'      => [__('<strong>Store:</strong> products, prices, variations, descriptions, orders, coupons, and sales reports.', 'twee-mcp')],
	'activity' => [__('<strong>Team activity:</strong> who worked on what, and for how long.', 'twee-mcp')],
];

$examples = [
	__('Which pages have no SEO description? Write one for each and show me before you save.', 'twee-mcp'),
	__('Write a 600-word blog post about [topic] and save it as a draft.', 'twee-mcp'),
	__('Find the comments waiting for approval and suggest replies to the questions.', 'twee-mcp'),
	__('List the images without Alt text and write a short description for each.', 'twee-mcp'),
	__('Add a link to the Contact page to the footer menu.', 'twee-mcp'),
];
if (!empty($active['woo'])) {
	$examples[] = __('Which 5 products sold best last month, and which have no short description?', 'twee-mcp');
}

$habits = [
	__('<strong>Be specific.</strong> Say what to change, where, and how, such as "Keep each description under 30 words."', 'twee-mcp'),
	__('<strong>Ask for drafts.</strong> New posts go live right away unless you say "save it as a draft".', 'twee-mcp'),
	__('<strong>Ask to see changes first.</strong> Say "Show me the new text and wait for my OK before you save it."', 'twee-mcp'),
	__('<strong>Check the facts.</strong> AI can make mistakes with names, prices, and dates.', 'twee-mcp'),
	__('<strong>Start from a ready prompt.</strong> The <strong>Agent Prompts</strong> on the Connection tab are tested requests to copy and adjust.', 'twee-mcp'),
];

$problems = [
	__('The Automation buttons are grey, or it says "Automation Paused".', 'twee-mcp') => __('Connect an AI provider first.', 'twee-mcp'),
	__('Test Connection fails.', 'twee-mcp')                                             => __('Check that you copied the whole key, chose the right company, and have credit with your provider.', 'twee-mcp'),
	__('Some items failed during a run.', 'twee-mcp')                                    => __('The provider may be busy or out of credit. Wait a few minutes and run the job again with Fill empty.', 'twee-mcp'),
	__('Claude or ChatGPT cannot connect.', 'twee-mcp')                                  => __('Check that you pasted the exact address. Then ask the person who runs your website to read Technical Setup above.', 'twee-mcp'),
	__('ChatGPT says "Couldn\'t discover OAuth settings".', 'twee-mcp')                   => __('Do the same, then click Retry discovery in ChatGPT.', 'twee-mcp'),
	__('Claude shows Reconnect.', 'twee-mcp')                                            => __('Click Reconnect and Allow again. This happens after a password change or a long break.', 'twee-mcp'),
	__('The AI says it cannot do something.', 'twee-mcp')                                => __('Your account may not be allowed to do it, or the tool is switched off on the Tools tab. After a change, choose Refresh tools list in the connector menu of Claude.', 'twee-mcp'),
];
?>

<div class="item help">
	<h2 class="heading"><?php echo esc_html__('Start Here', 'twee-mcp'); ?></h2>
	<p class="text"><?php echo esc_html__('This plugin lets AI help you run your website in two ways. You can use one or both.', 'twee-mcp'); ?></p>
	<ul class="help_list">
		<li><?php echo wp_kses_post(__('<strong>Automation:</strong> the website writes texts in bulk, such as summaries, search engine titles, image descriptions, and product texts.', 'twee-mcp')); ?>
			<a href="#twcp_help_provider"><?php echo esc_html__('Connect an AI provider', 'twee-mcp'); ?></a></li>
		<li><?php echo wp_kses_post(__('<strong>Chat:</strong> you ask Claude or ChatGPT in plain words, and they read and change your website for you.', 'twee-mcp')); ?>
			<a href="#twcp_help_agents"><?php echo esc_html__('Connect Claude or ChatGPT', 'twee-mcp'); ?></a></li>
	</ul>
	<p class="text text_muted">
		<?php echo esc_html__('Also on this page:', 'twee-mcp'); ?>
		<a href="#twcp_help_chat"><?php echo esc_html__('Using Claude or ChatGPT', 'twee-mcp'); ?></a> ·
		<a href="#twcp_help_use"><?php echo esc_html__('Tips', 'twee-mcp'); ?></a> ·
		<a href="#twcp_help_setup"><?php echo esc_html__('Technical Setup', 'twee-mcp'); ?></a> ·
		<a href="#twcp_help_problems"><?php echo esc_html__('Problems', 'twee-mcp'); ?></a>
	</p>
</div>

<div class="item help" id="twcp_help_provider">
	<h2 class="heading"><?php echo esc_html__('Connect an AI Provider', 'twee-mcp'); ?></h2>
	<p class="text"><?php echo esc_html__('The provider is the company whose AI writes the texts: OpenAI (ChatGPT), Anthropic (Claude), or OpenRouter (many AI models in one account). You do this once. The website pays the provider for what it uses through an API key, a secret code that works like a password.', 'twee-mcp'); ?></p>
	<?php if (!$can_manage) { ?>
		<p class="help_note"><?php echo esc_html__('Only an administrator of this website can connect a provider. Send them a link to this page.', 'twee-mcp'); ?></p>
	<?php } ?>

	<?php foreach ($guides['provider'] as $guide) { ?>
		<h3 class="help_title"><?php echo esc_html($guide['title']); ?></h3>
		<ol class="help_list">
			<?php foreach ($guide['steps'] as $step) { ?>
				<li><?php echo wp_kses_post($step); ?></li>
			<?php } ?>
		</ol>
	<?php } ?>

	<p class="help_note">
		<?php echo esc_html__('The ChatGPT plan is a test feature of OpenAI and can stop working at any time. OpenAI allows it for personal projects; for a public or client website, fill in its interest form first. Every job then uses your plan limits, so set a weekly limit for this app in ChatGPT under Settings → Usage.', 'twee-mcp'); ?>
		<a href="<?php echo esc_url(TWCP_CHATGPT_INTEREST); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Interest form', 'twee-mcp'); ?></a>
	</p>

	<?php if ($can_manage) { ?>
		<div class="buttons">
			<a href="<?php echo esc_url($connection_url); ?>" class="button button_primary"><?php echo esc_html__('Open the Connection Tab', 'twee-mcp'); ?></a>
		</div>
	<?php } ?>
</div>

<div class="item help" id="twcp_help_agents">
	<h2 class="heading"><?php echo esc_html__('Connect Claude or ChatGPT', 'twee-mcp'); ?></h2>
	<p class="text"><?php echo esc_html__('Use the Claude or ChatGPT account you already have. First, copy the address they use to reach your website:', 'twee-mcp'); ?></p>
	<div class="field">
		<div class="control">
			<input type="text" id="twcp_help_url" readonly class="code" value="<?php echo esc_attr($server_url); ?>" onclick="this.select();" aria-label="<?php echo esc_attr__('Website address for AI', 'twee-mcp'); ?>" />
			<button type="button" class="button button_primary twcp_copy_prompt" data-target="twcp_help_url"><?php echo esc_html__('Copy', 'twee-mcp'); ?></button>
		</div>
		<div class="text text_muted"><?php echo esc_html__('It works only on a live website that starts with https, not on a copy that runs on your own computer.', 'twee-mcp'); ?></div>
	</div>

	<?php foreach ($guides['agents'] as $guide) { ?>
		<h3 class="help_title"><?php echo esc_html($guide['title']); ?></h3>
		<ol class="help_list">
			<?php foreach ($guide['steps'] as $step) { ?>
				<li><?php echo wp_kses_post($step); ?></li>
			<?php } ?>
		</ol>
	<?php } ?>

	<p class="text text_muted"><?php echo esc_html__('If an option is missing, your plan may not include it, or the admin of your Claude team or ChatGPT workspace has to allow custom connectors.', 'twee-mcp'); ?></p>
</div>

<div class="item help" id="twcp_help_chat">
	<h2 class="heading"><?php echo esc_html__('Use Claude or ChatGPT With Your Website', 'twee-mcp'); ?></h2>
	<p class="text"><?php echo esc_html__('Talk to Claude or ChatGPT as you would to a helpful colleague who can open your WordPress admin. It looks things up on your website first, then makes the changes you ask for.', 'twee-mcp'); ?></p>

	<h3 class="help_title"><?php echo esc_html__('Start a chat', 'twee-mcp'); ?></h3>
	<ul class="help_list">
		<li><?php echo wp_kses_post(__('<strong>Claude:</strong> start a new chat and ask about your website. If Claude does not use it, open the tools menu under the message box and check that your connector is on.', 'twee-mcp')); ?></li>
		<li><?php echo wp_kses_post(__('<strong>ChatGPT:</strong> start a new chat, type <strong>@</strong>, pick your website, and then type your request.', 'twee-mcp')); ?></li>
	</ul>

	<h3 class="help_title"><?php echo esc_html__('What you can ask for', 'twee-mcp'); ?></h3>
	<ul class="help_list">
		<?php foreach ($abilities as $module => $list) {
			if (!empty($active[$module])) {
				foreach ($list as $ability) { ?>
					<li><?php echo wp_kses_post($ability); ?></li>
				<?php }
			}
		} ?>
	</ul>

	<h3 class="help_title"><?php echo esc_html__('Examples', 'twee-mcp'); ?></h3>
	<ul class="help_list help_examples">
		<?php foreach ($examples as $example) { ?>
			<li>“<?php echo esc_html($example); ?>”</li>
		<?php } ?>
	</ul>

	<h3 class="help_title"><?php echo esc_html__('When it asks for your OK', 'twee-mcp'); ?></h3>
	<p class="text"><?php echo esc_html__('Claude and ChatGPT may ask for permission before they use your website. Looking things up is safe to allow. For a change, check that it is the change you asked for before you allow it. You can let them always allow look-ups, so they ask less often.', 'twee-mcp'); ?></p>

	<h3 class="help_title"><?php echo esc_html__('Good habits', 'twee-mcp'); ?></h3>
	<ul class="help_list">
		<?php foreach ($habits as $habit) { ?>
			<li><?php echo wp_kses_post($habit); ?></li>
		<?php } ?>
	</ul>
</div>

<div class="item help" id="twcp_help_use">
	<h2 class="heading"><?php echo esc_html__('Tips', 'twee-mcp'); ?></h2>
	<?php foreach ($tips as $title => $list) { ?>
		<h3 class="help_title"><?php echo esc_html($title); ?></h3>
		<ul class="help_list">
			<?php foreach ($list as $tip) { ?>
				<li><?php echo wp_kses_post($tip); ?></li>
			<?php } ?>
		</ul>
	<?php } ?>
</div>

<div class="item help" id="twcp_help_setup">
	<h2 class="heading"><?php echo esc_html__('Technical Setup', 'twee-mcp'); ?></h2>
	<p class="text"><?php echo esc_html__('For the person who runs the website. Most websites need none of this.', 'twee-mcp'); ?></p>

	<h3 class="help_title"><?php echo esc_html__('Claude Code', 'twee-mcp'); ?></h3>
	<p class="text"><?php echo wp_kses_post(__('Run this command, then type <code>/mcp</code> in Claude Code, choose the server, and sign in to WordPress:', 'twee-mcp')); ?></p>
	<pre class="pre"><code>claude mcp add --transport http straps <?php echo esc_html($server_url); ?></code></pre>

	<h3 class="help_title"><?php echo esc_html__('Cloudflare', 'twee-mcp'); ?></h3>
	<p class="text"><?php echo esc_html__('Cloudflare can block Claude and ChatGPT. Let them through to the plugin addresses only:', 'twee-mcp'); ?></p>
	<ol class="help_list">
		<li><?php echo wp_kses_post(__('In the Cloudflare dashboard, open <strong>Security → Security rules</strong> and create a <strong>Custom rule</strong>.', 'twee-mcp')); ?></li>
		<li><?php echo wp_kses_post(__('Click <strong>Edit expression</strong> and paste:', 'twee-mcp')); ?>
			<pre class="pre"><code>(http.request.uri.path starts_with "/wp-json/straps/v1/") or (http.request.uri.path starts_with "/.well-known/") or (http.request.uri.path eq "/authorize") or (http.request.uri.path eq "/register") or (http.request.uri.path eq "/token")</code></pre>
		</li>
		<li><?php echo wp_kses_post(__('Under <strong>Action</strong>, choose <strong>Skip</strong> and tick all remaining custom rules, all rate limiting rules, all managed rules, and all Super Bot Fight Mode rules.', 'twee-mcp')); ?></li>
		<li><?php echo wp_kses_post(__('Set the position to <strong>First</strong> and click <strong>Save</strong>.', 'twee-mcp')); ?></li>
	</ol>

	<h3 class="help_title"><?php echo esc_html__('Connection check', 'twee-mcp'); ?></h3>
	<p class="text">
		<?php $metadata_url = set_url_scheme(rest_url('straps/v1/.well-known/oauth-protected-resource'));
		echo esc_html__('Open', 'twee-mcp'); ?>
		<a href="<?php echo esc_url($metadata_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($metadata_url); ?></a>.
		<?php echo esc_html__('It should show text in curly brackets. An error or a security check means a firewall, a security plugin, or Cloudflare blocks AI apps. Also check that the MCP server is switched on under MCP Server Status on the Connection tab.', 'twee-mcp'); ?>
	</p>
</div>

<div class="item help" id="twcp_help_problems">
	<h2 class="heading"><?php echo esc_html__('Problems', 'twee-mcp'); ?></h2>
	<table class="table">
		<thead>
		<tr>
			<th><?php echo esc_html__('What you see', 'twee-mcp'); ?></th>
			<th><?php echo esc_html__('What to do', 'twee-mcp'); ?></th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ($problems as $problem => $fix) { ?>
			<tr>
				<td><?php echo esc_html($problem); ?></td>
				<td><?php echo esc_html($fix); ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
</div>

<?php
/**
 * Tab Template: MCP Connection & Client Authorization
 *
 * @var bool   $is_enabled
 * @var string $server_url
 * @var array  $sessions
 * @var array  $hosts
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<!-- 1. Master Enable / Disable Toggle Card -->
<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('MCP Server Status', 'twee-mcp'); ?></h2>
		<span class="badge <?php echo $is_enabled ? 'badge_success' : 'badge_error'; ?>">
			<?php echo $is_enabled ? esc_html__('Active & Listening', 'twee-mcp') : esc_html__('Server Disabled', 'twee-mcp'); ?>
		</span>
	</div>
	<form method="post" action="options.php">
		<?php settings_fields('twcp_general_group'); ?>
		<div class="field" style="margin-bottom: 0;">
			<label class="switch">
				<input type="hidden" name="twcp_enabled" value="0" />
				<input type="checkbox" name="twcp_enabled" value="1" <?php checked($is_enabled); ?> onchange="this.form.submit();" />
				<span><?php echo esc_html__('Enable MCP Server & Automated AI Helpers', 'twee-mcp'); ?></span>
			</label>
			<p class="text text_muted" style="margin-top: 8px;">
				<?php echo esc_html__('When toggled off, incoming agent requests (OAuth, JSON-RPC REST endpoints) and automated content generation tools are completely disabled.', 'twee-mcp'); ?>
			</p>
		</div>

		<div class="field" style="margin-top: 16px;">
			<label class="label" for="twcp_redirect_hosts"><?php echo esc_html__('Allowed OAuth Redirect Hosts', 'twee-mcp'); ?></label>
			<?php
			$display_hosts = trim((string) get_option('twcp_redirect_hosts', ''));
			if ($display_hosts === '') {
				$display_hosts = defined('TWCP_REDIRECT_HOSTS') ? TWCP_REDIRECT_HOSTS : "claude.ai\nclaude.com\nanthropic.com\nchatgpt.com\nopenai.com";
			}
			?>
			<textarea id="twcp_redirect_hosts" name="twcp_redirect_hosts" rows="4" class="large-text" style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea($display_hosts); ?></textarea>
			<div class="text text_muted"><?php echo esc_html__('One host per line. Authorized domains allowed for OAuth 2.1 PKCE redirection.', 'twee-mcp'); ?></div>
		</div>

		<div class="buttons" style="margin-top: 12px;">
			<button type="submit" class="button"><?php echo esc_html__('Save Connection Settings', 'twee-mcp'); ?></button>
		</div>
	</form>
</div>

<!-- 2. Endpoint Card -->
<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Server Endpoint', 'twee-mcp'); ?></h2>
		<div class="badges">
			<span class="badge badge_success">OAuth 2.1 (PKCE)</span>
			<span class="badge">Application Passwords</span>
		</div>
	</div>
	<div class="field">
		<label class="label" for="twcp_url_input"><?php echo esc_html__('Server URL', 'twee-mcp'); ?></label>
		<div class="control">
			<input type="text" id="twcp_url_input" readonly class="code" value="<?php echo esc_attr($server_url); ?>" onclick="this.select();" />
			<button type="button" id="twcp_copy_url_btn" class="button button_primary">
				<?php echo esc_html__('Copy URL', 'twee-mcp'); ?>
			</button>
		</div>
		<div class="text text_muted"><?php echo esc_html__('Paste this exact endpoint into the MCP server URL field in Claude, Claude Code, or ChatGPT. Remote clients require a publicly accessible HTTPS URL.', 'twee-mcp'); ?></div>
	</div>
</div>

<!-- 3. Connect Instructions Card -->
<div class="item">
	<h2 class="heading"><?php echo esc_html__('How to Connect AI Clients', 'twee-mcp'); ?></h2>

	<div class="group">
		<h3 class="title"><?php echo esc_html__('Claude (Web & Desktop App)', 'twee-mcp'); ?></h3>
		<ol class="list">
			<li><?php echo esc_html__('Open your profile menu, then go to ', 'twee-mcp'); ?><strong><?php echo esc_html__('Settings → Connectors', 'twee-mcp'); ?></strong>.</li>
			<li><?php echo esc_html__('On the ', 'twee-mcp'); ?><strong><?php echo esc_html__('Yours', 'twee-mcp'); ?></strong><?php echo esc_html__(' tab, choose ', 'twee-mcp'); ?><strong><?php echo esc_html__('Add connector → Add custom connector', 'twee-mcp'); ?></strong>.</li>
			<li><?php echo esc_html__('Enter a name (e.g. ', 'twee-mcp'); ?>
				<em><?php echo esc_html__('Store Management', 'twee-mcp'); ?></em><?php echo esc_html__(') and paste the Server URL: ', 'twee-mcp'); ?>
				<code><?php echo esc_html($server_url); ?></code></li>
			<li><?php echo esc_html__('Click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Continue', 'twee-mcp'); ?></strong><?php echo esc_html__('. Claude discovers the OAuth settings and registers its client automatically; do not add an OAuth Client ID manually.', 'twee-mcp'); ?></li>
			<li><?php echo esc_html__('When the WordPress authorization screen opens, sign in and click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Allow', 'twee-mcp'); ?></strong><?php echo esc_html__('. Claude should return to the connector with a Connected status.', 'twee-mcp'); ?></li>
		</ol>
		<p class="text text_muted"><?php echo esc_html__('After plugin tools change, open the connector menu and choose Refresh tools list. If Claude shows Reconnect, reconnect and complete the WordPress authorization again.', 'twee-mcp'); ?></p>
	</div>

	<div class="group">
		<h3 class="title"><?php echo esc_html__('Claude Code (CLI)', 'twee-mcp'); ?></h3>
		<p class="text"><?php echo esc_html__('Run this command in your terminal to register the MCP server:', 'twee-mcp'); ?></p>
		<pre class="pre"><code>claude mcp add --transport http straps <?php echo esc_html($server_url); ?></code></pre>
		<p class="text text_muted"><?php echo esc_html__('Then run ', 'twee-mcp'); ?><code>/mcp</code><?php echo esc_html__(' inside Claude Code and select authenticate.', 'twee-mcp'); ?></p>
	</div>

	<div class="group">
		<h3 class="title"><?php echo esc_html__('ChatGPT Plugins (Web & Desktop App)', 'twee-mcp'); ?></h3>
		<ol class="list">
			<li><?php echo esc_html__('Open ', 'twee-mcp'); ?><strong><?php echo esc_html__('Plugins', 'twee-mcp'); ?></strong><?php echo esc_html__(' from the ChatGPT sidebar.', 'twee-mcp'); ?></li>
			<li><?php echo esc_html__('Click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Add → Add custom MCP server', 'twee-mcp'); ?></strong>.</li>
			<li><?php echo esc_html__('Enter a name and optional description, keep ', 'twee-mcp'); ?><strong><?php echo esc_html__('Server URL', 'twee-mcp'); ?></strong><?php echo esc_html__(' selected, and paste: ', 'twee-mcp'); ?><code><?php echo esc_html($server_url); ?></code></li>
			<li><?php echo esc_html__('Keep ', 'twee-mcp'); ?><strong><?php echo esc_html__('OAuth', 'twee-mcp'); ?></strong><?php echo esc_html__(' as the authentication method. No manual client ID is needed because ChatGPT discovers the OAuth endpoints and registers automatically.', 'twee-mcp'); ?></li>
			<li><?php echo esc_html__('Confirm the trust warning and click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Create as a plugin', 'twee-mcp'); ?></strong>.</li>
			<li><?php echo esc_html__('When the WordPress authorization screen opens, sign in and click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Allow', 'twee-mcp'); ?></strong>.</li>
		</ol>
	</div>

	<div class="group" style="border-top: 1px solid #e2e4e7; padding-top: 18px; margin-top: 18px;">
		<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
			<h3 class="title" style="margin: 0;"><?php echo esc_html__('Cloudflare Configuration & WAF Rules', 'twee-mcp'); ?></h3>
			<span class="badge badge_primary"><?php echo esc_html__('Required for Live Sites behind Cloudflare', 'twee-mcp'); ?></span>
		</div>
		<p class="text">
			<?php echo esc_html__('Cloudflare Bot Management, Managed Challenges, or WAF rules will block automated Claude and ChatGPT servers during OAuth discovery and MCP queries. Create a WAF Custom Rule / Skip Rule to bypass security checks for the MCP and OAuth paths:', 'twee-mcp'); ?>
		</p>
		<ol class="list">
			<li><?php echo esc_html__('In the Cloudflare Dashboard, navigate to ', 'twee-mcp'); ?><strong><?php echo esc_html__('Security → Security rules', 'twee-mcp'); ?></strong>.</li>
			<li><?php echo esc_html__('Create a ', 'twee-mcp'); ?><strong><?php echo esc_html__('Custom rule', 'twee-mcp'); ?></strong><?php echo esc_html__(' and name it ', 'twee-mcp'); ?><em><?php echo esc_html__('Allow MCP Server & OAuth', 'twee-mcp'); ?></em>.</li>
			<li><?php echo esc_html__('Click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Edit expression', 'twee-mcp'); ?></strong><?php echo esc_html__(' and paste the following expression:', 'twee-mcp'); ?>
				<pre class="pre" style="margin: 10px 0;"><code>(http.request.uri.path starts_with "/wp-json/straps/v1/mcp") or (http.request.uri.path starts_with "/.well-known/oauth-") or (http.request.uri.path eq "/authorize") or (http.request.uri.path eq "/register") or (http.request.uri.path eq "/token")</code></pre>
			</li>
			<li><?php echo esc_html__('For the ', 'twee-mcp'); ?><strong><?php echo esc_html__('Action', 'twee-mcp'); ?></strong><?php echo esc_html__(', select ', 'twee-mcp'); ?><strong><?php echo esc_html__('Skip', 'twee-mcp'); ?></strong><?php echo esc_html__(' and select these components:', 'twee-mcp'); ?>
				<ul style="list-style: disc; margin: 6px 0 6px 20px;">
					<li><?php echo esc_html__('All remaining custom rules', 'twee-mcp'); ?></li>
					<li><?php echo esc_html__('All rate limiting rules', 'twee-mcp'); ?></li>
					<li><?php echo esc_html__('All managed rules', 'twee-mcp'); ?></li>
					<li><?php echo esc_html__('All Super Bot Fight Mode rules', 'twee-mcp'); ?></li>
				</ul>
			</li>
			<li><?php echo esc_html__('Set the rule position to ', 'twee-mcp'); ?><strong><?php echo esc_html__('First', 'twee-mcp'); ?></strong><?php echo esc_html__(' and click ', 'twee-mcp'); ?><strong><?php echo esc_html__('Save', 'twee-mcp'); ?></strong>.</li>
		</ol>
	</div>

	<div class="group" style="border-top: 1px solid #e2e4e7; padding-top: 18px; margin-top: 18px;">
		<h3 class="title"><?php echo esc_html__('Connection Troubleshooting', 'twee-mcp'); ?></h3>
		<p class="text">
			<?php echo esc_html__('Refreshing a tool list only re-reads the available tool definitions; it does not sign the client in. Connected Agents & Active Sessions remains empty until the WordPress OAuth flow finishes and the client exchanges its authorization code for tokens.', 'twee-mcp'); ?>
		</p>
		<p class="text text_muted">
			<?php echo esc_html__('If authentication fails or the client asks you to reconnect, confirm that the MCP server is enabled and the Cloudflare skip rule above is saved, then reconnect and approve access in WordPress again.', 'twee-mcp'); ?>
		</p>
	</div>
</div>

<!-- 4. Connected Agents Card -->
<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Connected Agents & Active Sessions', 'twee-mcp'); ?></h2>
		<span class="badge"><?php echo sprintf(esc_html__('%d active', 'twee-mcp'), count($sessions)); ?></span>
	</div>
	<?php if (empty($sessions)) { ?>
		<p class="text text_muted"><?php echo esc_html__('No external AI agents have connected yet.', 'twee-mcp'); ?></p>
	<?php } else { ?>
		<table class="widefat striped" style="margin-top: 12px;">
			<thead>
			<tr>
				<th><?php echo esc_html__('Agent', 'twee-mcp'); ?></th>
				<th><?php echo esc_html__('Client ID', 'twee-mcp'); ?></th>
				<th><?php echo esc_html__('Last Authorized', 'twee-mcp'); ?></th>
				<th><?php echo esc_html__('Authorization Expires', 'twee-mcp'); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($sessions as $client_hash => $s) { ?>
				<tr>
					<td><?php echo esc_html($s['name'] ?? __('MCP Client', 'twee-mcp')); ?></td>
					<td><code><?php echo esc_html(substr((string) $client_hash, 0, 18)); ?>...</code></td>
					<td><?php echo esc_html(human_time_diff((int) ($s['updated'] ?? time()), time())); ?> ago</td>
					<td>in <?php echo esc_html(human_time_diff(time(), (int) ($s['refresh_expires'] ?? $s['expires'] ?? time()))); ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
	<?php } ?>
</div>

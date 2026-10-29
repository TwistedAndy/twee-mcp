<?php
/**
 * Template part: MCP server, client setup, and sessions of the Connection tab
 *
 * @var bool   $is_enabled
 * @var string $server_url
 * @var array  $sessions
 * @var array  $hosts
 * @var string $help_url
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
			<button type="button" class="button button_primary twcp_copy_prompt" data-target="twcp_url_input">
				<?php echo esc_html__('Copy URL', 'twee-mcp'); ?>
			</button>
		</div>
		<div class="text text_muted"><?php echo esc_html__('Paste this exact endpoint into the MCP server URL field in Claude, Claude Code, or ChatGPT. Remote clients require a publicly accessible HTTPS URL.', 'twee-mcp'); ?></div>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('The Help tab has the steps for', 'twee-mcp'); ?>
		<a href="<?php echo esc_url($help_url . '#twcp_help_agents'); ?>"><?php echo esc_html__('Claude and ChatGPT', 'twee-mcp'); ?></a>,
		<?php echo esc_html__('and for', 'twee-mcp'); ?>
		<a href="<?php echo esc_url($help_url . '#twcp_help_setup'); ?>"><?php echo esc_html__('Claude Code, Cloudflare, and connection checks', 'twee-mcp'); ?></a>.
	</p>
</div>

<!-- 3. Connected Agents Card -->
<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Connected Agents & Active Sessions', 'twee-mcp'); ?></h2>
		<span class="badge"><?php echo sprintf(esc_html__('%d active', 'twee-mcp'), count($sessions)); ?></span>
	</div>
	<?php if (empty($sessions)) { ?>
		<p class="text text_muted">
			<?php echo esc_html__('No external AI agents have connected yet.', 'twee-mcp'); ?>
			<a href="<?php echo esc_url($help_url . '#twcp_help_agents'); ?>"><?php echo esc_html__('How to connect one', 'twee-mcp'); ?></a>
		</p>
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

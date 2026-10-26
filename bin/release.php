<?php
/**
 * Build the plugin ZIP and publish it as a GitHub release
 *
 * composer zip                     Builds build/twee-mcp-{version}.zip from the plugin/ directory at HEAD
 * composer release [-- notes.md]   Builds the ZIP and publishes release v{version} with the GitHub CLI
 *
 * Without a notes file, the notes list the commits since the previous release.
 */

chdir(dirname(__DIR__));


/**
 * Stop with a message
 *
 * @param string $message
 */
function release_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}


/**
 * Run a command and stop when it fails
 *
 * @param string $command
 *
 * @return string Output
 */
function release_run(string $command): string
{
	exec($command . ' 2>&1', $output, $code);

	if ($code !== 0) {
		release_fail("Failed: $command\n" . implode(PHP_EOL, $output));
	}

	return trim(implode("\n", $output));
}


// Sites compare the header with the release tag, and the plugin reports the constant, so both must match
$index = file_get_contents('plugin/index.php');
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $index, $header);
preg_match("/define\('TWCP_VERSION', '([^']+)'\)/", $index, $constant);

$version = $header[1] ?? '';
if (!$version or $version !== ($constant[1] ?? '')) {
	release_fail('The Version header and TWCP_VERSION in plugin/index.php do not match.');
}

$tag = 'v' . $version;
$zip = "build/twee-mcp-$version.zip";
$publish = ($argv[1] ?? '') === 'publish';

// The ZIP and the tag come from HEAD, so it must hold every change and already be on GitHub
if ($publish) {
	exec('gh --version 2>&1', $output, $code);
	if ($code !== 0) {
		release_fail('Install the GitHub CLI from https://cli.github.com and run "gh auth login".');
	}
	if (release_run('git status --porcelain') !== '') {
		release_fail('Commit the changes first.');
	}

	release_run('git fetch --quiet --tags origin');

	if (release_run('git branch -r --contains HEAD') === '') {
		release_fail('Push HEAD first.');
	}
	if (release_run('git tag --list ' . $tag) !== '') {
		release_fail("Release $tag already exists. Bump the version in plugin/index.php first.");
	}
}

// The prefix makes WordPress install and update the plugin in its twee-mcp folder
if (!is_dir('build')) {
	mkdir('build');
}
release_run("git archive --format=zip --prefix=twee-mcp/ -o $zip HEAD:plugin");
echo "Built $zip from HEAD." . PHP_EOL;

if (!$publish) {
	exit(0);
}

$notes = $argv[2] ?? '';
if ($notes === '') {
	exec('git describe --tags --abbrev=0 HEAD 2>&1', $previous, $code);
	$range = $code === 0 ? trim($previous[0]) . '..HEAD' : 'HEAD';
	$commits = array_filter(explode("\n", release_run('git log --format=%s ' . $range)));

	$notes = 'build/notes.md';
	file_put_contents($notes, implode("\n", [
		'## Install',
		"Download `twee-mcp-$version.zip` below, then go to **Plugins → Add New → Upload Plugin** in WordPress. Sites with the plugin installed see the update under **Dashboard → Updates**.",
		'',
		'## Changes',
		...array_map(fn(string $commit) => '- ' . $commit, $commits),
	]) . "\n");
} elseif (!is_file($notes)) {
	release_fail("Notes file $notes is not found.");
}

echo release_run(sprintf('gh release create %s %s --title %s --target %s --notes-file %s --latest', $tag, $zip, $tag, release_run('git rev-parse HEAD'), escapeshellarg($notes))) . PHP_EOL;

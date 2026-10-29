<?php

/**
 * Modules: project modules from folders outside the plugin, and the plugin with each module switched off
 */
class ModulesTest extends TWCP_TestCase {

	/**
	 * A module in a folder of the twcp_module_dirs filter loads like those of the plugin: it can be switched off, adds
	 * its tools, and its template parts are found next to it. Its tool is public, so anyone may run it.
	 */
	public function test_project_module(): void
	{
		$this->assertContains(dirname(__DIR__) . '/modules', twcp_module_dirs());
		$this->assertSame('wp', array_key_first(twcp_module_files()));
		$this->assertSame('Sample', twcp_modules_found()['sample']['name']);
		$this->assertTrue(twcp_module_active('sample'));
		$this->assertSame('sample', twcp_tools_groups()['sample']['module']);
		$this->assertSame('<p>Hello</p>', trim(twcp_template('sample', ['text' => 'Hello'])));

		wp_set_current_user(0);
		$this->assertSame(['text' => 'Hi'], $this->call('sample_echo', ['text' => 'Hi']));
	}

	/**
	 * The plugin works with any module switched off: its tools, jobs, and prompts are listed, and every screen renders.
	 * test_module_switched_off() runs it with each module switched off.
	 */
	public function test_without_a_module(): void
	{
		$module = (string) getenv('TWCP_TESTS_MODULE_OFF');
		if ($module !== '') {
			$this->assertArrayNotHasKey($module, twcp_modules());
		}

		$this->assertNotEmpty(twcp_tools_list());
		foreach (twcp_prompt_registry() as $option => $prompt) {
			$this->assertIsString(call_user_func($prompt['default']), $option);
		}
		foreach (array_keys(twcp_batch_types()) as $type) {
			$this->assertIsInt(twcp_batch_total($type), $type);
		}
		$this->assertNotSame('', twcp_general_context_default());

		foreach (twcp_admin_tabs() as $info) {
			foreach ($info['parts'] as $part) {
				$this->assertNotSame('', twcp_template($part, twcp_admin_data($part)), $part);
			}
		}
	}

	/**
	 * A module cannot be unloaded, so test_without_a_module() runs in a test run of its own with the module switched off
	 * from the start
	 *
	 * @dataProvider modules
	 */
	public function test_module_switched_off(string $module): void
	{
		if (getenv('TWCP_TESTS_MODULE_OFF')) {
			$this->markTestSkipped('Runs in the main test run only.');
		}

		$root = dirname(__DIR__, 2);
		$command = escapeshellarg(PHP_BINARY) . ' -d opcache.jit=disable ' . escapeshellarg($root . '/vendor/phpunit/phpunit/phpunit');
		$command .= ' --configuration ' . escapeshellarg($root . '/phpunit.xml') . ' --no-coverage --filter test_without_a_module';

		putenv('TWCP_TESTS_MODULE_OFF=' . $module);
		exec($command . ' 2>&1', $output, $code);
		putenv('TWCP_TESTS_MODULE_OFF');

		$this->assertSame(0, $code, implode("\n", $output));
		$this->assertStringContainsString('OK (1 test', implode("\n", $output));
	}

	/**
	 * Modules that can be switched off
	 *
	 * @return array
	 */
	public function modules(): array
	{
		$modules = [];
		foreach (glob(dirname(__DIR__, 2) . '/plugin/modules/*/module.php') as $file) {
			$name = basename(dirname($file));
			if ($name !== 'wp') {
				$modules[$name] = [$name];
			}
		}

		return $modules;
	}

}

<?php

namespace NExT\TermDeleteGuard\Tests\Integration;

use WP_UnitTestCase;

class PluginActiveTest extends WP_UnitTestCase {

	public function test_plugin_is_active(): void {
		$this->assertTrue(
			is_plugin_active( 'NExT-term-delete-guard/term-delete-guard.php' )
		);
	}
}

<?php

namespace NExT\TermDeleteGuard\Tests\Integration;

use WP_UnitTestCase;

class PluginActiveTest extends WP_UnitTestCase {

	public function test_plugin_class_is_loaded(): void {
		$this->assertTrue( class_exists( 'Term_Delete_Guard' ) );
	}

	public function test_pre_delete_term_filter_is_registered(): void {
		$this->assertGreaterThan(
			0,
			has_filter( 'pre_delete_term', array( new Term_Delete_Guard(), 'prevent_term_deletion' ) )
		);
	}
}

<?php

namespace NExT\TermDeleteGuard\Tests\Unit;

use Yoast\WPTestUtils\BrainMonkey\TestCase;

class TermDeleteGuardTest extends TestCase {

	public function test_plugin_file_exists(): void {
		$plugin_file = dirname( __DIR__, 3 ) . '/term-delete-guard.php';
		$this->assertFileExists( $plugin_file );
	}

	public function test_plugin_has_correct_version_header(): void {
		$content = file_get_contents( dirname( __DIR__, 3 ) . '/term-delete-guard.php' );
		$this->assertStringContainsString( 'Plugin Name: NExT Term Delete Guard', $content );
	}
}

<?php
/**
 * PHPUnit bootstrap file for Integration tests.
 */

// Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// WordPress テストライブラリの読み込み.
require getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php';

/**
 * テスト対象プラグインを手動で読み込む.
 */
function _manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/term-delete-guard.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// WordPress テスト環境を起動.
require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';

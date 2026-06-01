<?php
/**
 * Plugin Name: NExT Term Delete Guard
 * Description: カテゴリー、タグ、タクソノミーに紐づく記事がある場合、削除を防止します。
 * Version: 1.0.0
 * Author: NExT-Season
 * Author URI: https://next-season.net
 * License: GPL v2 or later
 * Text Domain: term-delete-guard
 * Domain Path: /languages
 *
 * @package Term_Delete_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 投稿が紐づくタームの削除を防止するプラグインのメインクラス.
 */
class Term_Delete_Guard {

	/**
	 * コンストラクタ. フックを登録する.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'pre_delete_term', array( $this, 'prevent_term_deletion' ), 10, 2 );
		add_action( 'wp_ajax_check_term_posts', array( $this, 'ajax_check_term_posts' ) );
		add_action( 'admin_notices', array( $this, 'display_error_notice' ) );
		// 削除リクエストを早期にインターセプトする.
		add_action( 'load-edit-tags.php', array( $this, 'intercept_delete_request' ) );
		add_action( 'wp_ajax_delete-tag', array( $this, 'intercept_ajax_delete' ), 0 );
	}

	/**
	 * 管理画面用のスクリプトを読み込む.
	 *
	 * @param string $hook 現在のページフック名.
	 */
	public function enqueue_scripts( $hook ) {
		// カテゴリー、タグ、タクソノミー編集画面でのみ読み込む.
		if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'term-delete-guard',
			plugin_dir_url( __FILE__ ) . 'assets/js/term-delete-guard.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script(
			'term-delete-guard',
			'termDeleteGuard',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'term_delete_guard_nonce' ),
				'message'        => __( 'このタームには紐づく記事があるため削除できません。', 'term-delete-guard' ),
				'confirmMessage' => __( 'このタームを削除してもよろしいですか？', 'term-delete-guard' ),
			)
		);
	}

	/**
	 * タームの削除を防止する（サーバーサイド）.
	 *
	 * @param bool|null $delete  削除するかどうか.
	 * @param int       $term_id タームID.
	 * @return bool|null|WP_Error 削除を許可する場合は $delete、防止する場合は WP_Error.
	 */
	public function prevent_term_deletion( $delete, $term_id ) {
		$term = get_term( $term_id );

		if ( is_wp_error( $term ) || ! $term ) {
			return $delete;
		}

		// タームに紐づく投稿数を取得する.
		$count = $this->get_term_post_count( $term_id, $term->taxonomy );

		if ( $count > 0 ) {
			$error_message = sprintf(
				// translators: 1: タームの名前, 2: 紐づく記事数.
				__( '「%1$s」には %2$d 件の記事が紐づいているため削除できません。', 'term-delete-guard' ),
				$term->name,
				$count
			);
			set_transient( 'term_delete_guard_error_' . get_current_user_id(), $error_message, 30 );

			return new WP_Error( 'term_has_posts', $error_message );
		}

		return $delete;
	}

	/**
	 * タームに紐づく投稿数を取得する.
	 *
	 * @param int    $term_id  タームID.
	 * @param string $taxonomy タクソノミー名.
	 * @return int 紐づく投稿数.
	 */
	private function get_term_post_count( $term_id, $taxonomy ) {
		$args = array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		);

		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Ajax: タームの投稿数をチェックする.
	 */
	public function ajax_check_term_posts() {
		check_ajax_referer( 'term_delete_guard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'term-delete-guard' ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? intval( $_POST['term_id'] ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : '';

		if ( ! $term_id || ! $taxonomy ) {
			wp_send_json_error( array( 'message' => __( '無効なリクエストです。', 'term-delete-guard' ) ) );
		}

		$term      = get_term( $term_id, $taxonomy );
		$term_name = $term && ! is_wp_error( $term ) ? $term->name : '';

		$count = $this->get_term_post_count( $term_id, $taxonomy );

		wp_send_json_success(
			array(
				'count'     => $count,
				'canDelete' => 0 === $count,
				'termName'  => $term_name,
			)
		);
	}

	/**
	 * エラー通知を表示する.
	 */
	public function display_error_notice() {
		$transient_key = 'term_delete_guard_error_' . get_current_user_id();
		$error_message = get_transient( $transient_key );

		if ( $error_message ) {
			delete_transient( $transient_key );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error_message )
			);
		}
	}

	/**
	 * 削除リクエストをインターセプトする（通常のフォーム送信）.
	 */
	public function intercept_delete_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は下で個別に検証する.
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'delete' !== $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する.
		$tag_id = isset( $_REQUEST['tag_ID'] ) ? intval( $_REQUEST['tag_ID'] ) : 0;

		// WordPressの組み込みnonce検証を実行する.
		check_admin_referer( 'delete-tag_' . $tag_id );

		$term_id = $tag_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は check_admin_referer で検証済み.
		$taxonomy = isset( $_REQUEST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['taxonomy'] ) ) : 'post_tag';

		if ( ! $term_id ) {
			return;
		}

		$count = $this->get_term_post_count( $term_id, $taxonomy );

		if ( $count > 0 ) {
			$term      = get_term( $term_id, $taxonomy );
			$term_name = $term ? $term->name : 'ID: ' . $term_id;

			$error_message = sprintf(
				// translators: 1: タームの名前, 2: 紐づく記事数.
				__( '「%1$s」には %2$d 件の記事が紐づいているため削除できません。', 'term-delete-guard' ),
				$term_name,
				$count
			);

			set_transient( 'term_delete_guard_error_' . get_current_user_id(), $error_message, 30 );

			$redirect_url = admin_url( 'edit-tags.php?taxonomy=' . $taxonomy );
			if ( 'category' === $taxonomy ) {
				$redirect_url = admin_url( 'edit-tags.php?taxonomy=category' );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Ajax削除リクエストをインターセプトする.
	 */
	public function intercept_ajax_delete() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_ajax_referer で検証する.
		$tag_id = isset( $_POST['tag_ID'] ) ? intval( $_POST['tag_ID'] ) : 0;

		// WordPressの組み込みAjax nonce検証を実行する.
		check_ajax_referer( 'delete-tag_' . $tag_id );

		$term_id = $tag_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は check_ajax_referer で検証済み.
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : 'post_tag';

		if ( ! $term_id ) {
			return;
		}

		$count = $this->get_term_post_count( $term_id, $taxonomy );

		if ( $count > 0 ) {
			$term      = get_term( $term_id, $taxonomy );
			$term_name = $term ? $term->name : 'ID: ' . $term_id;

			$error_message = sprintf(
				// translators: 1: タームの名前, 2: 紐づく記事数.
				__( '「%1$s」には %2$d 件の記事が紐づいているため削除できません。', 'term-delete-guard' ),
				$term_name,
				$count
			);

			wp_die( esc_html( $error_message ) );
		}
	}
}

new Term_Delete_Guard();

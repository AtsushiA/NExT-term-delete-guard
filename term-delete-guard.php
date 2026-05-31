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
 */

if (!defined('ABSPATH')) {
    exit;
}

class Term_Delete_Guard {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('pre_delete_term', [$this, 'prevent_term_deletion'], 10, 2);
        add_action('wp_ajax_check_term_posts', [$this, 'ajax_check_term_posts']);
        add_action('admin_notices', [$this, 'display_error_notice']);
        // 削除リクエストを早期にインターセプト
        add_action('load-edit-tags.php', [$this, 'intercept_delete_request']);
        add_action('wp_ajax_delete-tag', [$this, 'intercept_ajax_delete'], 0);
    }

    /**
     * 管理画面用のスクリプトを読み込む
     */
    public function enqueue_scripts($hook) {
        // カテゴリー、タグ、タクソノミー編集画面でのみ読み込む
        if ($hook !== 'edit-tags.php' && $hook !== 'term.php') {
            return;
        }

        wp_enqueue_script(
            'term-delete-guard',
            plugin_dir_url(__FILE__) . 'assets/js/term-delete-guard.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('term-delete-guard', 'termDeleteGuard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('term_delete_guard_nonce'),
            'message' => __('このタームには紐づく記事があるため削除できません。', 'term-delete-guard'),
            'confirmMessage' => __('このタームを削除してもよろしいですか？', 'term-delete-guard'),
        ]);
    }

    /**
     * タームの削除を防止する（サーバーサイド）
     */
    public function prevent_term_deletion($delete, $term_id) {
        $term = get_term($term_id);

        if (is_wp_error($term) || !$term) {
            return $delete;
        }

        // タームに紐づく投稿数を取得
        $count = $this->get_term_post_count($term_id, $term->taxonomy);

        if ($count > 0) {
            $error_message = sprintf(
                __('「%s」には %d 件の記事が紐づいているため削除できません。', 'term-delete-guard'),
                $term->name,
                $count
            );
            set_transient('term_delete_guard_error_' . get_current_user_id(), $error_message, 30);

            return new WP_Error('term_has_posts', $error_message);
        }

        return $delete;
    }

    /**
     * タームに紐づく投稿数を取得
     */
    private function get_term_post_count($term_id, $taxonomy) {
        $args = [
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ];

        $query = new WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Ajax: タームの投稿数をチェック
     */
    public function ajax_check_term_posts() {
        check_ajax_referer('term_delete_guard_nonce', 'nonce');

        if (!current_user_can('manage_categories')) {
            wp_send_json_error(['message' => __('権限がありません。', 'term-delete-guard')]);
        }

        $term_id  = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';

        if (!$term_id || !$taxonomy) {
            wp_send_json_error(['message' => __('無効なリクエストです。', 'term-delete-guard')]);
        }

        $term = get_term($term_id, $taxonomy);
        $term_name = $term && !is_wp_error($term) ? $term->name : '';

        $count = $this->get_term_post_count($term_id, $taxonomy);

        wp_send_json_success([
            'count'      => $count,
            'canDelete'  => $count === 0,
            'termName'   => $term_name,
        ]);
    }

    /**
     * エラー通知を表示
     */
    public function display_error_notice() {
        $transient_key = 'term_delete_guard_error_' . get_current_user_id();
        $error_message = get_transient($transient_key);

        if ($error_message) {
            delete_transient($transient_key);
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($error_message)
            );
        }
    }

    /**
     * 削除リクエストをインターセプト（通常のフォーム送信）
     */
    public function intercept_delete_request() {
        $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';

        if ($action !== 'delete') {
            return;
        }

        $term_id  = isset($_REQUEST['tag_ID']) ? intval($_REQUEST['tag_ID']) : 0;
        $taxonomy = isset($_REQUEST['taxonomy']) ? sanitize_text_field($_REQUEST['taxonomy']) : 'post_tag';

        if (!$term_id) {
            return;
        }

        $count = $this->get_term_post_count($term_id, $taxonomy);

        if ($count > 0) {
            $term = get_term($term_id, $taxonomy);
            $term_name = $term ? $term->name : 'ID: ' . $term_id;

            $error_message = sprintf(
                __('「%s」には %d 件の記事が紐づいているため削除できません。', 'term-delete-guard'),
                $term_name,
                $count
            );

            set_transient('term_delete_guard_error_' . get_current_user_id(), $error_message, 30);

            $redirect_url = admin_url('edit-tags.php?taxonomy=' . $taxonomy);
            if ($taxonomy === 'category') {
                $redirect_url = admin_url('edit-tags.php?taxonomy=category');
            }

            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Ajax削除リクエストをインターセプト
     */
    public function intercept_ajax_delete() {
        $term_id  = isset($_POST['tag_ID']) ? intval($_POST['tag_ID']) : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'post_tag';

        if (!$term_id) {
            return;
        }

        $count = $this->get_term_post_count($term_id, $taxonomy);

        if ($count > 0) {
            $term = get_term($term_id, $taxonomy);
            $term_name = $term ? $term->name : 'ID: ' . $term_id;

            $error_message = sprintf(
                __('「%s」には %d 件の記事が紐づいているため削除できません。', 'term-delete-guard'),
                $term_name,
                $count
            );

            wp_die($error_message);
        }
    }
}

new Term_Delete_Guard();

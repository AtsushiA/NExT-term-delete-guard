(function($) {
    'use strict';

    // タクソノミーを取得（URLパラメータから）
    var urlParams = new URLSearchParams(window.location.search);
    var taxonomy = urlParams.get('taxonomy') || 'category';

    // 削除処理中のフラグ
    var pendingDeletes = {};

    // WordPressのAjax削除リクエストをインターセプト
    $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
        // delete-tag アクションかどうかをチェック
        if (options.data && typeof options.data === 'string' && options.data.indexOf('action=delete-tag') !== -1) {
            // tag_IDを抽出
            var match = options.data.match(/tag_ID=(\d+)/);
            if (match) {
                var termId = match[1];

                // 既にチェック済みで許可されている場合はスキップ
                if (pendingDeletes[termId] === 'allowed') {
                    delete pendingDeletes[termId];
                    return;
                }

                // リクエストを中止
                jqXHR.abort();

                // 投稿数をチェック
                checkTermBeforeDelete(termId, options.data);
            }
        }
    });

    /**
     * 削除前にタームの投稿数をチェック
     */
    function checkTermBeforeDelete(termId, originalData) {
        $.ajax({
            url: termDeleteGuard.ajaxUrl,
            type: 'POST',
            data: {
                action: 'check_term_posts',
                nonce: termDeleteGuard.nonce,
                term_id: termId,
                taxonomy: taxonomy
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.canDelete) {
                        // 削除可能 - 元のリクエストを再実行
                        pendingDeletes[termId] = 'allowed';
                        $.ajax({
                            url: termDeleteGuard.ajaxUrl,
                            type: 'POST',
                            data: originalData,
                            success: function() {
                                // 成功時はページをリロード
                                location.reload();
                            },
                            error: function() {
                                showErrorNotice('削除中にエラーが発生しました。');
                            }
                        });
                    } else {
                        // 削除不可 - エラーメッセージを表示
                        var message = '「' + (response.data.termName || 'このターム') + '」には ' + response.data.count + ' 件の記事が紐づいているため削除できません。';
                        showErrorNotice(message);
                    }
                } else {
                    showErrorNotice(response.data.message || 'エラーが発生しました。');
                }
            },
            error: function() {
                showErrorNotice('通信エラーが発生しました。');
            }
        });
    }

    /**
     * エラー通知を表示
     * @param {string} message - 表示するメッセージ
     * @param {boolean} isHtml - HTMLとして表示するかどうか
     */
    function showErrorNotice(message, isHtml) {
        // 既存のエラー通知を削除
        $('.term-delete-guard-notice').remove();

        // メッセージをエスケープするかどうか
        var content = isHtml ? message : escapeHtml(message);

        // 新しいエラー通知を追加
        var $notice = $('<div class="notice notice-error is-dismissible term-delete-guard-notice"><p>' + content + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">この通知を非表示にする。</span></button></div>');

        $('.wrap h1').first().after($notice);

        // 閉じるボタンの動作
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });

        // ページ上部にスクロール
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    /**
     * HTMLエスケープ
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    $(document).ready(function() {
        // 一括削除のフォーム送信をインターセプト
        $('#posts-filter').on('submit', function(e) {
            var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();

            if (action === 'delete') {
                e.preventDefault();

                var checkedItems = $('input[name="delete_tags[]"]:checked');

                if (checkedItems.length === 0) {
                    alert('削除するタームを選択してください。');
                    return;
                }

                var termIds = [];
                checkedItems.each(function() {
                    termIds.push($(this).val());
                });

                // 各タームの投稿数をチェック
                checkMultipleTerms(termIds, $(this));
            }
        });

        /**
         * 複数タームの投稿数をチェック
         */
        function checkMultipleTerms(termIds, $form) {
            var nonDeletableTerms = [];
            var checksCompleted = 0;
            var totalChecks = termIds.length;

            termIds.forEach(function(termId) {
                $.ajax({
                    url: termDeleteGuard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'check_term_posts',
                        nonce: termDeleteGuard.nonce,
                        term_id: termId,
                        taxonomy: taxonomy
                    },
                    success: function(response) {
                        checksCompleted++;

                        if (response.success && !response.data.canDelete) {
                            nonDeletableTerms.push({
                                id: termId,
                                name: response.data.termName || 'ID: ' + termId,
                                count: response.data.count
                            });
                        }

                        if (checksCompleted === totalChecks) {
                            handleBulkDeleteResult(nonDeletableTerms, $form);
                        }
                    },
                    error: function() {
                        checksCompleted++;
                        if (checksCompleted === totalChecks) {
                            handleBulkDeleteResult(nonDeletableTerms, $form);
                        }
                    }
                });
            });
        }

        /**
         * 一括削除のチェック結果を処理
         */
        function handleBulkDeleteResult(nonDeletableTerms, $form) {
            if (nonDeletableTerms.length > 0) {
                var message = '以下のタームには紐づく記事があるため削除できません:<ul style="margin: 0.5em 0 0 1.5em;">';
                nonDeletableTerms.forEach(function(term) {
                    message += '<li>' + escapeHtml(term.name) + ': ' + term.count + '件の記事</li>';
                });
                message += '</ul>';
                showErrorNotice(message, true);
            } else {
                // 一時的にイベントを解除してフォームを送信
                $form.off('submit').submit();
            }
        }
    });
})(jQuery);

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'NExT Term Delete Guard', () => {
	test( 'プラグインが有効化されている', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );
		const pluginRow = page.locator( 'tr[data-slug="next-term-delete-guard"]' );
		await expect( pluginRow ).toHaveClass( /active/ );
	} );

	test( '記事が紐づくカテゴリーの削除がブロックされる', async ( { admin, page, requestUtils } ) => {
		// テスト用カテゴリーと記事を作成
		const category = await requestUtils.createTerm( { taxonomy: 'category', name: 'e2e-test-category' } );
		await requestUtils.createPost( {
			title: 'e2e test post',
			status: 'publish',
			categories: [ category.id ],
		} );

		// カテゴリー管理画面でカテゴリーを削除しようとする
		await admin.visitAdminPage( 'edit-tags.php?taxonomy=category' );
		const categoryRow = page.locator( `tr#tag-${ category.id }` );
		await categoryRow.locator( '.delete a' ).click();

		// エラーまたは削除防止メッセージが表示されることを確認
		const bodyText = await page.locator( 'body' ).textContent();
		const isBlocked =
			bodyText?.includes( '削除できません' ) ||
			bodyText?.includes( 'cannot be deleted' ) ||
			bodyText?.includes( '記事' );
		expect( isBlocked ).toBeTruthy();
	} );
} );

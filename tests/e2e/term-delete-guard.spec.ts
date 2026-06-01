import { test, expect, request } from '@playwright/test';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

test.describe( 'NExT Term Delete Guard', () => {
	test( 'プラグインが有効化されている', async ( { page } ) => {
		await page.goto( `${ WP_BASE_URL }/wp-admin/plugins.php` );
		const pluginRow = page.locator( 'tr[data-slug="next-term-delete-guard"]' );
		await expect( pluginRow ).toHaveClass( /active/ );
	} );

	test( '記事が紐づくカテゴリーの削除がブロックされる', async ( { page } ) => {
		const apiContext = await request.newContext( {
			baseURL: WP_BASE_URL,
			extraHTTPHeaders: {
				Authorization: 'Basic ' + Buffer.from( 'admin:password' ).toString( 'base64' ),
			},
		} );

		// テスト用カテゴリーを作成する.
		const catResponse = await apiContext.post( '/wp-json/wp/v2/categories', {
			data: { name: 'e2e-test-category-' + Date.now() },
		} );
		const category = await catResponse.json();

		// テスト用記事をカテゴリーに紐づけて作成する.
		await apiContext.post( '/wp-json/wp/v2/posts', {
			data: {
				title: 'e2e test post',
				status: 'publish',
				categories: [ category.id ],
			},
		} );

		// カテゴリー管理画面でカテゴリーを削除しようとする.
		await page.goto( `${ WP_BASE_URL }/wp-admin/edit-tags.php?taxonomy=category` );
		const categoryRow = page.locator( `tr#tag-${ category.id }` );
		await expect( categoryRow ).toBeVisible();
		await categoryRow.locator( '.delete a' ).click();

		// エラーメッセージが表示されることを確認する.
		await expect( page.locator( '.notice-error' ) ).toBeVisible( { timeout: 5000 } );

		await apiContext.dispose();
	} );
} );

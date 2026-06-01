import { test, expect } from '@playwright/test';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

test.describe( 'NExT Term Delete Guard', () => {
	test( 'プラグインが有効化されている', async ( { page } ) => {
		await page.goto( `${ WP_BASE_URL }/wp-admin/plugins.php` );
		// プラグイン名が有効な行に表示されている.
		await expect(
			page.locator( 'tr.active td.plugin-title strong', { hasText: 'NExT Term Delete Guard' } )
		).toBeVisible();
	} );

	test( '記事が紐づくカテゴリーの削除がブロックされる', async ( { page } ) => {
		// 管理画面に移動して REST API nonce を取得する.
		await page.goto( `${ WP_BASE_URL }/wp-admin/` );
		const nonce = await page.evaluate(
			() => ( window as unknown as { wpApiSettings?: { nonce?: string } } ).wpApiSettings?.nonce ?? ''
		);

		// nonce を使用してカテゴリーを作成する.
		const catResponse = await page.request.post(
			`${ WP_BASE_URL }/wp-json/wp/v2/categories`,
			{
				data: { name: 'e2e-test-category-' + Date.now() },
				headers: { 'X-WP-Nonce': nonce },
			}
		);

		if ( ! catResponse.ok() ) {
			throw new Error( `Category creation failed: ${ await catResponse.text() }` );
		}

		const category = await catResponse.json();

		// テスト用記事をカテゴリーに紐づけて作成する.
		await page.request.post(
			`${ WP_BASE_URL }/wp-json/wp/v2/posts`,
			{
				data: {
					title: 'e2e test post',
					status: 'publish',
					categories: [ category.id ],
				},
				headers: { 'X-WP-Nonce': nonce },
			}
		);

		// カテゴリー管理画面で削除 URL を取得する.
		await page.goto( `${ WP_BASE_URL }/wp-admin/edit-tags.php?taxonomy=category` );
		const categoryRow = page.locator( `tr#tag-${ category.id }` );
		await expect( categoryRow ).toBeVisible();

		// delete a の href（nonce 付き URL）を取得して直接ナビゲートする.
		const href = await categoryRow.locator( '.delete a' ).getAttribute( 'href' );
		await page.goto( `${ WP_BASE_URL }/wp-admin/${ href }` );

		// エラーメッセージが表示されることを確認する.
		await expect( page.locator( '.notice-error' ) ).toBeVisible( { timeout: 8000 } );
	} );
} );

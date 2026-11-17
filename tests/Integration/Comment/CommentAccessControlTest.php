<?php
/**
 * 留言端點存取控制整合測試（滲透測試報告：Broken Access Control / CWE-862）
 *
 * 前台留言刻意允許訪客，因此防線不是登入，而是：
 * - 欄位白名單：comment_approved、comment_date、任意 meta 一律丟棄
 * - 走 wp_new_comment()：審核狀態跟隨「設定 → 討論」
 * - Power Course 設定 comment_moderation = no 時才跳過審核（垃圾留言仍擋）
 * - 公開的 GET 端點非管理者只能看到已核准留言
 *
 * @group comment
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Comment;

use J7\PowerCourse\Api\Comment as CommentApi;
use J7\PowerCourse\Resources\Comment\Service\Crud;
use J7\PowerCourse\Resources\Comment\Service\Query;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use Tests\Integration\TestCase;

/**
 * Class CommentAccessControlTest
 */
class CommentAccessControlTest extends TestCase {

	/** @var int 已發佈的課程商品 */
	private int $course_id;

	/** @var mixed 測試前的設定 option */
	private mixed $original_option;

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 直接呼叫 Service
	}

	/** 每個測試前：建立課程、套用 WordPress 預設的討論設定、以訪客身分執行 */
	public function set_up(): void {
		parent::set_up();

		$this->original_option = \get_option( Settings::SETTINGS_OPTION_NAME );
		\delete_option( Settings::SETTINGS_OPTION_NAME );
		$this->reset_settings_instance();

		// WordPress 安裝預設值：不強制人工審核、需有已核准留言
		\update_option( 'comment_moderation', '0' );
		\update_option( 'comment_previously_approved', '1' );
		\update_option( 'disallowed_keys', '' );

		// 同一個測試內會連發多則留言，關掉灌水間隔避免互相干擾
		\add_filter( 'wp_is_comment_flood', '__return_false' );

		$this->course_id = $this->create_course( [ 'comment_status' => 'open' ] );
		\wp_set_current_user( 0 );
	}

	/** 每個測試後還原設定 */
	public function tear_down(): void {
		\remove_filter( 'wp_is_comment_flood', '__return_false' );
		if ( false === $this->original_option ) {
			\delete_option( Settings::SETTINGS_OPTION_NAME );
		} else {
			\update_option( Settings::SETTINGS_OPTION_NAME, $this->original_option );
		}
		$this->reset_settings_instance();
		parent::tear_down();
	}

	// ========== 審核狀態 ==========

	/**
	 * @test
	 * @group smoke
	 * 報告步驟 1、2：訪客首次留言，在 WordPress 預設設定下為待審
	 */
	public function test_訪客首次留言預設為待審(): void {
		$result = Crud::create( $this->guest_payload() );

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( 'unapproved', $result['data']['status'] ?? null );
		$this->assertSame( 'unapproved', \wp_get_comment_status( (int) $result['data']['id'] ) );
	}

	/**
	 * @test
	 * 報告步驟 3：請求帶的 comment_approved / comment_date / comment_karma / 任意 meta 一律被丟棄
	 */
	public function test_訪客無法控制審核狀態與其他欄位(): void {
		$result = Crud::create(
			$this->guest_payload(
				[
					'comment_approved' => '1',
					'comment_date'     => '2000-01-01 00:00:00',
					'comment_karma'    => '99',
					'user_id'          => '1',
					'evil_meta'        => 'x',
					'_pc_chapter_id'   => '123',
				]
			)
		);

		$comment_id = (int) $result['data']['id'];
		$comment    = \get_comment( $comment_id );

		$this->assertInstanceOf( \WP_Comment::class, $comment );
		$this->assertSame( '0', $comment->comment_approved );
		$this->assertNotSame( '2000-01-01 00:00:00', $comment->comment_date );
		$this->assertSame( 0, (int) $comment->comment_karma );
		$this->assertSame( 0, (int) $comment->user_id );
		$this->assertSame( '', \get_comment_meta( $comment_id, 'evil_meta', true ) );
		$this->assertSame( '', \get_comment_meta( $comment_id, '_pc_chapter_id', true ) );
	}

	/**
	 * @test
	 * 跟隨 WordPress：兩個審核選項都關閉時，訪客留言直接公開
	 */
	public function test_WordPress討論設定不審核時直接公開(): void {
		\update_option( 'comment_previously_approved', '0' );

		$result = Crud::create( $this->guest_payload() );

		$this->assertSame( 'approved', $result['data']['status'] ?? null );
	}

	/**
	 * @test
	 * 跟隨 WordPress：開啟「留言必須經過人工審核」時，已核准過的訪客也要待審
	 */
	public function test_WordPress開啟人工審核時一律待審(): void {
		\update_option( 'comment_previously_approved', '0' );
		\update_option( 'comment_moderation', '1' );

		$result = Crud::create( $this->guest_payload() );

		$this->assertSame( 'unapproved', $result['data']['status'] ?? null );
	}

	/**
	 * @test
	 * Power Course 關閉審核：即使 WordPress 開了人工審核，留言也直接公開
	 */
	public function test_PowerCourse關閉審核時直接公開(): void {
		\update_option( 'comment_moderation', '1' );
		$this->set_pc_moderation( 'no' );

		$result = Crud::create( $this->guest_payload() );

		$this->assertSame( 'approved', $result['data']['status'] ?? null );
		$this->assertFalse( \has_filter( 'pre_comment_approved' ) && $this->has_skip_filter(), '覆寫審核的 filter 用完要移除' );
	}

	/**
	 * @test
	 * Power Course 關閉審核：命中「禁止使用的留言關鍵字」仍不會公開，回應也不透露被判為垃圾
	 */
	public function test_PowerCourse關閉審核時黑名單仍生效(): void {
		$this->set_pc_moderation( 'no' );
		\update_option( 'disallowed_keys', 'casino' );

		$result = Crud::create( $this->guest_payload( [ 'comment_content' => 'best casino here' ] ) );

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( 'unapproved', $result['data']['status'] ?? null );
		$this->assertContains( \wp_get_comment_status( (int) $result['data']['id'] ), [ 'spam', 'trash' ] );
	}

	/**
	 * @test
	 * 管理員留言不受審核影響
	 */
	public function test_管理員留言直接公開(): void {
		\update_option( 'comment_moderation', '1' );
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = Crud::create(
			[
				'comment_post_ID' => $this->course_id,
				'comment_content' => 'admin reply',
				'comment_type'    => 'comment',
			]
		);

		$this->assertSame( 'approved', $result['data']['status'] ?? null );
	}

	/**
	 * @test
	 * Settings 只接受 yes / no，其他值一律回到需要審核
	 */
	public function test_設定值白名單(): void {
		$settings = Settings::instance();
		$this->assertSame( 'yes', $settings->comment_moderation, '預設需要審核' );

		$settings->set_properties( [ 'comment_moderation' => 'no' ] );
		$this->assertSame( 'no', $settings->comment_moderation );

		$settings->set_properties( [ 'comment_moderation' => 'whatever' ] );
		$this->assertSame( 'yes', $settings->comment_moderation );
	}

	// ========== 輸入驗證 ==========

	/**
	 * @test
	 * 不合法的 comment_type 被拒絕
	 */
	public function test_不合法的留言類型被拒絕(): void {
		$result = Crud::create( $this->guest_payload( [ 'comment_type' => 'review_x' ] ) );
		$this->assertSame( 400, $result['code'] );
	}

	/**
	 * @test
	 * 訪客必須提供合法 email
	 */
	public function test_訪客缺少email被拒絕(): void {
		$result = Crud::create( $this->guest_payload( [ 'comment_author_email' => 'not-an-email' ] ) );
		$this->assertSame( 400, $result['code'] );
	}

	/**
	 * @test
	 * 空白內容被拒絕
	 */
	public function test_空白內容被拒絕(): void {
		$result = Crud::create( $this->guest_payload( [ 'comment_content' => '   ' ] ) );
		$this->assertSame( 400, $result['code'] );
	}

	/**
	 * @test
	 * 訪客不能對草稿商品留言
	 */
	public function test_訪客不能對未發佈商品留言(): void {
		$draft_id = $this->create_course( [ 'post_status' => 'draft' ] );

		$result = Crud::create( $this->guest_payload( [ 'comment_post_ID' => $draft_id ] ) );

		$this->assertSame( 400, $result['code'] );
		$this->assertSame( 0, (int) \get_comments( [ 'post_id' => $draft_id, 'count' => true, 'status' => 'all' ] ) );
	}

	/**
	 * @test
	 * 父留言必須屬於同一個商品
	 */
	public function test_不能回覆其他商品的留言(): void {
		$other_id     = $this->create_course();
		$other_parent = $this->factory()->comment->create(
			[
				'comment_post_ID'  => $other_id,
				'comment_approved' => '1',
			]
		);

		$result = Crud::create( $this->guest_payload( [ 'comment_parent' => $other_parent ] ) );

		$this->assertSame( 400, $result['code'] );
	}

	/**
	 * @test
	 * 訪客不能回覆待審留言
	 */
	public function test_訪客不能回覆待審留言(): void {
		$pending_parent = $this->factory()->comment->create(
			[
				'comment_post_ID'  => $this->course_id,
				'comment_approved' => '0',
			]
		);

		$result = Crud::create( $this->guest_payload( [ 'comment_parent' => $pending_parent ] ) );

		$this->assertSame( 400, $result['code'] );
	}

	/**
	 * @test
	 * 學員評價：rating 必須是 1–5，合法時寫入 rating meta
	 */
	public function test_評價星等驗證(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->enroll_user_to_course( $user_id, $this->course_id );
		\wp_set_current_user( $user_id );

		$bad = Crud::create(
			[
				'comment_post_ID' => $this->course_id,
				'comment_content' => 'great',
				'comment_type'    => 'review',
				'rating'          => '9',
			]
		);
		$this->assertSame( 400, $bad['code'] );

		$good = Crud::create(
			[
				'comment_post_ID' => $this->course_id,
				'comment_content' => 'great',
				'comment_type'    => 'review',
				'rating'          => '4',
			]
		);
		$this->assertSame( 200, $good['code'], (string) $good['message'] );
		$this->assertSame( '4', \get_comment_meta( (int) $good['data']['id'], 'rating', true ) );
	}

	/**
	 * @test
	 * 一般留言不能夾帶星等：WooCommerce 的 comment_post hook 會直接讀 $_POST['rating']，
	 * 而 WC 平均星等的 SQL 不分留言類型，夾帶進去就能操縱課程評分（瀏覽器驗收時發現）
	 */
	public function test_一般留言不能夾帶星等(): void {
		$_POST['rating']          = '5';
		$_POST['comment_post_ID'] = (string) $this->course_id;

		try {
			$result = Crud::create( $this->guest_payload( [ 'rating' => '5' ] ) );
		} finally {
			unset( $_POST['rating'], $_POST['comment_post_ID'] );
		}

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( '', \get_comment_meta( (int) $result['data']['id'], 'rating', true ) );
		$this->assertNotFalse( \has_action( 'comment_post', [ \WC_Comments::class, 'add_comment_rating' ] ), 'WooCommerce 的 hook 用完要掛回去' );
	}

	/**
	 * @test
	 * 章節留言（comment）在真實表單請求下不能被 WooCommerce 改成 review：
	 * WC_Comments::update_comment_type 在 preprocess_comment 看到 $_POST['comment_post_ID'] 是商品，
	 * 就把預設類型改成 review（瀏覽器驗收時發現）
	 */
	public function test_一般留言不會被WooCommerce改成評價(): void {
		\update_option( 'comment_previously_approved', '0' );
		$_POST['comment_post_ID'] = (string) $this->course_id;
		$_POST['comment_type']    = 'comment';

		try {
			$result = Crud::create( $this->guest_payload() );
		} finally {
			unset( $_POST['comment_post_ID'], $_POST['comment_type'] );
		}

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( 'comment', \get_comment( (int) $result['data']['id'] )->comment_type );
	}

	/**
	 * @test
	 * 內容的反斜線不會被吃掉（wp_new_comment 需要 slashed 資料）
	 */
	public function test_內容反斜線完整保留(): void {
		\update_option( 'comment_previously_approved', '0' );

		$result = Crud::create( $this->guest_payload( [ 'comment_content' => 'C:\\Users\\path' ] ) );

		$this->assertSame( 'C:\\Users\\path', \get_comment( (int) $result['data']['id'] )->comment_content );
	}

	/**
	 * @test
	 * @group smoke
	 * 經由真實 REST 路由重放滲透報告步驟 3（訪客、無 nonce、帶 comment_approved=1）
	 */
	public function test_REST重放報告步驟3(): void {
		\do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/power-course/comments' );
		$request->set_body_params(
			[
				'comment_post_ID'      => (string) $this->course_id,
				'rating'               => '5',
				'comment_content'      => '[pentest-approved]',
				'comment_type'         => 'comment',
				'comment_approved'     => '1',
				'comment_author_email' => 'pnt@example.com',
			]
		);

		$response = \rest_do_request( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unapproved', $body['data']['status'] ?? null );
		$this->assertSame( '0', \get_comment( (int) $body['data']['id'] )->comment_approved );
	}

	// ========== 讀取端點 ==========

	/**
	 * @test
	 * 訪客帶 status=hold / all 也看不到待審留言
	 */
	public function test_訪客查不到待審留言(): void {
		$this->factory()->comment->create(
			[
				'comment_post_ID'  => $this->course_id,
				'comment_approved' => '0',
				'comment_type'     => 'comment',
				'comment_content'  => 'pending one',
			]
		);
		$this->factory()->comment->create(
			[
				'comment_post_ID'  => $this->course_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'comment_content'  => 'approved one',
			]
		);

		foreach ( [ 'hold', 'all', 'spam' ] as $status ) {
			$result   = Query::list(
				[
					'post_id' => $this->course_id,
					'type'    => 'comment',
					'status'  => $status,
				],
				fn( \WP_Comment $c, int $depth, array $args ): array => CommentApi::instance()->format_comment_details( $c, $depth, $args )
			);
			$statuses = array_column( $result['comments'], 'comment_approved' );

			$this->assertSame( [ '1' ], $statuses, "status={$status} 不應回傳待審留言" );
		}
	}

	/**
	 * @test
	 * 管理員仍可看到全部留言
	 */
	public function test_管理員可查到待審留言(): void {
		$this->factory()->comment->create(
			[
				'comment_post_ID'  => $this->course_id,
				'comment_approved' => '0',
				'comment_type'     => 'comment',
			]
		);
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = Query::list(
			[
				'post_id' => $this->course_id,
				'type'    => 'comment',
			],
			fn( \WP_Comment $c, int $depth, array $args ): array => CommentApi::instance()->format_comment_details( $c, $depth, $args )
		);

		$this->assertSame( 1, $result['total'] );
	}

	// ========== helpers ==========

	/**
	 * 訪客留言 payload
	 *
	 * @param array<string, mixed> $overrides 覆寫欄位
	 * @return array<string, mixed>
	 */
	private function guest_payload( array $overrides = [] ): array {
		return array_merge(
			[
				'comment_post_ID'      => $this->course_id,
				'comment_content'      => 'hello ' . \wp_generate_password( 8, false ),
				'comment_type'         => 'comment',
				'comment_author_email' => 'guest@example.com',
			],
			$overrides
		);
	}

	/**
	 * 設定 Power Course 的審核開關
	 *
	 * @param string $value yes / no
	 */
	private function set_pc_moderation( string $value ): void {
		\update_option( Settings::SETTINGS_OPTION_NAME, [ 'comment_moderation' => $value ] );
		$this->reset_settings_instance();
	}

	/** 是否還掛著 Crud 用來跳過審核的 filter（以優先序 PHP_INT_MAX 判斷） */
	private function has_skip_filter(): bool {
		global $wp_filter;
		return isset( $wp_filter['pre_comment_approved']->callbacks[ PHP_INT_MAX ] );
	}

	/** 清掉 Settings 單例 */
	private function reset_settings_instance(): void {
		$property = new \ReflectionProperty( Settings::class, 'dto_instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}

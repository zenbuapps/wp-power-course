<?php
/**
 * 信件允許重複寄送設定整合測試
 *
 * Feature: Issue #232 — Email 的 allow_repeat_send 開關
 * 測試 Email 資源的 allow_repeat_send 屬性行為：
 * - 缺少 meta 時預設為 true
 * - meta 值 'yes' → true；'no' → false；其他（含空字串）→ true
 * - 儲存 'no' 到 post_meta 後 Email 物件正確讀取
 *
 * @group email
 * @group allow-repeat-send
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;

/**
 * Class AllowRepeatSendTest
 * 測試 Email 資源的 allow_repeat_send 屬性（尚未實作，預期 Red 狀態）
 */
class AllowRepeatSendTest extends TestCase {

	/** @var int 測試用郵件模板 ID */
	private int $email_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 使用正確的 CPT post_type = 'pe_email'
		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程開通信_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->ids['EmailTemplate'] = $this->email_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * Email 類別可以從 pe_email post 實例化
	 */
	public function test_冒煙_Email類別可從pe_email_post實例化(): void {
		$email = new Email( $this->email_id );
		$this->assertInstanceOf( Email::class, $email );
		$this->assertSame( (string) $this->email_id, $email->id );
	}

	// ========== 快樂路徑（Happy）==========

	/**
	 * @test
	 * @group happy
	 * 缺少 allow_repeat_send meta 時，屬性應預設為 true
	 * （只有字面量 'no' 才是 false，缺少 meta 等同 true）
	 */
	public function test_缺少allow_repeat_send_meta時_屬性預設為true(): void {
		// 確保 meta 不存在
		delete_post_meta( $this->email_id, 'allow_repeat_send' );

		$email = new Email( $this->email_id );

		// 預期 allow_repeat_send 屬性存在且為 true
		// 此屬性目前尚未實作，測試將 Red
		$this->assertTrue(
			$email->allow_repeat_send,
			'缺少 allow_repeat_send meta 時，allow_repeat_send 應預設為 true'
		);
	}

	/**
	 * @test
	 * @group happy
	 * meta 值為 'yes' 時，allow_repeat_send 應為 true
	 */
	public function test_meta值為yes時_allow_repeat_send為true(): void {
		update_post_meta( $this->email_id, 'allow_repeat_send', 'yes' );

		$email = new Email( $this->email_id );

		$this->assertTrue(
			$email->allow_repeat_send,
			"meta 值為 'yes' 時，allow_repeat_send 應為 true"
		);
	}

	/**
	 * @test
	 * @group happy
	 * meta 值為 'no' 時，allow_repeat_send 應為 false
	 */
	public function test_meta值為no時_allow_repeat_send為false(): void {
		update_post_meta( $this->email_id, 'allow_repeat_send', 'no' );

		$email = new Email( $this->email_id );

		$this->assertFalse(
			$email->allow_repeat_send,
			"meta 值為 'no' 時，allow_repeat_send 應為 false（唯一 false 的情況）"
		);
	}

	/**
	 * @test
	 * @group happy
	 * meta 寫入 'no' 的完整來回路徑：
	 * update_post_meta → get_post_meta 確認 'no' → new Email() → allow_repeat_send === false
	 */
	public function test_meta來回路徑_寫入no後Email屬性為false(): void {
		// 模擬 API 寫入路徑：直接對 post_meta 操作
		update_post_meta( $this->email_id, 'allow_repeat_send', 'no' );

		// 確認 meta 正確存入
		$raw_meta = get_post_meta( $this->email_id, 'allow_repeat_send', true );
		$this->assertSame( 'no', $raw_meta, "update_post_meta 寫入 'no' 後，get_post_meta 應回傳 'no'" );

		// 確認 Email 物件正確讀取並轉換
		$email = new Email( $this->email_id );
		$this->assertFalse(
			$email->allow_repeat_send,
			"get_post_meta 為 'no' 時，Email::allow_repeat_send 應為 false"
		);
	}

	// ========== 邊緣案例（Edge）==========

	/**
	 * @test
	 * @group edge
	 * meta 值為空字串 '' 時，allow_repeat_send 應為 true
	 * （只有字面量 'no' 才是 false，空字串不算）
	 */
	public function test_meta值為空字串時_allow_repeat_send為true(): void {
		update_post_meta( $this->email_id, 'allow_repeat_send', '' );

		$email = new Email( $this->email_id );

		$this->assertTrue(
			$email->allow_repeat_send,
			"meta 值為空字串時，allow_repeat_send 應為 true（只有字面量 'no' 才是 false）"
		);
	}
}

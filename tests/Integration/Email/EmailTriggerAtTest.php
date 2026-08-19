<?php
/**
 * pe_email 的 trigger_at 空值防線 整合測試
 *
 * Issue #263 對抗式審查衍生問題（修復 1）：
 * trigger_at 被存成空字串時，那封信會「靜默地永遠不寄」——
 * At::schedule_email() 是以 `meta_key = trigger_at AND meta_value = {slug}` 撈信，
 * 空字串永遠命中不了任何一個 slug，而後台畫面上完全看不出異常。
 *
 * 本檔涵蓋修復的三層 PHP 防線（第四層 React Select required rule 不在 PHPUnit 範圍）：
 *   (a) Email::__construct             —— trigger_at 改在 condition 早退「之前」讀，且 '' → course_granted
 *   (b) Trigger\Condition::__construct —— 空字串也要正規化（原本的 `??` 只擋 null）
 *   (c) Email\Api::post_emails_with_id_callback —— REST 明確帶空值 / 非法值一律 400
 * 外加驗證「空 trigger_at 的信真的撈不到」這個根本機制（受害點）。
 *
 * 注意：@group 放在**檔案** docblock PHPUnit 讀不到，只有 class / method docblock 才算數，
 * 故本檔的 group 宣告在下方 class docblock 與各測試方法上。
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\Condition;

/**
 * Class EmailTriggerAtTest
 * 測試 trigger_at 不得為空字串的各道關卡
 *
 * @group email
 * @group trigger-at
 * @group issue-263-followup
 * @group happy
 */
final class EmailTriggerAtTest extends TestCase {

	/**
	 * 管理員 ID
	 * Email\Api 沿用 ApiBase 預設 permission_callback（manage_options | manage_woocommerce），
	 * 故 REST 測試必須以管理員身分呼叫，否則拿到的是 401 而不是我們要驗的 400。
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * 初始化依賴
	 * 本測試直接操作 Email / Condition / REST，不需要注入 repository / service
	 */
	protected function configure_dependencies(): void {
		// 無需額外依賴
	}

	/**
	 * 每個測試前建立管理員
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_trigger_at_' . uniqid(),
				'user_email' => 'admin_trigger_at_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->ids['Admin'] = $this->admin_id;
	}

	// ========== Helper ==========

	/**
	 * 建立一封信件模板（pe_email）
	 *
	 * @param array<string, mixed> $meta 要寫入的 post meta（trigger_at / condition ...）
	 * @return int 信件 ID
	 */
	private function create_email( array $meta = [] ): int {
		$email_id = $this->factory()->post->create(
			[
				'post_title'  => '測試信件_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $email_id, $key, $value );
		}

		return $email_id;
	}

	/**
	 * 呼叫 REST POST /power-email/emails/{id}
	 * 注意 namespace 是 `power-email`（Email\Api::$namespace），不是 `power-course`
	 *
	 * @param int                  $email_id 信件 ID
	 * @param array<string, mixed> $body     body params（Api::separator() 讀的是 get_body_params()）
	 * @return \WP_REST_Response
	 */
	private function call_post_email( int $email_id, array $body ): \WP_REST_Response {
		wp_set_current_user( $this->admin_id );

		$request = new \WP_REST_Request( 'POST', "/power-email/emails/{$email_id}" );
		$request->set_body_params( $body );

		/** @var \WP_REST_Response $response dispatch() 一定會把 WP_Error 轉成 WP_REST_Response */
		$response = rest_do_request( $request );

		return $response;
	}

	/**
	 * 依 At::schedule_email() 的查詢條件撈信
	 * schedule_email() 是 private 無法直接呼叫，這裡原樣複製它的 get_posts 條件，
	 * 用來驗證「trigger_at 為空的信撈不撈得到」這個根本機制。
	 *
	 * @param string $slug 觸發時機點 slug
	 * @return array<int> 信件 ID 陣列
	 */
	private function query_emails_by_trigger_at( string $slug ): array {
		$email_ids = get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_key'       => 'trigger_at',
				'meta_value'     => $slug,
			]
		);

		return array_map( 'intval', (array) $email_ids );
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 信件更新端點已註冊
	 *
	 * 沒有這道冒煙檢查的話，一旦端點沒註冊，下面的 REST 測試全部會拿到 404，
	 * 而失敗訊息會指向「狀態碼不是 400」，讓人往驗證邏輯的方向找錯。
	 */
	public function test_冒煙_信件更新端點已註冊(): void {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/power-email/emails/(?P<id>\d+)',
			$routes,
			'信件更新端點 /power-email/emails/{id} 未被註冊'
		);
	}

	// ========== (a) Email::__construct ==========

	/**
	 * @test
	 * @group happy
	 * 還沒設過 condition 的信，trigger_at 不得為空
	 *
	 * 修復前為什麼會紅：
	 * 舊版 Email::__construct 把 `$this->trigger_at = get_post_meta( ..., 'trigger_at' )`
	 * 放在「condition meta 不存在就 return」的早退**之後**。
	 * 只要這封信還沒設過 condition（剛建立、或只改過主旨），
	 * 建構子在讀 trigger_at 之前就 return 了，屬性維持宣告時的預設值 ''。
	 * 前端表單因此拿到 ''（不是 undefined）→ rc-field-form 不套 initialValue → Select 空白
	 * → 使用者一存檔就把 'course_granted' 洗成 '' → 這封信再也不會被 schedule 到。
	 */
	public function test_未設過condition的信trigger_at不得為空(): void {
		$email_id = $this->create_email( [ 'trigger_at' => AtHelper::COURSE_GRANTED ] );

		// 前置條件：這封信必須真的沒有 condition meta，否則走不到早退那條路，等於什麼都沒測到
		$this->assertFalse(
			metadata_exists( 'post', $email_id, 'condition' ),
			'前置條件不成立：這封信不該有 condition meta，否則測不到「早退之前讀不讀得到 trigger_at」'
		);

		$email = new Email( $email_id );

		$this->assertNotSame( '', $email->trigger_at, '沒有 condition 的信，trigger_at 不該是空字串' );
		$this->assertSame(
			AtHelper::COURSE_GRANTED,
			$email->trigger_at,
			'沒有 condition 的信，trigger_at 應讀到 post meta 的 course_granted'
		);

		// 防假綠：course_granted 同時也是 fallback 值，只驗這一個值的話，
		// 就算讀取仍留在早退之後（實際拿到 ''），也可能被 fallback 蓋成綠。
		// 這裡改用一個 fallback 不可能產生的 slug 再驗一次，證明值真的是從 meta 讀出來的。
		$email_id_2 = $this->create_email( [ 'trigger_at' => AtHelper::CHAPTER_FINISHED ] );
		$email_2    = new Email( $email_id_2 );

		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			$email_2->trigger_at,
			'沒有 condition 的信，trigger_at 應原樣讀到 chapter_finish（而不是空字串或 fallback 值）'
		);
	}

	/**
	 * @test
	 * @group edge
	 * trigger_at 的 post meta 為空字串時回退為 course_granted
	 *
	 * 修復前為什麼會紅：
	 * 舊版只有 `(string) get_post_meta( ... )`，沒有任何回退，
	 * 既有站台被前端洗成 '' 的信讀出來還是 ''，
	 * 於是 At::schedule_email() 的 `meta_value = {slug}` 永遠對不上，信永遠不寄。
	 * （沒有 condition 與有 condition 兩條路徑都要回退，故兩種都驗。）
	 */
	public function test_trigger_at的post_meta為空字串時回退為course_granted(): void {
		$email_id = $this->create_email( [ 'trigger_at' => '' ] );

		// 前置條件：meta 必須「存在且為空字串」，而不是「不存在」——
		// get_post_meta() 兩種情況都回 ''，所以要用 metadata_exists() 才問得清楚
		$this->assertTrue(
			metadata_exists( 'post', $email_id, 'trigger_at' ),
			'前置條件不成立：trigger_at meta 應存在（值為空字串）'
		);
		$this->assertSame(
			'',
			get_post_meta( $email_id, 'trigger_at', true ),
			'前置條件不成立：trigger_at meta 應為空字串'
		);

		$email = new Email( $email_id );

		$this->assertSame(
			AtHelper::COURSE_GRANTED,
			$email->trigger_at,
			'trigger_at meta 為空字串時應回退為 course_granted，否則這封信永遠撈不到'
		);

		// 有 condition 的路徑（不會早退）也要回退，而且回退值要一路傳進 Condition 物件
		$email_id_with_condition = $this->create_email(
			[
				'trigger_at' => '',
				'condition'  => [
					'trigger_condition' => 'each',
					'course_ids'        => [],
					'chapter_ids'       => [],
				],
			]
		);

		$email_with_condition = new Email( $email_id_with_condition );

		$this->assertSame(
			AtHelper::COURSE_GRANTED,
			$email_with_condition->trigger_at,
			'有 condition 的信，trigger_at 空字串一樣要回退為 course_granted'
		);
		$this->assertInstanceOf(
			Condition::class,
			$email_with_condition->condition,
			'condition meta 是陣列時應建成 Condition 物件'
		);
		$this->assertSame(
			AtHelper::COURSE_GRANTED,
			$email_with_condition->condition->trigger_at,
			'回退後的 trigger_at 必須一路傳進 Condition 物件，否則 required_ids 會算錯'
		);
	}

	/**
	 * @test
	 * @group happy
	 * trigger_at 有值時不被覆蓋（防呆：修法不可以把所有信都變成 course_granted）
	 *
	 * 修復前為什麼會紅：
	 * 這一條在修復前其實是綠的（有 condition 就不會早退，trigger_at 讀得到）。
	 * 它存在的目的是釘住修法的另一半——回退只能發生在空字串。
	 * 若有人把 (a) 寫成無條件覆寫，所有章節類信件都會被改成 course_granted 而在錯的時機寄出，
	 * 這條會立刻紅。
	 */
	public function test_trigger_at有值時不被覆蓋(): void {
		$email_id = $this->create_email(
			[
				'trigger_at' => AtHelper::CHAPTER_FINISHED,
				'condition'  => [
					'trigger_condition' => 'each',
					'course_ids'        => [],
					'chapter_ids'       => [],
				],
			]
		);

		$email = new Email( $email_id );

		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			$email->trigger_at,
			'trigger_at 已有合法值時不得被 course_granted 覆蓋'
		);

		$this->assertInstanceOf( Condition::class, $email->condition, 'condition meta 是陣列時應建成 Condition 物件' );
		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			$email->condition->trigger_at,
			'Condition 物件內的 trigger_at 也要維持 chapter_finish'
		);
	}

	// ========== (b) Trigger\Condition::__construct ==========

	/**
	 * @test
	 * @group edge
	 * Condition 建構子把空字串 trigger_at 正規化為 course_granted
	 *
	 * 修復前為什麼會紅：
	 * 舊版是 `$condition['trigger_at'] ?? AtHelper::COURSE_GRANTED`，
	 * 而 `??` 只擋 null、擋不掉空字串，
	 * 於是 trigger_at = '' 會原樣落進 $this->trigger_at，
	 * set_required_ids() 的 in_array 判斷不成立 → 掉進章節分支 →
	 * course_ids / chapter_ids 都空時直接撈「全站所有章節」當 required_ids，條件判斷整個失真。
	 */
	public function test_condition建構子把空字串trigger_at正規化(): void {
		/** @var array<string, array<string, mixed>> $cases 三種「等同於沒填」的輸入 */
		$cases = [
			'空字串'    => [
				'trigger_at'        => '',
				'trigger_condition' => 'each',
			],
			'null'     => [
				'trigger_at'        => null,
				'trigger_condition' => 'each',
			],
			'缺少 key' => [
				'trigger_condition' => 'each',
			],
		];

		foreach ( $cases as $label => $condition_array ) {
			$condition = new Condition( $condition_array );

			$this->assertSame(
				AtHelper::COURSE_GRANTED,
				$condition->trigger_at,
				"trigger_at 為「{$label}」時，Condition 應正規化為 course_granted"
			);
		}

		// 防呆：合法值不得被正規化掉
		$valid_condition = new Condition(
			[
				'trigger_at'        => AtHelper::CHAPTER_FINISHED,
				'trigger_condition' => 'each',
			]
		);

		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			$valid_condition->trigger_at,
			'合法的 trigger_at 不得被正規化成 course_granted'
		);
	}

	// ========== (c) REST 閘門 ==========

	/**
	 * @test
	 * @group error
	 * REST 拒絕空的 trigger_at，且不得改動 DB
	 *
	 * 修復前為什麼會紅：
	 * 舊版 post_emails_with_id_callback 對 meta_data 不做任何檢查，
	 * `trigger_at => ''` 會被原樣寫進 post meta 並回 200「更新成功」，
	 * 使用者看到的是成功提示，實際上這封信從此不會再被排程。
	 */
	public function test_REST拒絕空的trigger_at(): void {
		$email_id = $this->create_email( [ 'trigger_at' => AtHelper::CHAPTER_FINISHED ] );

		$response = $this->call_post_email( $email_id, [ 'trigger_at' => '' ] );

		$this->assertSame( 400, $response->get_status(), '空的 trigger_at 應回 400' );

		$data = $response->get_data();
		$this->assertIsArray( $data, '錯誤回應應為陣列' );
		$this->assertSame( 'invalid_trigger_at', $data['code'] ?? null, '錯誤代碼應為 invalid_trigger_at' );

		// 最重要的一條：擋下來之後 DB 不可以被改到（否則擋了也是白擋）
		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			get_post_meta( $email_id, 'trigger_at', true ),
			'請求被拒絕後，DB 內的 trigger_at 不得被改動'
		);
	}

	/**
	 * @test
	 * @group error
	 * REST 拒絕不在白名單內的 trigger_at
	 *
	 * 修復前為什麼會紅：
	 * 舊版完全不驗 slug，任何字串都寫得進去。
	 * 非法 slug 與空字串的後果一樣——schedule_email() 的 meta_value 永遠對不上；
	 * 而 AtHelper::validate_slug() 只是把它悄悄改成 course_granted 並寫 log，
	 * 導致「存進去的」與「實際跑的」不一致。
	 */
	public function test_REST拒絕不合法的trigger_at(): void {
		$email_id = $this->create_email( [ 'trigger_at' => AtHelper::COURSE_GRANTED ] );

		$response = $this->call_post_email( $email_id, [ 'trigger_at' => 'not_a_real_slug' ] );

		$this->assertSame( 400, $response->get_status(), '不在 AtHelper::$allowed_slugs 內的 trigger_at 應回 400' );

		$data = $response->get_data();
		$this->assertIsArray( $data, '錯誤回應應為陣列' );
		$this->assertSame( 'invalid_trigger_at', $data['code'] ?? null, '錯誤代碼應為 invalid_trigger_at' );

		$this->assertSame(
			AtHelper::COURSE_GRANTED,
			get_post_meta( $email_id, 'trigger_at', true ),
			'請求被拒絕後，DB 內的 trigger_at 不得被改動'
		);
	}

	/**
	 * @test
	 * @group happy
	 * REST 允許合法的 trigger_at（證明閘門沒有誤擋正常路徑）
	 *
	 * 修復前為什麼會紅：
	 * 這條在修復前是綠的，它的作用是釘住「閘門不可以過度攔截」——
	 * 沒有這條的話，把 (c) 寫成「一律 400」也能讓上面兩條錯誤測試通過，但功能整個壞掉。
	 */
	public function test_REST允許合法的trigger_at(): void {
		$email_id = $this->create_email( [ 'trigger_at' => AtHelper::COURSE_GRANTED ] );

		$response = $this->call_post_email( $email_id, [ 'trigger_at' => AtHelper::CHAPTER_FINISHED ] );

		$this->assertSame( 200, $response->get_status(), '合法的 trigger_at 應回 200' );

		$data = $response->get_data();
		$this->assertIsArray( $data, '成功回應應為陣列' );
		$this->assertSame( 'update_success', $data['code'] ?? null, '回應代碼應為 update_success' );

		// DB 真的被改了（不是「回 200 但沒寫進去」的假成功）
		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			get_post_meta( $email_id, 'trigger_at', true ),
			'合法請求應真的把 trigger_at 寫進 DB'
		);

		// 再從 Email 資源讀一次，確認寫入端與讀取端一致
		$email = new Email( $email_id );
		$this->assertSame(
			AtHelper::CHAPTER_FINISHED,
			$email->trigger_at,
			'Email 資源讀回來的 trigger_at 應與寫入值一致'
		);
	}

	// ========== 根本機制：空值撈不到 ==========

	/**
	 * @test
	 * @group edge
	 * trigger_at 為空的信不會被 schedule_email 的查詢撿到
	 *
	 * 修復前為什麼會紅：
	 * 嚴格說這條驗的是 WordPress 的查詢行為（meta_value = {slug} 撈不到空字串），
	 * 它本身在修復前後都成立——但它是整條 bug 鏈「為什麼會靜默失效」的證據：
	 * 只要 trigger_at 被存成 ''，這封信對 At::schedule_email() 的所有 slug 全部隱形，
	 * 既不報錯也不留 log。上面 (a)(b)(c) 三道防線就是為了讓這種信不可能存在，
	 * 所以最後再斷言「修復後讀出來的 trigger_at 必定是合法 slug」。
	 * 另外同時驗「正常信撈得到」作為對照組，避免查詢本身壞掉時全部撈不到而假綠。
	 */
	public function test_空trigger_at的信不會被schedule_email撿到(): void {
		$good_email_id  = $this->create_email( [ 'trigger_at' => AtHelper::COURSE_GRANTED ] );
		$empty_email_id = $this->create_email( [ 'trigger_at' => '' ] );

		// 對照組先驗：查詢本身是有效的
		$found = $this->query_emails_by_trigger_at( AtHelper::COURSE_GRANTED );
		$this->assertContains(
			$good_email_id,
			$found,
			'trigger_at = course_granted 的信應該要被撈到（否則是查詢本身壞了，下面的斷言會假綠）'
		);

		// 空值信對每一個時機點都隱形
		foreach ( AtHelper::$allowed_slugs as $slug ) {
			$found_by_slug = $this->query_emails_by_trigger_at( $slug );

			$this->assertNotContains(
				$empty_email_id,
				$found_by_slug,
				"trigger_at 為空字串的信不該被 slug「{$slug}」撈到——這就是它永遠不寄的原因"
			);
		}

		// 修復後：透過建構子讀出來的 trigger_at 一定非空，故不可能再產生這種隱形信
		$empty_email = new Email( $empty_email_id );
		$this->assertNotSame( '', $empty_email->trigger_at, '修復後 Email::$trigger_at 不得為空字串' );
		$this->assertContains(
			$empty_email->trigger_at,
			AtHelper::$allowed_slugs,
			'修復後 Email::$trigger_at 必須是 AtHelper::$allowed_slugs 內的合法值'
		);
	}
}

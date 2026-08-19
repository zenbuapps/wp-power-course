<?php
/**
 * Email::is_sent() identifier 對稱性整合測試
 *
 * Issue #263 對抗式審查衍生修復 2 —— is_sent() 的 identifier 與寫入端不對稱
 *
 * 寫入端 CPT::record_user_id_after_send_email() 寫的是
 *   get_identifier( [ $course_id, $chapter_id ], $user_id )   → ids = "course,chapter"
 * 舊讀取端 Email::is_sent( int $post_id, int $user_id ) 查的是
 *   get_identifier( [ $post_id ], $user_id )                  → ids = "chapter"
 * 而 At::trigger_condition() 傳進去的 $post_id 是 `$chapter_id ?: $course_id`。
 *
 * 於是「章節類信件（chapter_id > 0）且 trigger_condition = 'each'」這個組合，
 * 寫入與查詢的 identifier 字串永遠對不上 → 去重永遠失效 →
 * allow_repeat_send = false 形同虛設，同一封信會重複寄給同一個學員。
 *
 * 非 'each' 條件時 get_identifier() 會把 $data['ids'] 覆寫成 required_ids，
 * 兩邊自然一致，所以不受影響 —— 本檔的 edge 測試就是在保護這條路徑不被修法打壞。
 *
 * 修法：is_sent( array $post_ids, int $user_id )；At.php 改傳 [ $course_id, $chapter_id ]
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;

/**
 * Class EmailIsSentIdentifierTest
 *
 * ⚠️ 群組標註必須放在 class docblock（或方法 docblock）。
 * 放在檔案 docblock（declare 之前）PHPUnit 讀不到，`--group X` 會回 No tests executed。
 * 且必須含 phpunit.xml.dist <groups><include> 白名單內的群組（happy / edge），
 * 否則 CI 的預設執行（不帶 --group）會整檔跳過。
 *
 * @group email
 * @group issue-263-followup
 * @group happy
 * @group edge
 */
class EmailIsSentIdentifierTest extends TestCase {

	/** @var int Alice（學員）用戶 ID */
	private int $alice_id;

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試章節 ID */
	private int $chapter_id;

	/** @var int 第二個測試章節 ID（給非 each 條件用） */
	private int $chapter_2_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 只用到 Email / EmailRecord / At，不需要額外容器
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_isid_' . uniqid(),
				'user_email' => 'alice_isid_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->course_id    = $this->create_course( [ 'post_title' => 'identifier 對稱性測試課程' ] );
		$this->chapter_id   = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$this->chapter_2_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第二章' ] );

		$this->ids['Alice']    = $this->alice_id;
		$this->ids['Course']   = $this->course_id;
		$this->ids['Chapter']  = $this->chapter_id;
		$this->ids['Chapter2'] = $this->chapter_2_id;
	}

	// ========== 私有輔助方法 ==========

	/**
	 * 建立 pe_email 模板
	 *
	 * condition 一定要給滿 trigger_condition / sending 兩個鍵：
	 * Trigger\Condition::__construct() 直接讀 $condition['trigger_condition'] 與
	 * $condition['sending']['type']，缺鍵會噴 warning，而 phpunit.xml.dist 設了
	 * convertWarningsToExceptions="true"，測試會以無關的理由變紅。
	 *
	 * @param string               $trigger_at        觸發時機 slug
	 * @param array<string, mixed> $condition         condition meta（會補上 sending 預設值）
	 * @param string               $allow_repeat_send 'yes' | 'no'
	 * @return int pe_email post ID
	 */
	private function create_email_template( string $trigger_at, array $condition, string $allow_repeat_send = 'no' ): int {
		$email_id = $this->factory()->post->create(
			[
				'post_title'  => '測試信件_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$condition = wp_parse_args(
			$condition,
			[
				'trigger_condition' => 'each',
				'course_ids'        => [],
				'chapter_ids'       => [],
				'qty'               => null,
				'sending'           => [
					'type'  => 'send_now',
					'value' => null,
					'unit'  => null,
					'range' => null,
				],
			]
		);

		update_post_meta( $email_id, 'trigger_at', $trigger_at );
		update_post_meta( $email_id, 'condition', $condition );
		update_post_meta( $email_id, 'allow_repeat_send', $allow_repeat_send );

		return $email_id;
	}

	/**
	 * 以「寫入端的形狀」寫一筆寄信紀錄
	 *
	 * 完全複製 CPT::record_user_id_after_send_email() 的參數：
	 * post_id 用 `$chapter_id ?: $course_id`，identifier 用 [ $course_id, $chapter_id ]。
	 * 測試若自己另外湊一組參數，就等於在測自己寫的假寫入端，抓不到真正的不對稱。
	 *
	 * @param Email  $email      信件
	 * @param int    $course_id  課程 ID
	 * @param int    $chapter_id 章節 ID（0 表示課程類信件）
	 * @param string $trigger_at 觸發時機 slug
	 * @return string 寫入時用的 identifier
	 */
	private function record_as_producer_does( Email $email, int $course_id, int $chapter_id, string $trigger_at ): string {
		$identifier = $email->get_identifier( [ $course_id, $chapter_id ], $this->alice_id );

		EmailRecord::add(
			$chapter_id ? $chapter_id : $course_id,
			$this->alice_id,
			(int) $email->id,
			'',
			$trigger_at,
			$identifier,
			true
		);

		return $identifier;
	}

	// ========== 修復 2：章節類信件（chapter_id > 0）＋ each ==========

	/**
	 * @test
	 * @group happy
	 * 章節類信件的寫入端與查詢端 identifier 必須一致
	 *
	 * 修復前為什麼會紅：
	 * 1. 舊簽章是 is_sent( int $post_id, int $user_id )，本測試傳陣列 →
	 *    declare(strict_types=1) 下直接 TypeError。
	 * 2. 就算照舊呼叫端的寫法傳 `$chapter_id ?: $course_id`（= chapter_id），
	 *    查詢用的 identifier ids 段是 "chapter"，寫入的卻是 "course,chapter"，
	 *    字串比不中 → is_sent() 回 false，去重形同虛設。
	 */
	public function test_章節類信件寫入與查詢的identifier必須一致(): void {
		$email_id = $this->create_email_template(
			AtHelper::CHAPTER_FINISHED,
			[
				'trigger_condition' => 'each',
				'course_ids'        => [ $this->course_id ],
				'chapter_ids'       => [ $this->chapter_id ],
			]
		);

		$email      = new Email( $email_id );
		$identifier = $this->record_as_producer_does(
			$email,
			$this->course_id,
			$this->chapter_id,
			AtHelper::CHAPTER_FINISHED
		);

		// 前置條件：確認寫入的 identifier 真的是 "course,chapter"，
		// 否則後面的斷言可能只是因為兩邊都空而假綠
		$this->assertStringContainsString(
			"ids:{$this->course_id},{$this->chapter_id}|",
			$identifier,
			'前置條件：each 條件下 identifier 的 ids 段應為 "course,chapter"'
		);

		// 紀錄確實落地（證明不是查不到資料才回 false）
		$records = EmailRecord::get( [ 'identifier' => $identifier ] );
		$this->assertCount( 1, $records, '前置條件：應寫入一筆 EmailRecord' );

		$this->assertTrue(
			$email->is_sent( [ $this->course_id, $this->chapter_id ], $this->alice_id ),
			'is_sent() 必須用與寫入端相同的 [ course_id, chapter_id ] 組出 identifier，否則去重永遠對不上'
		);
	}

	/**
	 * @test
	 * @group happy
	 * allow_repeat_send = false 時，章節信不應重複寄送（走真正的呼叫端 At::trigger_condition）
	 *
	 * 修復前為什麼會紅：
	 * At::trigger_condition() 舊版算出 $post_id = `$chapter_id ?: $course_id` = chapter_id，
	 * 傳給 is_sent() 後 identifier ids 只有 "chapter"，與寫入的 "course,chapter" 對不上
	 * → is_sent() 回 false → 去重沒擋住 → 繼續往下走 condition->can_trigger()，
	 * 'each' + 章節在 required_ids 內 → 回 true → 同一封信被重複寄出。
	 * 本測試斷言 false，故修復前為紅。
	 */
	public function test_allow_repeat_send為false時章節信不會重複寄(): void {
		$email_id = $this->create_email_template(
			AtHelper::CHAPTER_FINISHED,
			[
				'trigger_condition' => 'each',
				'course_ids'        => [ $this->course_id ],
				'chapter_ids'       => [ $this->chapter_id ],
			],
			'no'
		);

		// 前置條件：還沒有任何寄送紀錄時，這封信本來就該被允許寄出。
		// 少了這個斷言，最後的 assertFalse 可能只是因為「條件根本不成立」而假綠。
		$this->assertTrue(
			At::instance()->trigger_condition(
				true,
				new Email( $email_id ),
				$this->alice_id,
				$this->course_id,
				$this->chapter_id
			),
			'前置條件：尚未寄送時，trigger_condition() 應回傳 true'
		);

		// 以寫入端的形狀留下「已寄送」紀錄
		$this->record_as_producer_does(
			new Email( $email_id ),
			$this->course_id,
			$this->chapter_id,
			AtHelper::CHAPTER_FINISHED
		);

		$this->assertFalse(
			At::instance()->trigger_condition(
				true,
				new Email( $email_id ),
				$this->alice_id,
				$this->course_id,
				$this->chapter_id
			),
			'allow_repeat_send=false 且已寄送過時，trigger_condition() 應回傳 false（不得重複寄送）'
		);
	}

	// ========== 回歸保護：修復前後應一致的路徑 ==========

	/**
	 * @test
	 * @group edge
	 * 課程類信件（chapter_id = 0）的 identifier 不受修法影響
	 *
	 * 修復前為什麼會紅：這一條**不該**紅 —— 它是回歸保護。
	 * get_identifier() 內部 array_filter() 會把 0 濾掉，所以
	 * [ course_id, 0 ] 與舊寫法的 [ course_id ] 產出完全相同的 ids 段 "course"。
	 * 若修法不小心動到 array_filter 或 ids 串接規則，這條會轉紅示警。
	 */
	public function test_課程類信件的identifier不受影響(): void {
		$email_id = $this->create_email_template(
			AtHelper::COURSE_GRANTED,
			[
				'trigger_condition' => 'each',
				'course_ids'        => [ $this->course_id ],
			],
			'no'
		);

		$email = new Email( $email_id );

		// 舊寫法（單一 id）與新寫法（含 chapter_id = 0）必須產出同一個字串
		$this->assertSame(
			$email->get_identifier( [ $this->course_id ], $this->alice_id ),
			$email->get_identifier( [ $this->course_id, 0 ], $this->alice_id ),
			'chapter_id = 0 時，array_filter() 會濾掉 0，新舊寫法的 identifier 必須相同'
		);

		$identifier = $this->record_as_producer_does(
			$email,
			$this->course_id,
			0,
			AtHelper::COURSE_GRANTED
		);

		$this->assertStringContainsString(
			"ids:{$this->course_id}|",
			$identifier,
			'課程類信件的 ids 段應只有課程 ID'
		);

		$this->assertTrue(
			$email->is_sent( [ $this->course_id, 0 ], $this->alice_id ),
			'課程類信件的去重在修法前後都應成立'
		);

		// 真正的呼叫端也要擋得住（chapter_id = 0 的路徑）
		$this->assertFalse(
			At::instance()->trigger_condition(
				true,
				new Email( $email_id ),
				$this->alice_id,
				$this->course_id,
				0
			),
			'allow_repeat_send=false 且已寄送時，課程類信件同樣不得重複寄送'
		);
	}

	/**
	 * @test
	 * @group edge
	 * 非 each 條件（all）的 identifier 以 required_ids 為準，與傳入的 post_ids 無關
	 *
	 * 修復前為什麼會紅：這一條**不該**紅 —— 它是回歸保護。
	 * get_identifier() 在 trigger_condition !== 'each' 時會把 $data['ids'] 整個
	 * 覆寫成 required_ids，傳進來的 post_ids 根本不影響結果，
	 * 所以舊版「單一 id」與新版「[ course, chapter ]」本來就一致，不受修法影響。
	 * 這條測試證明修法沒有把這條路徑一起改壞。
	 */
	public function test_非each條件的identifier以required_ids為準(): void {
		$email_id = $this->create_email_template(
			AtHelper::CHAPTER_FINISHED,
			[
				'trigger_condition' => 'all',
				'course_ids'        => [ $this->course_id ],
				'chapter_ids'       => [ $this->chapter_id, $this->chapter_2_id ],
			],
			'no'
		);

		$email = new Email( $email_id );

		$write_identifier = $email->get_identifier( [ $this->course_id, $this->chapter_id ], $this->alice_id );

		// ids 段應該是 required_ids（兩個章節），而不是傳進去的 [ course, chapter ]
		$this->assertStringContainsString(
			"ids:{$this->chapter_id},{$this->chapter_2_id}|",
			$write_identifier,
			"trigger_condition = 'all' 時，ids 段應被 required_ids 覆寫"
		);

		// 舊寫法（單一 id）產出的字串必須完全相同 —— 這正是這條路徑不受修法影響的原因
		$this->assertSame(
			$write_identifier,
			$email->get_identifier( [ $this->chapter_id ], $this->alice_id ),
			"trigger_condition = 'all' 時，傳入的 post_ids 不影響 identifier"
		);

		$this->record_as_producer_does(
			$email,
			$this->course_id,
			$this->chapter_id,
			AtHelper::CHAPTER_FINISHED
		);

		$this->assertTrue(
			$email->is_sent( [ $this->course_id, $this->chapter_id ], $this->alice_id ),
			"trigger_condition = 'all' 時，寫入與查詢仍必須一致"
		);
	}

	/**
	 * @test
	 * @group edge
	 * is_sent() 只認 mark_as_sent = 1 的紀錄
	 *
	 * 修復前為什麼會紅：這一條**不該**紅 —— 它是回歸保護。
	 * is_sent() 的查詢條件是 identifier + mark_as_sent = '1' 兩個欄位；
	 * 修復 4 把 EmailRecord::get() 改成白名單 + prepare 後，
	 * 若 mark_as_sent 漏進白名單（或值的型別轉換出錯），
	 * 這個條件會被靜默丟掉 → 撤銷後重寄的判斷全部失準。
	 */
	public function test_is_sent只認mark_as_sent為1的紀錄(): void {
		$email_id = $this->create_email_template(
			AtHelper::CHAPTER_FINISHED,
			[
				'trigger_condition' => 'each',
				'course_ids'        => [ $this->course_id ],
				'chapter_ids'       => [ $this->chapter_id ],
			],
			'no'
		);

		$email      = new Email( $email_id );
		$identifier = $this->record_as_producer_does(
			$email,
			$this->course_id,
			$this->chapter_id,
			AtHelper::CHAPTER_FINISHED
		);

		$this->assertTrue(
			$email->is_sent( [ $this->course_id, $this->chapter_id ], $this->alice_id ),
			'前置條件：EmailRecord::add() 寫入時 mark_as_sent = 1，is_sent() 應為 true'
		);

		// 把 mark_as_sent 改成 0。
		// where 只用數值欄位：EmailRecord::update() 的 where format 固定是 [ '%d' ]，
		// 帶字串欄位（如 trigger_at）進去會被轉成 0，比對結果不可預期。
		$updated = EmailRecord::update(
			[
				'post_id'  => $this->chapter_id,
				'user_id'  => $this->alice_id,
				'email_id' => $email_id,
			],
			[ 'mark_as_sent' => '0' ]
		);
		$this->assertNotFalse( $updated, 'EmailRecord::update() 不應失敗' );

		// 紀錄仍然存在（證明 is_sent() 轉 false 是因為 mark_as_sent，不是因為紀錄被刪）
		$records = EmailRecord::get( [ 'identifier' => $identifier ] );
		$this->assertCount( 1, $records, 'EmailRecord 應仍存在' );
		$this->assertSame( '0', (string) $records[0]->mark_as_sent, 'mark_as_sent 應已被改為 0' );

		$this->assertFalse(
			$email->is_sent( [ $this->course_id, $this->chapter_id ], $this->alice_id ),
			'mark_as_sent = 0 的紀錄不應被視為已寄送'
		);

		// allow_repeat_send = false 但未寄送 → 應放行（撤銷後重寄的關鍵路徑）
		$this->assertTrue(
			At::instance()->trigger_condition(
				true,
				new Email( $email_id ),
				$this->alice_id,
				$this->course_id,
				$this->chapter_id
			),
			'mark_as_sent 已重設為 0 時，trigger_condition() 應回傳 true（允許重新寄送）'
		);
	}
}

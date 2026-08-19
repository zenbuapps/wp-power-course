<?php
/**
 * EmailRecord CRUD::get() 白名單與 prepare 整合測試
 *
 * Feature: Issue #263 對抗式審查衍生 —— 修復 4：EmailRecord\CRUD::get() 的 SQL 注入面
 *
 * 修復前 get() 的實作是：
 *   foreach ($where as $key => $value) { $where_arr[] = "{$key} = '{$value}'"; }
 *   return $wpdb->get_results("SELECT * FROM $table_name WHERE $where"); // phpcs:ignore
 * 欄位名**與**值都直接字串內插，再用 phpcs:ignore 把警告壓掉。
 *
 * 修復後：
 *   - 欄位名走 CRUD::$allowed_columns 白名單（欄位名無法用 placeholder 保護）
 *   - 值全部走 $wpdb->prepare() 的 %s
 *   - $where 為空、或全部欄位都不在白名單 → 回傳 []
 *
 * 注意：group 標註必須寫在 class / method 的 docblock。寫在檔案 docblock（declare 之前）
 * PHPUnit 讀不到，--group 會回 No tests executed。
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;

/**
 * Class EmailRecordCrudTest
 * 測試 EmailRecord\CRUD::get() 的欄位白名單與參數化查詢
 *
 * class 層級掛上白名單 group（security）是刻意的：phpunit.xml.dist 的 <groups><include>
 * 只收 smoke / happy / error / edge / security，而 CI 是不帶 --group 的預設執行；
 * 少了白名單 group，整個檔案會被靜默略過（假綠）。
 *
 * @group security
 * @group email
 * @group email-record-crud
 * @group issue-263-followup
 */
class EmailRecordCrudTest extends TestCase {

	/** @var int 課程 A 的 post_id */
	private int $post_a;

	/** @var int 課程 B 的 post_id */
	private int $post_b;

	/** @var int 學員 Alice 的 user_id */
	private int $alice_id;

	/** @var int 學員 Bob 的 user_id */
	private int $bob_id;

	/** @var int 信件模板 A 的 ID */
	private int $email_a;

	/** @var int 信件模板 B 的 ID */
	private int $email_b;

	/** @var string 對應 R1 / R3 的 identifier */
	private string $identifier_a = '';

	/** @var string 對應 R2 的 identifier */
	private string $identifier_b = '';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接呼叫 EmailRecord 的靜態方法，不需額外依賴
	}

	/**
	 * 每個測試前植入三筆紀錄
	 *
	 * 三筆的組合是刻意設計的：
	 * - 課程 A 有兩筆（Alice / Bob）→ 單欄位查詢會回多筆
	 * - 課程 B 只有一筆 → 多條件 AND 能收斂
	 * - 「回傳全表」與「正確命中」在筆數上必定不同（3 vs 1 或 2），
	 *   注入成功時斷言一定會紅，不會剛好矇對。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->post_a   = $this->factory()->post->create( [ 'post_title' => '課程A' ] );
		$this->post_b   = $this->factory()->post->create( [ 'post_title' => '課程B' ] );
		$this->email_a  = $this->factory()->post->create( [ 'post_title' => '信件A' ] );
		$this->email_b  = $this->factory()->post->create( [ 'post_title' => '信件B' ] );
		$this->alice_id = $this->factory()->user->create( [ 'user_login' => 'alice_' . uniqid() ] );
		$this->bob_id   = $this->factory()->user->create( [ 'user_login' => 'bob_' . uniqid() ] );

		$this->identifier_a = "email_id:{$this->email_a}|ids:{$this->post_a}";
		$this->identifier_b = "email_id:{$this->email_b}|ids:{$this->post_b}";

		// R1：課程A × Alice × 信件A
		$this->add_record( $this->post_a, $this->alice_id, $this->email_a, '課程開通通知', 'course_granted', $this->identifier_a );
		// R2：課程B × Alice × 信件B
		$this->add_record( $this->post_b, $this->alice_id, $this->email_b, '章節完成通知', 'chapter_finished', $this->identifier_b );
		// R3：課程A × Bob × 信件A
		$this->add_record( $this->post_a, $this->bob_id, $this->email_a, '課程開通通知', 'course_granted', $this->identifier_a );
	}

	// ========== 快樂路徑（Happy）==========

	/**
	 * @test
	 * @group happy
	 * 以合法欄位查詢可正確取得紀錄
	 *
	 * 修復前為什麼會紅：這條在修復前是綠的——它是「改寫成 prepare 之後功能必須完全不變」
	 * 的回歸網。值改走 %s placeholder 後，bigint 欄位變成拿字串字面量去比對
	 * （post_id = '123'），一旦 %s 被誤寫成 %d、或 $where_arr 與 $values 錯位，
	 * 這條是第一個變紅的；沒有它，白名單改壞了也只會靜默查不到東西。
	 */
	public function test_以合法欄位查詢可正確取得紀錄(): void {
		// post_id：課程 A 有兩筆
		$by_post = EmailRecord::get( [ 'post_id' => (string) $this->post_a ] );
		$this->assertCount( 2, $by_post, 'post_id 查詢應回傳課程A 的兩筆紀錄' );
		foreach ( $by_post as $record ) {
			$this->assertSame( (string) $this->post_a, (string) $record->post_id );
		}

		// user_id：Alice 有兩筆
		$by_user = EmailRecord::get( [ 'user_id' => (string) $this->alice_id ] );
		$this->assertCount( 2, $by_user, 'user_id 查詢應回傳 Alice 的兩筆紀錄' );

		// email_id：信件 B 只有一筆
		$by_email = EmailRecord::get( [ 'email_id' => (string) $this->email_b ] );
		$this->assertCount( 1, $by_email, 'email_id 查詢應回傳信件B 的一筆紀錄' );
		$this->assertSame( '章節完成通知', $by_email[0]->email_subject );
		$this->assertSame( 'chapter_finished', $by_email[0]->trigger_at );

		// identifier：identifier_b 只有一筆
		$by_identifier = EmailRecord::get( [ 'identifier' => $this->identifier_b ] );
		$this->assertCount( 1, $by_identifier, 'identifier 查詢應回傳一筆紀錄' );
		$this->assertSame( (string) $this->post_b, (string) $by_identifier[0]->post_id );

		// mark_as_sent：add() 一律寫入 1，所以 1 應回三筆、0 應回零筆
		$sent = EmailRecord::get( [ 'mark_as_sent' => '1' ] );
		$this->assertCount( 3, $sent, 'mark_as_sent=1 應回傳全部三筆' );

		$unsent = EmailRecord::get( [ 'mark_as_sent' => '0' ] );
		$this->assertSame( [], $unsent, 'mark_as_sent=0 應查無紀錄' );
	}

	/**
	 * @test
	 * @group happy
	 * 多條件以 AND 組合（不是 OR，也不是只取第一個條件）
	 *
	 * 修復前為什麼會紅：這條在修復前也是綠的，同樣是回歸保護。
	 * 白名單過濾用的是 continue，最容易寫錯的形狀就是「$where_arr 少一項、
	 * $values 卻沒少」——長度一旦對不上，$wpdb->prepare() 會直接失敗回 null，
	 * 而多條件查詢是唯一能同時踩到「過濾 + 多個 placeholder」的路徑。
	 */
	public function test_多條件以AND組合(): void {
		// 課程A × Bob → 只該命中 R3
		$records = EmailRecord::get(
			[
				'post_id' => (string) $this->post_a,
				'user_id' => (string) $this->bob_id,
			]
		);
		$this->assertCount( 1, $records, '課程A × Bob 應只命中一筆' );
		$this->assertSame( (string) $this->bob_id, (string) $records[0]->user_id );
		$this->assertSame( (string) $this->post_a, (string) $records[0]->post_id );

		// 四條件全中 → R1
		$records = EmailRecord::get(
			[
				'post_id'    => (string) $this->post_a,
				'user_id'    => (string) $this->alice_id,
				'email_id'   => (string) $this->email_a,
				'trigger_at' => 'course_granted',
			]
		);
		$this->assertCount( 1, $records, '四條件 AND 應只命中 R1' );
		$this->assertSame( $this->identifier_a, $records[0]->identifier );

		// 條件互斥（課程B × Bob）→ 應為空，不能因為當成 OR 而回傳任何一筆
		$records = EmailRecord::get(
			[
				'post_id' => (string) $this->post_b,
				'user_id' => (string) $this->bob_id,
			]
		);
		$this->assertSame( [], $records, '互斥條件應回傳空陣列（證明是 AND 不是 OR）' );
	}

	// ========== 安全性（Security）==========

	/**
	 * @test
	 * @group security
	 * 值含單引號不會破壞查詢
	 *
	 * 修復前為什麼會紅："{$key} = '{$value}'" 把值直接內插，
	 * 值裡的單引號會提前關閉字串，整條 WHERE 變成
	 *   identifier = 'email_id:1|ids:1' OR '1'='1'
	 * ——一個恆真條件，回傳整張表（4 筆），assertCount( 1, ... ) 直接紅。
	 */
	public function test_值含單引號不會破壞查詢(): void {
		$evil_identifier = "email_id:1|ids:1' OR '1'='1";

		$this->add_record(
			$this->post_b,
			$this->bob_id,
			$this->email_b,
			'含單引號的紀錄',
			'course_granted',
			$evil_identifier
		);

		$records = EmailRecord::get( [ 'identifier' => $evil_identifier ] );

		$this->assertCount( 1, $records, '含單引號的 identifier 應精確命中一筆，而不是逸出後回傳全表' );
		$this->assertSame( $evil_identifier, $records[0]->identifier, 'identifier 應原樣存回、也原樣查得到' );
		$this->assertSame( '含單引號的紀錄', $records[0]->email_subject );

		// 反向確認：正常的 identifier_a 仍只命中它自己的兩筆，沒被上面那筆污染
		$normal = EmailRecord::get( [ 'identifier' => $this->identifier_a ] );
		$this->assertCount( 2, $normal, '正常 identifier 的查詢結果不應被含引號的紀錄影響' );
	}

	/**
	 * @test
	 * @group security
	 * 值含 SQL 注入 payload 不會刪表也不會回傳全部
	 *
	 * 修復前為什麼會紅：內插後 WHERE 變成
	 *   identifier = 'x' OR 1=1 -- '
	 * `OR 1=1` 生效、`--` 把結尾的引號註解掉 → 回傳全部三筆，
	 * assertSame( [], $records ) 紅。DROP / UNION 的 payload 則會讓 SQL 語法出錯，
	 * $wpdb->get_results() 回 null，同樣紅。
	 */
	public function test_值含SQL注入payload不會刪表也不會回傳全部(): void {
		$table_name = $this->get_table_name();
		$before     = $this->count_rows();
		$this->assertSame( 3, $before, '前置條件：應有三筆紀錄' );

		// payload 1：恆真條件 + 註解掉尾引號
		$records = EmailRecord::get( [ 'identifier' => "x' OR 1=1 -- " ] );
		$this->assertSame( [], $records, 'OR 1=1 payload 應被當成普通字串，查無紀錄' );

		// payload 2：企圖刪表
		$records = EmailRecord::get( [ 'identifier' => "x'; DROP TABLE {$table_name}; -- " ] );
		$this->assertSame( [], $records, 'DROP TABLE payload 應被當成普通字串，查無紀錄' );

		// payload 3：UNION 竊取整表
		$records = EmailRecord::get( [ 'identifier' => "x' UNION SELECT * FROM {$table_name} -- " ] );
		$this->assertSame( [], $records, 'UNION payload 應被當成普通字串，查無紀錄' );

		// COUNT(*) 查得到就代表資料表還在；筆數未變代表沒有任何一筆被刪掉
		$after = $this->count_rows();
		$this->assertSame( $before, $after, '注入嘗試後資料表仍在，且筆數未變' );

		// 原本的資料也還查得到（證明表不是「還在但被清空」）
		$this->assertCount( 3, EmailRecord::get( [ 'mark_as_sent' => '1' ] ), '注入嘗試後原本的紀錄仍查得到' );
	}

	/**
	 * @test
	 * @group security
	 * 不在白名單的欄位會讓查詢整個中止（fail-close）
	 *
	 * 修復前為什麼會紅：欄位名同樣是直接內插，
	 *   evil_column = 'x'
	 * MySQL 回 Unknown column 錯誤 → $wpdb->get_results() 回空 →
	 * 更糟的是欄位名連反引號都沒有，像 `1=1 -- ` 這種 key 會整段變成有效 SQL。
	 *
	 * 為什麼是 fail-close 而不是「忽略該欄位繼續查」：
	 * 靜默丟掉一個 WHERE 條件會讓結果集**變寬**，而本表的主要用途是
	 * Email::is_sent() 的「已寄過就別再寄」閘門 ——
	 * 條件變寬 = 更容易誤判成已寄 = 該寄的信不寄，而且完全無跡可循。
	 * 回空陣列則是往「重複寄」的方向失敗，傷害小得多，而且會留下 WC::log。
	 */
	public function test_不在白名單的欄位會讓查詢中止(): void {
		// 前置：白名單欄位單獨查得到，證明下面的空結果不是因為資料不存在
		$this->assertCount(
			2,
			EmailRecord::get( [ 'post_id' => (string) $this->post_a ] ),
			'前置條件：合法欄位應查得到兩筆'
		);

		// 白名單欄位 + 非白名單欄位混用 → 整個查詢中止，不退化成「少一個條件」
		$records = EmailRecord::get(
			[
				'post_id'     => (string) $this->post_a,
				'evil_column' => 'x',
			]
		);
		$this->assertSame( [], $records, '混入非白名單欄位時應整個中止，不得靜默放寬 WHERE' );

		// 欄位名本身就是 SQL 片段時，同樣中止
		$records = EmailRecord::get(
			[
				'post_id'                => (string) $this->post_a,
				'1=1 OR post_id'         => 'x',
				'id`, (SELECT 1) `x'     => 'x',
				'user_id = 0 OR user_id' => 'x',
			]
		);
		$this->assertSame( [], $records, '帶 SQL 片段的欄位名應讓查詢中止' );

		// 全部都不在白名單 → 回空陣列（不能退化成「沒有 WHERE 的全表查詢」）
		$this->assertSame( [], EmailRecord::get( [ 'evil_column' => 'x' ] ), '不得退化成全表查詢' );

		// 資料沒有被破壞
		$this->assertSame( 3, $this->count_rows(), '被拒絕的查詢不應影響資料' );
	}

	// ========== 邊界（Edge）==========

	/**
	 * @test
	 * @group edge
	 * 空 where 回傳空陣列
	 *
	 * 修復前為什麼會紅：$where_arr 是空的，implode 出空字串，
	 * SQL 變成 "SELECT * FROM wp_pc_email_records WHERE " → 語法錯誤，
	 * $wpdb->get_results() 回 null，assertSame( [], null ) 紅。
	 */
	public function test_空where回傳空陣列(): void {
		$this->assertSame( [], EmailRecord::get( [] ), '空 where 應回傳空陣列，而不是語法錯誤或全表' );

		// 前置資料還在，證明上面不是因為表被清空才回空
		$this->assertSame( 3, $this->count_rows(), '空 where 查詢不應影響資料' );
	}

	/**
	 * @test
	 * @group edge
	 * 白名單涵蓋資料表所有欄位
	 *
	 * 修復前為什麼會紅：修復前沒有 $allowed_columns，這條會直接 fatal
	 * （存取不存在的靜態屬性）。修復後它守的是未來——以後 AbstractTable 加了新欄位
	 * 卻忘了同步白名單，用該欄位查詢會被 continue 靜默忽略，
	 * 也就是「查詢條件憑空消失 → 回傳全表」，這是最難察覺的失效方式。
	 */
	public function test_白名單涵蓋資料表所有欄位(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		// 用 SHOW COLUMNS 而不是 SHOW TABLES：WP 測試套件把自訂表建成 TEMPORARY table，
		// SHOW TABLES 看不到 TEMPORARY table，SHOW COLUMNS 則正常。
		$db_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`", 0 ); // phpcs:ignore

		$this->assertNotEmpty( $db_columns, 'SHOW COLUMNS 應取得欄位清單（資料表必須存在）' );

		$db_columns = array_values( array_map( 'strval', (array) $db_columns ) );
		$whitelist  = array_values( EmailRecord::$allowed_columns );

		sort( $db_columns );
		sort( $whitelist );

		$this->assertSame(
			$db_columns,
			$whitelist,
			sprintf(
				"CRUD::\$allowed_columns 與 %s 的實際欄位不一致。\n實際欄位：%s\n白名單：%s\n（新增欄位後請同步更新白名單，否則以該欄位查詢會被靜默忽略）",
				$table_name,
				implode( ', ', $db_columns ),
				implode( ', ', $whitelist )
			)
		);
	}

	// ========== 私有 Helper ==========

	/**
	 * 植入一筆寄信紀錄
	 *
	 * 一律用 $unique = false，讓 add() 走純 insert：
	 * $unique = true 時 add() 內部會先呼叫 get()（也就是被測目標），
	 * 前置資料就會被被測程式的正確性影響，測試會失去獨立性。
	 *
	 * @param int    $post_id       課程/章節 ID
	 * @param int    $user_id       使用者 ID
	 * @param int    $email_id      信件 ID
	 * @param string $email_subject 信件主題
	 * @param string $trigger_at    觸發時機
	 * @param string $identifier    唯一識別字串
	 */
	private function add_record( int $post_id, int $user_id, int $email_id, string $email_subject, string $trigger_at, string $identifier ): void {
		$result = EmailRecord::add(
			$post_id,
			$user_id,
			$email_id,
			$email_subject,
			$trigger_at,
			$identifier,
			false
		);

		$this->assertNotFalse( $result, "植入 EmailRecord 失敗：post_id={$post_id}, user_id={$user_id}" );
	}

	/**
	 * 取得完整資料表名稱（含前綴）
	 *
	 * @return string
	 */
	private function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::EMAIL_RECORDS_TABLE_NAME;
	}

	/**
	 * 直接以 SQL 數資料表筆數（刻意不經過被測的 get()）
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;
		$table_name = $this->get_table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ); // phpcs:ignore
	}
}

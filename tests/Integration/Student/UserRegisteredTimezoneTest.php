<?php
/**
 * 學員「註冊於 / user_registered」時區轉換 整合測試
 * Feature: specs/features/student/學員註冊時間時區轉換.feature
 * Issue:   #233
 *
 * 驗收重點：
 *   - 6 個輸出點（列表 API / Query::get / ExportCSV / ExportAllCSV / MCP List / MCP ExportCsv）
 *     的 user_registered 一律以 WordPress 設定時區（Asia/Taipei, +8）輸出，而非 DB 的 UTC 原值。
 *   - 格式維持 Y-m-d H:i:s（不套站台日期/時間格式）。
 *   - 空字串 / 0000-00-00 原樣保留，不得變 1970 epoch。
 *   - 列表 API 後處理僅 +8（不得 double-shift 變 +16）。
 *
 * Background（每個 test 前）：
 *   - WordPress 時區設定為 Asia/Taipei（gmt_offset = 8）。
 *   - Alice：user_registered = 2026-05-17 13:07:33（UTC）→ 站台 2026-05-17 21:07:33。
 *   - Bob：  user_registered = 2026-05-17 18:30:00（UTC）→ 站台 2026-05-18 02:30:00（跨日）。
 *   - 課程 100（_is_course=yes, publish），Alice / Bob 已開通（expire 0）。
 *
 * @group student
 * @group timezone
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\Datetime;
use J7\PowerCourse\Resources\Student\Core\Api;
use J7\PowerCourse\Resources\Student\Service\Query;
use J7\PowerCourse\Resources\Student\Service\ExportCSV;
use J7\PowerCourse\Resources\Student\Service\ExportAllCSV;
use J7\PowerCourse\Api\Mcp\Tools\Student\StudentListTool;
use J7\PowerCourse\Api\Mcp\Tools\Student\StudentExportCsvTool;

/**
 * Class UserRegisteredTimezoneTest
 */
class UserRegisteredTimezoneTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** Alice 的站台時區註冊時間（同日） */
	private const ALICE_UTC  = '2026-05-17 13:07:33';
	private const ALICE_SITE = '2026-05-17 21:07:33';

	/** Bob 的站台時區註冊時間（跨日） */
	private const BOB_UTC  = '2026-05-17 18:30:00';
	private const BOB_SITE = '2026-05-18 02:30:00';

	/** 此測試直接呼叫 Api / Query / Service / MCP Tool，不需額外依賴注入 */
	protected function configure_dependencies(): void {}

	/**
	 * 每個測試前建立 Background fixture
	 */
	public function set_up(): void {
		parent::set_up();

		// WordPress 時區設定為 Asia/Taipei（gmt_offset = 8）。
		// get_date_from_gmt() 讀 wp_timezone()（option），與 PHP date.timezone 無關，故測試穩定。
		\update_option( 'timezone_string', 'Asia/Taipei' );
		\update_option( 'gmt_offset', '' );

		// 建立課程 100（以實際產生的 ID 為準）。
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		// 建立 Alice（同日時段）與 Bob（跨日時段），並把 user_registered 覆寫為指定 UTC 原值。
		$this->ids['Alice'] = $this->create_user_with_registered( 'Alice', self::ALICE_UTC );
		$this->ids['Bob']   = $this->create_user_with_registered( 'Bob', self::BOB_UTC );

		// Alice / Bob 開通課程（永久）。
		$this->enroll_user_to_course( $this->ids['Alice'], $this->course_id );
		$this->enroll_user_to_course( $this->ids['Bob'], $this->course_id );

		// 建立並登入管理員（MCP run() 權限檢查需要）。
		$this->ids['Admin'] = $this->factory()->user->create(
			[
				'user_login' => 'admin_' . uniqid(),
				'user_email' => 'admin_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->ids['Admin'] );
	}

	/**
	 * 建立用戶並把 user_registered 覆寫為指定 UTC 原值（模擬 WP 核心以 UTC 寫入）。
	 *
	 * @param string $name        display_name。
	 * @param string $registered  欲寫入的 UTC 原值（Y-m-d H:i:s）。
	 * @return int 用戶 ID。
	 */
	private function create_user_with_registered( string $name, string $registered ): int {
		global $wpdb;
		$user_id = $this->factory()->user->create(
			[
				'user_login'   => strtolower( $name ) . '_' . uniqid(),
				'user_email'   => strtolower( $name ) . '_' . uniqid() . '@test.com',
				'display_name' => $name,
				'role'         => 'subscriber',
			]
		);
		// 直寫 wp_users.user_registered 為指定 UTC 值（factory 預設寫入當下時間，需覆寫）。
		$wpdb->update( $wpdb->users, [ 'user_registered' => $registered ], [ 'ID' => $user_id ] );
		\clean_user_cache( $user_id );
		return $user_id;
	}

	/**
	 * Reflection helper：讀取物件的 protected/private 屬性。
	 *
	 * @param object $obj      目標物件。
	 * @param string $property 屬性名稱。
	 * @return mixed
	 */
	private function get_protected_property( object $obj, string $property ): mixed {
		$ref  = new \ReflectionClass( $obj );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		return $prop->getValue( $obj );
	}

	/**
	 * Reflection helper：呼叫物件的 protected/private 方法。
	 *
	 * @param object              $obj    目標物件。
	 * @param string              $method 方法名稱。
	 * @param array<int, mixed>   $args   參數。
	 * @return mixed
	 */
	private function call_protected_method( object $obj, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	/**
	 * 從一組 row（array 或 object）中找出指定 user_id 的 user_registered 值。
	 *
	 * @param iterable<mixed> $rows    資料列。
	 * @param int             $user_id 目標用戶 ID。
	 * @return string|null
	 */
	private function find_user_registered( iterable $rows, int $user_id ): ?string {
		foreach ( $rows as $row ) {
			$arr = (array) $row;
			$id  = (int) ( $arr['id'] ?? $arr['user_id'] ?? 0 );
			if ( $id === $user_id ) {
				return isset( $arr['user_registered'] ) ? (string) $arr['user_registered'] : null;
			}
		}
		return null;
	}

	// ========== T1-T5：helper 行為 ==========

	/**
	 * T1 同日時段 UTC 13:07:33 → 台灣時間 21:07:33。
	 *
	 * @test
	 */
	public function test_helper_同日時段轉台灣時間(): void {
		$this->assertSame( self::ALICE_SITE, Datetime::to_site_timezone( self::ALICE_UTC ) );
	}

	/**
	 * T2 跨日時段 UTC 18:30:00 → 台灣時間隔日 02:30:00。
	 *
	 * @test
	 */
	public function test_helper_跨日時段轉隔日(): void {
		$this->assertSame( self::BOB_SITE, Datetime::to_site_timezone( self::BOB_UTC ) );
	}

	/**
	 * T3 即使後台日期/時間格式被改，輸出仍維持 Y-m-d H:i:s。
	 *
	 * @test
	 */
	public function test_helper_格式不套站台日期格式(): void {
		\update_option( 'date_format', 'F j, Y' );
		\update_option( 'time_format', 'g:i a' );
		$this->assertSame( self::ALICE_SITE, Datetime::to_site_timezone( self::ALICE_UTC ) );
	}

	/**
	 * T4 空字串原樣回傳，不得變 1970 epoch。
	 *
	 * @test
	 */
	public function test_helper_空字串原樣回傳(): void {
		$this->assertSame( '', Datetime::to_site_timezone( '' ) );
		$this->assertNotSame( '1970-01-01 08:00:00', Datetime::to_site_timezone( '' ) );
		$this->assertSame( '', Datetime::to_site_timezone( null ) );
	}

	/**
	 * T5 0000-00-00 全零日期原樣保留，不得變 1970 epoch。
	 *
	 * @test
	 */
	public function test_helper_全零日期原樣保留(): void {
		$result = Datetime::to_site_timezone( '0000-00-00 00:00:00' );
		$this->assertNotSame( '1970-01-01 08:00:00', $result );
		$this->assertSame( '0000-00-00 00:00:00', $result );
	}

	// ========== T6-T7：學員列表 API（get_students_callback）==========

	/**
	 * T6 列表 API 回應 user_registered 為站台時區（Alice 同日、Bob 跨日）。
	 *
	 * @test
	 */
	public function test_列表API_user_registered_為站台時區(): void {
		$request = new \WP_REST_Request( 'GET', '/power-course/v2/students' );
		$params  = [
			'meta_value'     => (string) $this->course_id,
			'posts_per_page' => 20,
			'paged'          => 1,
		];
		$request->set_query_params( $params );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}

		$response = Api::instance()->get_students_callback( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( self::ALICE_SITE, $this->find_user_registered( $data, $this->ids['Alice'] ) );
		$this->assertSame( self::BOB_SITE, $this->find_user_registered( $data, $this->ids['Bob'] ) );
	}

	/**
	 * T7 double-shift 護欄：列表後處理對 Alice 僅 +8（21:07:33），不得 +16（05:07:33）。
	 *
	 * @test
	 */
	public function test_列表API_不得double_shift(): void {
		$request = new \WP_REST_Request( 'GET', '/power-course/v2/students' );
		$params  = [
			'meta_value'     => (string) $this->course_id,
			'posts_per_page' => 20,
			'paged'          => 1,
		];
		$request->set_query_params( $params );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}

		$response = Api::instance()->get_students_callback( $request );
		$alice    = $this->find_user_registered( $response->get_data(), $this->ids['Alice'] );

		$this->assertSame( self::ALICE_SITE, $alice );
		$this->assertNotSame( '2026-05-18 05:07:33', $alice );
	}

	// ========== T8：單一學員 Query::get（餵 MCP StudentGetTool）==========

	/**
	 * T8 Query::get 的 user_registered 為站台時區。
	 *
	 * @test
	 */
	public function test_單一學員Query_get_為站台時區(): void {
		$result = Query::get( $this->ids['Alice'] );
		$this->assertIsArray( $result );
		$this->assertSame( self::ALICE_SITE, (string) $result['user_registered'] );
	}

	// ========== T9：單一課程匯出 CSV ==========

	/**
	 * T9 ExportCSV rows 的 user_registered 為站台時區。
	 *
	 * @test
	 */
	public function test_單一課程匯出CSV_為站台時區(): void {
		$export = new ExportCSV( $this->course_id );
		$rows   = $this->get_protected_property( $export, 'rows' );

		$this->assertSame( self::ALICE_SITE, $this->find_user_registered( $rows, $this->ids['Alice'] ) );
		$this->assertSame( self::BOB_SITE, $this->find_user_registered( $rows, $this->ids['Bob'] ) );

		// Q3=A：欄位標頭維持原樣（不額外加註時區）。
		$columns = $this->get_protected_property( $export, 'columns' );
		$this->assertSame( 'Student registration date', $columns['user_registered'] );
	}

	// ========== T10：全域匯出學員 CSV ==========

	/**
	 * T10 ExportAllCSV rows 的 user_registered 為站台時區。
	 *
	 * @test
	 */
	public function test_全域匯出CSV_為站台時區(): void {
		$export = new ExportAllCSV( '', [ (string) $this->course_id ] );
		$rows   = $this->get_protected_property( $export, 'rows' );

		$this->assertSame( self::ALICE_SITE, $this->find_user_registered( $rows, $this->ids['Alice'] ) );
	}

	// ========== T11：MCP StudentListTool ==========

	/**
	 * T11 MCP StudentListTool 輸出 user_registered 為站台時區。
	 *
	 * @test
	 */
	public function test_MCP_StudentListTool_為站台時區(): void {
		$tool   = new StudentListTool();
		$result = $this->call_protected_method( $tool, 'execute', [ [ 'course_id' => $this->course_id ] ] );

		$this->assertIsArray( $result );
		$this->assertSame(
			self::ALICE_SITE,
			$this->find_user_registered( $result['students'], $this->ids['Alice'] )
		);
	}

	// ========== T12：MCP StudentExportCsvTool ==========

	/**
	 * T12 MCP StudentExportCsvTool 收集的資料列 user_registered 為站台時區。
	 *
	 * @test
	 */
	public function test_MCP_StudentExportCsvTool_為站台時區(): void {
		$tool = new StudentExportCsvTool();
		$rows = $this->call_protected_method( $tool, 'collect_rows', [ $this->course_id ] );

		$this->assertSame( self::ALICE_SITE, $this->find_user_registered( $rows, $this->ids['Alice'] ) );
	}
}

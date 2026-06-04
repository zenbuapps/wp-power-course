<?php
/**
 * 學員匯出 CSV — 權限把關 + 欄位擴充 整合測試（TDD Red Phase）
 *
 * Feature: specs/issue-238-student-edit/student-edit-fixes.feature
 *   Rule: 匯出學員 CSV 功能（B7 / F8 / Q6）
 * API Spec: specs/issue-238-student-edit/api.yml
 *
 * 對應 Issue #238：
 * - B7 / Q6：三個 export 端點補上 manage_options 權限把關
 *   （新增靜態方法 J7\PowerCourse\Resources\Student\Core\Api::check_manage_options_permission）
 * - F8 / Q5=B：ExportAllCSV / ExportCSV 的 $columns 與 row 物件補上 billing_* + shipping_* 欄位
 *
 * @group student
 * @group export
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Student\Core\Api as StudentApi;
use J7\PowerCourse\Resources\Student\Service\ExportAllCSV;
use J7\PowerCourse\Resources\Student\Service\ExportCSV;

/**
 * Class StudentExportPermissionTest
 */
class StudentExportPermissionTest extends TestCase {

	/** @var int 課程 ID */
	private int $course_id;

	/** @var int 已選課學員（附 billing/shipping meta） */
	private int $student_id;

	/** 期望的帳單欄位 key（F8） */
	private const EXPECTED_BILLING_KEYS = [
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'billing_phone',
		'billing_company',
		'billing_country',
		'billing_state',
		'billing_city',
		'billing_postcode',
		'billing_address_1',
		'billing_address_2',
	];

	/** 期望的運送欄位 key（F8 / Q5=B） */
	private const EXPECTED_SHIPPING_KEYS = [
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_country',
		'shipping_state',
		'shipping_city',
		'shipping_postcode',
		'shipping_address_1',
		'shipping_address_2',
	];

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {}

	/**
	 * 每個測試前建立課程 + 已選課學員
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 課程' ] );

		$this->student_id = $this->factory()->user->create(
			[
				'user_login'   => 'exp_stu_' . uniqid(),
				'user_email'   => 'exp_stu_' . uniqid() . '@test.com',
				'display_name' => '王小明',
				'first_name'   => '小明',
				'last_name'    => '王',
				'role'         => 'customer',
			]
		);

		// 帳單 / 運送 meta（供 F8 值驗證）
		update_user_meta( $this->student_id, 'billing_first_name', 'BFN' );
		update_user_meta( $this->student_id, 'billing_last_name', 'BLN' );
		update_user_meta( $this->student_id, 'billing_email', 'billing@test.com' );
		update_user_meta( $this->student_id, 'billing_phone', '0912345678' );
		update_user_meta( $this->student_id, 'billing_company', 'ACME' );
		update_user_meta( $this->student_id, 'billing_country', 'TW' );
		update_user_meta( $this->student_id, 'billing_state', 'TPE' );
		update_user_meta( $this->student_id, 'billing_city', '台北市' );
		update_user_meta( $this->student_id, 'billing_postcode', '100' );
		update_user_meta( $this->student_id, 'billing_address_1', '帳單路 1 號' );
		update_user_meta( $this->student_id, 'billing_address_2', '帳單路 2 號' );
		update_user_meta( $this->student_id, 'shipping_first_name', 'SFN' );
		update_user_meta( $this->student_id, 'shipping_last_name', 'SLN' );
		update_user_meta( $this->student_id, 'shipping_company', 'SHIP CO' );
		update_user_meta( $this->student_id, 'shipping_country', 'TW' );
		update_user_meta( $this->student_id, 'shipping_state', 'NWT' );
		update_user_meta( $this->student_id, 'shipping_city', '新北市' );
		update_user_meta( $this->student_id, 'shipping_postcode', '220' );
		update_user_meta( $this->student_id, 'shipping_address_1', '運送路 1 號' );
		update_user_meta( $this->student_id, 'shipping_address_2', '運送路 2 號' );

		$this->enroll_user_to_course( $this->student_id, $this->course_id, 0 );
	}

	// ========== B7 / Q6：匯出權限守門 check_manage_options_permission ==========

	/**
	 * @test
	 * @group security
	 * Rule: 未登入呼叫匯出端點權限檢查 → WP_Error 401 rest_not_logged_in
	 */
	public function test_export_permission_denies_logged_out_user(): void {
		wp_set_current_user( 0 );

		$result = StudentApi::check_manage_options_permission();

		$this->assertInstanceOf( \WP_Error::class, $result, '未登入應回傳 WP_Error' );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code(), 'error code 應為 rest_not_logged_in' );
		$error_data = $result->get_error_data();
		$this->assertSame( 401, $error_data['status'], 'HTTP status 應為 401' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 僅具 edit_users（無 manage_options）的協作管理員 → WP_Error 403 rest_forbidden
	 */
	public function test_export_permission_denies_non_admin_with_edit_users(): void {
		$editor_id = $this->factory()->user->create( [ 'role' => 'editor' ] );
		$editor     = get_userdata( $editor_id );
		$editor->add_cap( 'edit_users' ); // 具 edit_users 但無 manage_options
		wp_set_current_user( $editor_id );

		$this->assertFalse( current_user_can( 'manage_options' ), '前置條件：editor 不應具 manage_options' );

		$result = StudentApi::check_manage_options_permission();

		$this->assertInstanceOf( \WP_Error::class, $result, '非管理員應回傳 WP_Error' );
		$this->assertSame( 'rest_forbidden', $result->get_error_code(), 'error code 應為 rest_forbidden' );
		$error_data = $result->get_error_data();
		$this->assertSame( 403, $error_data['status'], 'HTTP status 應為 403' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: administrator（具 manage_options）→ 回傳 true
	 */
	public function test_export_permission_grants_administrator(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$result = StudentApi::check_manage_options_permission();

		$this->assertTrue( $result, 'administrator 應通過權限檢查（回傳 true）' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 三個 export 端點的 permission_callback 皆已設為 check_manage_options_permission（非 null）
	 */
	public function test_export_endpoints_register_manage_options_permission(): void {
		$api   = StudentApi::instance();
		$ref   = new \ReflectionClass( $api );
		$prop  = $ref->getProperty( 'apis' );
		$prop->setAccessible( true );
		/** @var array<array{endpoint:string, method:string, permission_callback:mixed}> $apis */
		$apis = $prop->getValue( $api );

		$export_endpoints = [
			'students/export/(?P<id>\d+)',
			'students/export-all',
			'students/export-count',
		];

		foreach ( $export_endpoints as $endpoint ) {
			$found = null;
			foreach ( $apis as $api_def ) {
				if ( $api_def['endpoint'] === $endpoint ) {
					$found = $api_def;
					break;
				}
			}
			$this->assertNotNull( $found, "應存在端點 {$endpoint}" );
			$this->assertNotNull(
				$found['permission_callback'],
				"端點 {$endpoint} 的 permission_callback 不應為 null（須鎖 manage_options）"
			);
			$this->assertSame(
				[ StudentApi::class, 'check_manage_options_permission' ],
				$found['permission_callback'],
				"端點 {$endpoint} 的 permission_callback 應為 check_manage_options_permission"
			);
		}
	}

	// ========== F8：ExportAllCSV 欄位與值 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: ExportAllCSV 的 $columns 在既有 12 欄外，包含全部 billing_* + shipping_* 欄位
	 */
	public function test_export_all_columns_include_billing_and_shipping(): void {
		$export  = new ExportAllCSV( '', [ (string) $this->course_id ], [] );
		$columns = $this->get_protected( $export, 'columns' );

		$this->assertIsArray( $columns, '$columns 應為陣列' );

		// 既有欄位仍保留
		foreach ( [ 'user_id', 'last_name', 'first_name', 'display_name', 'user_email', 'course_name', 'subscription_id' ] as $existing ) {
			$this->assertArrayHasKey( $existing, $columns, "應保留既有欄位 {$existing}" );
		}

		// 新增 billing/shipping 欄位
		foreach ( array_merge( self::EXPECTED_BILLING_KEYS, self::EXPECTED_SHIPPING_KEYS ) as $key ) {
			$this->assertArrayHasKey( $key, $columns, sprintf( "columns 應包含 %s", $key ) );
			$this->assertNotSame( '', (string) $columns[ $key ], "{$key} 的欄位標頭不應為空（須為已翻譯文字）" );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: ExportAllCSV 產出的 row 物件 billing_* / shipping_* 值取自對應 user_meta
	 */
	public function test_export_all_rows_carry_billing_and_shipping_values(): void {
		$export = new ExportAllCSV( '', [ (string) $this->course_id ], [] );
		$rows   = $this->get_protected( $export, 'rows' );

		$this->assertIsArray( $rows, '$rows 應為陣列' );
		$this->assertNotEmpty( $rows, '應至少有一列（已選課學員）' );

		$row = null;
		foreach ( $rows as $candidate ) {
			if ( (int) $candidate->user_id === $this->student_id ) {
				$row = $candidate;
				break;
			}
		}
		$this->assertNotNull( $row, '應找到目標學員的匯出列' );

		$this->assertSame( 'BFN', $row->billing_first_name, 'billing_first_name 應取自 user_meta' );
		$this->assertSame( '0912345678', $row->billing_phone, 'billing_phone 應取自 user_meta' );
		$this->assertSame( '帳單路 1 號', $row->billing_address_1, 'billing_address_1 應取自 user_meta' );
		$this->assertSame( 'SFN', $row->shipping_first_name, 'shipping_first_name 應取自 user_meta' );
		$this->assertSame( '運送路 1 號', $row->shipping_address_1, 'shipping_address_1 應取自 user_meta' );
	}

	// ========== F8：ExportCSV（單一課程）欄位與值 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: ExportCSV（單一課程）的 $columns 同樣包含 billing_* + shipping_* 欄位
	 */
	public function test_export_single_course_columns_include_billing_and_shipping(): void {
		$export  = new ExportCSV( $this->course_id );
		$columns = $this->get_protected( $export, 'columns' );

		$this->assertIsArray( $columns, '$columns 應為陣列' );
		foreach ( array_merge( self::EXPECTED_BILLING_KEYS, self::EXPECTED_SHIPPING_KEYS ) as $key ) {
			$this->assertArrayHasKey( $key, $columns, sprintf( "columns 應包含 %s", $key ) );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: ExportCSV（單一課程）row 物件 billing_* / shipping_* 值取自對應 user_meta
	 */
	public function test_export_single_course_rows_carry_billing_and_shipping_values(): void {
		$export = new ExportCSV( $this->course_id );
		$rows   = $this->get_protected( $export, 'rows' );

		$this->assertIsArray( $rows, '$rows 應為陣列' );
		$this->assertNotEmpty( $rows, '應至少有一列（已選課學員）' );

		$row = null;
		foreach ( $rows as $candidate ) {
			if ( (int) $candidate->user_id === $this->student_id ) {
				$row = $candidate;
				break;
			}
		}
		$this->assertNotNull( $row, '應找到目標學員的匯出列' );

		$this->assertSame( 'BLN', $row->billing_last_name, 'billing_last_name 應取自 user_meta' );
		$this->assertSame( '新北市', $row->shipping_city, 'shipping_city 應取自 user_meta' );
	}

	/**
	 * 透過 Reflection 讀取 protected 屬性
	 *
	 * @param object $obj  物件實例
	 * @param string $name 屬性名稱
	 * @return mixed
	 */
	private function get_protected( object $obj, string $name ): mixed {
		$ref  = new \ReflectionClass( $obj );
		$prop = $ref->getProperty( $name );
		$prop->setAccessible( true );
		return $prop->getValue( $obj );
	}
}

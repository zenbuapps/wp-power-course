<?php
/**
 * 講師顯示名稱 整合測試
 * Issue: #264 - 講師名稱不應沿用學員的帳單姓名 fallback
 *
 * @group teacher
 * @group issue-264
 */

declare( strict_types=1 );

namespace Tests\Integration\Teacher;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\User;
use J7\PowerCourse\Plugin;

/**
 * Class TeacherDisplayNameTest
 *
 * Issue #264 的病灶：Issue #54 為了讓管理者能把學員對上金流後台，替學員設計了
 * `formatted_name` 的 fallback chain（billing → WP meta → display_name）。
 * 但講師列表、講師選擇器、前台課程頁的講師區塊也讀同一個欄位，
 * 於是「改講師的公開名稱」會被帳單姓名蓋掉，站長被迫連帶改帳單姓名才能改講師名。
 *
 * 修法：講師情境改用 `get_teacher_display_name()`（純 display_name），
 * 學員情境的 `get_formatted_name()` 一個字都不動。
 *
 * 本測試檔的每一支都刻意讓「帳單姓名」與「公開顯示名稱」不同 ——
 * 兩者相同時整個 bug 不會顯形，測試也就驗不到東西。
 */
class TeacherDisplayNameTest extends TestCase {

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 本測試只用靜態方法與模板，不需要額外 Service 實例
	}

	/**
	 * 建立一個「帳單姓名 ≠ 公開顯示名稱」的使用者
	 *
	 * @param string $display_name  公開顯示名稱。
	 * @param string $billing_last  帳單姓。
	 * @param string $billing_first 帳單名。
	 * @return int 使用者 ID
	 */
	private function create_user_with_divergent_names(
		string $display_name = '王老師',
		string $billing_last = '王',
		string $billing_first = '大明'
	): int {
		$user_id = $this->factory()->user->create(
			[
				'user_login'   => 'teacher_' . uniqid(),
				'display_name' => $display_name,
			]
		);

		\update_user_meta( $user_id, 'billing_last_name', $billing_last );
		\update_user_meta( $user_id, 'billing_first_name', $billing_first );

		return $user_id;
	}

	// ========== 核心行為 ==========

	/**
	 * @test
	 * @group happy
	 * 有帳單姓名時，講師名稱仍取公開顯示名稱
	 */
	public function test_講師名稱取display_name而非帳單姓名(): void {
		$user_id = $this->create_user_with_divergent_names( '王老師', '王', '大明' );

		$this->assertSame(
			'王老師',
			User::get_teacher_display_name( $user_id ),
			'講師名稱必須是 WP 公開顯示名稱，不得被 billing 姓名蓋掉'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 改了 display_name 後，講師名稱立即跟著變（驗收條件 1）
	 */
	public function test_更新display_name後講師名稱立即更新(): void {
		$user_id = $this->create_user_with_divergent_names( '舊講師名', '王', '大明' );

		\wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => '新講師名',
			]
		);
		\clean_user_cache( $user_id );

		$this->assertSame(
			'新講師名',
			User::get_teacher_display_name( $user_id ),
			'改完 display_name 應立即反映，不需要連帶改帳單姓名'
		);
	}

	/**
	 * @test
	 * @group security
	 * 取講師名稱不得寫入或改動任何 billing meta（驗收條件 2）
	 */
	public function test_取講師名稱不會動到帳單資料(): void {
		$user_id = $this->create_user_with_divergent_names( '王老師', '王', '大明' );

		$before_last  = \get_user_meta( $user_id, 'billing_last_name', true );
		$before_first = \get_user_meta( $user_id, 'billing_first_name', true );

		User::get_teacher_display_name( $user_id );

		$this->assertSame( $before_last, \get_user_meta( $user_id, 'billing_last_name', true ), 'billing_last_name 不得被改動' );
		$this->assertSame( $before_first, \get_user_meta( $user_id, 'billing_first_name', true ), 'billing_first_name 不得被改動' );
		$this->assertSame( '王', \get_user_meta( $user_id, 'billing_last_name', true ) );
		$this->assertSame( '大明', \get_user_meta( $user_id, 'billing_first_name', true ) );
	}

	// ========== 學員情境不回歸 ==========

	/**
	 * @test
	 * @group happy
	 * 學員的 formatted_name 仍維持 Issue #54 的 billing 優先（驗收條件 3）
	 */
	public function test_學員的formatted_name仍走billing優先(): void {
		$user_id = $this->create_user_with_divergent_names( '王老師', '王', '大明' );

		$this->assertSame(
			'王大明',
			User::get_formatted_name( $user_id ),
			'學員情境必須維持 Issue #54 的 fallback chain，不得被 #264 波及'
		);
	}

	/**
	 * @test
	 * @group edge
	 * 同一人同時是講師與學員時，兩種情境各自顯示正確名稱（驗收條件 4）
	 */
	public function test_同一人身兼講師與學員時兩種名稱互不干擾(): void {
		$user_id = $this->create_user_with_divergent_names( '王老師', '王', '大明' );
		\update_user_meta( $user_id, 'is_teacher', 'yes' );

		$teacher_name = User::get_teacher_display_name( $user_id );
		$student_name = User::get_formatted_name( $user_id );

		$this->assertSame( '王老師', $teacher_name, '講師介面顯示公開名稱' );
		$this->assertSame( '王大明', $student_name, '學員介面顯示帳單姓名' );
		$this->assertNotSame(
			$teacher_name,
			$student_name,
			'兩個情境必須能得到不同的名字 —— 相同就代表又被綁在一起了'
		);
	}

	// ========== 邊界 ==========

	/**
	 * @test
	 * @group edge
	 * 使用者不存在時回空字串，不得拋錯
	 */
	public function test_用戶不存在時回空字串(): void {
		$this->assertSame( '', User::get_teacher_display_name( 99999999 ) );
	}

	/**
	 * @test
	 * @group edge
	 * 沒有任何 billing 資料時，講師名稱一樣是 display_name
	 */
	public function test_無帳單資料時仍取display_name(): void {
		$user_id = $this->factory()->user->create(
			[
				'user_login'   => 'teacher_nobilling_' . uniqid(),
				'display_name' => '李老師',
			]
		);

		$this->assertSame( '李老師', User::get_teacher_display_name( $user_id ) );
	}

	// ========== 前台模板（端對端） ==========

	/**
	 * @test
	 * @group happy
	 * 課程銷售頁「關於講師」區塊輸出 display_name，不是帳單姓名
	 */
	public function test_前台關於講師模板輸出display_name(): void {
		$user_id = $this->create_user_with_divergent_names( '王老師', '王', '大明' );
		$user    = \get_user_by( 'ID', $user_id );

		$html = Plugin::load_template( 'user/about', [ 'user' => $user ], false );

		$this->assertIsString( $html );
		$this->assertStringContainsString( '王老師', $html, '講師區塊應顯示公開顯示名稱' );
		$this->assertStringNotContainsString( '王大明', $html, '講師區塊不得洩漏 WooCommerce 帳單姓名' );
	}

	/**
	 * @test
	 * @group happy
	 * 課程銷售頁頂部的講師標籤輸出 display_name，不是帳單姓名
	 */
	public function test_前台講師標籤模板輸出display_name(): void {
		$user_id = $this->create_user_with_divergent_names( '陳老師', '陳', '小華' );
		$user    = \get_user_by( 'ID', $user_id );

		$html = Plugin::load_template( 'user', [ 'user' => $user ], false );

		$this->assertIsString( $html );
		$this->assertStringContainsString( '陳老師', $html, '講師標籤應顯示公開顯示名稱' );
		$this->assertStringNotContainsString( '陳小華', $html, '講師標籤不得洩漏 WooCommerce 帳單姓名' );
	}
}

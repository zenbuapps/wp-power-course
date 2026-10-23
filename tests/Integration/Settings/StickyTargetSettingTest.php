<?php
/**
 * 行動版吸附對象設定整合測試（Issue #278）
 *
 * 外觀設定「行動版影片與分頁吸附」新增子選項 fix_video_and_tabs_mobile_target：
 * - both（預設，維持既有行為：影片與分頁都吸附）
 * - tabs_only（只吸附分頁）
 * - video_only（只吸附影片）
 *
 * 驗證：
 * - 既有站台（option 沒有此欄位）預設為 both
 * - REST POST /power-course/settings 可儲存三種合法值，GET 讀得回來
 * - 不合法的值一律回到 both（白名單）
 * - 前台 pc_data 帶出此設定，option 內殘留不合法值時也輸出 both
 *
 * @group settings
 * @group sticky
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Settings\Core\Api;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\Templates\Ajax;
use Tests\Integration\TestCase;

/**
 * Class StickyTargetSettingTest
 */
class StickyTargetSettingTest extends TestCase {

	/** @var mixed 測試前的設定 option，tear_down 還原 */
	private mixed $original_option;

	/** @var \WP_Scripts|null 測試前的 $GLOBALS['wp_scripts']，get_localized_pc_data() 會換掉它，tear_down 還原 */
	private ?\WP_Scripts $original_wp_scripts = null;

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 直接使用 Settings Model / Api callback
	}

	/** 每個測試前備份設定、清掉 Settings 單例 */
	public function set_up(): void {
		parent::set_up();

		$this->original_option     = \get_option( Settings::SETTINGS_OPTION_NAME );
		$this->original_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$this->reset_settings_instance();

		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/** 每個測試後還原設定 */
	public function tear_down(): void {
		if ( false === $this->original_option ) {
			\delete_option( Settings::SETTINGS_OPTION_NAME );
		} else {
			\update_option( Settings::SETTINGS_OPTION_NAME, $this->original_option );
		}
		$this->reset_settings_instance();
		$GLOBALS['wp_scripts'] = $this->original_wp_scripts;

		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 既有站台的 option 沒有此欄位時，預設為 both（維持舊行為）
	 */
	public function test_既有站台未設定時預設為both(): void {
		\update_option(
			Settings::SETTINGS_OPTION_NAME,
			[
				'fix_video_and_tabs_mobile' => 'yes',
				'pc_header_offset'          => '64',
			]
		);
		$this->reset_settings_instance();

		$settings = Settings::instance();

		$this->assertSame( 'both', $settings->fix_video_and_tabs_mobile_target );
		$this->assertSame( 'both', $settings->to_array()['fix_video_and_tabs_mobile_target'] ?? null, 'GET /settings 應回傳此欄位' );
	}

	// ========== 快樂路徑（Happy）==========

	/**
	 * @test
	 * @group happy
	 * @dataProvider valid_target_provider
	 * REST 儲存三種合法值後，重新讀取仍是同一個值
	 *
	 * @param string $target 吸附對象
	 */
	public function test_REST可儲存合法的吸附對象( string $target ): void {
		$response = $this->post_settings(
			[
				'fix_video_and_tabs_mobile'        => 'yes',
				'fix_video_and_tabs_mobile_target' => $target,
			]
		);
		$this->assertSame( 200, $response->get_status() );

		$this->reset_settings_instance();
		$this->assertSame( $target, $this->get_settings_data()['fix_video_and_tabs_mobile_target'] ?? null );
	}

	/**
	 * 合法的吸附對象
	 *
	 * @return array<string, array{0: string}>
	 */
	public function valid_target_provider(): array {
		return [
			'both'       => [ 'both' ],
			'tabs_only'  => [ 'tabs_only' ],
			'video_only' => [ 'video_only' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 * 只更新吸附對象，不影響主開關與偏移距離
	 */
	public function test_只更新吸附對象不影響其他外觀設定(): void {
		$this->post_settings(
			[
				'fix_video_and_tabs_mobile' => 'yes',
				'pc_header_offset'          => '48',
			]
		);
		$this->reset_settings_instance();

		$this->post_settings( [ 'fix_video_and_tabs_mobile_target' => 'tabs_only' ] );
		$this->reset_settings_instance();

		$data = $this->get_settings_data();
		$this->assertSame( 'tabs_only', $data['fix_video_and_tabs_mobile_target'] ?? null );
		$this->assertSame( 'yes', $data['fix_video_and_tabs_mobile'] ?? null );
		$this->assertSame( '48', $data['pc_header_offset'] ?? null );
	}

	/**
	 * @test
	 * @group happy
	 * 前台 pc_data 帶出吸附對象
	 */
	public function test_前台pc_data帶出吸附對象(): void {
		\update_option(
			Settings::SETTINGS_OPTION_NAME,
			[
				'fix_video_and_tabs_mobile'        => 'yes',
				'fix_video_and_tabs_mobile_target' => 'tabs_only',
			]
		);
		$this->reset_settings_instance();

		$pc_data = $this->get_localized_pc_data();

		$this->assertSame( 'yes', $pc_data['fix_video_and_tabs_mobile'] ?? null );
		$this->assertSame( 'tabs_only', $pc_data['fix_video_and_tabs_mobile_target'] ?? null );
	}

	// ========== 錯誤處理 / 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * @dataProvider invalid_target_provider
	 * 不在白名單內的值一律回到 both
	 *
	 * @param mixed $target 不合法的吸附對象
	 */
	public function test_不合法的吸附對象回到both( mixed $target ): void {
		// 先存一個非預設值，才分得出「被正規化成 both」與「沒存進去」
		$this->post_settings( [ 'fix_video_and_tabs_mobile_target' => 'tabs_only' ] );
		$this->reset_settings_instance();

		$this->post_settings( [ 'fix_video_and_tabs_mobile_target' => $target ] );
		$this->reset_settings_instance();

		$this->assertSame( 'both', $this->get_settings_data()['fix_video_and_tabs_mobile_target'] ?? null );
	}

	/**
	 * 不合法的吸附對象
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_target_provider(): array {
		return [
			'未知字串'   => [ 'header_only' ],
			'大小寫不符' => [ 'TABS_ONLY' ],
			'空字串'    => [ '' ],
			'陣列'     => [ [ 'tabs_only' ] ],
			'數字'     => [ 1 ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * option 內殘留不合法值（例如手動改 DB）時，前台 pc_data 仍輸出 both
	 */
	public function test_option殘留不合法值時前台輸出both(): void {
		\update_option(
			Settings::SETTINGS_OPTION_NAME,
			[
				'fix_video_and_tabs_mobile'        => 'yes',
				'fix_video_and_tabs_mobile_target' => '<script>',
			]
		);
		$this->reset_settings_instance();

		$pc_data = $this->get_localized_pc_data();

		$this->assertSame( 'both', $pc_data['fix_video_and_tabs_mobile_target'] ?? null );
	}

	// ========== 輔助方法 ==========

	/**
	 * 打 POST /power-course/settings（直呼 callback）
	 *
	 * @param array<string, mixed> $body 設定
	 * @return \WP_REST_Response
	 */
	private function post_settings( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-course/settings' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $body ) );

		return Api::instance()->post_settings_callback( $request );
	}

	/**
	 * 打 GET /power-course/settings（直呼 callback），回傳 data
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings_data(): array {
		$request  = new \WP_REST_Request( 'GET', '/power-course/settings' );
		$response = Api::instance()->get_settings_callback( $request );
		$body     = $response->get_data();

		$this->assertIsArray( $body );
		$this->assertIsArray( $body['data'] ?? null );

		return $body['data'];
	}

	/**
	 * 執行前台 enqueue，解析 wp_localize_script 輸出的 pc_data
	 *
	 * @return array<string, mixed>
	 */
	private function get_localized_pc_data(): array {
		// 換一個乾淨的 WP_Scripts，避免前一支測試 localize 的資料疊加
		$GLOBALS['wp_scripts'] = null;
		Ajax::wp_enqueue_scripts();

		$script = (string) \wp_scripts()->get_data( Plugin::$kebab . '-template', 'data' );
		$this->assertMatchesRegularExpression( '/var pc_data = (\{.*\});/', $script, '前台 script 應 localize pc_data' );

		preg_match( '/var pc_data = (\{.*\});/', $script, $matches );
		$pc_data = json_decode( $matches[1], true );
		$this->assertIsArray( $pc_data );

		return $pc_data;
	}

	/**
	 * 清掉 Settings 的 process 級單例快取（理由見 PurchaseGrantAccessPassTest::reset_settings_instance）
	 *
	 * @return void
	 */
	private function reset_settings_instance(): void {
		$property = new \ReflectionProperty( Settings::class, 'dto_instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}

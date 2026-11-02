<?php
/**
 * 無法解析的 Email 內容：檢查與修復（Issue #290）
 *
 * @group email
 * @group json-content
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Mcp\Tools\Email\EmailCreateTool;
use J7\PowerCourse\Api\Mcp\Tools\Email\EmailUpdateTool;
use J7\PowerCourse\PowerEmail\Resources\Email\Api as EmailApi;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;
use J7\PowerCourse\PowerEmail\Resources\Email\JsonRepair;

/**
 * Class EmailJsonRepairTest
 *
 * @group json-content
 */
class EmailJsonRepairTest extends TestCase {

	/** @var string Issue 回報的損壞樣式 */
	private const BROKEN = '{"type":"page","children":[{"data":{"value":{"content":"<div style="text-align: right">署名</div><br /><span style="word-spacing: normal">內文</span>"}}}]}';

	/** @var string 正確跳脫後應有的樣子 */
	private const REPAIRED = '{"type":"page","children":[{"data":{"value":{"content":"<div style=\"text-align: right\">署名</div><br /><span style=\"word-spacing: normal\">內文</span>"}}}]}';

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/** 以管理員身分執行 */
	public function set_up(): void {
		parent::set_up();
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * 建立內容已損壞的模板（直寫 DB，繞過所有 filter）
	 *
	 * @param string $excerpt post_excerpt
	 * @param string $status  post_status
	 * @return int
	 */
	private function create_email( string $excerpt, string $status = 'publish' ): int {
		global $wpdb;
		$id = $this->factory()->post->create(
			[
				'post_title'   => '模板_' . uniqid(),
				'post_type'    => CPT::POST_TYPE,
				'post_status'  => $status,
				'post_content' => '<p>寄信用 HTML</p>',
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $excerpt ], [ 'ID' => $id ] );
		\clean_post_cache( $id );
		return $id;
	}

	/**
	 * @param int $id 模板 ID
	 * @return string DB 內的原始位元組
	 */
	private function db_excerpt( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	}

	/**
	 * @param int $id 模板 ID
	 * @return array<string, mixed> 掃描結果中對應此模板的項目
	 */
	private function scan_item( int $id ): array {
		foreach ( JsonRepair::scan() as $item ) {
			if ( (string) $id === $item['id'] ) {
				return $item;
			}
		}
		$this->fail( "掃描結果沒有模板 {$id}" );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 只補回標籤屬性的跳脫，得到的內容與正確版本逐 byte 相同
	 */
	public function test_propose_只補必要跳脫(): void {
		$proposal = JsonRepair::propose( self::BROKEN );

		$this->assertNotNull( $proposal );
		$this->assertSame( self::REPAIRED, $proposal['json'] );
		$this->assertCount( 2, $proposal['changes'] );
		$this->assertSame( '<div style="text-align: right">', $proposal['changes'][0]['before'] );
		$this->assertSame( '<div style=\"text-align: right\">', $proposal['changes'][0]['after'] );
	}

	/**
	 * @return array<string, array{0: string}> 不應自動修復的內容
	 */
	public static function unrepairable_provider(): array {
		return [
			'標籤外的未跳脫引號'  => [ '{"content":"他說 "你好" 然後離開"}' ],
			'標籤修好但正文仍壞'  => [ '{"content":"<div style="color: red">他說 "你好"</div>"}' ],
			'缺少結尾括號'     => [ '{"content":"<div style="color: red">a</div>"' ],
			'不是 JSON'     => [ '這不是 JSON' ],
			'屬性值含大於符號'   => [ '{"content":"<div title="a>b">c</div>"}' ],
		];
	}

	/**
	 * @test
	 * @dataProvider unrepairable_provider
	 * @group error
	 * Rule: 無法確認的損壞一律不修，也不能動到資料
	 *
	 * @param string $raw 內容
	 */
	public function test_無法確認的損壞不修復( string $raw ): void {
		$id = $this->create_email( $raw );

		$item = $this->scan_item( $id );
		$this->assertSame( JsonRepair::STATE_UNREPAIRABLE, $item['state'] );
		$this->assertSame( [], $item['changes'] );

		$result = JsonRepair::repair( $id, $item['fingerprint'] );

		$this->assertWPError( $result );
		$this->assertSame( 'email_content_unrepairable', $result->get_error_code() );
		$this->assertSame( $raw, $this->db_excerpt( $id ) );
		$this->assertSame( [], \get_post_meta( $id, JsonRepair::BACKUP_META_KEY ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 掃描列出已發佈與草稿的損壞模板；合法、空白、雙重編碼的都不列；掃描不改資料
	 */
	public function test_scan_只列出無法解析的模板且不改資料(): void {
		$broken_publish = $this->create_email( self::BROKEN );
		$broken_draft   = $this->create_email( self::BROKEN, 'draft' );
		$valid          = $this->create_email( self::REPAIRED );
		$empty          = $this->create_email( '' );
		$double_encoded = $this->create_email( (string) \wp_json_encode( self::REPAIRED ) );

		$ids = \array_column( JsonRepair::scan(), 'id' );

		$this->assertContains( (string) $broken_publish, $ids );
		$this->assertContains( (string) $broken_draft, $ids );
		$this->assertNotContains( (string) $valid, $ids );
		$this->assertNotContains( (string) $empty, $ids );
		$this->assertNotContains( (string) $double_encoded, $ids );
		$this->assertSame( self::BROKEN, $this->db_excerpt( $broken_publish ) );
		$this->assertSame( [], \get_post_meta( $broken_publish, JsonRepair::BACKUP_META_KEY ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 修復只動 post_excerpt，備份原始內容；正文、修改日期、meta 與狀態都不變
	 */
	public function test_repair_只修內容並備份原始資料(): void {
		$id = $this->create_email( self::BROKEN );
		\update_post_meta( $id, 'trigger_at', 'course_granted' );
		$before = \get_post( $id );

		$result = JsonRepair::repair( $id, $this->scan_item( $id )['fingerprint'] );

		$this->assertTrue( $result );
		$this->assertSame( self::REPAIRED, $this->db_excerpt( $id ) );

		$after = \get_post( $id );
		$this->assertSame( self::REPAIRED, $after->post_excerpt, 'object cache 必須同步更新' );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified, $after->post_modified );
		$this->assertSame( $before->post_status, $after->post_status );
		$this->assertSame( 'course_granted', \get_post_meta( $id, 'trigger_at', true ) );

		$backups = \get_post_meta( $id, JsonRepair::BACKUP_META_KEY );
		$this->assertCount( 1, $backups );
		$this->assertSame( self::BROKEN, $backups[0]['post_excerpt'], '備份必須與修復前逐 byte 相同' );
		$this->assertSame( \get_current_user_id(), $backups[0]['repaired_by'] );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 預覽後內容有變動，修復必須拒絕，避免修到管理者沒看過的版本
	 */
	public function test_repair_指紋不符時拒絕(): void {
		$id = $this->create_email( self::BROKEN );

		$result = JsonRepair::repair( $id, 'stale-fingerprint' );

		$this->assertWPError( $result );
		$this->assertSame( 'email_content_changed', $result->get_error_code() );
		$this->assertSame( self::BROKEN, $this->db_excerpt( $id ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 已經合法的內容不修復、也不留備份
	 */
	public function test_repair_合法內容不動作(): void {
		$id = $this->create_email( self::REPAIRED );

		$result = JsonRepair::repair( $id, \hash( 'sha256', self::REPAIRED ) );

		$this->assertWPError( $result );
		$this->assertSame( 'email_content_valid', $result->get_error_code() );
		$this->assertSame( [], \get_post_meta( $id, JsonRepair::BACKUP_META_KEY ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 不存在或不是 Email 的 ID 回 404，不得碰到其他文章
	 */
	public function test_repair_非Email文章回404(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'post' ] );

		$result = JsonRepair::repair( $post_id, \hash( 'sha256', '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'email_not_found', $result->get_error_code() );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 指紋比對之後內容才被別人改掉時，修復不得覆寫新內容，也不留下多餘的備份
	 */
	public function test_repair_比對後內容被改動時不覆寫(): void {
		global $wpdb;
		$id          = $this->create_email( self::BROKEN );
		$fingerprint = $this->scan_item( $id )['fingerprint'];
		$newer       = '{"content":"別人剛存的新內容"}';

		// 備份寫入是比對與更新之間的時間點，在這裡模擬另一個請求搶先存檔
		$race = static function () use ( $wpdb, $id, $newer ): void {
			$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $newer ], [ 'ID' => $id ] );
		};
		\add_action( 'added_post_meta', $race );
		$result = JsonRepair::repair( $id, $fingerprint );
		\remove_action( 'added_post_meta', $race );

		$this->assertWPError( $result );
		$this->assertSame( 'email_content_changed', $result->get_error_code() );
		$this->assertSame( $newer, $this->db_excerpt( $id ) );
		$this->assertSame( [], \get_post_meta( $id, JsonRepair::BACKUP_META_KEY ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 只有真正的 HTML 屬性才補跳脫。看起來像標籤、實際跨越 JSON 字串邊界的內容不得修改
	 */
	public function test_propose_不修改跨越字串邊界的內容(): void {
		$this->assertNull( JsonRepair::propose( '{"a":"<b x","d":"x=" ," y>"}' ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 複製範本不帶走修復前的備份：複本是新資料，不該留著別封信的損壞原文
	 */
	public function test_複製範本不複製修復備份(): void {
		$id = $this->create_email( self::BROKEN );
		JsonRepair::repair( $id, $this->scan_item( $id )['fingerprint'] );
		$this->assertCount( 1, \get_post_meta( $id, JsonRepair::BACKUP_META_KEY ) );

		$new_id = \J7\PowerCourse\Utils\Duplicate::duplicate_post( $id );

		$this->assertSame( [], \get_post_meta( $new_id, JsonRepair::BACKUP_META_KEY ) );
		$this->assertSame( self::REPAIRED, \get_post( $new_id )->post_excerpt );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 修復後走正式更新流程儲存，內容與設定仍保留（編輯 → 儲存 → 重開）
	 */
	public function test_修復後可再次正常儲存(): void {
		$id = $this->create_email( self::BROKEN );
		JsonRepair::repair( $id, $this->scan_item( $id )['fingerprint'] );

		$request = new \WP_REST_Request( 'POST', '/power-email/emails/' . $id );
		$request->set_url_params( [ 'id' => (string) $id ] );
		$request->set_body_params( [ 'short_description' => $this->db_excerpt( $id ) ] );
		$response = EmailApi::instance()->post_emails_with_id_callback( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( self::REPAIRED, $this->db_excerpt( $id ) );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 走真實 REST 路由：一般使用者不可檢查或修復，資料不變
	 */
	public function test_REST端點拒絕沒有權限的使用者(): void {
		$id = $this->create_email( self::BROKEN );
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$health = \rest_do_request( new \WP_REST_Request( 'GET', '/power-email/emails/json-health' ) );
		$repair = new \WP_REST_Request( 'POST', "/power-email/emails/{$id}/repair-json" );
		$repair->set_param( 'fingerprint', \hash( 'sha256', self::BROKEN ) );

		$this->assertSame( 403, $health->get_status() );
		$this->assertSame( 403, \rest_do_request( $repair )->get_status() );
		$this->assertSame( self::BROKEN, $this->db_excerpt( $id ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 走真實 REST 路由：管理員先預覽、再帶 fingerprint 確認修復
	 */
	public function test_REST端點預覽後確認修復(): void {
		$id = $this->create_email( self::BROKEN );

		$health = \rest_do_request( new \WP_REST_Request( 'GET', '/power-email/emails/json-health' ) );
		$this->assertSame( 200, $health->get_status() );

		$items = \array_values( \array_filter( $health->get_data(), static fn( array $item ): bool => (string) $id === $item['id'] ) );
		$this->assertCount( 1, $items );
		$this->assertSame( JsonRepair::STATE_REPAIRABLE, $items[0]['state'] );

		$repair = new \WP_REST_Request( 'POST', "/power-email/emails/{$id}/repair-json" );
		$repair->set_param( 'fingerprint', $items[0]['fingerprint'] );
		$response = \rest_do_request( $repair );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::REPAIRED, $this->db_excerpt( $id ) );

		// 同一份預覽不能再用第二次
		$this->assertSame( 409, \rest_do_request( $repair )->get_status() );
	}

	/**
	 * 直接呼叫 MCP tool 的 execute（略過後台開關，只驗內容防線）
	 *
	 * @param object               $tool MCP tool
	 * @param array<string, mixed> $args 參數
	 * @return mixed
	 */
	private function execute_tool( object $tool, array $args ): mixed {
		$method = new \ReflectionMethod( $tool, 'execute' );
		$method->setAccessible( true );
		return $method->invoke( $tool, $args );
	}

	/**
	 * @test
	 * @group error
	 * Rule: MCP 更新入口與 REST 同一道防線：非法 JSON 拒存，DB 不變
	 */
	public function test_MCP更新拒絕非法JSON(): void {
		$id = $this->create_email( self::REPAIRED );

		$result = $this->execute_tool(
			new EmailUpdateTool(),
			[
				'id'                => $id,
				'short_description' => self::BROKEN,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_json_content', $result->get_error_code() );
		$this->assertSame( self::REPAIRED, $this->db_excerpt( $id ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: MCP 建立入口與 REST 同一道防線：非法 JSON 不建立模板
	 */
	public function test_MCP建立拒絕非法JSON(): void {
		$result = $this->execute_tool(
			new EmailCreateTool(),
			[
				'trigger_at'        => 'course_granted',
				'short_description' => self::BROKEN,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_json_content', $result->get_error_code() );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: MCP 寫入合法 JSON 維持逐 byte 不變
	 */
	public function test_MCP更新保留合法JSON(): void {
		$id = $this->create_email( '' );

		$this->execute_tool(
			new EmailUpdateTool(),
			[
				'id'                => $id,
				'short_description' => self::REPAIRED,
			]
		);

		$this->assertSame( self::REPAIRED, $this->db_excerpt( $id ) );
	}
}

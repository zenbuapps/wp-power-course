<?php
/**
 * 章節內容跳脫字元儲存整合測試
 *
 * Feature: JSON parse error 根治（JSON_PARSE_ERROR.md）
 * 測試 ChapterCrud create / update 對 post_content（Power 編輯器 HTML）與 meta 的寫入對稱性：
 * - 含反斜線的內容儲存後 byte 不變（原 bug：wp_update_post 內部 wp_unslash 每存一次咬掉一層）
 * - 重複儲存不劣化
 *
 * @group chapter
 * @group json-content
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;

/**
 * Class ChapterContentSlashTest
 *
 * @group json-content
 */
class ChapterContentSlashTest extends TestCase {

	/** @var string 含各種跳脫字元的編輯器 HTML（程式課程常見：程式碼區塊含反斜線） */
	private string $html_with_escapes = '<div class="power-editor"><pre><code>printf("a\\nb"); $path = "C:\\\\temp";</code></pre><p>引號 "測試" 與反斜線 \\ 混排</p></div>';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/**
	 * 每個測試前以管理員身分執行
	 */
	public function set_up(): void {
		parent::set_up();
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/** @return string 直接從 DB 讀 post_content（避開任何 cache / filter） */
	private function get_db_content( int $post_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: create——含反斜線的 post_content 建立後 byte 不變
	 */
	public function test_create含反斜線內容byte不變(): void {
		$chapter_id = ChapterCrud::create(
			[
				'post_title'   => '反斜線章節_' . uniqid(),
				'post_content' => $this->html_with_escapes,
			],
			[ 'chapter_video' => [ 'type' => 'youtube', 'id' => 'abc\\def' ] ]
		);

		$this->assertGreaterThan( 0, $chapter_id );
		$this->assertSame(
			$this->html_with_escapes,
			$this->get_db_content( $chapter_id ),
			'create 後 post_content 必須與傳入內容完全一致'
		);

		$video = \get_post_meta( $chapter_id, 'chapter_video', true );
		$this->assertSame( 'abc\\def', $video['id'] ?? null, 'meta 內字串的反斜線不得遺失' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: update——含反斜線的 post_content 更新後 byte 不變
	 */
	public function test_update含反斜線內容byte不變(): void {
		$chapter_id = ChapterCrud::create( [ 'post_title' => '章節_' . uniqid() ] );

		ChapterCrud::update( $chapter_id, [ 'post_content' => $this->html_with_escapes ] );

		$this->assertSame(
			$this->html_with_escapes,
			$this->get_db_content( $chapter_id ),
			'update 後 post_content 必須與傳入內容完全一致'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 刁鑽字串 round-trip——各種容易觸發 slash / 編碼問題的 HTML 內容，
	 * update 後 byte 不變
	 */
	public function test_刁鑽HTML內容round_trip不變(): void {
		$torture_cases = [
			'windows_path' => '<pre><code>cd C:\\Users\\test && echo "done"</code></pre>',
			'trailing_bs'  => '<p>ends with backslash \\</p>',
			'literal_bs_n' => '<p>literal \\n and \\t in text</p>',
			'quotes_mix'   => '<p>single \' double " backtick ` mixed</p>',
			'crlf'         => "<p>line1\r\nline2</p>",
			'emoji'        => '<p>🎉🚀 中文 emoji ✅ 👨‍👩‍👧‍👦</p>',
			'shortcode'    => '<p>[gallery ids="1,2"] 100% {display_name} %s</p>',
			'data_attr'    => '<div data-content-type="video" data-v-id="a\\b" data-title="he said &quot;hi&quot;">x</div>',
			'sql_danger'   => "<p>'; DROP TABLE wp_posts; -- \\' OR 1=1</p>",
		];

		$chapter_id = ChapterCrud::create( [ 'post_title' => '刁鑽章節_' . uniqid() ] );

		foreach ( $torture_cases as $label => $html ) {
			ChapterCrud::update( $chapter_id, [ 'post_content' => $html ] );

			$this->assertSame(
				$html,
				$this->get_db_content( $chapter_id ),
				"[{$label}] update 後 post_content 必須與傳入內容完全一致"
			);
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 重複儲存不劣化——同一份內容存三次，byte 仍不變（原 bug：每存一次掉一層反斜線）
	 */
	public function test_重複儲存不劣化(): void {
		$chapter_id = ChapterCrud::create( [ 'post_title' => '章節_' . uniqid() ] );

		for ( $i = 0; $i < 3; $i++ ) {
			$current = 0 === $i ? $this->html_with_escapes : $this->get_db_content( $chapter_id );
			ChapterCrud::update( $chapter_id, [ 'post_content' => $current ] );
		}

		$this->assertSame(
			$this->html_with_escapes,
			$this->get_db_content( $chapter_id ),
			'重複儲存三次後內容不得遺失任何字元'
		);
	}
}

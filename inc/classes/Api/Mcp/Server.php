<?php
/**
 * MCP Server — 整合並啟動 power-course-mcp MCP Server
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

/**
 * Class Server
 * 負責掛載 mcp_adapter_init hook，建立並設定 power-course-mcp MCP Server
 * 整合 Settings（category 啟用判斷）、AbstractTool（各 tool 能力名稱）
 */
final class Server {

	/** MCP Server 識別符（Q3 決策：只做這一個 server） */
	const SERVER_ID = 'power-course-mcp';

	/** REST API namespace */
	const ROUTE_NAMESPACE = 'power-course/v2';

	/** REST route */
	const ROUTE = 'mcp';

	/**
	 * 所有 Power Course MCP 工具的 category 定義
	 * slug => [ label, description ]
	 *
	 * Slug 必須符合 Abilities API 的 category regex `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`
	 * ——**不接受底線**（Issue #259）。新增 category 時一律用 dash 分隔。
	 *
	 * @var array<string, array{string, string}>
	 */
	const CATEGORIES = [
		'course'         => [ 'Course', 'Course CRUD and management tools' ],
		'chapter'        => [ 'Chapter', 'Chapter/unit hierarchy and content tools' ],
		'student'        => [ 'Student', 'Student enrollment and progress tracking tools' ],
		'teacher'        => [ 'Teacher', 'Instructor management and assignment tools' ],
		'bundle'         => [ 'Bundle', 'Bundle/sales plan product tools' ],
		'order'          => [ 'Order', 'WooCommerce order integration tools' ],
		'progress'       => [ 'Progress', 'Student progress and completion tools' ],
		'comment'        => [ 'Comment', 'Chapter comments and reviews tools' ],
		'report'         => [ 'Report', 'Analytics and reporting tools' ],
		'subtitle'       => [ 'Subtitle', 'Subtitle (caption track) management tools for chapter and course videos' ],
		'announcement'   => [ 'Announcement', 'Course announcement management tools' ],
		'contact-remark' => [ 'Contact Remark', 'Student contact remark (manual contact note) tools' ],
		'student-log'    => [ 'Student Log', 'Student activity log query and audit tools' ],
		'email'          => [ 'Email', 'Email template management and manual/scheduled sending tools' ],
	];

	/**
	 * Constructor
	 * 掛載 abilities categories/init 與 mcp_adapter_init hook
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		add_action( 'mcp_adapter_init', [ $this, 'bootstrap' ] );

		// Bearer Token 認證：讓外部 MCP client 可透過 Token 存取 REST API
		new BearerAuth();
	}

	/**
	 * 註冊所有 Power Course 的 ability categories
	 * 在 wp_abilities_api_categories_init hook 中被呼叫
	 *
	 * Slug 一律過 AbstractTool::normalize_category_slug()（底線轉 dash），與
	 * AbstractTool::get_category_slug() 使用同一套規則，避免註冊端與比對端分岔（Issue #259）。
	 *
	 * @return void
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::CATEGORIES as $slug => [ $label, $description ] ) {
			wp_register_ability_category(
				AbstractTool::normalize_category_slug( (string) $slug ),
				[
					'label'       => $label,
					'description' => $description,
				]
			);
		}
	}

	/**
	 * 在 Abilities API 初始化時，逐一註冊所有 tool 的 ability
	 * 在 wp_abilities_api_init hook 中被呼叫
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$settings = new Settings();
		$all      = $this->get_all_tool_classes();

		foreach ( $all as $tool_class ) {
			if ( ! class_exists( $tool_class ) ) {
				continue;
			}

			/** @var AbstractTool $tool */
			$tool = new $tool_class();

			if ( $settings->is_category_enabled( $tool->get_category() ) ) {
				$tool->register();
			}
		}
	}

	/**
	 * Bootstrap：建立 MCP Server
	 * 在 mcp_adapter_init hook 中被呼叫
	 *
	 * 參數**刻意不加型別宣告**（Issue: PYS 前綴 adapter 造成 REST API 全站 500）。
	 *
	 * WordPress 的 hook 名稱是**全域字串**，不帶 namespace。第三方外掛（例如
	 * PixelYourSite Pro）用 php-scoper 把 mcp-adapter 打包成
	 * `PYS_PRO_GLOBAL\WP\MCP\Core\McpAdapter` 時，php-scoper 只改「類別名稱」，
	 * **不會改 `do_action( 'mcp_adapter_init', $this )` 裡的字串字面量**，
	 * 於是那份前綴副本仍然會用同一個 hook 名稱、把「別人家的 adapter 物件」丟給我們。
	 *
	 * 若這裡宣告 `McpAdapter $adapter`，PHP 會在**綁定參數的當下**就丟 TypeError
	 * ——method body 一行都跑不到，任何 class_exists() 防呆都來不及。而
	 * `mcp_adapter_init` 是在 `rest_api_init` 觸發的，等於**整站 REST API 全部 500**
	 * （含 /wp-json/power-course/courses）。故改為無型別 + instanceof 守門。
	 *
	 * @param mixed $adapter MCP Adapter 實例（可能是其他外掛的前綴副本，需自行驗型）
	 * @return void
	 */
	public function bootstrap( $adapter ): void {
		// 只認我們自己 vendor 的 McpAdapter；別人家的前綴副本（或非物件）一律略過
		if ( ! class_exists( McpAdapter::class ) || ! ( $adapter instanceof McpAdapter ) ) {
			return;
		}

		// Abilities API 未載入時（如 WP < 6.9），graceful 降級
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}

		/*
		* MCP Server 未啟用就不建立 server（Issue #259）。
		*
		* `pc_mcp_settings` 的 default 是 enabled=false，但這裡原本沒有把關，
		* 導致「從未開啟過 MCP 的站」（option 根本不存在）照樣建立 server、
		* 把全部 ability 名稱丟給 mcp-adapter 逐一查詢，任何註冊失敗都會變成
		* 每次 WP 載入寫一行 ERROR log。設定說「關閉」就真的不要啟動。
		*/
		if ( ! ( new Settings() )->is_server_enabled() ) {
			return;
		}

		$enabled_tools = $this->filter_registered_abilities( $this->get_enabled_tools() );

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Power Course MCP Server',
			'Provides MCP tools for Power Course LMS management',
			'1.0.0',
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$enabled_tools,   // tools（ability 名稱陣列）
			[],               // resources
			[]                // prompts
		);
	}

	/**
	 * 取得已啟用的 tool ability 名稱陣列
	 * 依 Settings 中的 enabled_categories 過濾
	 *
	 * @return string[] ability 名稱清單
	 */
	public function get_enabled_tools(): array {
		$settings = new Settings();
		$all      = $this->get_all_tool_classes();
		$enabled  = [];

		foreach ( $all as $tool_class ) {
			if ( ! class_exists( $tool_class ) ) {
				continue;
			}

			/** @var AbstractTool $tool */
			$tool = new $tool_class();

			if ( $settings->is_category_enabled( $tool->get_category() ) ) {
				$enabled[] = $tool->get_ability_name();
			}
		}

		return $enabled;
	}

	/**
	 * 過濾掉「設定上啟用、但實際沒註冊成功」的 ability（Issue #259 防線）
	 *
	 * 上游的 get_enabled_tools() 只依「hard-coded tool class 清單 × 設定開關」推導名稱，
	 * 從不確認 ability 真的存在於 WP_Abilities_Registry。只要有任何一支註冊失敗
	 * （category 非法、schema 有誤、被 filter 擋下…），mcp-adapter 就會在
	 * **每一次** WP 載入時對每支查不到的 ability 寫一行 ERROR log。
	 *
	 * 有了這道防線，未來任何註冊失敗最多只是「少一支 tool」，不會演變成 log 洗版。
	 * WP_DEBUG 開啟時仍會寫一行彙總，讓開發者看得到問題、正式站則保持安靜。
	 *
	 * @param string[] $ability_names 待檢查的 ability 名稱清單。
	 * @return string[] 實際已註冊的 ability 名稱清單。
	 */
	private function filter_registered_abilities( array $ability_names ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $ability_names;
		}

		$registered = [];
		$missing    = [];

		foreach ( $ability_names as $name ) {
			if ( null === wp_get_ability( $name ) ) {
				$missing[] = $name;
				continue;
			}
			$registered[] = $name;
		}

		if ( $missing && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 僅 WP_DEBUG 下輸出，供開發者診斷註冊失敗
				sprintf(
					'[power-course][MCP] %1$d 支 ability 未註冊成功，已從 MCP server 排除：%2$s',
					count( $missing ),
					implode( ', ', $missing )
				)
			);
		}

		return $registered;
	}

	/**
	 * 取得所有可用的 tool class 列表（hard-coded，Phase 2 逐漸填充）
	 * Phase 1 時此陣列為空，tool class 由 Phase 2 的各領域 agent 新增
	 *
	 * @return array<string> FQCN class 名稱陣列
	 */
	public function get_all_tool_classes(): array {
		/**
		 * Phase 2 各領域 tool class 註冊表
		 * 依領域排序，方便維護
		 *
		 * @var array<class-string<AbstractTool>> $default
		 */
		$default = [
			// ---------- Wave 1: Course (6) ----------
			Tools\Course\CourseListTool::class,
			Tools\Course\CourseGetTool::class,
			Tools\Course\CourseCreateTool::class,
			Tools\Course\CourseUpdateTool::class,
			Tools\Course\CourseDeleteTool::class,
			Tools\Course\CourseDuplicateTool::class,

			// ---------- Wave 1: Chapter (7) ----------
			Tools\Chapter\ChapterListTool::class,
			Tools\Chapter\ChapterGetTool::class,
			Tools\Chapter\ChapterCreateTool::class,
			Tools\Chapter\ChapterUpdateTool::class,
			Tools\Chapter\ChapterDeleteTool::class,
			Tools\Chapter\ChapterSortTool::class,
			Tools\Chapter\ChapterToggleFinishTool::class,

			// ---------- Wave 1: Comment (3) ----------
			Tools\Comment\CommentListTool::class,
			Tools\Comment\CommentCreateTool::class,
			Tools\Comment\CommentToggleApprovedTool::class,

			// ---------- Wave 2: Student (9) ----------
			Tools\Student\StudentListTool::class,
			Tools\Student\StudentGetTool::class,
			Tools\Student\StudentExportCsvTool::class,
			Tools\Student\StudentExportCountTool::class,
			Tools\Student\StudentAddToCourseTool::class,
			Tools\Student\StudentRemoveFromCourseTool::class,
			Tools\Student\StudentGetProgressTool::class,
			Tools\Student\StudentUpdateMetaTool::class,
			Tools\Student\StudentGetLogTool::class,

			// ---------- Wave 2: Bundle (4) ----------
			Tools\Bundle\BundleListTool::class,
			Tools\Bundle\BundleGetTool::class,
			Tools\Bundle\BundleSetProductsTool::class,
			Tools\Bundle\BundleDeleteProductsTool::class,

			// ---------- Wave 2: Teacher (4) ----------
			Tools\Teacher\TeacherListTool::class,
			Tools\Teacher\TeacherGetTool::class,
			Tools\Teacher\TeacherAssignToCourseTool::class,
			Tools\Teacher\TeacherRemoveFromCourseTool::class,

			// ---------- Wave 3: Order (3, HPOS-aware) ----------
			Tools\Order\OrderListTool::class,
			Tools\Order\OrderGetTool::class,
			Tools\Order\OrderGrantCoursesTool::class,

			// ---------- Wave 3: Progress (3) ----------
			Tools\Progress\ProgressGetByUserCourseTool::class,
			Tools\Progress\ProgressMarkChapterFinishedTool::class,
			Tools\Progress\ProgressResetTool::class,

			// ---------- Wave 3: Report (2) ----------
			Tools\Report\ReportRevenueStatsTool::class,
			Tools\Report\ReportStudentCountTool::class,

			// ---------- Subtitle (3) ----------
			Tools\Subtitle\SubtitleListTool::class,
			Tools\Subtitle\SubtitleUploadTool::class,
			Tools\Subtitle\SubtitleDeleteTool::class,

			// ---------- Announcement (5) ----------
			Tools\Announcement\AnnouncementListTool::class,
			Tools\Announcement\AnnouncementGetTool::class,
			Tools\Announcement\AnnouncementCreateTool::class,
			Tools\Announcement\AnnouncementUpdateTool::class,
			Tools\Announcement\AnnouncementDeleteTool::class,

			// ---------- ContactRemark (3) ----------
			Tools\ContactRemark\ContactRemarkListTool::class,
			Tools\ContactRemark\ContactRemarkCreateTool::class,
			Tools\ContactRemark\ContactRemarkDeleteTool::class,

			// ---------- StudentLog (2) ----------
			Tools\StudentLog\StudentLogListTool::class,
			Tools\StudentLog\StudentLogCountTool::class,

			// ---------- Email (7) ----------
			Tools\Email\EmailListTool::class,
			Tools\Email\EmailGetTool::class,
			Tools\Email\EmailCreateTool::class,
			Tools\Email\EmailUpdateTool::class,
			Tools\Email\EmailDeleteTool::class,
			Tools\Email\EmailSendNowTool::class,
			Tools\Email\EmailSendScheduleTool::class,
		];

		/**
		 * 允許第三方擴充 / override tool class 清單
		 *
		 * @var array<string> $classes
		 */
		$classes = apply_filters( 'pc_mcp_tool_classes', $default );
		return $classes;
	}
}

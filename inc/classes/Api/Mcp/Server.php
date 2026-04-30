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
	 * @var array<string, array{string, string}>
	 */
	const CATEGORIES = [
		'course'   => [ 'Course', 'Course CRUD and management tools' ],
		'chapter'  => [ 'Chapter', 'Chapter/unit hierarchy and content tools' ],
		'student'  => [ 'Student', 'Student enrollment and progress tracking tools' ],
		'teacher'  => [ 'Teacher', 'Instructor management and assignment tools' ],
		'bundle'   => [ 'Bundle', 'Bundle/sales plan product tools' ],
		'order'    => [ 'Order', 'WooCommerce order integration tools' ],
		'progress' => [ 'Progress', 'Student progress and completion tools' ],
		'comment'  => [ 'Comment', 'Chapter comments and reviews tools' ],
		'report'   => [ 'Report', 'Analytics and reporting tools' ],
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
	 * @return void
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::CATEGORIES as $slug => [ $label, $description ] ) {
			wp_register_ability_category(
				$slug,
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
	 * @param McpAdapter $adapter MCP Adapter 實例
	 * @return void
	 */
	public function bootstrap( McpAdapter $adapter ): void {
		// 若 McpAdapter class 不存在（尚未安裝 mcp-adapter），跳過
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		// Abilities API 未載入時（如 WP < 6.9），graceful 降級
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}

		$enabled_tools = $this->get_enabled_tools();

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

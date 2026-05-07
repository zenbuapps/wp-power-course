<?php
/**
 * MCP Student Export CSV Tool
 *
 * 匯出指定課程學員清單為 CSV 檔。
 * 為避免大檔案造成訊息過大，將 CSV 寫入 WP uploads 目錄並回傳 attachment URL。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Student\Service\Query;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\User as UserUtils;

/**
 * Class StudentExportCsvTool
 *
 * 對應 MCP ability：power-course/student_export_csv
 */
final class StudentExportCsvTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_export_csv';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '匯出課程學員 CSV', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '將指定課程的學員名單匯出為 CSV，並回傳可下載的 attachment URL。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '課程 ID。', 'power-course' ),
				],
			],
			'required'   => [ 'course_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'      => [
					'type'        => 'string',
					'description' => \__( 'CSV 檔案的下載 URL。', 'power-course' ),
				],
				'filename' => [
					'type'        => 'string',
					'description' => \__( 'CSV 檔案名稱。', 'power-course' ),
				],
				'rows'     => [
					'type'        => 'integer',
					'description' => \__( 'CSV 資料列數（不含標題列）。', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'list_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'student';
	}

	/**
	 * 執行匯出 CSV
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{url: string, filename: string, rows: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['course_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$course_id = (int) $args['course_id'];
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id 需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'mcp_course_not_found',
				\__( '找不到指定的課程。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		try {
			$rows   = $this->collect_rows( $course_id );
			$result = $this->write_csv_file( $course_id, $rows );
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);
			return new \WP_Error(
				'mcp_student_export_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'filename' => $result['filename'],
				'rows'     => $result['rows'],
			],
			true
		);

		return $result;
	}

	/**
	 * 收集該課程學員資料列
	 *
	 * @param int $course_id 課程 ID
	 * @return array<int, array<string, string>>
	 */
	private function collect_rows( int $course_id ): array {
		$query = new Query(
			[
				'posts_per_page' => -1,
				'meta_key'       => 'avl_course_ids',
				'meta_value'     => (string) $course_id,
			]
		);
		$users      = $query->get_users();
		$course_name = (string) \get_the_title( $course_id );

		$rows = [];
		foreach ( $users as $user ) {
			$expire = ExpireDate::instance( $course_id, (int) $user->ID );
			$rows[] = [
				'user_id'           => (string) $user->ID,
				'last_name'         => (string) UserUtils::get_last_name( (int) $user->ID ),
				'first_name'        => (string) UserUtils::get_first_name( (int) $user->ID ),
				'display_name'      => (string) $user->display_name,
				'user_email'        => (string) $user->user_email,
				'user_registered'   => (string) $user->user_registered,
				'course_name'       => $course_name,
				'course_id'         => (string) $course_id,
				'progress'          => CourseUtils::get_course_progress( $course_id, (int) $user->ID ) . '%',
				'expire_date_label' => (string) $expire->expire_date_label,
				'is_expired'        => $expire->is_expired ? '是' : '否',
				'subscription_id'   => isset( $expire->subscription_id ) ? (string) $expire->subscription_id : '',
			];
		}

		return $rows;
	}

	/**
	 * 將資料列寫入 CSV 檔（存於 WP uploads 目錄）
	 *
	 * @param int                               $course_id 課程 ID
	 * @param array<int, array<string, string>> $rows      資料列
	 * @return array{url: string, filename: string, rows: int}
	 * @throws \RuntimeException 寫入失敗時拋出
	 */
	private function write_csv_file( int $course_id, array $rows ): array {
		$columns = [
			'user_id'           => '學員 ID',
			'last_name'         => '姓',
			'first_name'        => '名',
			'display_name'      => '顯示名稱',
			'user_email'        => '學員 Email',
			'user_registered'   => '學員註冊時間',
			'course_name'       => '課程名稱',
			'course_id'         => '課程 ID',
			'progress'          => '學習進度',
			'expire_date_label' => '觀看期限',
			'is_expired'        => '是否過期',
			'subscription_id'   => '訂閱 ID',
		];

		$upload = \wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( (string) $upload['error'] );
		}

		$sub_dir = trailingslashit( $upload['basedir'] ) . 'power-course-mcp';
		$sub_url = trailingslashit( $upload['baseurl'] ) . 'power-course-mcp';

		if ( ! \wp_mkdir_p( $sub_dir ) ) {
			throw new \RuntimeException( \sprintf( 'Unable to create directory: %s', $sub_dir ) );
		}

		$course_title = \sanitize_file_name( (string) \get_the_title( $course_id ) );
		$suffix       = \wp_generate_password( 8, false, false );
		$filename     = "{$course_title}_students_" . \wp_date( 'Ymd_His' ) . "_{$suffix}.csv";
		$filepath     = trailingslashit( $sub_dir ) . $filename;
		$fileurl      = trailingslashit( $sub_url ) . $filename;

		$handle = \fopen( $filepath, 'w' );
		if ( false === $handle ) {
			throw new \RuntimeException( \sprintf( 'Unable to open file: %s', $filepath ) );
		}

		// 寫入 BOM 讓 Excel 正確辨識 UTF-8
		\fwrite( $handle, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		\fputcsv( $handle, array_values( $columns ) );

		foreach ( $rows as $row ) {
			$ordered = [];
			foreach ( array_keys( $columns ) as $key ) {
				$ordered[] = $row[ $key ] ?? '';
			}
			\fputcsv( $handle, $ordered );
		}

		\fclose( $handle );

		return [
			'url'      => $fileurl,
			'filename' => $filename,
			'rows'     => count( $rows ),
		];
	}
}

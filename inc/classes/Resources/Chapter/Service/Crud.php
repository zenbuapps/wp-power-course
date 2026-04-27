<?php
/**
 * Chapter Service Crud
 *
 * 章節的 Create / Update / Delete / Sort 業務邏輯，供 REST callback 與 MCP tools 共用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Service;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\Powerhouse\Utils\Base as PowerhouseBase;

/**
 * Chapter CRUD Service
 *
 * 職責：封裝章節建立、更新、刪除與排序
 */
final class Crud {

	/**
	 * 建立單一章節
	 *
	 * @param array<string, mixed> $data      章節資料（post_title / post_content / post_parent 等）
	 * @param array<string, mixed> $meta_data 章節 meta 資料
	 *
	 * @return int 新建章節 ID
	 * @throws \RuntimeException 當建立失敗時拋出
	 */
	public static function create( array $data, array $meta_data = [] ): int {
		$data['meta_input'] = $meta_data;

		$result = ChapterUtils::create_chapter( $data );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException(
				'建立章節失敗：' . $result->get_error_message()
			);
		}

		if ( ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( '建立章節失敗：回傳值不合法' );
		}

		return $result;
	}

	/**
	 * 更新單一章節
	 *
	 * @param int                  $chapter_id 章節 ID
	 * @param array<string, mixed> $data       章節資料
	 * @param array<string, mixed> $meta_data  章節 meta 資料
	 *
	 * @return int 被更新的章節 ID
	 * @throws \RuntimeException 當更新失敗時拋出
	 */
	public static function update( int $chapter_id, array $data = [], array $meta_data = [] ): int {
		$data['ID']         = $chapter_id;
		$data['meta_input'] = $meta_data;

		/** @var array{ID: int, meta_input: array<string, mixed>} $data */
		$result = \wp_update_post( $data, true );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException(
				'更新章節失敗：' . $result->get_error_message()
			);
		}

		if ( ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( '更新章節失敗：回傳值不合法' );
		}

		return $result;
	}

	/**
	 * 刪除單一章節（移至垃圾桶）
	 *
	 * @param int $chapter_id 章節 ID
	 *
	 * @return bool 是否刪除成功
	 */
	public static function delete( int $chapter_id ): bool {
		// 若已在垃圾桶，視為已刪除
		$status = \get_post_status( $chapter_id );
		if ( $status === 'trash' ) {
			return true;
		}

		$result = \wp_trash_post( $chapter_id );
		return (bool) $result;
	}

	/**
	 * 批次刪除章節
	 *
	 * 內部逐筆呼叫 delete，並回傳失敗的 ID 清單。
	 *
	 * @param array<int|string> $chapter_ids 章節 ID 陣列
	 *
	 * @return array{success: array<int>, failed: array<int>} 成功與失敗的 ID
	 */
	public static function delete_many( array $chapter_ids ): array {
		$success = [];
		$failed  = [];

		$results = PowerhouseBase::batch_process(
			$chapter_ids,
			static function ( $id ) {
				$chapter_id = (int) $id;
				$status     = \get_post_status( $chapter_id );
				if ( $status === 'trash' ) {
					return true;
				}
				return \wp_trash_post( $chapter_id );
			}
		);

		foreach ( $chapter_ids as $index => $id ) {
			$ok = $results[ $index ] ?? false;
			if ( $ok ) {
				$success[] = (int) $id;
			} else {
				$failed[] = (int) $id;
			}
		}

		return [
			'success' => $success,
			'failed'  => $failed,
		];
	}

	/**
	 * 章節排序（原子操作）
	 *
	 * 以 from_tree / to_tree 結構計算排序差異並批次更新。
	 * 若任何一步失敗會 rollback（依賴 ChapterUtils::sort_chapters 內部的 transaction）。
	 *
	 * @param array{from_tree: array<int, array<string, mixed>>, to_tree: array<int, array<string, mixed>>} $params 排序參數
	 *
	 * @return true 成功時回傳 true
	 * @throws \RuntimeException 當排序失敗時拋出
	 */
	public static function sort( array $params ): bool {
		/** @var array{from_tree: array<array{id: string, depth: string, menu_order: string, name: string, slug: string, parent_id: string}>, to_tree: array<array{id: string, depth: string, menu_order: string, name: string, slug: string, parent_id: string}>} $params */
		$result = ChapterUtils::sort_chapters( $params );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException(
				'章節排序失敗：' . $result->get_error_message()
			);
		}

		if ( $result !== true ) {
			throw new \RuntimeException( '章節排序失敗：未預期的回傳值' );
		}

		return true;
	}
}

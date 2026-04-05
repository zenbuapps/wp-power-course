<?php
/**
 * 對 AVL ChapterMeta table 的 CRUD
 * AVLChapter = 章節，額外資訊就是 AVLChapterMeta
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Utils;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\MetaCRUD as AbstractMetaCRUD;
/**
 * Class MetaCRUD
 */
abstract class MetaCRUD extends AbstractMetaCRUD {
	/**
	 * 對應的 table name with wpdb prefix
	 *
	 * @var string
	 */
	public static string $table_name = Plugin::CHAPTER_TABLE_NAME;
}

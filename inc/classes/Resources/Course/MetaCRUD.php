<?php
/**
 * 對 AVL CourseMeta table 的 CRUD
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

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
	public static string $table_name = Plugin::COURSE_TABLE_NAME;
}

<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Student\Core;

use J7\Powerhouse\Domains\Post\Service\MetaQueryBuilder;

/**
 * 拓展 User Query
 * 拓展特殊的查詢
 */
final class ExtendQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter('powerhouse/user/prepare_query_args/meta_query_builder', [ $this, 'extend_query_args' ], 20);
	}

	/**
	 * 拓展 User Query
	 *
	 * @param MetaQueryBuilder $builder 查詢參數
	 * @return MetaQueryBuilder
	 */
	public function extend_query_args( MetaQueryBuilder $builder ): MetaQueryBuilder {

		$clause = $builder->find( 'avl_course_ids' );
		if ($clause) {
			$builder->remove( 'avl_course_ids' );
			$clause_values = is_array( $clause->value ) ? $clause->value : [ $clause->value ];
			foreach ( $clause_values as $course_id ) {
				$builder->add(
					[
						'key'     => 'avl_course_ids',
						'value'   => $course_id,
						'compare' => '=',
					]
					);
			}
		}

		return $builder;
	}
}

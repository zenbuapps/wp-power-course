<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Teacher\Core;

use J7\Powerhouse\Domains\Post\Service\MetaQueryBuilder;

/**
 * 拓展 User Query
 * 拓展特殊的查詢
 */
final class ExtendQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter('powerhouse/user/prepare_query_args/meta_query_builder', [ $this, 'extend_query_args' ], 30);
	}

	/**
	 * 拓展 User Query
	 *
	 * @param MetaQueryBuilder $builder 查詢參數
	 * @return MetaQueryBuilder
	 */
	public function extend_query_args( MetaQueryBuilder $builder ): MetaQueryBuilder {

		$clause = $builder->find( 'is_teacher' );
		// 查詢非老師的用戶
		if ($clause && $clause->value === '!yes') {
			$builder
			->remove( 'is_teacher' )
			->add(
				[
					'key'     => 'is_teacher',
					'value'   => 'yes',
					'compare' => '!=',
				]
				)
			->add(
				[
					'key'     => 'is_teacher',
					'compare' => 'NOT EXISTS',
				]
				);
			$builder->relation = 'OR';
		}

		return $builder;
	}
}

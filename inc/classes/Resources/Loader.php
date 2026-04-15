<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources;

/** Class Loader */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		Student\Core\Api::instance();
		Chapter\Core\Loader::instance();
		Order::instance();
		Comment::instance();
		Course\LifeCycle::instance();
		Teacher\Core\ExtendQuery::instance();
		Settings\Core\Api::instance();
	}
}

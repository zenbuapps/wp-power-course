<?php
/**
 * Bootstrap
 */

declare (strict_types = 1);

namespace J7\PowerCourse\PowerEmail;

/**
 * Class Bootstrap
 */
final class Bootstrap {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		Resources\Email\CPT::instance();
		Resources\Email\Api::instance();
		Resources\Email\Trigger\At::instance();
	}
}

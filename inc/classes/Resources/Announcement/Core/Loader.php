<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Core;

/** Class Loader */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CPT::instance();
		Api::instance();
		LifeCycle::instance();
	}
}

<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Core;

/** Class Loader */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CPT::instance();
		Api::instance();
		SubtitleApi::instance();
		LifeCycle::instance();
		Templates::instance();
	}
}

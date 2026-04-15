<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\ChapterProgress\Core;

/**
 * ChapterProgress Resource Loader
 * 初始化 ChapterProgress 相關模組
 */
final class Loader {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		Api::instance();
	}
}

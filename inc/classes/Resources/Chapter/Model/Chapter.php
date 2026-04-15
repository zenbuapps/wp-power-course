<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Model;

use J7\PowerCourse\Utils\Base;
use J7\PowerCourse\Resources\Chapter\Utils\Utils;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD;

/** Class Chapter 用戶可以上的章節 */
final class Chapter {

	/** @var int Chapter ID */
	public int $id;

	/** @var int User ID */
	public int $user_id;

	/** @var int|null Course ID */
	public int|null $course_id;

	/** @var string|null 第一次進入章節時間 */
	public string|null $first_visit_at;

	/** @var string|null 完成章節時間 */
	public string|null $finished_at;

	/**
	 * Constructor
	 *
	 * @param int      $id Chapter ID.
	 * @param int|null $user_id User ID.
	 * @throws \Exception 如果 user_id 為 null.
	 */
	public function __construct( int $id, ?int $user_id = null ) {
		$this->id      = $id;
		$this->user_id = $user_id ? $user_id : \get_current_user_id();
		if ( ! $this->user_id ) {
			throw new \Exception( esc_html__( 'user_id cannot be null', 'power-course' ) );
		}

		$this->course_id      = Utils::get_course_id( $id );
		$this->first_visit_at = (string) MetaCRUD::get( $id, $this->user_id, 'first_visit_at', true );
		$this->finished_at    = (string) MetaCRUD::get( $id, $this->user_id, 'finished_at', true );
	}

	/**
	 * 取得章節長度
	 *
	 * @param bool $human_readable 是否要人類可讀的格式.
	 * @return int|string 秒數 或 時:分:秒
	 */
	public function get_chapter_length( bool $human_readable = false ): int|string {
		$length = (int) get_post_meta( $this->id, 'chapter_length', true );
		if ( $human_readable ) {
			return Base::get_video_length_by_seconds( $length );
		}
		return $length;
	}

	/**
	 * 取得課程商品
	 *
	 * @return \WC_Product|null
	 */
	public function get_course_product(): \WC_Product|null {
		if ( ! $this->course_id ) {
			return null;
		}

		$product = \wc_get_product( $this->course_id );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		return $product;
	}
}

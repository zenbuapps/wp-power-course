<?php
/**
 * 字幕服務（Subtitle Service）
 * 負責字幕上傳、刪除、格式轉換等業務邏輯.
 * 已與章節解耦，支援多種 post type 與 video slot.
 *
 * @package J7\PowerCourse\Resources\Chapter\Service
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Service;

/**
 * Class Subtitle
 * 字幕管理服務，支援多種 post type 與 video slot.
 */
final class Subtitle {

	/**
	 * 支援的字幕語言列表（BCP-47 語言代碼 → 顯示名稱）.
	 */
	public const SUPPORTED_LANGUAGES = [
		'zh-TW' => '繁體中文',
		'zh-CN' => '简体中文',
		'en'    => 'English',
		'ja'    => '日本語',
		'ko'    => '한국어',
		'vi'    => 'Tiếng Việt',
		'th'    => 'ไทย',
		'id'    => 'Bahasa Indonesia',
		'ms'    => 'Bahasa Melayu',
		'fr'    => 'Français',
		'de'    => 'Deutsch',
		'es'    => 'Español',
		'pt'    => 'Português',
		'ru'    => 'Русский',
		'ar'    => 'العربية',
		'hi'    => 'हिन्दी',
	];

	/**
	 * 支援的字幕檔案格式.
	 */
	public const SUPPORTED_EXTENSIONS = [ 'srt', 'vtt' ];

	/**
	 * 有效的 video slot 與允許的 post type 映射.
	 * key 為 video slot 名稱，value 為允許的 post type.
	 *
	 * 注意：每部試看影片獨立字幕的 slot（`trial_video_0` ~ `trial_video_5`）並未列於此處，
	 * 而是由 {@see self::resolve_post_type_for_slot()} 動態解析（pattern: `^trial_video_\d+$`，N 介於 0~TRIAL_VIDEOS_MAX-1）.
	 */
	public const VALID_VIDEO_SLOTS = [
		'chapter_video' => 'pc_chapter',
		'feature_video' => 'product',
		'trial_video'   => 'product',
	];

	/**
	 * 試看影片（trial_videos）最多支援數量.
	 * 對應前端 Form.List 的 maxCount，`trial_video_{N}` 的 N 必須介於 0 ~ (TRIAL_VIDEOS_MAX - 1).
	 */
	public const TRIAL_VIDEOS_MAX = 6;

	/**
	 * 解析給定 video slot 對應的 post type.
	 *
	 * 支援兩種來源：
	 * 1. {@see self::VALID_VIDEO_SLOTS} 白名單（chapter_video / feature_video / trial_video）.
	 * 2. 動態 slot `trial_video_{N}`（N 介於 0 ~ TRIAL_VIDEOS_MAX - 1），對應 `product` post type.
	 *
	 * @param string $video_slot Video slot 名稱.
	 * @return string|null 對應的 post type；若 slot 不合法則回傳 null.
	 */
	private function resolve_post_type_for_slot( string $video_slot ): ?string {
		// 1. 既有靜態白名單.
		if ( \array_key_exists( $video_slot, self::VALID_VIDEO_SLOTS ) ) {
			return self::VALID_VIDEO_SLOTS[ $video_slot ];
		}

		// 2. 動態 trial_video_{N} slot：每部試看影片獨立字幕.
		if ( 1 === preg_match( '/^trial_video_(\d+)$/', $video_slot, $matches ) ) {
			$index = (int) $matches[1];
			if ( $index >= 0 && $index < self::TRIAL_VIDEOS_MAX ) {
				return 'product';
			}
		}

		return null;
	}

	/**
	 * 驗證 post 與 video slot 的搭配是否合法.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $video_slot Video slot 名稱.
	 * @return void
	 * @throws \RuntimeException 當 post 不存在、post type 不支援或 slot 搭配不符時拋出.
	 */
	public function validate_post_and_slot( int $post_id, string $video_slot ): void {
		// 檢查 video slot 是否合法（含靜態白名單與動態 trial_video_{N}）.
		$allowed_post_type = $this->resolve_post_type_for_slot( $video_slot );
		if ( null === $allowed_post_type ) {
			throw new \RuntimeException( 'invalid_video_slot: ' . esc_html__( 'Invalid video slot', 'power-course' ) );
		}

		// 檢查 post 是否存在.
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'post_not_found: ' . esc_html__( 'Post does not exist', 'power-course' ) );
		}

		// 檢查 post type 是否符合該 slot 的要求.
		if ( $post->post_type !== $allowed_post_type ) {
			// 如果 post type 完全不在任何 slot 允許的清單中，回傳 post_not_found.
			$all_allowed_types = \array_values( self::VALID_VIDEO_SLOTS );
			if ( ! \in_array( $post->post_type, $all_allowed_types, true ) ) {
				throw new \RuntimeException( 'post_not_found: ' . esc_html__( 'Post type does not support subtitles', 'power-course' ) );
			}

			throw new \RuntimeException( 'invalid_video_slot: ' . esc_html__( 'Post type does not match video slot', 'power-course' ) );
		}
	}

	/**
	 * 取得 meta key 名稱.
	 *
	 * @param string $video_slot Video slot 名稱.
	 * @return string meta key.
	 */
	private function get_meta_key( string $video_slot ): string {
		return "pc_subtitles_{$video_slot}";
	}

	/**
	 * 上傳字幕.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $file_path  字幕檔案路徑.
	 * @param string $file_name  字幕檔案名稱（含副檔名）.
	 * @param string $srclang    BCP-47 語言代碼.
	 * @param string $video_slot Video slot 名稱.
	 * @return array{srclang: string, label: string, url: string, attachment_id: int} 字幕軌道資料.
	 * @throws \InvalidArgumentException 參數驗證失敗.
	 * @throws \RuntimeException Post 不存在、slot 不合法或重複語言.
	 */
	public function upload_subtitle( int $post_id, string $file_path, string $file_name, string $srclang, string $video_slot ): array {
		// 參數驗證：按順序檢查，第一個失敗即拋出.
		if ( '' === $file_path ) {
			throw new \InvalidArgumentException( esc_html__( 'Subtitle file is required', 'power-course' ) );
		}

		if ( '' === $srclang ) {
			throw new \InvalidArgumentException( esc_html__( 'Subtitle language is required', 'power-course' ) );
		}

		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! \in_array( $extension, self::SUPPORTED_EXTENSIONS, true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Only .srt and .vtt formats are supported', 'power-course' ) );
		}

		if ( ! $this->validate_srclang( $srclang ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid language code', 'power-course' ) );
		}

		// 驗證 post 與 video slot 搭配.
		$this->validate_post_and_slot( $post_id, $video_slot );

		$meta_key           = $this->get_meta_key( $video_slot );
		$raw_subtitles      = \get_post_meta( $post_id, $meta_key, true );
		$existing_subtitles = ( \is_array( $raw_subtitles ) && ! empty( $raw_subtitles ) ) ? $raw_subtitles : [];

		foreach ( $existing_subtitles as $subtitle ) {
			/** @var array{srclang?: string} $subtitle */
			if ( isset( $subtitle['srclang'] ) && $subtitle['srclang'] === $srclang ) {
				throw new \RuntimeException( 'subtitle_exists: ' . esc_html__( 'Subtitle for this language already exists, please delete it first', 'power-course' ) );
			}
		}

		// 讀取檔案內容.
		$content = (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// 如果是 SRT 格式，自動轉換為 VTT.
		if ( 'srt' === $extension ) {
			$content = $this->convert_srt_to_vtt( $content );
		}

		// 儲存 VTT 到 WordPress uploads 目錄.
		$vtt_filename = "subtitle-{$srclang}.vtt";
		$upload       = \wp_upload_bits( $vtt_filename, null, $content );

		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( esc_html__( 'Failed to upload file', 'power-course' ) . ': ' . \esc_html( (string) $upload['error'] ) );
		}

		// 建立 WordPress attachment 記錄.
		$attachment_id = \wp_insert_attachment(
			[
				'post_title'     => "subtitle-{$srclang}",
				'post_mime_type' => 'text/vtt',
				'post_status'    => 'inherit',
			],
			$upload['file']
		);

		if ( 0 === $attachment_id ) {
			throw new \RuntimeException( esc_html__( 'Failed to create media attachment', 'power-course' ) );
		}

		// 組裝字幕軌道資料.
		$label = $this->get_language_label( $srclang );
		$track = [
			'srclang'       => $srclang,
			'label'         => $label,
			'url'           => $upload['url'],
			'attachment_id' => $attachment_id,
		];

		// 更新 post 的字幕 postmeta.
		$existing_subtitles[] = $track;
		\update_post_meta( $post_id, $meta_key, $existing_subtitles );

		return $track;
	}

	/**
	 * 刪除字幕.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $srclang    BCP-47 語言代碼.
	 * @param string $video_slot Video slot 名稱.
	 * @return bool 是否刪除成功.
	 * @throws \InvalidArgumentException 參數驗證失敗.
	 * @throws \RuntimeException Post 不存在、slot 不合法或字幕不存在.
	 */
	public function delete_subtitle( int $post_id, string $srclang, string $video_slot ): bool {
		// 參數驗證：srclang 不得為空.
		if ( '' === $srclang ) {
			throw new \InvalidArgumentException( esc_html__( 'srclang parameter is required', 'power-course' ) );
		}

		// 驗證 post 與 video slot 搭配.
		$this->validate_post_and_slot( $post_id, $video_slot );

		$meta_key      = $this->get_meta_key( $video_slot );
		$raw_subtitles = \get_post_meta( $post_id, $meta_key, true );
		$subtitles     = ( \is_array( $raw_subtitles ) && ! empty( $raw_subtitles ) ) ? $raw_subtitles : [];

		// 尋找指定語言的字幕索引.
		$found_index   = null;
		$attachment_id = 0;

		foreach ( $subtitles as $index => $subtitle ) {
			/** @var array{srclang?: string, attachment_id?: int} $subtitle */
			if ( isset( $subtitle['srclang'] ) && $subtitle['srclang'] === $srclang ) {
				$found_index   = $index;
				$attachment_id = (int) ( $subtitle['attachment_id'] ?? 0 );
				break;
			}
		}

		if ( null === $found_index ) {
			throw new \RuntimeException( 'subtitle_not_found: ' . esc_html__( 'Subtitle for this language does not exist', 'power-course' ) );
		}

		// 刪除 WordPress attachment 附件.
		if ( $attachment_id > 0 ) {
			\wp_delete_attachment( $attachment_id, true );
		}

		// 從陣列移除該語言字幕並重新索引.
		unset( $subtitles[ $found_index ] );
		$subtitles = \array_values( $subtitles );

		// 更新 post 的字幕 postmeta.
		\update_post_meta( $post_id, $meta_key, $subtitles );

		return true;
	}

	/**
	 * 取得字幕列表.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $video_slot Video slot 名稱.
	 * @return array<int, array{srclang: string, label: string, url: string, attachment_id: int}> 字幕軌道陣列.
	 * @throws \RuntimeException Post 不存在或 slot 不合法.
	 */
	public function get_subtitles( int $post_id, string $video_slot ): array {
		// 驗證 post 與 video slot 搭配.
		$this->validate_post_and_slot( $post_id, $video_slot );

		$meta_key  = $this->get_meta_key( $video_slot );
		$subtitles = \get_post_meta( $post_id, $meta_key, true );

		if ( empty( $subtitles ) || ! \is_array( $subtitles ) ) {
			return [];
		}

		/** @var array<int, array{srclang: string, label: string, url: string, attachment_id: int}> $subtitles */
		return $subtitles;
	}

	/**
	 * 將 SRT 格式轉換為 WebVTT 格式.
	 *
	 * @param string $srt_content SRT 字幕內容.
	 * @return string WebVTT 字幕內容.
	 */
	public function convert_srt_to_vtt( string $srt_content ): string {
		// 移除 BOM 字元.
		$content = str_replace( "\xEF\xBB\xBF", '', $srt_content );

		// 統一換行符為 LF.
		$content = str_replace( [ "\r\n", "\r" ], "\n", $content );

		// 移除序號行（純數字行）.
		$content = (string) preg_replace( '/^\d+\n/m', '', $content );

		// 將時間碼中的逗號替換為句點.
		$content = (string) preg_replace( '/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $content );

		// 移除開頭多餘空行.
		$content = ltrim( $content, "\n" );

		// 加上 WEBVTT header.
		return "WEBVTT\n\n" . $content;
	}

	/**
	 * 驗證語言代碼是否有效.
	 *
	 * @param string $srclang BCP-47 語言代碼.
	 * @return bool 是否有效.
	 */
	public function validate_srclang( string $srclang ): bool {
		return \array_key_exists( $srclang, self::SUPPORTED_LANGUAGES );
	}

	/**
	 * 取得語言代碼對應的顯示名稱.
	 *
	 * @param string $srclang BCP-47 語言代碼.
	 * @return string 語言顯示名稱.
	 */
	public function get_language_label( string $srclang ): string {
		return self::SUPPORTED_LANGUAGES[ $srclang ] ?? $srclang;
	}
}

<?php
/**
 * Email 編輯器 JSON 的 kses 防護（Issue #290）
 *
 * Email 模板的 post_excerpt 存的是編輯器 JSON，但 WordPress 對「沒有 unfiltered_html 的目前使用者」
 * （DISALLOW_UNFILTERED_HTML、多站台非超級管理員、未登入的排程 / CLI 情境）會在 excerpt_save_pre
 * 掛 wp_filter_post_kses，把整份 JSON 當 HTML 過濾。其中 wp_kses_split2 → wp_kses_stripslashes
 * 會把 HTML 標籤內的 \" 改成 "，JSON 字串提前結束，整封信的編輯內容就此無法解析。
 *
 * 只要任何 wp_insert_post / wp_update_post 經過（包含只改標題、移到垃圾桶），既有內容都會重走一次
 * filter，所以防護必須放在 wp_insert_post_data，而不是個別 API 入口。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email;

/**
 * Class EditorJsonGuard
 */
final class EditorJsonGuard {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter( 'wp_insert_post_data', [ __CLASS__, 'restore_excerpt' ], 10, 3 );
		\add_filter( 'wp_insert_post_data', [ __CLASS__, 'restore_content' ], 10, 3 );
	}

	/**
	 * 以 Email 文件專用的過濾結果取代 wp_kses_post 對寄送 HTML 的過濾結果
	 *
	 * 和編輯器 JSON 同一個觸發條件：任何 wp_insert_post / wp_update_post 都會重走 content_save_pre。
	 *
	 * @param array<string, mixed> $data                已 slash 且已跑過 save_pre filter 的欄位
	 * @param array<string, mixed> $postarr             已 sanitize 的輸入
	 * @param array<string, mixed> $unsanitized_postarr 未經 filter 的原始輸入（已 slash）
	 * @return array<string, mixed>
	 */
	public static function restore_content( array $data, array $postarr, array $unsanitized_postarr ): array {
		if ( CPT::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		// 只處理 kses 確實作用中的情況；有 unfiltered_html 時完全不介入
		if ( false === \has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			return $data;
		}

		$raw = $unsanitized_postarr['post_content'] ?? null;
		if ( ! \is_string( $raw ) || '' === $raw ) {
			return $data;
		}

		$data['post_content'] = \wp_slash( HtmlSanitizer::sanitize( \wp_unslash( $raw ) ) );

		return $data;
	}

	/**
	 * 以「逐字串過濾」的結果取代 kses 對整份 JSON 的過濾結果
	 *
	 * 沒有 unfiltered_html 的使用者，JSON 內的 HTML 標籤（值與 key）一樣會被 wp_kses_post 過濾，
	 * 網址欄位的危險協定（javascript: 等）也會被移除，否則管理員之後重存時會把它寫進寄送 HTML。
	 * 與 kses 本身相同的限制：不處理實體編碼的文字（&lt;img&gt;）。
	 *
	 * @param array<string, mixed> $data                已 slash 且已跑過 save_pre filter 的欄位
	 * @param array<string, mixed> $postarr             已 sanitize 的輸入
	 * @param array<string, mixed> $unsanitized_postarr 未經 filter 的原始輸入（已 slash）
	 * @return array<string, mixed>
	 */
	public static function restore_excerpt( array $data, array $postarr, array $unsanitized_postarr ): array {
		if ( CPT::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		// 只處理 kses 確實作用中的情況；有 unfiltered_html 時完全不介入
		if ( false === \has_filter( 'excerpt_save_pre', 'wp_filter_post_kses' ) ) {
			return $data;
		}

		$raw = $unsanitized_postarr['post_excerpt'] ?? null;
		if ( ! \is_string( $raw ) || '' === $raw ) {
			return $data;
		}

		$sanitized = self::sanitize_json( \wp_unslash( $raw ) );
		if ( null !== $sanitized ) {
			$data['post_excerpt'] = \wp_slash( $sanitized );
		}

		return $data;
	}

	/**
	 * 逐字串套用 wp_kses_post，結構與跳脫不受影響
	 *
	 * 沒有任何字串被過濾時回傳原字串（逐 byte 不變）；只有真的過濾掉東西才重新編碼。
	 *
	 * @param string $json 原始 JSON 字串
	 * @return string|null 過濾後的 JSON；不是合法的 JSON 物件 / 陣列時回傳 null（不介入）
	 */
	public static function sanitize_json( string $json ): ?string {
		// 不用 associative：stdClass 才能保住空物件 {}，否則會被編碼成 []
		$decoded = json_decode( $json );
		if ( JSON_ERROR_NONE !== json_last_error() || ( ! \is_object( $decoded ) && ! \is_array( $decoded ) ) ) {
			return null;
		}

		$changed   = false;
		$sanitized = self::sanitize_node( $decoded, $changed );
		if ( ! $changed ) {
			return $json;
		}

		$encoded = \wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return \is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * 遞迴過濾節點內的字串，包含物件的 key
	 *
	 * Key 也要過濾：easy-email 會把 attributes 的 key 直接拼進 MJML 標籤。
	 *
	 * @param mixed $node    節點
	 * @param bool  $changed 是否有任何字串被改動
	 * @return mixed
	 */
	private static function sanitize_node( mixed $node, bool &$changed ): mixed {
		if ( \is_string( $node ) ) {
			return self::sanitize_string( $node, $changed );
		}

		if ( \is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				$node[ $key ] = self::sanitize_node( $value, $changed );
			}
			return $node;
		}

		if ( \is_object( $node ) ) {
			// 重建物件才能改 key，同時保留原本的順序
			$clean = new \stdClass();
			foreach ( \get_object_vars( $node ) as $key => $value ) {
				$clean_key           = self::sanitize_string( (string) $key, $changed );
				$clean->{$clean_key} = \is_string( $value ) && self::is_url_key( (string) $key )
				? self::sanitize_url( $value, $changed )
				: self::sanitize_node( $value, $changed );
			}
			return $clean;
		}

		return $node;
	}

	/**
	 * 判斷欄位是否存放網址（href、src、background-url 等）
	 *
	 * @param string $key 欄位名稱
	 * @return bool
	 */
	private static function is_url_key( string $key ): bool {
		$key = \strtolower( $key );

		return 'href' === $key || 'src' === $key || \str_ends_with( $key, '-url' ) || \str_ends_with( $key, '_url' );
	}

	/**
	 * 移除危險協定的網址
	 *
	 * 要先解開實體編碼：easy-email 轉 MJML 時會解一層，java&#115;cript: 寄出時就是 javascript:。
	 * 合法網址原樣保留：{{course.permalink}} 這類變數與 & 都不能被改動。
	 *
	 * @param string $url     網址
	 * @param bool   $changed 是否有任何字串被改動
	 * @return string
	 */
	private static function sanitize_url( string $url, bool &$changed ): string {
		$decoded = $url;
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = \html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		if ( \wp_kses_bad_protocol( $decoded, \wp_allowed_protocols() ) === $decoded ) {
			return self::sanitize_string( $url, $changed );
		}

		$changed = true;

		return '';
	}

	/**
	 * 過濾單一字串
	 *
	 * 只過濾含 `<` 的字串：其他字串不可能有 HTML 標籤，過 kses 只會把網址內的 & 改成 &amp;。
	 *
	 * @param string $text    字串
	 * @param bool   $changed 是否有任何字串被改動
	 * @return string
	 */
	private static function sanitize_string( string $text, bool &$changed ): string {
		if ( ! \str_contains( $text, '<' ) ) {
			return $text;
		}

		$clean = \wp_kses_post( $text );
		if ( $clean !== $text ) {
			$changed = true;
		}

		return $clean;
	}
}

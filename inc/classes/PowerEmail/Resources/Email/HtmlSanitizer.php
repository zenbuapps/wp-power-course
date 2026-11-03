<?php
/**
 * 過濾 Email 寄送用的完整 HTML 文件
 *
 * Email 模板的 post_content 是 MJML 產生的完整文件，wp_kses_post 是給文章內容用的白名單：
 * 它會移除 html / head / body / style，CSS 變成信裡看得到的純文字；
 * 也會弄壞 Outlook 條件註解，<!--<![endif]--> 會變成信裡看得到的 <!--。
 *
 * 給沒有 unfiltered_html 的使用者用，所以仍是白名單：不允許 script、事件屬性、iframe、危險網址。
 * 但比文章內容寬：可以放 style、外部樣式表與版面用的 CSS（包含 position），會影響信件外觀。
 *
 * 不信任 kses 之後的字串處理：
 * - style / href / src / background 不交給 kses，先換成佔位屬性，由這裡檢查並完整跳脫後換回去
 *   （kses 的 CSS 與網址處理依 WordPress 版本而異，會刪掉含 & 的 url()，也會弄壞含冒號的網址）
 * - style 區塊與條件註解 kses 看不懂，先換成佔位字串；換回去的內容都已過濾
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email;

/**
 * Class HtmlSanitizer
 */
abstract class HtmlSanitizer {

	/** @var array<int, string> 由這裡檢查、不交給 kses 的屬性 */
	private const HELD_ATTRIBUTES = [ 'style', 'href', 'src', 'background' ];

	/** @var string 佔位屬性的前綴（見 hold_attributes） */
	private const HELD_ATTRIBUTE_PREFIX = 'data-pc-';

	/** @var array<int, string> 信件網址可用的協定；不用 wp_allowed_protocols，feed:javascript: 這類巢狀協定用不到 */
	private const PROTOCOLS = [ 'http', 'https', 'mailto', 'tel', 'sms', 'cid', 'line', 'whatsapp' ];

	/** @var int 可處理的 HTML 上限：Gmail 超過 102KB 就會截斷，PCRE 的回溯上限約在 1MB 才會碰到 */
	public const MAX_BYTES = 512000;

	/** @var int 網址長度上限 */
	private const MAX_URL_BYTES = 8192;

	/** @var array<int, string> 信件內容可用的標籤；不繼承文章白名單，那份名單會被其他外掛擴充（例如 Api\Course 加了 iframe） */
	private const CONTENT_TAGS = [
		'a',
		'abbr',
		'article',
		'aside',
		'b',
		'big',
		'blockquote',
		'br',
		'caption',
		'center',
		'cite',
		'code',
		'col',
		'colgroup',
		'dd',
		'del',
		'div',
		'dl',
		'dt',
		'em',
		'figcaption',
		'figure',
		'font',
		'footer',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'i',
		'img',
		'ins',
		'label',
		'li',
		'mark',
		'ol',
		'p',
		'pre',
		'q',
		's',
		'section',
		'small',
		'span',
		'strike',
		'strong',
		'sub',
		'sup',
		'table',
		'tbody',
		'td',
		'tfoot',
		'th',
		'thead',
		'tr',
		'u',
		'ul',
	];

	/** @var array<int, string> 行內樣式可用的 CSS 屬性（mso- 開頭另外放行） */
	public const STYLE_PROPERTIES = [
		'-moz-user-select',
		'-ms-interpolation-mode',
		'-ms-text-size-adjust',
		'-webkit-text-size-adjust',
		'-webkit-user-select',
		'background',
		'background-color',
		'background-image',
		'background-position',
		'background-repeat',
		'background-size',
		'border',
		'border-bottom',
		'border-bottom-color',
		'border-bottom-left-radius',
		'border-bottom-right-radius',
		'border-bottom-style',
		'border-bottom-width',
		'border-collapse',
		'border-color',
		'border-left',
		'border-left-color',
		'border-left-style',
		'border-left-width',
		'border-radius',
		'border-right',
		'border-right-color',
		'border-right-style',
		'border-right-width',
		'border-spacing',
		'border-style',
		'border-top',
		'border-top-color',
		'border-top-left-radius',
		'border-top-right-radius',
		'border-top-style',
		'border-top-width',
		'border-width',
		'bottom',
		'box-shadow',
		'box-sizing',
		'caption-side',
		'clear',
		'color',
		'cursor',
		'direction',
		'display',
		'float',
		'font',
		'font-family',
		'font-size',
		'font-style',
		'font-variant',
		'font-weight',
		'height',
		'left',
		'letter-spacing',
		'line-height',
		'list-style',
		'list-style-type',
		'margin',
		'margin-bottom',
		'margin-left',
		'margin-right',
		'margin-top',
		'max-height',
		'max-width',
		'min-height',
		'min-width',
		'opacity',
		'outline',
		'overflow',
		'padding',
		'padding-bottom',
		'padding-left',
		'padding-right',
		'padding-top',
		'position',
		'right',
		'table-layout',
		'text-align',
		'text-decoration',
		'text-indent',
		'text-shadow',
		'text-transform',
		'top',
		'user-select',
		'vertical-align',
		'visibility',
		'white-space',
		'width',
		'word-break',
		'word-spacing',
		'z-index',
	];

	/**
	 * 過濾完整的 Email HTML
	 *
	 * 過大、regex 失敗或輸出沒通過檢查時回傳 null，由呼叫端拒存：
	 * 當成空字串會把整封信清空，退回 wp_kses_post 則會繞過這裡的標籤清單。
	 *
	 * @param string $html 原始 HTML
	 * @return string|null
	 */
	public static function sanitize( string $html ): ?string {
		if ( \strlen( $html ) > self::MAX_BYTES ) {
			return null;
		}

		try {
			$has_doctype = 1 === \preg_match( '/^\s*<!doctype html>/i', $html );
			$body        = self::checked( \preg_replace( '/^\s*<!doctype[^>]*>/i', '', $html ) );

			$clean = ( $has_doctype ? '<!doctype html>' : '' ) . self::sanitize_fragment( $body, self::allowed_html() );

			// 過濾會讓內容變大（& 變成 &amp;）：存檔時會再過濾一次，超過上限要在這裡就拒絕
			return \strlen( $clean ) > self::MAX_BYTES ? null : $clean;
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * 判斷是否為 wp_kses_post 會弄壞的 Email 文件
	 *
	 * 這些結構 kses 看不懂（見檔頭）。其他 HTML 片段 kses 處理得了，維持原本的結果，不改變既有行為。
	 *
	 * @param string $html HTML
	 * @return bool
	 */
	public static function is_email_document( string $html ): bool {
		return 1 === \preg_match( '/<!doctype\b|<html\b|<head\b|<body\b|<style\b|<!--\[if/i', $html );
	}

	/**
	 * 過濾寫入寄送欄位的內容：Email 文件走 sanitize()，HTML 片段維持 wp_kses_post
	 *
	 * @param string $html HTML
	 * @return string|null 無法處理時回傳 null
	 */
	public static function sanitize_content( string $html ): ?string {
		return self::is_email_document( $html ) ? self::sanitize( $html ) : \wp_kses_post( $html );
	}

	/**
	 * 判斷網址的協定是否安全
	 *
	 * 只看真正的 scheme：{{site.url}}:8080、/a?t=12:30 都不是協定。
	 * 要先解開實體編碼：瀏覽器與 easy-email 都會解，java&#115;cript: 寄出時就是 javascript:。
	 * 判斷不了一律視為不安全。
	 *
	 * @param string $url 網址
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		if ( \strlen( $url ) > self::MAX_URL_BYTES ) {
			return false;
		}

		$decoded = $url;
		for ( $i = 0; $i < 3; $i++ ) {
			// 瀏覽器接受沒有分號的數字實體（&#58），html_entity_decode 不接受
			$decoded = \preg_replace( '/&#(x[0-9a-f]+|[0-9]+);?/i', '&#$1;', $decoded );
			if ( null === $decoded ) {
				return false;
			}
			$decoded = \html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// 變數由伺服器替換；空白與控制字元瀏覽器會略過，java\tscript: 一樣會執行
		$normalized = \preg_replace( [ '/\{\{[^{}]*\}\}/', '/[\x00-\x20\x7f]+/' ], '', $decoded );
		// 開頭兩個反斜線是 UNC 路徑，Outlook 可能會連過去
		if ( null === $normalized || \str_starts_with( $normalized, '\\\\' ) ) {
			return false;
		}

		$has_scheme = \preg_match( '/^([^\/?#:]*):/', $normalized, $match );
		if ( false === $has_scheme ) {
			return false;
		}

		return 0 === $has_scheme || '' === $match[1] || \in_array( \strtolower( $match[1] ), self::PROTOCOLS, true );
	}

	/**
	 * 判斷 HTML 是否為被 wp_kses_post 弄壞的 MJML 文件
	 *
	 * 這種內容照寄會讓 CSS 或 <!-- 出現在信裡，但伺服器無法重新產生，要回編輯器重新儲存。
	 *
	 * @param string $html 寄送用的 HTML
	 * @return bool
	 */
	public static function is_damaged( string $html ): bool {
		$is_mjml = \str_contains( $html, 'mj-column-per-' ) || \str_contains( $html, '#outlook a {' );
		if ( ! $is_mjml ) {
			return false;
		}

		$without_comments = (string) \preg_replace( '/<!--.*?(-->|$)/s', '', $html );

		return ! \preg_match( '/<style[\s>]/i', $without_comments );
	}

	/**
	 * 過濾一段 HTML
	 *
	 * @param string                              $html    HTML
	 * @param array<string, array<string, mixed>> $allowed 允許的標籤
	 * @return string
	 * @throws \RuntimeException Regex 失敗時。
	 */
	private static function sanitize_fragment( string $html, array $allowed ): string {
		$prefix = 'pcemail' . \strtolower( \wp_generate_password( 16, false ) ) . 'x';
		$tokens = [];
		$hold   = static function ( string $safe ) use ( &$tokens, $prefix ): string {
			$token            = $prefix . \count( $tokens ) . 'x';
			$tokens[ $token ] = $safe;
			return $token;
		};

		// 下層相容的條件註解本身就是完整的註解，內容仍會走下面的過濾
		$html = self::checked(
			\preg_replace_callback(
				'/<!--\[if [a-zA-Z0-9 |&!()]+\]><!-->|<!--<!\[endif\]-->/',
				static fn( array $match ): string => $hold( $match[0] ),
				$html
			)
		);

		// 只有 Outlook 看得到的條件註解：內容一樣要過濾，且不能提早結束註解
		$html = self::checked(
			\preg_replace_callback(
				'/<!--\[if ([a-zA-Z0-9 |&!()]+)\]>(.*?)<!\[endif\]-->/s',
				static function ( array $match ) use ( $hold ): string {
					$inner = self::sanitize_outlook_content( $match[2] );
					if ( \str_contains( $inner, '-->' ) || \str_contains( $inner, '--!>' ) ) {
						return '';
					}
					return $hold( "<!--[if {$match[1]}]>{$inner}<![endif]-->" );
				},
				$html
			)
		);

		// 其他註解在信件裡沒有用途，kses 又會把內容改成看得到的文字
		$html = self::checked( \preg_replace( '/<!--.*?(-->|$)/s', '', $html ) );

		$html = self::checked(
			\preg_replace_callback(
				'/<style\b([^>]*)>(.*?)<\/style\s*>/is',
				static fn( array $match ): string => self::is_safe_css( $match[2] ) ? $hold( self::style_open_tag( $match[1] ) . $match[2] . '</style>' ) : '',
				$html
			)
		);

		$attributes = [];
		$html       = self::hold_attributes( $html, $prefix, $attributes );

		$clean = self::checked( \wp_kses( $html, $allowed, self::PROTOCOLS ) );

		// 佔位字串跑進標籤內（例如 alt="..."）代表有人刻意構造：換回去會跳出屬性，直接移除
		foreach ( \array_keys( $tokens ) as $token ) {
			if ( \preg_match( '/<[^<>]*' . \preg_quote( $token, '/' ) . '/', $clean ) ) {
				$tokens[ $token ] = '';
			}
		}

		$result = \strtr( \strtr( $clean, $tokens ), $attributes );

		self::assert_allowed( $result, $allowed );

		return $result;
	}

	/**
	 * 用 HTML5 解析器檢查輸出：上面的步驟都是字串處理，這裡以瀏覽器的看法再確認一次
	 *
	 * 條件註解的內容在 sanitize_outlook_content 的 sanitize_fragment 檢查過。
	 * WP_HTML_Tag_Processor 從 WordPress 6.2 才有；更舊的版本只靠上面的過濾。
	 *
	 * @param string                              $html    過濾後的 HTML
	 * @param array<string, array<string, mixed>> $allowed 允許的標籤
	 * @return void
	 * @throws \RuntimeException 有不該出現的標籤或屬性時。
	 */
	private static function assert_allowed( string $html, array $allowed ): void {
		if ( ! \class_exists( \WP_HTML_Tag_Processor::class ) ) {
			return;
		}

		$allowed['style'] = [
			'media' => true,
			'type'  => true,
		];

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = \strtolower( (string) $processor->get_tag() );
			if ( ! isset( $allowed[ $tag ] ) ) {
				throw new \RuntimeException( "unexpected tag: {$tag}" );
			}

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				if ( ! self::is_allowed_attribute( $tag, (string) $name, $processor->get_attribute( (string) $name ), $allowed[ $tag ] ) ) {
					throw new \RuntimeException( "unexpected attribute: {$tag}[{$name}]" );
				}
			}
		}
	}

	/**
	 * 判斷輸出裡的屬性是否合法
	 *
	 * @param string               $tag     標籤
	 * @param string               $name    屬性名稱
	 * @param mixed                $value   屬性值（解開實體後）
	 * @param array<string, mixed> $allowed 這個標籤允許的屬性
	 * @return bool
	 */
	private static function is_allowed_attribute( string $tag, string $name, mixed $value, array $allowed ): bool {
		if ( 'style' === $name ) {
			return 'style' !== $tag;
		}

		if ( \in_array( $name, self::HELD_ATTRIBUTES, true ) ) {
			return \is_string( $value ) && self::is_safe_url( $value );
		}

		return isset( $allowed[ $name ] ) || \str_starts_with( $name, 'data-' ) || \str_starts_with( $name, 'aria-' );
	}

	/**
	 * 把 style / href / src / background 換成佔位屬性，檢查並跳脫的結果存進 $attributes
	 *
	 * 這一步在 kses 之前：比對錯位只會讓 kses 看到比較奇怪的內容，不會繞過它。
	 * 換回去時不管落在什麼位置都不會跳出：值裡的引號與角括號都已跳脫。
	 *
	 * @param string                $html       HTML
	 * @param string                $prefix     本次過濾的隨機字串
	 * @param array<string, string> $attributes 佔位屬性 => 真正的屬性
	 * @return string
	 * @throws \RuntimeException Regex 失敗時。
	 */
	private static function hold_attributes( string $html, string $prefix, array &$attributes ): string {
		return self::checked(
			\preg_replace_callback(
				'/\s(' . \implode( '|', self::HELD_ATTRIBUTES ) . ')\s*=\s*("[^"]*"|\'[^\']*\')/i',
				static function ( array $match ) use ( $prefix, &$attributes ): string {
					$name  = \strtolower( $match[1] );
					$value = \html_entity_decode( \substr( $match[2], 1, -1 ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$value = 'style' === $name ? self::sanitize_inline_style( $value ) : ( self::is_safe_url( $value ) ? $value : '' );

					// 值裡有佔位字串代表有人把條件註解或 style 區塊塞進屬性
					if ( \str_contains( $value, $prefix ) ) {
						$value = '';
					}

					$placeholder = self::HELD_ATTRIBUTE_PREFIX . $name;
					$token       = $prefix . 'a' . \count( $attributes ) . 'x';
					$held        = " {$placeholder}=\"{$token}\"";

					$attributes[ $held ] = '' === $value ? '' : " {$name}=\"" . \htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"';
					// 落在別的屬性值裡時 kses 會把引號編碼，換不回來的佔位字串不能留在信裡
					$attributes[ "{$placeholder}=&quot;{$token}&quot;" ] = '';

					return $held;
				},
				$html
			)
		);
	}

	/**
	 * 過濾行內樣式：只留白名單內的屬性，值要通過 CSS 檢查
	 *
	 * @param string $style 解開實體後的 style 屬性值
	 * @return string
	 */
	private static function sanitize_inline_style( string $style ): string {
		// 括號可以巢狀：url('a(1).png')、calc(100% - (2 * 10px)) 內的右括號不代表宣告結束
		if ( false === \preg_match_all( '/(?:[^;"\'()]|"[^"]*"|\'[^\']*\'|(\((?:[^()"\']|"[^"]*"|\'[^\']*\'|(?1))*\)))+/', $style, $declarations ) ) {
			return '';
		}

		$clean = [];
		foreach ( $declarations[0] as $declaration ) {
			$parts = \explode( ':', $declaration, 2 );
			if ( 2 !== \count( $parts ) ) {
				continue;
			}

			$property = \strtolower( \trim( $parts[0] ) );
			$is_known = \in_array( $property, self::STYLE_PROPERTIES, true ) || 1 === \preg_match( '/^mso-[a-z-]+$/', $property );
			$value    = \trim( $parts[1] );

			// 大括號可以結束規則；{{變數}} 與實際寄送用的 {course_image_url} 由伺服器替換，不算
			$has_braces = 1 === \preg_match( '/[{}]/', (string) \preg_replace( '/\{\{.*?\}\}|\{[a-z0-9_]+\}/i', '', $value ) );

			if ( $is_known && '' !== $value && ! $has_braces && self::is_safe_css( $value ) ) {
				$clean[] = "{$property}:{$value}";
			}
		}

		return \implode( ';', $clean );
	}

	/**
	 * 過濾只有 Outlook 看得到的內容
	 *
	 * WordPress 的 kses 標籤名稱不接受冒號，v:rect 這類標籤要先換成 v-rect。
	 * 這些標籤只在這裡放行：一般內容不需要，也不應該出現。
	 *
	 * @param string $inner 條件註解內的 HTML（可能是不成對的標籤）
	 * @return string
	 * @throws \RuntimeException Regex 失敗時。
	 */
	private static function sanitize_outlook_content( string $inner ): string {
		$renamed = self::checked( \preg_replace( '/<(\/?)(v|o):([a-zA-Z]+)/', '<$1$2-$3', $inner ) );
		$clean   = self::sanitize_fragment( $renamed, self::allowed_html() + self::outlook_allowed_html() );

		return self::checked( \preg_replace( '/<(\/?)(v|o)-([a-zA-Z]+)/', '<$1$2:$3', $clean ) );
	}

	/**
	 * 判斷 CSS 是否可以放行
	 *
	 * 原文與去掉註解後都要檢查：註解可以藏標籤（瀏覽器在 style 內不認 CSS 註解，</style x> 會結束標籤），
	 * 也可以拆開關鍵字。反斜線可以用來隱藏關鍵字（\65xpression），信件的 CSS 用不到，一律不放行。
	 *
	 * @param string $css CSS
	 * @return bool
	 */
	private static function is_safe_css( string $css ): bool {
		if ( \str_contains( $css, '<' ) || \str_contains( $css, '\\' ) ) {
			return false;
		}

		$without_comments = \preg_replace( '/\/\*.*?(\*\/|$)/s', '', $css );
		if ( null === $without_comments ) {
			return false;
		}

		foreach ( [ $css, $without_comments ] as $text ) {
			$text = self::normalize_css( $text );
			if ( null === $text ) {
				return false;
			}

			// scroll-behavior、transition-behavior 是一般屬性，-ms-behavior 才和 behavior 相同
			if ( 0 !== \preg_match( '/expression\(|(?<!scroll-)(?<!transition-)behaviou?r:|-moz-binding|(java|vb)script:/i', $text ) ) {
				return false;
			}

			// 函式參數的引號也算：image-set("data:...") 不經過 url()。[href^="tel:"]、content:"a:b" 是文字，不算
			if ( false === \preg_match_all( '/(?:url\(|@import|[(,]["\'])[\'"]?([a-z][a-z0-9+.-]*):/i', $text, $schemes ) ) {
				return false;
			}

			foreach ( $schemes[1] as $scheme ) {
				if ( ! \in_array( \strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * 還原 CSS 實際生效的樣子，只用來判斷，不會寫回輸出
	 *
	 * - 全形英數在舊版 IE 等同半形。字型名稱會用到全形（ＭＳ Ｐゴシック），所以轉換後判斷，不直接拒絕
	 * - 變數由伺服器替換，換成空字串後 da{x}ta: 就是 data:
	 * - 瀏覽器會略過網址內的空白與控制字元：da\tta: 也是 data:
	 *
	 * @param string $css CSS
	 * @return string|null 編碼無效或 regex 失敗時回傳 null
	 */
	private static function normalize_css( string $css ): ?string {
		$halfwidth = \preg_replace_callback(
			'/[\x{FF01}-\x{FF5E}]/u',
			// 這個範圍的 UTF-8 固定三個 byte，碼位 = 0xF000 + 後兩個 byte 的低 6 位；減 0xFEE0 就是半形
			static fn( array $match ): string => \chr( ( ( ( \ord( $match[0][1] ) & 0x3F ) << 6 ) | ( \ord( $match[0][2] ) & 0x3F ) ) + 0xF000 - 0xFEE0 ),
			$css
		);

		return null === $halfwidth ? null : \preg_replace( [ '/\{\{[^{}]*\}\}|\{[a-z0-9_]+\}/i', '/[\x00-\x20\x7f]+/' ], '', $halfwidth );
	}

	/**
	 * 重建 style 開始標籤，只保留 type 與 media
	 *
	 * @param string $attributes 原始屬性字串
	 * @return string
	 */
	private static function style_open_tag( string $attributes ): string {
		$tag = '<style';
		foreach ( \wp_kses_hair( $attributes, [] ) as $name => $attribute ) {
			if ( \in_array( $name, [ 'type', 'media' ], true ) ) {
				$tag .= " {$name}=\"" . \esc_attr( $attribute['value'] ) . '"';
			}
		}

		return $tag . '>';
	}

	/**
	 * 確認 regex 成功：失敗回傳 null，當成空字串會把整封信清空
	 *
	 * @param string|array<string>|null $result preg_replace 或 wp_kses 的結果
	 * @return string
	 * @throws \RuntimeException Regex 失敗時。
	 */
	private static function checked( string|array|null $result ): string {
		if ( ! \is_string( $result ) ) {
			throw new \RuntimeException( \preg_last_error_msg() );
		}

		return $result;
	}

	/**
	 * Email 文件允許的標籤
	 *
	 * 每個標籤都帶佔位屬性（見 hold_attributes）；真正的 style、href、src 不在名單內，kses 會移除漏網的寫法。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function allowed_html(): array {
		$post    = \wp_kses_allowed_html( 'post' );
		$allowed = [];

		$common = [
			'align'                => true,
			'alt'                  => true,
			'aria-label'           => true,
			'aria-roledescription' => true,
			'bgcolor'              => true,
			'border'               => true,
			'cellpadding'          => true,
			'cellspacing'          => true,
			'checked'              => true,
			'class'                => true,
			'color'                => true,
			'colspan'              => true,
			'dir'                  => true,
			'face'                 => true,
			'for'                  => true,
			'height'               => true,
			'id'                   => true,
			'lang'                 => true,
			'name'                 => true,
			'rel'                  => true,
			'role'                 => true,
			'rowspan'              => true,
			'size'                 => true,
			'target'               => true,
			'title'                => true,
			'valign'               => true,
			'width'                => true,
		];
		foreach ( self::CONTENT_TAGS as $tag ) {
			$attributes = ( $post[ $tag ] ?? [] ) + $common;
			foreach ( self::HELD_ATTRIBUTES as $attribute ) {
				unset( $attributes[ $attribute ] );
			}
			$allowed[ $tag ] = $attributes;
		}

		$allowed['html']  = [
			'dir'     => true,
			'lang'    => true,
			'xmlns'   => true,
			'xmlns:o' => true,
			'xmlns:v' => true,
		];
		$allowed['head']  = [];
		$allowed['title'] = [];
		$allowed['body']  = [
			'bgcolor' => true,
			'class'   => true,
			'dir'     => true,
			'id'      => true,
			'lang'    => true,
		];
		// 只放行 MJML 會產生的值：refresh 可以把開信的人導去別的網址，charset 可以改變整封信的解析方式
		$allowed['meta'] = [
			'content'    => [
				'values' => [
					'ie=edge',
					'text/html; charset=utf-8',
					'width=device-width, initial-scale=1',
					'width=device-width,initial-scale=1',
				],
			],
			'http-equiv' => [ 'values' => [ 'content-type', 'x-ua-compatible' ] ],
			'name'       => [ 'values' => [ 'viewport' ] ],
		];
		$allowed['link'] = [
			'media' => true,
			'rel'   => [ 'values' => [ 'stylesheet' ] ],
			'type'  => true,
		];
		// 手風琴與輪播靠 checkbox / radio 運作，其他類型不需要
		$allowed['input'] = [
			'checked' => true,
			'class'   => true,
			'id'      => true,
			'name'    => true,
			'type'    => [
				'required' => true,
				'values'   => [ 'checkbox', 'radio' ],
			],
		];

		return self::with_held_attributes( $allowed );
	}

	/**
	 * 只有 Outlook 條件註解內才允許的標籤（v:、o: 已換成連字號，見 sanitize_outlook_content）
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function outlook_allowed_html(): array {
		$vml = [
			'aspect'   => true,
			'color'    => true,
			'fill'     => true,
			'inset'    => true,
			'origin'   => true,
			'position' => true,
			'size'     => true,
			'stroke'   => true,
			'type'     => true,
			'xmlns:v'  => true,
		];

		return self::with_held_attributes(
			[
				'noscript'                 => [],
				'xml'                      => [],
				'o-allowpng'               => [],
				'o-officedocumentsettings' => [],
				'o-pixelsperinch'          => [],
				'v-fill'                   => $vml,
				'v-image'                  => $vml,
				'v-rect'                   => $vml,
				'v-textbox'                => $vml,
			]
		);
	}

	/**
	 * 替每個標籤加上佔位屬性
	 *
	 * @param array<string, array<string, mixed>> $allowed 允許的標籤
	 * @return array<string, array<string, mixed>>
	 */
	private static function with_held_attributes( array $allowed ): array {
		foreach ( $allowed as $tag => $attributes ) {
			foreach ( self::HELD_ATTRIBUTES as $attribute ) {
				$allowed[ $tag ][ self::HELD_ATTRIBUTE_PREFIX . $attribute ] = true;
			}
		}

		return $allowed;
	}
}

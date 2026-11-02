<?php
/**
 * 過濾 Email 寄送用的完整 HTML 文件
 *
 * Email 模板的 post_content 是 MJML 產生的完整文件，wp_kses_post 是給文章內容用的白名單：
 * 它會移除 html / head / body / style，CSS 變成信裡看得到的純文字；
 * 也會弄壞 Outlook 條件註解，<!--<![endif]--> 會變成信裡看得到的 <!--。
 *
 * 這裡維持 kses 的安全邊界（不允許 script、事件屬性、iframe、危險網址），
 * 只多放行 Email 文件需要的結構，所以沒有 unfiltered_html 的使用者仍然不能存入任意 HTML。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email;

/**
 * Class HtmlSanitizer
 */
abstract class HtmlSanitizer {

	/** @var array<int, string> MJML 行內樣式會用到、但 kses 預設不允許的 CSS 屬性 */
	private const EXTRA_STYLE_PROPERTIES = [
		'-moz-user-select',
		'-ms-text-size-adjust',
		'-webkit-text-size-adjust',
		'-webkit-user-select',
		'background-position',
		'background-repeat',
		'background-size',
		'border-collapse',
		'border-spacing',
		'caption-side',
		'cursor',
		'direction',
		'display',
		'float',
		'height',
		'line-height',
		'max-height',
		'max-width',
		'mso-fit-shape-to-text',
		'mso-hide',
		'mso-line-height-rule',
		'mso-padding-alt',
		'mso-position-horizontal',
		'mso-table-lspace',
		'mso-table-rspace',
		'mso-width-percent',
		'opacity',
		'outline',
		'overflow',
		'position',
		'table-layout',
		'text-decoration',
		'text-transform',
		'top',
		'user-select',
		'vertical-align',
		'visibility',
		'word-break',
		'word-spacing',
		'z-index',
	];

	/**
	 * 過濾完整的 Email HTML（結果可重複過濾，內容不會再變動）
	 *
	 * @param string $html 原始 HTML
	 * @return string
	 */
	public static function sanitize( string $html ): string {
		$has_doctype = 1 === \preg_match( '/^\s*<!doctype html>/i', $html );
		$body        = (string) \preg_replace( '/^\s*<!doctype[^>]*>/i', '', $html );

		$clean = self::sanitize_fragment( $body, self::allowed_html() );

		return ( $has_doctype ? '<!doctype html>' : '' ) . $clean;
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
		$is_mjml = \str_contains( $html, 'mj-column-per-' ) || \str_contains( $html, '#outlook a' );

		return $is_mjml && ! \preg_match( '/<style[\s>]/i', $html );
	}

	/**
	 * 過濾一段 HTML：先把 kses 處理不了的語法換成佔位字串，過完 kses 再換回過濾後的內容
	 *
	 * @param string                              $html    HTML
	 * @param array<string, array<string, mixed>> $allowed 允許的標籤
	 * @return string
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
		$html = (string) \preg_replace_callback(
			'/<!--\[if [a-zA-Z0-9 |&!()]+\]><!-->|<!--<!\[endif\]-->/',
			static fn( array $match ): string => $hold( $match[0] ),
			$html
		);

		// 只有 Outlook 看得到的條件註解：內容一樣要過濾，且不能提早結束註解
		$html = (string) \preg_replace_callback(
			'/<!--\[if ([a-zA-Z0-9 |&!()]+)\]>(.*?)<!\[endif\]-->/s',
			static function ( array $match ) use ( $hold ): string {
				$inner = self::sanitize_outlook_content( $match[2] );
				if ( \str_contains( $inner, '-->' ) || \str_contains( $inner, '--!>' ) ) {
					return '';
				}
				return $hold( "<!--[if {$match[1]}]>{$inner}<![endif]-->" );
			},
			$html
		);

		// 其他註解在信件裡沒有用途，kses 又會把內容改成看得到的文字
		$html = (string) \preg_replace( '/<!--.*?(-->|$)/s', '', $html );

		$html = (string) \preg_replace_callback(
			'/<style\b([^>]*)>(.*?)<\/style\s*>/is',
			static fn( array $match ): string => self::is_safe_css( $match[2] ) ? $hold( self::style_open_tag( $match[1] ) . $match[2] . '</style>' ) : '',
			$html
		);

		$clean = self::kses( $html, $allowed );

		// 佔位字串跑進標籤內（例如 alt="..."）代表有人刻意構造：換回去會跳出屬性，直接移除
		foreach ( \array_keys( $tokens ) as $token ) {
			if ( \preg_match( '/<[^<>]*' . \preg_quote( $token, '/' ) . '/', $clean ) ) {
				$tokens[ $token ] = '';
			}
		}

		return \strtr( $clean, $tokens );
	}

	/**
	 * 過濾只有 Outlook 看得到的內容
	 *
	 * WordPress 的 kses 標籤名稱不接受冒號，v:rect 這類標籤要先換成 v-rect。
	 * 這些標籤只在這裡放行：一般內容不需要，也不應該出現。
	 *
	 * @param string $inner 條件註解內的 HTML（可能是不成對的標籤）
	 * @return string
	 */
	private static function sanitize_outlook_content( string $inner ): string {
		$renamed = (string) \preg_replace( '/<(\/?)(v|o):([a-zA-Z]+)/', '<$1$2-$3', $inner );
		$clean   = self::sanitize_fragment( $renamed, self::allowed_html() + self::outlook_allowed_html() );

		return (string) \preg_replace( '/<(\/?)(v|o)-([a-zA-Z]+)/', '<$1$2:$3', $clean );
	}

	/**
	 * 判斷 CSS 是否可以放行
	 *
	 * 反斜線可以用來隱藏關鍵字（\65xpression），信件的 CSS 用不到，一律不放行。
	 *
	 * @param string $css style 標籤的內容
	 * @return bool
	 */
	private static function is_safe_css( string $css ): bool {
		$css = (string) \preg_replace( '/\/\*.*?(\*\/|$)/s', '', $css );

		if ( \str_contains( $css, '<' ) || \str_contains( $css, '\\' ) ) {
			return false;
		}

		if ( \preg_match( '/expression\s*\(|behaviou?r\s*:|-moz-binding|(java|vb)script\s*:/i', $css ) ) {
			return false;
		}

		\preg_match_all( '/(?:url\(|@import)\s*[\'"]?\s*([a-z][a-z0-9+.-]*):/i', $css, $schemes );
		foreach ( $schemes[1] as $scheme ) {
			if ( ! \in_array( \strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
				return false;
			}
		}

		return true;
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
	 * 套用 kses，過濾期間暫時放行 MJML 需要的行內樣式
	 *
	 * @param string                              $html    HTML
	 * @param array<string, array<string, mixed>> $allowed 允許的標籤
	 * @return string
	 */
	private static function kses( string $html, array $allowed ): string {
		$add_properties = static fn( array $properties ): array => \array_merge( $properties, self::EXTRA_STYLE_PROPERTIES );

		\add_filter( 'safe_style_css', $add_properties );
		$clean = \wp_kses( $html, $allowed );
		\remove_filter( 'safe_style_css', $add_properties );

		// kses 每跑一次就換一種單引號編碼（' → &#039; → &apos;），部分信件軟體也不認得 &apos;。
		// 這裡的屬性值都由雙引號包住，還原成單引號不會跳出屬性。
		return (string) \preg_replace_callback(
			'/\sstyle="[^"]*"/',
			static fn( array $match ): string => \str_replace( [ '&#039;', '&apos;' ], "'", $match[0] ),
			$clean
		);
	}

	/**
	 * Email 文件允許的標籤：文章白名單 + 文件結構 + 版面屬性
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function allowed_html(): array {
		$allowed = \wp_kses_allowed_html( 'post' );

		// 其他外掛（包含本外掛的 Api\Course）可能透過 filter 替文章白名單加上這些標籤，信件不需要
		foreach ( [ 'iframe', 'script', 'object', 'embed', 'applet', 'frame', 'frameset', 'base', 'form' ] as $tag ) {
			unset( $allowed[ $tag ] );
		}

		$layout = [
			'align'               => true,
			'aria-roledescription' => true,
			'background'          => true,
			'bgcolor'             => true,
			'border'              => true,
			'cellpadding'         => true,
			'cellspacing'         => true,
			'class'               => true,
			'dir'                 => true,
			'height'              => true,
			'id'                  => true,
			'lang'                => true,
			'role'                => true,
			'style'               => true,
			'valign'              => true,
			'width'               => true,
		];
		foreach ( [ 'a', 'div', 'img', 'label', 'p', 'span', 'table', 'tbody', 'td', 'tr' ] as $tag ) {
			$allowed[ $tag ] = ( $allowed[ $tag ] ?? [] ) + $layout;
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
			'id'      => true,
			'lang'    => true,
			'dir'     => true,
			'style'   => true,
		];
		// http-equiv 限定值：refresh 可以把開信的人導去別的網址
		$allowed['meta'] = [
			'charset'    => true,
			'content'    => true,
			'http-equiv' => [ 'values' => [ 'content-type', 'x-ua-compatible' ] ],
			'name'       => true,
		];
		$allowed['link'] = [
			'href'  => true,
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
			'style'   => true,
			'type'    => [
				'required' => true,
				'values'   => [ 'checkbox', 'radio' ],
			],
		];

		return $allowed;
	}

	/**
	 * 只有 Outlook 條件註解內才允許的標籤（v:、o: 已換成連字號，見 sanitize_outlook_content）
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function outlook_allowed_html(): array {
		$vml = [
			'color'   => true,
			'fill'    => true,
			'inset'   => true,
			'origin'  => true,
			'position' => true,
			'src'     => true,
			'stroke'  => true,
			'style'   => true,
			'type'    => true,
			'xmlns:v' => true,
		];

		return [
			'noscript'                 => [],
			'xml'                      => [],
			'o-allowpng'               => [],
			'o-officedocumentsettings' => [],
			'o-pixelsperinch'          => [],
			'v-fill'                   => $vml,
			'v-image'                  => $vml,
			'v-rect'                   => $vml,
			'v-textbox'                => $vml,
		];
	}
}

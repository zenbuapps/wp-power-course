<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Templates;

use J7\PowerCourse\Plugin;

/**
 * Power Course 自產的前台 Tailwind / daisyUI CSS（Issue #285）
 *
 * 前台模板用的 class 原本全靠 Powerhouse 的 front.min.css，而那份 CSS 只在 Powerhouse
 * 建置時旁邊剛好有 power-course 才掃得到（3.6.0 就整批漏掉）。改由我們自己掃模板產出
 * （`pnpm run build:css:front`，設定見 `tailwind.config.front.cjs`），分成兩份：
 * front-components（daisyUI 元件）與 front-utilities（base + utility）。
 *
 * **載入順序是正確性的一部分**：兩份都是獨立的 Tailwind build，選擇器 specificity 與
 * Powerhouse 相同，同一條規則誰排後面誰贏。每次 build 內部保證「基底 class 在響應式 /
 * 狀態變體之前」，兩次 build 串接後，排在後面那份的 `flex-col` 會蓋掉前面那份的
 * `xl:flex-row`——不論怎麼交錯排，總有一方的順序被打破（PR #286 審查 B2）。所以依
 * Powerhouse 那份是否完整決定排法：
 *
 * - 完整（常態）→ 整份排在 `powerhouse_front` **之前**：Powerhouse 會依正確順序把每條規則
 *   重新宣告一次，結果與沒有這份 CSS 時完全相同；Powerhouse 沒有的少數規則（版本錯位時
 *   新模板用的 class）由我們補上
 * - 不完整（3.6.0 這類建置事故）→ 元件排在之前、utility 排在之後：Power Course 元素上的
 *   utility 不會被 Powerhouse 的基底 class 蓋回；代價是姊妹外掛的響應式 class 可能失效，
 *   但那時它們本來就缺規則
 *
 * 全站載入：短碼可以放在任何頁面。
 */
final class UtilityStyle {

	/** 前台元件（daisyUI）CSS 的 handle */
	public const COMPONENT_HANDLE = 'power-course-front-components';

	/** 前台 base + utility CSS 的 handle */
	public const UTILITY_HANDLE = 'power-course-front-utilities';

	/** Powerhouse 前台 CSS 的 handle（見 Powerhouse `Bootstrap::enqueue_frontend_assets()`） */
	public const POWERHOUSE_HANDLE = 'powerhouse_front';

	/** 載入模式：兩份都排在 powerhouse_front 之前（Powerhouse 完整時） */
	public const MODE_BEFORE = 'before';

	/** 載入模式：元件在 powerhouse_front 之前、utility 在之後（Powerhouse 不完整時） */
	public const MODE_SANDWICH = 'sandwich';

	/** 覆蓋率快取的 option name */
	public const COVERAGE_OPTION = 'power_course_powerhouse_front_css_coverage';

	/**
	 * 我們的選擇器在 Powerhouse 那份裡缺漏超過這個比例，就視為不完整
	 *
	 * 實測：3.6.1 缺 0%、3.5.5 缺 0.1%（版本錯位，新模板多一個 class）、3.6.0 缺 58.7%
	 */
	public const MAX_MISSING_RATIO = 0.05;

	/** 自產 CSS 檔（handle => 相對於外掛根目錄的路徑），陣列順序就是輸出順序 */
	public const FILES = [
		self::COMPONENT_HANDLE => '/inc/assets/dist/css/front-components.min.css',
		self::UTILITY_HANDLE   => '/inc/assets/dist/css/front-utilities.min.css',
	];

	/**
	 * 註冊並依載入模式安排兩份 CSS 的位置
	 *
	 * 「排在 powerhouse_front 之前」的做法是把自己加進它的相依：WP 一定先印相依再印本身，
	 * 不受兩邊 `wp_enqueue_scripts` priority 先後影響（Powerhouse 在 -100 註冊，我們在 -10 執行）。
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$handles = self::register_files();
		if ( [] === $handles ) {
			return;
		}

		$powerhouse = \wp_styles()->query( self::POWERHOUSE_HANDLE, 'registered' );
		// 沒有 Powerhouse 就沒有順序問題，直接載入（也不可宣告相依：WP 遇到未註冊的相依會連本身都不印）
		if ( ! ( $powerhouse instanceof \_WP_Dependency ) ) {
			foreach ( $handles as $handle ) {
				\wp_enqueue_style( $handle );
			}
			return;
		}

		if ( self::MODE_BEFORE === self::get_mode() ) {
			self::add_deps( $powerhouse, $handles );
			return;
		}

		if ( in_array( self::COMPONENT_HANDLE, $handles, true ) ) {
			self::add_deps( $powerhouse, [ self::COMPONENT_HANDLE ] );
		}
		$utility = \wp_styles()->query( self::UTILITY_HANDLE, 'registered' );
		if ( $utility instanceof \_WP_Dependency ) {
			self::add_deps( $utility, [ self::POWERHOUSE_HANDLE ] );
			\wp_enqueue_style( self::UTILITY_HANDLE );
		}
	}

	/**
	 * 目前的載入模式
	 *
	 * 讀不到 Powerhouse 的 CSS 檔（無法判斷）時當成不完整：寧可讓姊妹外掛的響應式 class
	 * 可能失效，也不讓 Power Course 自己的頁面壞掉。
	 *
	 * @return string self::MODE_BEFORE | self::MODE_SANDWICH
	 */
	public static function get_mode(): string {
		$coverage = self::get_coverage();
		$mode     = ( null !== $coverage && $coverage['total'] > 0 && $coverage['missing'] / $coverage['total'] <= self::MAX_MISSING_RATIO )
		? self::MODE_BEFORE
		: self::MODE_SANDWICH;

		/**
		 * 覆寫前台自產 CSS 的載入模式
		 *
		 * @param string                                $mode     'before'（排在 powerhouse_front 之前）或 'sandwich'（元件在前、utility 在後）
		 * @param array{missing: int, total: int}|null $coverage 我們的選擇器在 Powerhouse CSS 裡的缺漏數；null = 讀不到 Powerhouse CSS
		 */
		$filtered = \apply_filters( 'power_course_front_utility_style_mode', $mode, $coverage );

		return in_array( $filtered, [ self::MODE_BEFORE, self::MODE_SANDWICH ], true ) ? $filtered : $mode;
	}

	/**
	 * 我們的選擇器有多少不在 Powerhouse 的 front.min.css 裡
	 *
	 * 結果以兩邊檔案的路徑 / mtime / 大小為 key 存在 option：只有 Powerhouse 或 Power Course
	 * 更新後的第一個前台請求會重算（讀約 180KB、比對約 700 個選擇器，實測 15ms 左右）。
	 *
	 * @return array{missing: int, total: int}|null 讀不到任一邊的檔案時為 null
	 */
	public static function get_coverage(): ?array {
		$powerhouse_path = self::get_powerhouse_css_path();
		if ( null === $powerhouse_path || ! is_readable( $powerhouse_path ) ) {
			return null;
		}

		$own_paths = [];
		foreach ( self::FILES as $relative_path ) {
			if ( is_readable( Plugin::$dir . $relative_path ) ) {
				$own_paths[] = Plugin::$dir . $relative_path;
			}
		}
		if ( [] === $own_paths ) {
			return null;
		}

		$fingerprint = [];
		foreach ( [ $powerhouse_path, ...$own_paths ] as $path ) {
			$fingerprint[] = [ $path, filemtime( $path ), filesize( $path ) ];
		}
		$key = md5( (string) \wp_json_encode( $fingerprint ) );

		$cached = \get_option( self::COVERAGE_OPTION );
		if ( is_array( $cached ) && ( $cached['key'] ?? null ) === $key && is_int( $cached['missing'] ?? null ) && is_int( $cached['total'] ?? null ) ) {
			return [
				'missing' => $cached['missing'],
				'total'   => $cached['total'],
			];
		}

		$selectors = [];
		foreach ( $own_paths as $path ) {
			$selectors = array_merge( $selectors, self::extract_selectors( (string) file_get_contents( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$selectors = array_values( array_unique( $selectors ) );

		$coverage = [
			'missing' => self::count_missing( (string) file_get_contents( $powerhouse_path ), $selectors ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'total'   => count( $selectors ),
		];
		\update_option( self::COVERAGE_OPTION, [ 'key' => $key ] + $coverage, true );

		return $coverage;
	}

	/**
	 * 抽出 minify 過的 CSS 裡所有的選擇器（不含 `@media` / `@layer` 這類 at-rule 本身）
	 *
	 * 選擇器清單以最外層的逗號拆開；`:is(a,b)` 括號內與跳脫過的 `\,` 不拆。
	 *
	 * @param string $css minify 過的 CSS
	 * @return string[]
	 */
	public static function extract_selectors( string $css ): array {
		// 選擇器清單一定緊接在 `{` / `}` / `;` / 檔頭之後、`{` 之前，且不含 `@`（at-rule 的條件不算）
		preg_match_all( '/(?:^|[{};])([^{}@;]+)\{/', $css, $matches );

		$selectors = [];
		foreach ( $matches[1] as $list ) {
			$depth  = 0;
			$start  = 0;
			$length = strlen( $list );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $list[ $i ];
				if ( '\\' === $char ) {
					++$i;
					continue;
				}
				if ( '(' === $char ) {
					++$depth;
				} elseif ( ')' === $char ) {
					--$depth;
				} elseif ( ',' === $char && 0 === $depth ) {
					$selectors[] = trim( substr( $list, $start, $i - $start ) );
					$start       = $i + 1;
				}
			}
			$selectors[] = trim( substr( $list, $start ) );
		}

		return array_values( array_unique( array_filter( $selectors, static fn( string $selector ): bool => '' !== $selector ) ) );
	}

	/**
	 * 計算有幾個選擇器不在指定的 CSS 裡
	 *
	 * 選擇器在 minify 過的 CSS 裡後面一定接 `{`（單獨一條）或 `,`（被合併進清單），
	 * 兩種都比對，才不會把 `#tw .flex` 誤認成 `#tw .flex-col` 的一部分。
	 *
	 * @param string   $css       要比對的 CSS（Powerhouse 的 front.min.css）
	 * @param string[] $selectors 選擇器
	 * @return int
	 */
	public static function count_missing( string $css, array $selectors ): int {
		$missing = 0;
		foreach ( $selectors as $selector ) {
			if ( ! str_contains( $css, $selector . '{' ) && ! str_contains( $css, $selector . ',' ) ) {
				++$missing;
			}
		}
		return $missing;
	}

	/**
	 * Powerhouse 前台 CSS 的檔案路徑
	 *
	 * @return string|null Powerhouse 沒啟用時為 null
	 */
	private static function get_powerhouse_css_path(): ?string {
		$path = class_exists( \J7\Powerhouse\Plugin::class ) ? \J7\Powerhouse\Plugin::$dir . '/js/dist/css/front.min.css' : null;

		/**
		 * 覆寫用來判斷完整度的 Powerhouse 前台 CSS 檔案路徑（例如 Powerhouse 改由 CDN 提供、本機檔案另有位置）
		 *
		 * @param string|null $path 檔案路徑
		 */
		$path = \apply_filters( 'power_course_powerhouse_front_css_path', $path );

		return is_string( $path ) ? $path : null;
	}

	/**
	 * 註冊有建置產物的 CSS 檔
	 *
	 * @return string[] 註冊成功的 handle（依輸出順序）
	 */
	private static function register_files(): array {
		$handles = [];
		foreach ( self::FILES as $handle => $relative_path ) {
			$path = Plugin::$dir . $relative_path;
			// 沒 build 過（例如只跑了 vite build）就不載入，避免輸出 404 的 <link>
			if ( ! file_exists( $path ) ) {
				continue;
			}
			\wp_register_style( $handle, Plugin::$url . $relative_path, [], (string) filemtime( $path ) );
			$handles[] = $handle;
		}
		return $handles;
	}

	/**
	 * 把 handle 依序加進某個 style 的相依（已存在的不重複加）
	 *
	 * @param \_WP_Dependency $style   要加相依的 style
	 * @param string[]        $handles 相依的 handle
	 * @return void
	 */
	private static function add_deps( \_WP_Dependency $style, array $handles ): void {
		foreach ( $handles as $handle ) {
			if ( ! in_array( $handle, $style->deps, true ) ) {
				$style->deps[] = $handle;
			}
		}
	}
}

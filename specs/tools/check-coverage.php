#!/usr/bin/env php
<?php
/**
 * Spec Coverage Checker（power-course 版）
 *
 * 解析 inc/classes/ 下所有 Api.php 的 $apis 陣列（例如 Resources/Chapter/Core/Api.php），
 * 掃描 specs/features/ 下的 Gherkin .feature 檔，
 * 驗證每個啟用的 REST endpoint 的資源領域都有對應的 feature 覆蓋。
 *
 * 判定規則（endpoint 被視為有覆蓋）：
 *   從 endpoint 萃取資源關鍵字（如 chapters → chapter），
 *   只要 specs/features/ 下有目錄名稱命中該關鍵字，即視為覆蓋。
 *
 *   範例：
 *     chapters, chapters/(?P<id>\d+), toggle-finish-chapters/(?P<id>\d+)
 *       → 關鍵字 chapter / chapters
 *       → 命中 specs/features/chapter/*.feature
 *
 *   備註：本專案 .feature 文件使用自然語言步驟（非 URL），故以目錄名稱為匹配基準，
 *         HTTP method 僅供報告顯示，不作為覆蓋判定條件。
 *
 * Usage:
 *   php specs/tools/check-coverage.php              # 完整報告
 *   php specs/tools/check-coverage.php --missing    # 只印缺失
 *   php specs/tools/check-coverage.php --json       # JSON 輸出
 *   php specs/tools/check-coverage.php --strict     # 有缺失時 exit 1
 *
 * Exit codes:
 *   0 = 全部覆蓋 或 非 strict 模式
 *   1 = --strict 且發現缺失
 *   2 = 解析錯誤（例如找不到目錄）
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$project_root = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
$api_scan_dir = $project_root . '/inc/classes';
$features_dir = $project_root . '/specs/features';
$rest_version = 'v2'; // WordPress REST API 版本前綴（由 ApiBase 自動附加於 $namespace 後）

/*
* endpoint 第一段 → feature 目錄/檔名關鍵字別名表
*
* 用於處理「動詞型」或「抽象資源名」的 endpoint，讓匹配不因命名差異漏判。
* 如果 endpoint 第一段（或去 s 後）命中此表，會額外把別名加入搜尋關鍵字。
*
* 規則：endpoint 基底（小寫、去尾 s）=> 額外要比對的 feature 目錄關鍵字陣列
*/
$endpoint_aliases = [
	'option'    => [ 'setting', 'settings' ], // Api/Option.php 的 options 歸在 settings/
	'duplicate' => [ 'course' ],                // courses 複製功能，feature 在 course/ 目錄
	'upload'    => [ 'media' ],                 // 檔案上傳，feature 在 media/ 目錄
];

$opts = [
	'missing_only' => in_array('--missing', $argv, true),
	'json'         => in_array('--json', $argv, true),
	'strict'       => in_array('--strict', $argv, true),
];

if (!is_dir($api_scan_dir)) {
	fwrite(STDERR, "Error: api scan dir not found: {$api_scan_dir}\n");
	exit(2);
}
if (!is_dir($features_dir)) {
	fwrite(STDERR, "Error: features dir not found: {$features_dir}\n");
	exit(2);
}

// ---------------------------------------------------------------------------
// Step 1: 找出所有宣告 $apis 的 API 類別檔案
// ---------------------------------------------------------------------------

/**
 * 遞迴搜尋目錄下所有 PHP 檔案，找出同時宣告 `protected $apis` 與 `protected $namespace` 的檔案。
 * 不限檔名——因為本專案的 API 類別分布在多處（Api/Course.php、Resources/Chapter/Core/Api.php 等）。
 *
 * @param string $dir
 * @return string[]
 */
function find_api_files( string $dir ): array {
	$files = [];
	$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if (!$f->isFile() || $f->getExtension() !== 'php') {
			continue;
		}
		$content = file_get_contents($f->getPathname());
		if ($content === false) {
			continue;
		}
		// 快速字串過濾：必須同時宣告 $apis 與 $namespace 才視為 API 類別
		if (strpos($content, 'protected $apis') === false) {
			continue;
		}
		if (strpos($content, 'protected $namespace') === false) {
			continue;
		}
		$files[] = $f->getPathname();
	}
	sort($files);
	return $files;
}

// ---------------------------------------------------------------------------
// Step 2: 解析 $apis 陣列
// ---------------------------------------------------------------------------

/**
 * 擷取某個 API 類別檔案中啟用的 endpoint 清單，以及該類別的 $namespace 值。
 * - 自動略過整行註解（// 開頭）與 /* ... *\/ 多行註解
 * - 回傳結構：['namespace' => string, 'endpoints' => array]
 *
 * @param string $file
 * @return array{namespace:string,endpoints:array<int,array{endpoint:string,method:string,line:int}>}
 */
function parse_apis_array( string $file ): array {
	$raw = file_get_contents($file);
	if ($raw === false) {
		return [
			'namespace' => '',
			'endpoints' => [],
		];
	}

	// 移除 /* ... */ 多行註解
	$raw = preg_replace('/\/\*.*?\*\//s', '', $raw) ?? $raw;

	// 逐行過濾整行註解
	$lines    = explode("\n", $raw);
	$cleaned  = [];
	$line_map = []; // index in cleaned → original line number
	foreach ($lines as $i => $line) {
		if (preg_match('/^\s*\/\//', $line)) {
			continue;
		}
		$cleaned[]  = $line;
		$line_map[] = $i + 1;
	}
	$clean_code = implode("\n", $cleaned);

	// 抽 protected $namespace = '...';（單/雙引號皆可）
	$namespace = '';
	if (preg_match('/protected\s+\$namespace\s*=\s*[\'"]([^\'"]+)[\'"]/', $clean_code, $ns_m)) {
		$namespace = $ns_m[1];
	}

	// 抓取 $apis 陣列區塊（到第一個頂層 ];\n 為止）
	if (!preg_match('/\$apis\s*=\s*\[(.*?)\];/s', $clean_code, $m, PREG_OFFSET_CAPTURE)) {
		return [
			'namespace' => $namespace,
			'endpoints' => [],
		];
	}
	$apis_body    = $m[1][0];
	$apis_offset  = $m[1][1]; // 在 clean_code 中的 offset

	// 從 body 擷取所有 'endpoint' + 相鄰 'method' 對
	// 假設 entry 結構：'endpoint' => '...', ... 'method' => '...',
	$pattern = "/'endpoint'\s*=>\s*'([^']+)'(?:(?!'endpoint').)*?'method'\s*=>\s*'([^']+)'/s";
	preg_match_all($pattern, $apis_body, $matches, PREG_OFFSET_CAPTURE);

	$endpoints = [];
	foreach ($matches[0] as $idx => $match) {
		$endpoint = $matches[1][ $idx ][0];
		$method   = strtoupper($matches[2][ $idx ][0]);

		// 計算回原檔行號
		$body_offset  = $match[1];
		$clean_offset = $apis_offset + $body_offset;
		$clean_line   = substr_count(substr($clean_code, 0, $clean_offset), "\n");
		$orig_line    = $line_map[ $clean_line ] ?? ( $clean_line + 1 );

		$endpoints[] = [
			'endpoint' => $endpoint,
			'method'   => $method,
			'line'     => $orig_line,
		];
	}

	return [
		'namespace' => $namespace,
		'endpoints' => $endpoints,
	];
}

// ---------------------------------------------------------------------------
// Step 3: 讀取所有 feature 檔
// ---------------------------------------------------------------------------

/**
 * @param string $dir
 * @return array<string,string> path => content
 */
function load_feature_files( string $dir ): array {
	$out = [];
	$it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if ($f->isFile() && str_ends_with($f->getFilename(), '.feature')) {
			$content = file_get_contents($f->getPathname());
			if ($content !== false) {
				$out[ $f->getPathname() ] = $content;
			}
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Step 4: 比對覆蓋
// ---------------------------------------------------------------------------

/**
 * 剝除 endpoint 中的 regex capture group，取得可搜尋的 base path。
 *   posts/(?P<id>\d+)                                → posts
 *   posts/(?P<id>\d+)/field/(?P<field_name>[a-z_]+)  → posts/{id}/field
 *
 * @param string $endpoint
 * @return string
 */
function normalize_endpoint( string $endpoint ): string {
	// 把每個 (?P<xxx>...) 替換為 {xxx}
	$normalized = preg_replace('/\(\?P<(\w+)>[^)]+\)/', '{$1}', $endpoint);
	return $normalized ?? $endpoint;
}

/**
 * 從 endpoint 萃取資源關鍵字（用來比對 feature 目錄名）。
 *
 * 範例：
 *   chapters                              → ['chapters', 'chapter']
 *   chapters/(?P<id>\d+)                  → ['chapters', 'chapter']
 *   toggle-finish-chapters/(?P<id>\d+)    → ['chapters', 'chapter']
 *   reports/revenue                       → ['reports', 'report']
 *   students/export-all                   → ['students', 'student']
 *   duplicate/(?P<id>\d+)（走別名表）     → ['duplicate', 'course']
 *
 * @param string                 $endpoint
 * @param array<string,string[]> $aliases endpoint 基底 → 額外關鍵字別名表
 * @return string[]
 */
function build_search_keys( string $endpoint, array $aliases = [] ): array {
	// 剝除 regex capture group：(?P<id>\d+) → ''
	$clean = preg_replace('/\(\?P<\w+>[^)]+\)/', '', $endpoint) ?? $endpoint;
	$clean = trim($clean, '/');

	// 取第一個路徑段
	$first = explode('/', $clean)[0]; // e.g. "chapters" 或 "toggle-finish-chapters"

	// 用 hyphen 分割，取最後一個有意義的名詞段
	$parts = array_values(array_filter(explode('-', $first), 'strlen'));
	if ($parts === []) {
		return [];
	}
	$noun     = strtolower(end($parts)); // "chapters" from "toggle-finish-chapters"
	$singular = rtrim($noun, 's');       // "chapter"

	$keys = [ $noun, $singular ];

	// 別名查表：以複數或單數形式命中即可
	foreach ([ $noun, $singular ] as $lookup) {
		if ($lookup !== '' && isset($aliases[ $lookup ])) {
			foreach ($aliases[ $lookup ] as $alias) {
				$keys[] = strtolower($alias);
			}
		}
	}

	return array_values(array_unique(array_filter($keys)));
}

/**
 * 判斷 endpoint 是否被某個 feature 檔覆蓋。
 *
 * 覆蓋條件：feature 檔路徑中任一目錄段命中資源關鍵字
 *   e.g. specs/features/chapter/建立章節.feature 命中關鍵字 "chapter"
 *
 * 備註：本專案 .feature 文件使用自然語言步驟（不含 API URL），
 *       因此改以目錄路徑為匹配基準，HTTP method 不參與判定。
 *
 * @param string   $feature_path
 * @param string[] $search_keys
 * @return bool
 */
function feature_covers( string $feature_path, array $search_keys ): bool {
	$path = str_replace('\\', '/', strtolower($feature_path));
	foreach ($search_keys as $key) {
		if ($key === '') {
			continue;
		}
		// 比對目錄段：/chapter/ 或 /chapters/
		if (strpos($path, "/{$key}/") !== false) {
			return true;
		}
	}
	return false;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$api_files = find_api_files($api_scan_dir);
if (empty($api_files)) {
	fwrite(STDERR, "No Api.php files found under {$api_scan_dir}\n");
	exit(2);
}

$features = load_feature_files($features_dir);
if (empty($features)) {
	fwrite(STDERR, "No .feature files found under {$features_dir}\n");
	exit(2);
}

$endpoints = []; // 所有啟用的 endpoint
$empty_api_files = []; // $apis 為空陣列的檔案（通常是在 constructor 手動註冊路由，腳本無法抓到）
foreach ($api_files as $file) {
	$rel    = str_replace('\\', '/', $file);
	$rel    = str_replace($project_root . '/', '', $rel);
	$parsed = parse_apis_array($file);
	$ns     = $parsed['namespace'] !== '' ? $parsed['namespace'] : 'power-course';
	if ($parsed['endpoints'] === []) {
		$empty_api_files[] = $rel;
		continue;
	}
	foreach ($parsed['endpoints'] as $ep) {
		$endpoints[] = $ep + [
			'file'      => $rel,
			'namespace' => $ns,
		];
	}
}

$covered = [];
$missing = [];

foreach ($endpoints as $ep) {
	$keys = build_search_keys($ep['endpoint'], $endpoint_aliases);
	$hits = [];
	foreach ($features as $path => $_content) {
		if (feature_covers($path, $keys)) {
			$rel_path = str_replace('\\', '/', $path);
			$rel_path = str_replace($project_root . '/', '', $rel_path);
			$hits[]   = $rel_path;
		}
	}
	if (!empty($hits)) {
		$covered[] = $ep + [ 'features' => $hits ];
	} else {
		$missing[] = $ep;
	}
}

// ---------------------------------------------------------------------------
// 輸出
// ---------------------------------------------------------------------------

$total = count($endpoints);
$cov   = count($covered);
$mis   = count($missing);
$rate  = $total > 0 ? round($cov / $total * 100, 1) : 0;

if ($opts['json']) {
	echo json_encode(
		[
			'summary' => [
				'total'    => $total,
				'covered'  => $cov,
				'missing'  => $mis,
				'rate_pct' => $rate,
			],
			'covered' => $covered,
			'missing' => $missing,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . "\n";
} else {
	echo "# Spec 覆蓋率報告\n\n";
	echo "## 總覽\n";
	echo '- API 類別檔案數：' . count($api_files) . "\n";
	echo '- Feature 檔案數：' . count($features) . "\n";
	echo "- 啟用的 endpoint 數：{$total}\n";
	echo "- 覆蓋：{$cov}\n";
	echo "- 缺失：{$mis}\n";
	echo "- 覆蓋率：{$rate}%\n\n";

	if ($empty_api_files !== []) {
		echo "## 警告：以下檔案 \$apis 為空陣列（可能在 constructor 手動註冊路由，腳本無法解析）\n";
		foreach ($empty_api_files as $ef) {
			echo "- `{$ef}`\n";
		}
		echo "\n";
	}

	if ($mis > 0) {
		echo '## 缺失的 endpoint (' . $mis . ")\n";
		foreach ($missing as $m) {
			$norm = normalize_endpoint($m['endpoint']);
			echo "- `[{$m['method']}] /{$m['namespace']}/{$rest_version}/{$norm}`\n";
			echo "  - 來源：`{$m['file']}:{$m['line']}`\n";
		}
		echo "\n";
	}

	if (!$opts['missing_only']) {
		echo '## 覆蓋的 endpoint (' . $cov . ")\n";
		foreach ($covered as $c) {
			$norm = normalize_endpoint($c['endpoint']);
			echo "- `[{$c['method']}] /{$c['namespace']}/{$rest_version}/{$norm}`\n";
			echo "  - 來源：`{$c['file']}:{$c['line']}`\n";
			$shown = array_slice($c['features'], 0, 3);
			foreach ($shown as $h) {
				echo "  - feature：`{$h}`\n";
			}
			if (count($c['features']) > 3) {
				$more = count($c['features']) - 3;
				echo "  - ...還有 {$more} 個\n";
			}
		}
	}
}

exit($opts['strict'] && $mis > 0 ? 1 : 0);

# Power Course - 專案概覽

## 用途
WordPress 課程外掛（LMS），讓站長可以在 WordPress + WooCommerce 環境中銷售與管理線上課程。

## 技術棧
- **後端**: PHP 8.0+, WordPress, WooCommerce, Powerhouse Plugin, PSR-4 autoload
- **前端**: React 18, TypeScript 5.5, Refine.dev 4.x, Ant Design 5.x, Vite, TanStack Query 4.x
- **狀態管理**: Jotai (atomic state), TanStack Query (server state)
- **視頻播放**: VidStack, hls.js, Bunny CDN, Vimeo, YouTube
- **郵件系統**: j7-easy-email (MJML-based email editor)
- **測試**: Playwright E2E (admin/frontend/integration), PHPUnit (PHP unit tests)
- **程式碼品質**: ESLint + Prettier (TS), PHPCS + PHPStan level 9 (PHP)

## 核心命名空間
- PHP: `J7\PowerCourse`
- PHP autoload 路徑: `inc/classes/`, `inc/src/`

## 必要依賴外掛
- WooCommerce >= 7.6.0
- Powerhouse >= 3.3.41 (提供 `J7\WpUtils` 工具庫)

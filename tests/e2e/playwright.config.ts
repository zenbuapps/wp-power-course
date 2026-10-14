import { defineConfig, devices } from '@playwright/test'
import * as dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.resolve(__dirname, '../../.env') })
const STORAGE_STATE = path.join(__dirname, '.auth', 'admin.json')

/** 是否在 CI 環境（字串比對：.env 寫 `CI=false`，而 "false" 在 JS 是 truthy）*/
const IS_CI = 'true' === process.env.CI

export default defineConfig({
	testDir: '.',
	fullyParallel: false,
	// CI 判定一律走 IS_CI，不要直接 `process.env.CI ? A : B`——所有 truthy 判斷
	// 都會把本機當成 CI。實際後果：admin-page.ts 的 SPA_LOAD_TIMEOUT 一直取
	// CI 的 30 秒而非本機的 45 秒，慢站台上 page.goto 逾時被誤讀成功能問題。
	forbidOnly: IS_CI,
	// 本機刻意保留 1 次重試：同一組 41 支測試實測 26.7～38.2 分鐘，機器負載
	// 變異大，單次逾時不足以判定回歸。重試後仍紅才算真紅，flaky 數量本身
	// 列入關卡觀察，不當成通過。
	retries: 1,
	workers: 1,
	reporter: IS_CI
		? [['github'], ['html', { open: 'never' }]]
		: [['list'], ['html', { open: 'on-failure' }]],

	// 本機站（Local + 大量外掛）的 wp-admin 冷載入實測 2～27 秒，
	// 舊值 30s / 10s / 15s 會讓 Admin SPA spec 隨機紅（Refine 的全頁 Spin 遮罩擋住點擊、
	// page.goto 逾時），紅燈原因與受測功能無關。放寬為實測上限的約兩倍。
	timeout: 90_000,
	expect: { timeout: 10_000 },

	globalSetup: './global-setup.ts',
	globalTeardown: './global-teardown.ts',

	use: {
		headless: IS_CI,
		baseURL: process.env.TEST_SITE_URL || 'http://localhost:8889',
		ignoreHTTPSErrors: true,
		storageState: STORAGE_STATE,
		locale: 'zh-TW',
		timezoneId: 'Asia/Taipei',
		trace: 'on-first-retry',
		screenshot: process.env.PW_FORCE_VIDEO === '1' ? 'on' : 'only-on-failure',
		video: process.env.PW_FORCE_VIDEO === '1' ? 'on' : 'retain-on-failure',
		// 點擊等的是「元素可互動」，而 Refine 的 Spin 遮罩在慢站台上可能蓋住
		// 目標超過 30 秒。實測頁面載入本身就要 2～60 秒，點擊容錯給 30 秒
		// 明顯不成比例——六輪最終關卡裡每一次 flaky 的死因都是
		// `locator.click: Timeout 30000ms exceeded`。放寬只影響「多久才放棄」，
		// 不改變任何斷言。
		actionTimeout: 60_000,
		navigationTimeout: 60_000,
	},

	projects: [
		{
			name: 'admin',
			testDir: './01-admin',
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1920, height: 1080 },
			},
		},
		{
			name: 'frontend',
			testDir: './02-frontend',
			use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
		},
		{
			name: 'integration',
			testDir: './03-integration',
			timeout: 120_000,
			use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
		},
	],
})

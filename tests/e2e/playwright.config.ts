import { defineConfig, devices } from '@playwright/test'
import * as dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.resolve(__dirname, '../../.env') })
const STORAGE_STATE = path.join(__dirname, '.auth', 'admin.json')

export default defineConfig({
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI
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
		headless: process.env.CI === 'true',
		baseURL: process.env.TEST_SITE_URL || 'http://localhost:8889',
		ignoreHTTPSErrors: true,
		storageState: STORAGE_STATE,
		locale: 'zh-TW',
		timezoneId: 'Asia/Taipei',
		trace: 'on-first-retry',
		screenshot: process.env.PW_FORCE_VIDEO === '1' ? 'on' : 'only-on-failure',
		video: process.env.PW_FORCE_VIDEO === '1' ? 'on' : 'retain-on-failure',
		actionTimeout: 30_000,
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

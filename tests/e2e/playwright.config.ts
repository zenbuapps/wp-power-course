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

	timeout: 30_000,
	expect: { timeout: 5_000 },

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
		actionTimeout: 10_000,
		navigationTimeout: 15_000,
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

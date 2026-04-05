/**
 * E2E 測試資料常數
 *
 * 所有測試共用的資料定義。帳密從環境變數讀取，其餘為固定常數。
 */

/** WordPress Admin 帳密 */
export const WP_ADMIN = {
	username: process.env.TEST_USERNAME || 'admin',
	password: process.env.TEST_PASSWORD || 'password',
}

/** 測試用學員帳號 */
export const TEST_STUDENT = {
	username: 'e2e_student',
	password: 'e2e_student_pass',
	email: 'e2e_student@test.local',
	firstName: '測試',
	lastName: '學員',
	displayName: '測試學員',
}

/** 測試用講師帳號 */
export const TEST_TEACHER = {
	username: 'e2e_teacher',
	password: 'e2e_teacher_pass',
	email: 'e2e_teacher@test.local',
	firstName: '測試',
	lastName: '講師',
	displayName: '測試講師',
}

/** 測試課程資料 */
export const TEST_COURSE = {
	name: 'E2E 測試課程',
	regularPrice: '1000',
	salePrice: '800',
	description: '這是一堂 E2E 測試用的課程，請勿刪除。',
	shortDescription: 'E2E 測試課程簡介',
}

/** 測試課程（免費） */
export const TEST_FREE_COURSE = {
	name: 'E2E 免費測試課程',
	regularPrice: '0',
	description: '免費的 E2E 測試課程',
}

/** 測試章節資料 */
export const TEST_CHAPTERS = {
	chapter1: {
		title: '第一章 課程介紹',
	},
	chapter2: {
		title: '第二章 進階內容',
	},
	subChapter1: {
		title: '1-1 基礎觀念',
	},
	subChapter2: {
		title: '1-2 實作練習',
	},
}

/** 測試銷售方案資料 */
export const TEST_BUNDLE_PRODUCT = {
	name: 'E2E 測試銷售方案',
	regularPrice: '2000',
	salePrice: '1500',
}

/** Email 模板資料 */
export const TEST_EMAIL_TEMPLATE = {
	subject: 'E2E 測試郵件 - 課程開通通知',
	trigger: 'course_granted',
}

/** WooCommerce 結帳資料 */
export const CHECKOUT_DATA = {
	firstName: '測試',
	lastName: '買家',
	address: '台北市中正區忠孝東路一段1號',
	city: '台北市',
	postcode: '100',
	phone: '0912345678',
	email: 'e2e_buyer@test.local',
}

/** 常用 URL 路徑 */
export const URLS = {
	adminDashboard: '/wp-admin/',
	adminCourses: '/wp-admin/admin.php?page=power-course#/courses',
	adminTeachers: '/wp-admin/admin.php?page=power-course#/teachers',
	adminStudents: '/wp-admin/admin.php?page=power-course#/students',
	adminProducts: '/wp-admin/admin.php?page=power-course#/products',
	adminEmails: '/wp-admin/admin.php?page=power-course#/emails',
	adminSettings: '/wp-admin/admin.php?page=power-course#/settings',
	adminAnalytics: '/wp-admin/admin.php?page=power-course#/analytics',
	adminMediaLibrary: '/wp-admin/admin.php?page=power-course#/media-library',
	adminBunnyMedia:
		'/wp-admin/admin.php?page=power-course#/bunny-media-library',
	adminShortcodes: '/wp-admin/admin.php?page=power-course#/shortcodes',
	wcMyAccount: '/my-account/',
	shop: '/shop/',
	cart: '/cart/',
	checkout: '/checkout/',
}

/** API 端點 */
export const API_ENDPOINTS = {
	courses: 'power-course/courses',
	chapters: 'power-course/chapters',
	users: 'power-course/users',
	options: 'power-course/options',
	upload: 'power-course/upload',
	reports: 'power-course/reports/revenue/stats',
}

/** 前台測試用課程資料（含 slug） */
export const FRONTEND_COURSE = {
	name: 'E2E Frontend Test Course',
	slug: 'e2e-frontend-test-course',
	regularPrice: '1500',
	chapters: [
		{ name: 'E2E Chapter 1 Intro', slug: 'e2e-chapter-1' },
		{ name: 'E2E Chapter 2 Core', slug: 'e2e-chapter-2' },
		{ name: 'E2E Chapter 3 Practice', slug: 'e2e-chapter-3' },
	],
}

/** 測試用訂閱者帳號（無課程存取權限） */
export const TEST_SUBSCRIBER = {
	username: 'e2e_subscriber',
	password: 'e2e_subscriber_pass',
	email: 'e2e_subscriber@test.local',
}

/** 前台 CSS 選擇器 */
export const SELECTORS = {
	// 課程銷售頁
	courseProduct: {
		featureVideo: '#courses-product__feature-video',
		featureContent: '#courses-product__feature-content',
		pricing: '#course-pricing',
		tabsNav: '#courses-product__tabs-nav',
		priceHtml: '.pc-price-html',
		ctaButton: '.pc-add-to-cart-link',
		addToCartWrapper: '.pc-add-to-cart',
		addToCartButton: 'button.add_to_cart_button',
		tabNavItem: '[id^="tab-nav-"]',
		btnPrimary: '.pc-btn-primary',
		badge: '.pc-badge',
	},
	// 章節折疊列表（銷售頁＆教室共用）
	chapterCollapse: {
		container: '#pc-sider__main-chapters',
		list: '.pc-sider-chapters',
		item: '#pc-sider__main-chapters li',
		itemTitle: '#pc-sider__main-chapters li span',
		arrow: '.icon-arrow',
	},
	// 教室頁面
	classroom: {
		header: '#pc-classroom-header',
		chapterTitle: '#classroom-chapter_title',
		titleBadge: '#classroom-chapter_title-badge',
		finishButton: '#finish-chapter__button',
		finishDialog: '#finish-chapter__dialog',
		sider: '#pc-sider',
		mainChapters: '#pc-sider__main-chapters',
		body: '#pc-classroom-body',
		chapterList: '.pc-sider-chapters',
		chapterItem: '#pc-sider__main-chapters li',
	},
	// My Account
	myAccount: {
		courseCard: '.pc-course-card',
		courseImage: '.pc-course-card__image',
		courseName: '.pc-course-card__name',
		courseTeachers: '.pc-course-card__teachers',
	},
	// 404 存取拒絕
	accessDenied: {
		alert: '.pc-alert',
		alertError: '.pc-alert.pc-alert-error',
		alertMessage: '.pc-alert span',
		buyButton: 'a:has-text("前往購買")',
	},
}

/** Timeout 常數 */
export const TIMEOUTS = {
	spaLoad: 15_000,
	apiResponse: 10_000,
	fileUpload: 30_000,
	pageNavigation: 15_000,
	wpEnvStart: 120_000,
}

declare global {
	var wpApiSettings: {
		root: string
		nonce: string
	}
	var appData: {
		env: {
			SITE_URL: string
			API_URL: string
			CURRENT_USER_ID: number | false
			CURRENT_POST_ID: number
			PERMALINK: string
			APP_NAME: string
			KEBAB: string
			SNAKE: string
			BUNNY_LIBRARY_ID: string
			BUNNY_CDN_HOSTNAME: string
			BUNNY_STREAM_API_KEY: string
			NONCE: string
			APP1_SELECTOR: string
			APP2_SELECTOR: string
			ELEMENTOR_ENABLED: boolean
			COURSE_PERMALINK_STRUCTURE: string
		}
	}
	var wp: {
		blocks: any
	}
}

export {}

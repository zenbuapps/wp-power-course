/** @type {import("eslint").Linter.Config} */
module.exports = {
	root: true,
	env: {
		node: true,
		browser: true,
		es2021: true,
		commonjs: true,
	},
	parser: '@typescript-eslint/parser',
	extends: [
		'plugin:@typescript-eslint/recommended',
		'plugin:@wordpress/eslint-plugin/custom',
		'plugin:@wordpress/eslint-plugin/esnext',
		'plugin:@wordpress/eslint-plugin/jsdoc',
		'plugin:react/recommended',
		'plugin:react-hooks/recommended',
		'plugin:jsx-a11y/recommended',
		'plugin:import/recommended',
		'plugin:import/typescript',
		'eslint-config-prettier',
		'prettier',
		'plugin:prettier/recommended',
	],
	parserOptions: {
		ecmaVersion: 'latest',
		sourceType: 'module',
		ecmaFeatures: {
			jsx: true,
		},
		project: './tsconfig.json',
	},
	plugins: [
		'react',
		'react-hooks',
		'@typescript-eslint',
		'jsx-a11y',
		'import',
		'prettier',
	],
	rules: {
		'quote-props': 'off',
		'jsdoc/check-param-names': 'off',
		'jsdoc/require-param': 'off',
		'jsdoc/require-param-type': 'off',
		'jsdoc/require-param-name': 'off',
		'jsdoc/require-param-description': 'off',
		'jsdoc/valid-types': 'off',
		'jsdoc/require-returns-type': 'off',
		'jsdoc/require-returns-description': 'off',
		'jsdoc/no-undefined-types': 'off',
		'@typescript-eslint/no-explicit-any': 'warn',
		'@wordpress/no-unused-vars-before-return': 'off',
		'@typescript-eslint/ban-types': 'off',
		'@typescript-eslint/interface-name-prefix': 'off',
		'@typescript-eslint/explicit-function-return-type': 'off',
		'@typescript-eslint/no-shadow': 'error',
		'@typescript-eslint/ban-ts-comment': 'off',
		'@typescript-eslint/no-unused-vars': [
			'warn',
			{
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
			},
		],
		'react/prop-types': 'off',
		'react/react-in-jsx-scope': 'off',
		'react/jsx-uses-react': 'off',
		'react/jsx-uses-vars': 'error',
		'react-hooks/rules-of-hooks': 'error',
		'react-hooks/exhaustive-deps': 'warn',
		'import/order': [
			'error',
			{
				groups: [
					'builtin',
					'external',
					'internal',
					'parent',
					'sibling',
					'index',
				],
				// tsconfig.json 把 @wordpress/i18n 的型別導向 js/src/shims/wordpress-i18n.ts
				// （讓 tsc 檢查的對象與 runtime 實際執行的 shim 一致），
				// 但 resolver 會因此把它誤判成 internal 而重排所有 import。
				// 它終究是 npm 套件，這裡明確歸回 external。
				pathGroups: [
					{
						pattern: '@wordpress/**',
						group: 'external',
					},
				],
				pathGroupsExcludedImportTypes: [],
				'newlines-between': 'always',
				alphabetize: {
					order: 'asc',
					caseInsensitive: true,
				},
			},
		],
		'import/no-unresolved': 'off',
		'import/extensions': [
			'error',
			'ignorePackages',
			{
				js: 'never',
				jsx: 'never',
				ts: 'never',
				tsx: 'never',
			},
		],
		semi: ['error', 'never'],
		// avoidEscape 對齊 Prettier 的 singleQuote 行為：字串本身含單引號時
		// （如 "the current user's email"）允許改用雙引號，避免無謂的轉義，
		// 否則 ESLint 與 Prettier 會互相打架、lint 永遠是紅的。
		quotes: [
			'error',
			'single',
			{ avoidEscape: true },
		],
		'no-console': ['warn'],
		'no-debugger': 'error',
		'array-callback-return': 'off',
		'no-duplicate-imports': 'error',
		'linebreak-style': 'off',
		'no-unused-vars': 'off',
		'no-shadow': 'error',
		camelcase: 'off',
		'prefer-const': 'error',
		'no-var': 'error',
		'lines-around-comment': 'off',
		'jsx-a11y/click-events-have-key-events': 'warn',
		'jsx-a11y/no-static-element-interactions': 'warn',
		'jsx-a11y/no-noninteractive-element-interactions': 'warn',
		'prettier/prettier': [
			'error',
			{
				endOfLine: 'auto',
				useTabs: true,
				tabWidth: 2,
				semi: false,
				singleQuote: true,
				trailingComma: 'es5',
				'prettier-multiline-arrays-set-threshold': 1,
			},
		],
	},
	overrides: [
		{
			files: ['*.d.ts'],
			rules: {
				'no-undef': 'off',
				'no-var': 'off',
			},
		},
		{
			files: ['*.js', '*.jsx'],
			rules: {
				'@typescript-eslint/no-var-requires': 'off',
			},
		},
		{
			files: ['*.tsx', '*.jsx'],
			rules: {
				'react/prop-types': 'off',
				'@typescript-eslint/no-unsafe-assignment': 'off',
				'@typescript-eslint/no-unsafe-return': 'off',
				'@typescript-eslint/restrict-template-expressions': 'off',
				'@typescript-eslint/no-unsafe-call': 'off',
				'@typescript-eslint/no-unsafe-member-access': 'off',
			},
		},
	],
	globals: {
		JSX: 'readonly',
		window: 'readonly',
		React: 'readonly',
		document: 'readonly',
		wpApiSettings: 'readonly',
		process: 'readonly',
		__dirname: 'readonly',
		__filename: 'readonly',
	},
	settings: {
		react: {
			version: 'detect',
		},
		'import/resolver': {
			typescript: {
				alwaysTryTypes: true,
				project: './tsconfig.json',
			},
			node: {
				extensions: ['.js', '.jsx', '.ts', '.tsx'],
			},
		},
	},
}

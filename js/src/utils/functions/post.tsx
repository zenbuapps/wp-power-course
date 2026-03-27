export const getPostStatus = (status: string) => {
	switch (status) {
		case 'pending':
			return {
				label: '待審閱',
				color: 'volcano',
			}
		case 'draft':
			return {
				label: '草稿',
				color: 'orange',
			}
		case 'publish':
			return {
				label: '已發佈',
				color: 'blue',
			}

		default:
			return {
				label: status,
				color: 'default',
			}
	}
}

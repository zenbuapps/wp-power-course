export const getASStatus = (status: string) => {
	switch (status) {
		case 'pending':
			return {
				label: '已排程',
				color: 'volcano',
			}
		case 'complete':
			return {
				label: '已完成',
				color: '#87d068',
			}
		default:
			return {
				label: status,
				color: 'default',
			}
	}
}

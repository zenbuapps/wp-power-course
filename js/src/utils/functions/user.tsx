/**
 * 取得最大公約數
 * @param items
 * @param key
 */
export function getGCDItems<T>(items: T[][], key = 'id'): T[] {
	if (items.length === 0) return []

	// sort by items length asc
	const sortedItems = items.sort((a, b) => a.length - b.length)
	if (sortedItems[0].length === 0) return []
	const firstItemIds = sortedItems?.[0]?.map((item) => item?.[key as keyof T])

	const gcdIds: string[] = []
	firstItemIds.forEach((id) => {
		if (
			sortedItems.every((item) =>
				item.some((course) => course?.[key as keyof T] === id)
			)
		) {
			gcdIds.push(id as string)
		}
	})
	const gcdItems = gcdIds
		.map((id) => {
			return sortedItems[0].find((item) => item?.[key as keyof T] === id)
		})
		.filter((item) => item !== undefined)

	return gcdItems
}

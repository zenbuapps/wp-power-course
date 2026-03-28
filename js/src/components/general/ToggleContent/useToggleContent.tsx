import { useState } from 'react'

export const useToggleContent = (
	isExpandDefault = false,
	showReadMoreDefault = false
) => {
	const [isExpand, setIsExpand] = useState(isExpandDefault)
	const [showReadMore, setShowReadMore] = useState(showReadMoreDefault)

	const toggleContentProps = {
		isExpand,
		setIsExpand,
		showReadMore,
		setShowReadMore,
	}

	return { toggleContentProps }
}

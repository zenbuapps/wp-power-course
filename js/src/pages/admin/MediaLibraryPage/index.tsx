import { MediaLibrary, TAttachment, TImage } from 'antd-toolkit/wp'
import React, { useState } from 'react'

const MediaLibraryPage = () => {
	const [selectedItems, setSelectedItems] = useState<(TAttachment | TImage)[]>(
		[]
	)
	return (
		<MediaLibrary
			selectedItems={selectedItems}
			setSelectedItems={setSelectedItems}
			limit={undefined}
		/>
	)
}

export default MediaLibraryPage

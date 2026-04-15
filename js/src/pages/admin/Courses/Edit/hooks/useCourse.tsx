import { createContext, useContext } from 'react'

import { TCourseRecord } from '@/pages/admin/Courses/List/types'

export const RecordContext = createContext<TCourseRecord | undefined>(undefined)

export const useRecord = () => {
	const record = useContext(RecordContext)

	return record
}

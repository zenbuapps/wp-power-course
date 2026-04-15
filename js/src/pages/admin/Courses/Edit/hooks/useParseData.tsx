import { createContext, useContext } from 'react'

import { TCourseRecord } from '@/pages/admin/Courses/List/types'

type TParseData = (values: Partial<TCourseRecord>) => Partial<TCourseRecord>

export const ParseDataContext = createContext<TParseData | undefined>(undefined)

export const useParseData = () => {
	const parseData = useContext<TParseData | undefined>(ParseDataContext)

	return parseData
}

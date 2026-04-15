import { Card } from 'antd'
import React from 'react'

import Courses from './Courses'
import MyCourses from './MyCourses'

const General = () => {
	return (
		<Card>
			<Courses />
			<MyCourses />
		</Card>
	)
}

export default General

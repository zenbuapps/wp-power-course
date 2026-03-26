import React from 'react'

import { UserTable } from '@/components/user'

const Students = () => {
	return (
		<>
			<UserTable canGrantCourseAccess={true} />
		</>
	)
}

export default Students

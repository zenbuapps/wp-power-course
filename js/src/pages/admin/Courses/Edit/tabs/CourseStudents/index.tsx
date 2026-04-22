import { __ } from '@wordpress/i18n'
import { Alert } from 'antd'
import React, { memo } from 'react'

import { UserTable } from '@/components/user/UserTable'

import StudentTable from './StudentTable'

const CourseStudentsComponent = () => {
	return (
		<>
			<div className="mb-4">
				<div className="max-w-[30rem] mb-4">
					<Alert
						message={__('Notes', 'power-course')}
						description={
							<ol className="pl-4">
								<li>
									{__(
										'Changes here take effect immediately, no save required',
										'power-course'
									)}
								</li>
								<li>
									{__(
										'Cannot find the user? They may already be enrolled in this course',
										'power-course'
									)}
								</li>
							</ol>
						}
						type="warning"
						showIcon
					/>
				</div>
				<UserTable mode="course-exclude" />
			</div>
			<StudentTable />
		</>
	)
}

export const CourseStudents = memo(CourseStudentsComponent)

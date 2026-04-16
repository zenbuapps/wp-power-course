import { __ } from '@wordpress/i18n'
import { Alert } from 'antd'
import React, { memo } from 'react'

import StudentTable from './StudentTable'
import UserSelector from './UserSelector'

const CourseStudentsComponent = () => {
	return (
		<>
			<div className="mb-4">
				<div className="max-w-[30rem]">
					<Alert
						className="mb-4"
						message={__('Notes', 'power-course')}
						description={
							<ol className="pl-4">
								<li>
									{__(
										'Search by keyword to find users (up to 30 results per query)',
										'power-course'
									)}
								</li>
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
					<UserSelector />
				</div>
			</div>
			<StudentTable />
		</>
	)
}

export const CourseStudents = memo(CourseStudentsComponent)

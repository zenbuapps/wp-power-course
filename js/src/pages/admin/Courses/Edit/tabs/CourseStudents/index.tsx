import { ArrowsAltOutlined } from '@ant-design/icons'
import { useModal } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import { Alert, Button, Modal } from 'antd'
import { useAtom } from 'jotai'
import React, { memo } from 'react'

import {
	UserTable,
	selectedUserIdsAtom,
	SelectedUser,
} from '@/components/user/UserTable'

import StudentTable from './StudentTable'

const CourseStudentsComponent = () => {
	const { show, modalProps } = useModal()
	const [selectedUserIds, setSelectedUserIds] = useAtom(selectedUserIdsAtom)

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
				<div className="flex gap-x-2 items-center">
					<Button
						onClick={show}
						icon={<ArrowsAltOutlined />}
						iconPosition="end"
					>
						{__('Select students to add', 'power-course')}
					</Button>
					<SelectedUser
						user_ids={selectedUserIds}
						onClear={() => setSelectedUserIds([])}
					/>
				</div>
			</div>
			<StudentTable />
			<Modal
				{...modalProps}
				title={__('Select students', 'power-course')}
				width={1600}
				footer={null}
				centered
			>
				<UserTable
					mode="course-exclude"
					cardProps={{ showCard: false }}
					tableProps={{
						scroll: { y: 420 },
					}}
				/>
			</Modal>
		</>
	)
}

export const CourseStudents = memo(CourseStudentsComponent)

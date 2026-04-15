import { ExportOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import React, { useState, memo } from 'react'

import { GrantCourseAccess } from '@/components/user'
import { useEnv } from '@/hooks'

const AddOtherCourse = ({ user_ids }: { user_ids: string[] }) => {
	const { SITE_URL } = useEnv()
	const [show, setShow] = useState(false)
	return (
		<div className="mb-4 -mt-2">
			<p>
				<span
					onClick={() => setShow(true)}
					className="cursor-pointer text-primary m-0"
				>
					{__('Add other courses', 'power-course')}
				</span>{' '}
				{__('or', 'power-course')}{' '}
				<a
					href={`${SITE_URL}/wp-admin/admin.php?page=power-course#/students`}
					target="_blank"
					className="cursor-pointer text-primary"
					rel="noreferrer"
				>
					{__('Go to student management', 'power-course')}
					<ExportOutlined className="ml-1" />
				</a>
			</p>
			{show && <GrantCourseAccess user_ids={user_ids as string[]} />}
		</div>
	)
}

export default memo(AddOtherCourse)

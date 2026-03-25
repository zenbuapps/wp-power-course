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
						message="注意事項"
						description={
							<ol className="pl-4">
								<li>請搜尋關鍵字尋找用戶 (每次最多顯示 30 筆結果) </li>
								<li>此處的變更立即生效，不需要點按儲存</li>
								<li>搜尋不到想找的用戶? 有可能他已經在學員列表內了!</li>
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

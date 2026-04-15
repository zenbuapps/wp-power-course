import { List } from '@refinedev/antd'

import Table from '@/pages/admin/Courses/List/Table'

// TODO  有空把 Item.*hidden 簡化一下

const CourseList = () => {
	return (
		<List title="">
			<Table />
		</List>
	)
}

export default CourseList

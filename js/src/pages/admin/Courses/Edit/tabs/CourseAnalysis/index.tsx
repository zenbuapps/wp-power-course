import AnalyticsPage from '@/pages/admin/Analytics'
import { useRecord } from '@/pages/admin/Courses/Edit/hooks'

export const CourseAnalysis = () => {
	const course = useRecord()

	if (!course) return null

	return (
		<div className="py-8">
			<AnalyticsPage
				context="detail"
				initialQuery={{ product_includes: [course.id] }}
			/>
		</div>
	)
}

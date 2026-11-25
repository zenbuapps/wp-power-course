import { useApiUrl } from '@refinedev/core'
import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Button, Alert, Tag } from 'antd'
import { memo } from 'react'
import { PiMicrosoftExcelLogoFill } from 'react-icons/pi'

import courseIdImg from '@/assets/images/course-id-in-list.jpg'
import { FileUpload } from '@/components/general'
import { useEnv } from '@/hooks'

const CsvUpload = () => {
	const { SITE_URL } = useEnv()
	const apiUrl = useApiUrl('power-course')
	return (
		<div className="flex flex-col md:flex-row gap-8 py-8">
			<div className="w-full">
				<Alert
					message={__('How to import', 'power-course')}
					description={
						<>
							<ol className="pl-4">
								<li>
									{__(
										'Fill in the sample file and save it as CSV.',
										'power-course'
									)}
									<Button
										type="link"
										className="pl-0 ml-0"
										icon={<PiMicrosoftExcelLogoFill />}
										iconPosition="end"
										href={`${SITE_URL}/wp-content/plugins/power-course/sample.csv`}
										download="sample.csv"
									>
										{__('Download sample CSV file', 'power-course')}
									</Button>
									<ul className="pl-4 my-1 list-disc">
										<li>
											<Tag>email</Tag>
											{__(
												'Student email. New emails get an account and a set-password email.',
												'power-course'
											)}
										</li>
										<li>
											<Tag>course_id</Tag>
											{__(
												'The #number next to the course name in the course list',
												'power-course'
											)}{' '}
											<a
												href={courseIdImg}
												target="_blank"
												rel="noopener noreferrer"
											>
												{__('See screenshot', 'power-course')}
											</a>
										</li>
										<li>
											<Tag>expire_date</Tag>
											{__('Expiry date:', 'power-course')}
										</li>
									</ul>
									<table className="my-2 table table-xs table-border-y text-xs [&_td]:text-left">
										<thead>
											<tr>
												<th className="w-2/5">
													{__('Enter this', 'power-course')}
												</th>
												<th className="w-3/5">
													{__('Meaning', 'power-course')}
												</th>
											</tr>
										</thead>
										<tbody>
											<tr>
												<td>0</td>
												<td>{__('Forever', 'power-course')}</td>
											</tr>
											<tr>
												<td>2025-01-01</td>
												<td>{__('Until 2025-01-01 00:00', 'power-course')}</td>
											</tr>
											<tr>
												<td>2025-01-01 20:00</td>
												<td>{__('Until 2025-01-01 20:00', 'power-course')}</td>
											</tr>
											<tr>
												<td>subscription_123</td>
												<td>
													{__(
														'Follows subscription #123; stops when it ends',
														'power-course'
													)}
												</td>
											</tr>
										</tbody>
									</table>
								</li>
								<li>
									{createInterpolateElement(
										__(
											'Choose the file to start importing in the background. The admin gets an email when done. <a>Check progress</a>',
											'power-course'
										),
										{
											// 連結文字由 createInterpolateElement 從翻譯字串填入
											a: (
												// eslint-disable-next-line jsx-a11y/anchor-has-content
												<a
													target="_blank"
													rel="noopener noreferrer"
													href={`${SITE_URL}/wp-admin/admin.php?page=wc-status&tab=action-scheduler&s=pc_batch_add_students_task`}
												/>
											),
										}
									)}
								</li>
							</ol>
							<div className="text-red-500">
								{__(
									'※ A mistyped date (e.g. 2025.1.1) means forever, and imports cannot be undone. Test with 2–3 rows first.',
									'power-course'
								)}
							</div>
						</>
					}
					type="info"
					showIcon
				/>

				<FileUpload
					uploadProps={{
						className: 'mt-4 tw-block w-full [&_.ant-upload]:w-full',
						action: `${apiUrl}/users/upload-students`,
					}}
					buttonProps={{
						danger: true,
						type: 'primary',
						className: '!w-full',
						children: __(
							'Upload CSV file (please backup database first)',
							'power-course'
						),
					}}
				/>
			</div>
		</div>
	)
}

export default memo(CsvUpload)

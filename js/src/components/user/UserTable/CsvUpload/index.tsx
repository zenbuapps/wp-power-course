import { useApiUrl } from '@refinedev/core'
import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Button, Alert, Tag } from 'antd'
import { memo } from 'react'
import { PiMicrosoftExcelLogoFill } from 'react-icons/pi'

import { FileUpload } from '@/components/general'
import { useEnv } from '@/hooks'

const CsvUpload = () => {
	const { SITE_URL } = useEnv()
	const apiUrl = useApiUrl('power-course')
	return (
		<div className="flex flex-col md:flex-row gap-8 py-8">
			<div className="w-full">
				<Alert
					message={__('Read this before importing', 'power-course')}
					description={
						<ol className="pl-4">
							<li>
								{__(
									'Download the sample file first, open it in Excel or Google Sheets, fill it in following the same format, and save it as CSV.',
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
							</li>
							<li>
								{__(
									'Each row means "add this person to this course" and has 3 columns:',
									'power-course'
								)}
								<ul className="pl-4 my-1 list-disc">
									<li>
										<Tag>email</Tag>
										{__(
											'The student\'s email. If the site has no account with this email yet, one will be created automatically and a "set your password" email will be sent to them.',
											'power-course'
										)}
									</li>
									<li>
										<Tag>course_id</Tag>
										{__(
											'The course ID. In the Power Course > Courses list, the #number next to the course name is the course ID (for #1234, enter 1234).',
											'power-course'
										)}
									</li>
									<li>
										<Tag>expire_date</Tag>
										{__('When access expires. You can enter:', 'power-course')}
									</li>
								</ul>
								<table className="my-2 table table-xs table-border-y text-xs [&_td]:text-left">
									<thead>
										<tr>
											<th className="w-2/5">
												{__('Enter this', 'power-course')}
											</th>
											<th className="w-3/5">{__('Meaning', 'power-course')}</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td>0</td>
											<td>{__('Can watch forever', 'power-course')}</td>
										</tr>
										<tr>
											<td>2025-01-01</td>
											<td>
												{__(
													'Can watch until 2025-01-01 00:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>2025-01-01 20:00</td>
											<td>
												{__(
													'Can watch until 2025-01-01 20:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>subscription_123</td>
											<td>
												{__(
													'Follows the subscription: access stops when the subscription stops (replace 123 with the subscription number, shown as #number in the WooCommerce > Subscriptions list)',
													'power-course'
												)}
											</td>
										</tr>
									</tbody>
								</table>
								<div className="text-red-500">
									⚠️{' '}
									{__(
										'If the date cannot be recognized (for example "test" or "2025.1.1"), it is treated as "can watch forever". Always write dates as year-month-day, like 2025-01-01.',
										'power-course'
									)}
								</div>
							</li>
							<li>
								{__(
									'The import starts as soon as you choose the file. 5000+ rows are fine: the system processes them in batches in the background, so you can close this window.',
									'power-course'
								)}
							</li>
							<li>
								{createInterpolateElement(
									__(
										'When the import finishes, the site administrator receives an email. To follow along, <a>check import progress</a> (opens the WooCommerce scheduled actions page; a "Complete" status means that batch is done).',
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
							<li className="text-red-500">
								⚠️{' '}
								{__(
									'There is no "undo" after importing. Students added by mistake must be removed one by one. For large files, test with 2–3 rows first, then import the rest.',
									'power-course'
								)}
							</li>
						</ol>
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

import { useApiUrl } from '@refinedev/core'
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
					message={__('Batch upload notes', 'power-course')}
					description={
						<ol className="pl-4">
							<li>
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
								{__('The', 'power-course')} <Tag>expire_date</Tag>{' '}
								{__('column accepts the following values', 'power-course')}
								<table className="my-2 table table-xs table-border-y text-xs [&_td]:text-left">
									<thead>
										<tr>
											<th className="w-2/5">{__('Value', 'power-course')}</th>
											<th className="w-3/5">
												{__('Description', 'power-course')}
											</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td>0</td>
											<td>{__('Unlimited', 'power-course')}</td>
										</tr>
										<tr>
											<td>subscription_123</td>
											<td>
												{__('Bind to subscription id 123', 'power-course')}
											</td>
										</tr>
										<tr>
											<td>1735732800</td>
											<td>
												{__(
													'Specified expire date 2025-01-01 20:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>2025-01-01</td>
											<td>
												{__(
													'Specified expire date 2024-05-01 00:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>2025-01-01 20:00</td>
											<td>
												{__(
													'Specified expire date 2025-01-01 20:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>2025-01-01 20:00:00</td>
											<td>
												{__(
													'Specified expire date 2025-01-01 20:00:00',
													'power-course'
												)}
											</td>
										</tr>
										<tr>
											<td>
												{__('Invalid time format, e.g. test', 'power-course')}
											</td>
											<td>
												{__(
													'Will grant unlimited course access',
													'power-course'
												)}
											</td>
										</tr>
									</tbody>
								</table>
							</li>
							<li>
								{__(
									'Tested with over 5000 records, processed in batches without blocking the server',
									'power-course'
								)}
							</li>
							<li>
								{__(
									'When finished, an email will be sent to the administrator, or go to',
									'power-course'
								)}{' '}
								<a
									target="_blank"
									rel="noopener noreferrer"
									href={`${SITE_URL}/wp-admin/admin.php?page=wc-status&tab=action-scheduler&s=pc_batch_add_students_task`}
								>
									Action Scheduler
								</a>{' '}
								{__('to check', 'power-course')}
							</li>
							<li>
								{__(
									'After selecting the file, upload is scheduled immediately. During the process, users will be added as students (if not found, users will be created and password reset email sent). Rollback is not possible after upload, please backup database first.',
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

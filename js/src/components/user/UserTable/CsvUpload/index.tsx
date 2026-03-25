import { useApiUrl } from '@refinedev/core'
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
					message="批次上傳注意事項"
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
									下載範例 csv 檔案
								</Button>
							</li>
							<li>
								欄位 <Tag>expire_date</Tag> 可以輸入的值如下
								<table className="my-2 table table-xs table-border-y text-xs [&_td]:text-left">
									<thead>
										<tr>
											<th className="w-2/5">值</th>
											<th className="w-3/5">說明</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td>0</td>
											<td>無期限</td>
										</tr>
										<tr>
											<td>subscription_123</td>
											<td>綁定指定訂閱 id 123</td>
										</tr>
										<tr>
											<td>1735732800</td>
											<td>指定到期日 2025-01-01 20:00:00</td>
										</tr>
										<tr>
											<td>2025-01-01</td>
											<td>指定到期日 2024-05-01 00:00:00</td>
										</tr>
										<tr>
											<td>2025-01-01 20:00</td>
											<td>指定到期日 2025-01-01 20:00:00</td>
										</tr>
										<tr>
											<td>2025-01-01 20:00:00</td>
											<td>指定到期日 2025-01-01 20:00:00</td>
										</tr>
										<tr>
											<td>錯誤的時間格式，例如 test</td>
											<td>會以無期限開通課程</td>
										</tr>
									</tbody>
								</table>
							</li>
							<li>
								實測可上傳 5000
								筆資料以上等大型資料，會分批處理，不會造成伺服器阻塞
							</li>
							<li>
								處理完成後會寄信通知管理員，或者到{' '}
								<a
									target="_blank"
									rel="noopener noreferrer"
									href={`${SITE_URL}/wp-admin/admin.php?page=wc-status&tab=action-scheduler&s=pc_batch_add_students_task`}
								>
									Action Scheduler
								</a>{' '}
								查看
							</li>
							<li>
								選擇檔案後會立即排程上傳，過程中會新增用戶成為課程學員(如果找不到會創建用戶並發送密碼重設信件)，上傳後無法
								rollback，建議上傳前備份資料庫
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
						children: '上傳 CSV 檔案 (請先備份資料庫)',
					}}
				/>
			</div>
		</div>
	)
}

export default memo(CsvUpload)

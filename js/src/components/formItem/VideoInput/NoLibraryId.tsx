import { DoubleRightOutlined } from '@ant-design/icons'
import { Button, Alert } from 'antd'
import { Link } from 'react-router'

const NoLibraryId = ({
	bunny_library_id,
	bunny_stream_api_key,
	bunny_cdn_hostname,
	type = 'alert',
}: {
	bunny_library_id?: string
	bunny_stream_api_key?: string
	bunny_cdn_hostname?: string
	type?: 'default' | 'video' | 'alert'
}) => {
	if ('alert' === type) {
		return (
			<Alert
				message="缺少必要參數"
				description={
					<>
						{!bunny_library_id && (
							<div className="text-sm font-normal">缺少 Bunny Library Id</div>
						)}
						{!bunny_stream_api_key && (
							<div className="text-sm font-normal">
								缺少 Bunny Stream Api Key
							</div>
						)}
						{!bunny_cdn_hostname && (
							<div className="text-sm font-normal">缺少 Bunny Cdn Hostname</div>
						)}

						<Link to="/settings">
							<Button
								className="pl-0 ml-0"
								type="link"
								icon={<DoubleRightOutlined />}
								iconPosition="end"
							>
								前往設定
							</Button>
						</Link>
					</>
				}
				type="warning"
				showIcon
			/>
		)
	}

	const className = ((value: string) =>
		({
			video:
				'aspect-video shadow rounded-lg border border-dashed border-gray-300 flex flex-col items-center justify-center',
			default: 'flex flex-col items-start justify-center w-full h-full',
		})[value] ?? 'flex flex-col items-start justify-center w-full h-full')(type)

	return (
		<div className={className}>
			{!bunny_library_id && (
				<div className="text-base font-normal">缺少 Bunny Library Id</div>
			)}
			{!bunny_stream_api_key && (
				<div className="text-base font-normal">缺少 Bunny Stream Api Key</div>
			)}
			{!bunny_cdn_hostname && (
				<div className="text-base font-normal">缺少 Bunny Cdn Hostname</div>
			)}
			<Link to="/settings">
				<Button
					className="pl-0 ml-0"
					type="link"
					icon={<DoubleRightOutlined />}
					iconPosition="end"
				>
					前往設定
				</Button>
			</Link>
		</div>
	)
}

export default NoLibraryId

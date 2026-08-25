import { ArrowsAltOutlined } from '@ant-design/icons'
import { useModal } from '@refinedev/antd'
import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	HttpError,
} from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import {
	Alert,
	Button,
	Modal,
	Table,
	Tag,
	Popconfirm,
	Avatar,
	Space,
	message,
	TableProps,
} from 'antd'
import { useAtom } from 'jotai'
import { memo, useState } from 'react'

import {
	UserTable,
	selectedUserIdsAtom,
	SelectedUser,
} from '@/components/user/UserTable'
import {
	TAccessPassHolder,
	TAccessPassHoldersResponse,
	TLimitType,
} from '@/pages/admin/AccessPasses/types'

const PAGE_SIZE = 20

/**
 * 通行證持有學員 Tab（Issue #252 補完）
 *
 * 站主在這裡看誰持有這張通行證、剩多久，並直接發放 / 撤銷——
 * 在此之前唯一的發放方式是替學員建一張免費訂單。
 *
 * 撤銷只刪 pc_user_access_pass 的對應列，不會動到學員單獨購買 / 逐課綁定的課程
 * （觀看權是 OR 疊加，後端 `Repository::delete_by_user_pass` 有對應保證）。
 */
const PassHoldersComponent = ({
	passId,
	limitType,
}: {
	passId: number
	limitType?: TLimitType
}) => {
	const apiUrl = useApiUrl('power-course')
	const { show, modalProps, close } = useModal()
	const [selectedUserIds, setSelectedUserIds] = useAtom(selectedUserIdsAtom)
	const [page, setPage] = useState(1)

	const { data, isLoading, refetch } = useCustom<
		TAccessPassHoldersResponse,
		HttpError
	>({
		url: `${apiUrl}/access-passes/${passId}/users`,
		method: 'get',
		config: {
			query: {
				page,
				per_page: PAGE_SIZE,
			},
		},
		queryOptions: {
			enabled: !!passId,
		},
	})

	const holders = data?.data?.items ?? []
	const total = data?.data?.total ?? 0

	const { mutate: grant, isLoading: isGranting } = useCustomMutation()
	const { mutate: revoke, isLoading: isRevoking } = useCustomMutation()

	// 跟隨訂閱的通行證不能手動發放：到期表達式必須綁一張真實訂閱，
	// 手動發放沒有訂單可推導，發了會立刻失效（後端 Grant::assert_grantable 也會擋）
	const canGrantManually = 'follow_subscription' !== limitType

	const handleGrant = () => {
		if (!selectedUserIds.length) {
			return
		}

		grant(
			{
				url: `${apiUrl}/access-passes/${passId}/grant`,
				method: 'post',
				values: { user_ids: selectedUserIds },
			},
			{
				onSuccess: (response) => {
					const result = (response?.data ?? {}) as {
						granted_count?: number
						failed?: { user_id: number; message: string }[]
					}
					const failedCount = result.failed?.length ?? 0

					if (failedCount > 0) {
						message.warning({
							content: sprintf(
								/* translators: 1: 成功筆數, 2: 失敗筆數 */
								__('%1$d granted, %2$d failed', 'power-course'),
								result.granted_count ?? 0,
								failedCount
							),
							key: 'grant-access-pass',
						})
					} else {
						message.success({
							content: __('Access pass granted', 'power-course'),
							key: 'grant-access-pass',
						})
					}

					setSelectedUserIds([])
					close()
					refetch()
				},
				onError: () => {
					message.error({
						content: __('Failed to grant access pass', 'power-course'),
						key: 'grant-access-pass',
					})
				},
			}
		)
	}

	const handleRevoke = (userId: number) => {
		revoke(
			{
				url: `${apiUrl}/access-passes/${passId}/revoke`,
				method: 'post',
				values: { user_ids: [userId] },
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Access pass revoked', 'power-course'),
						key: 'revoke-access-pass',
					})
					refetch()
				},
				onError: () => {
					message.error({
						content: __('Failed to revoke access pass', 'power-course'),
						key: 'revoke-access-pass',
					})
				},
			}
		)
	}

	/** 期限欄：狀態 Tag + 人看得懂的到期說明 */
	const renderPeriod = (record: TAccessPassHolder) => {
		if (!record.is_valid) {
			return (
				<Space direction="vertical" size={0}>
					<Tag color="default">{__('Expired', 'power-course')}</Tag>
					{record.expire_date_human && (
						<span className="text-xs text-gray-400">
							{record.expire_date_human}
						</span>
					)}
				</Space>
			)
		}

		if ('unlimited' === record.limit_type) {
			return <Tag color="green">{__('Permanent', 'power-course')}</Tag>
		}

		if ('follow_subscription' === record.limit_type) {
			return (
				<Space direction="vertical" size={0}>
					<Tag color="blue">{__('Follows subscription', 'power-course')}</Tag>
					{record.subscription_id && (
						<span className="text-xs text-gray-400">
							#{record.subscription_id} {record.subscription_status}
						</span>
					)}
				</Space>
			)
		}

		return (
			<Space direction="vertical" size={0}>
				<Tag color={record.is_expiring_soon ? 'orange' : 'green'}>
					{sprintf(
						/* translators: %d: 剩餘天數 */
						__('%d days left', 'power-course'),
						record.days_remaining ?? 0
					)}
				</Tag>
				<span className="text-xs text-gray-400">
					{record.expire_date_human}
				</span>
			</Space>
		)
	}

	/** 來源欄：手動發放要看得出是誰發的，訂單要能追溯單號 */
	const renderSource = (record: TAccessPassHolder) => {
		if ('manual' === record.source) {
			return (
				<Space direction="vertical" size={0}>
					<Tag color="purple">{__('Granted manually', 'power-course')}</Tag>
					{record.granted_by_name && (
						<span className="text-xs text-gray-400">
							{record.granted_by_name}
						</span>
					)}
				</Space>
			)
		}

		if ('order' === record.source) {
			return (
				<Space direction="vertical" size={0}>
					<Tag color="cyan">{__('Order', 'power-course')}</Tag>
					{!!record.source_order_id && (
						<span className="text-xs text-gray-400">
							#{record.source_order_id}
						</span>
					)}
				</Space>
			)
		}

		return <Tag color="geekblue">{__('Subscription', 'power-course')}</Tag>
	}

	const columns: TableProps<TAccessPassHolder>['columns'] = [
		{
			title: __('Student', 'power-course'),
			dataIndex: 'display_name',
			render: (_value, record) => (
				<Space>
					<Avatar src={record.avatar_url} size="small" />
					<Space direction="vertical" size={0}>
						<span>{record.display_name || record.user_login}</span>
						<span className="text-xs text-gray-400">{record.user_email}</span>
					</Space>
				</Space>
			),
		},
		{
			title: __('Watch period', 'power-course'),
			dataIndex: 'expire_date',
			width: 180,
			render: (_value, record) => renderPeriod(record),
		},
		{
			title: __('Source', 'power-course'),
			dataIndex: 'source',
			width: 160,
			render: (_value, record) => renderSource(record),
		},
		{
			title: __('Granted at', 'power-course'),
			dataIndex: 'granted_at',
			width: 170,
			render: (value: string | null) => (
				<span className="text-xs text-gray-500">{value ?? '-'}</span>
			),
		},
		{
			title: __('Actions', 'power-course'),
			dataIndex: 'actions',
			width: 100,
			render: (_value, record) => (
				<Popconfirm
					title={__('Revoke this access pass?', 'power-course')}
					description={__(
						'The student loses access covered by this pass only. Courses they bought separately are not affected.',
						'power-course'
					)}
					okText={__('Confirm revoke', 'power-course')}
					cancelText={__('Cancel', 'power-course')}
					okButtonProps={{ danger: true, loading: isRevoking }}
					onConfirm={() => handleRevoke(record.user_id)}
				>
					<Button danger type="link" size="small">
						{__('Revoke', 'power-course')}
					</Button>
				</Popconfirm>
			),
		},
	]

	return (
		<>
			{!canGrantManually && (
				<Alert
					className="mb-4 max-w-[40rem]"
					type="info"
					showIcon
					message={__(
						'This access pass follows a subscription',
						'power-course'
					)}
					description={__(
						'Its expiration is tied to a real subscription, so it cannot be granted manually. Students get it by purchasing the subscription product.',
						'power-course'
					)}
				/>
			)}

			<div className="mb-4 flex gap-x-2 items-center">
				<Button
					onClick={show}
					icon={<ArrowsAltOutlined />}
					iconPosition="end"
					disabled={!canGrantManually}
				>
					{__('Select students to grant', 'power-course')}
				</Button>
				<SelectedUser
					user_ids={selectedUserIds}
					onClear={() => setSelectedUserIds([])}
				/>
			</div>

			<Table<TAccessPassHolder>
				rowKey="id"
				columns={columns}
				dataSource={holders}
				loading={isLoading}
				size="small"
				pagination={{
					current: page,
					pageSize: PAGE_SIZE,
					total,
					showSizeChanger: false,
					onChange: setPage,
				}}
			/>

			<Modal
				{...modalProps}
				title={__('Select students', 'power-course')}
				width={1600}
				centered
				okText={__('Grant access pass', 'power-course')}
				cancelText={__('Cancel', 'power-course')}
				okButtonProps={{
					loading: isGranting,
					disabled: !selectedUserIds.length,
				}}
				onOk={handleGrant}
			>
				<UserTable
					mode="global"
					cardProps={{ showCard: false }}
					tableProps={{
						scroll: { y: 420 },
					}}
				/>
			</Modal>
		</>
	)
}

export const PassHolders = memo(PassHoldersComponent)

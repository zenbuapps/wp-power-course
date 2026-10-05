/**
 * 作業討論串（Issue #131 第二波，DEC-W2-17）
 *
 * 放在繳交詳情抽屜最後一段，掛載即載入；外層以 key 綁 submission id，換一筆就重新掛載。
 * 讀取時後端會先把學員訊息標為已讀（DEC-W2-04），載入成功後由 `useSubmissionThread` 重新取得列表，
 * 讓「討論」徽章歸零。
 * 對應 `features/assignment/查詢作業討論串.feature`、`發送作業討論訊息.feature`。
 *
 * 訊息本文原樣儲存，一律以 React 文字節點呈現，不解析 HTML（R-W2-09）。
 */
import { SendOutlined, UserOutlined } from '@ant-design/icons'
import { _x, __ } from '@wordpress/i18n'
import { Alert, Avatar, Button, Input, Spin } from 'antd'
import {
	memo,
	useEffect,
	useMemo,
	useRef,
	useState,
	type KeyboardEvent,
} from 'react'

import { usePostThreadMessage, useSubmissionThread } from '../hooks'
import {
	MESSAGE_SENDER,
	type TAssignmentMessage,
	type TSubmissionThreadProps,
	type TThreadMessageProps,
} from '../types'

/** 訊息本文長度上限（後端同為 10000） */
const MESSAGE_MAX_LENGTH = 10000

/** 老師一方的強調色（antd 預設主色） */
const TEACHER_COLOR = '#1677ff'

/** 學員一方的頭像底色 */
const STUDENT_COLOR = '#94a3b8'

/**
 * 單則討論訊息：發送者、時間、已讀（只有己方訊息被對方讀過才顯示）與本文
 */
const ThreadMessage = ({ message }: TThreadMessageProps) => {
	const isTeacher = MESSAGE_SENDER.TEACHER === message.sender_type

	return (
		<div className="pc-assignment-thread-message flex gap-2">
			<Avatar
				size="small"
				icon={<UserOutlined />}
				style={{
					backgroundColor: isTeacher ? TEACHER_COLOR : STUDENT_COLOR,
					flexShrink: 0,
				}}
			/>
			<div className="min-w-0">
				<div className="flex items-center gap-2 text-xs text-gray-500">
					<span className="font-semibold">
						{isTeacher
							? _x('Teacher', 'assignment thread sender', 'power-course')
							: __('Student', 'power-course')}
					</span>
					{/* created_at 為站台時區 Y-m-d H:i:s，顯示到分鐘 */}
					<span>{message.created_at.slice(0, 16)}</span>
					{message.mine && !!message.read_at && (
						<span>
							{_x('Read', 'assignment message status', 'power-course')}
						</span>
					)}
				</div>
				<div
					className="mt-1 inline-block whitespace-pre-wrap rounded-lg px-3 py-2"
					style={{
						wordBreak: 'break-word',
						backgroundColor: isTeacher ? '#e6f4ff' : '#f5f5f5',
					}}
				>
					{message.body}
				</div>
			</div>
		</div>
	)
}

/**
 * 討論串元件
 */
const SubmissionThreadComponent = ({
	submissionId,
}: TSubmissionThreadProps) => {
	const { messages, isLoading, isError } = useSubmissionThread(submissionId)
	const { post, isPosting } = usePostThreadMessage()
	const [draft, setDraft] = useState('')
	// 這次開啟後送出的訊息：就地附加，不為了顯示自己剛送的訊息再打一次會標已讀的 GET
	const [sent, setSent] = useState<TAssignmentMessage[]>([])
	const listRef = useRef<HTMLDivElement>(null)

	const thread = useMemo(() => {
		const loadedIds = new Set(messages.map(({ id }) => id))

		return [...messages, ...sent.filter(({ id }) => !loadedIds.has(id))]
	}, [messages, sent])

	// 有新訊息時捲到最底
	useEffect(() => {
		const list = listRef.current
		if (list) {
			list.scrollTop = list.scrollHeight
		}
	}, [thread.length])

	/**
	 * 送出草稿：trim 後為空或正在送出時不送
	 */
	const handleSend = () => {
		const body = draft.trim()
		if (!body || isPosting) {
			return
		}

		post(submissionId, body, (message) => {
			setSent((prev) => [...prev, message])
			setDraft('')
		})
	}

	/**
	 * Enter 送出；輸入法選字時的 Enter 只是確認選字，不送出
	 *
	 * @param event 鍵盤事件
	 */
	const handlePressEnter = (event: KeyboardEvent<HTMLInputElement>) => {
		if (event.nativeEvent.isComposing) {
			return
		}

		handleSend()
	}

	return (
		<div className="pc-assignment-thread">
			<div className="mb-2 font-semibold">
				{__('Discussion thread', 'power-course')}
			</div>

			{isLoading && (
				<div className="flex items-center gap-2 text-gray-500">
					<Spin size="small" />
					{__('Loading discussion...', 'power-course')}
				</div>
			)}

			{!isLoading && isError && (
				<Alert
					type="error"
					showIcon
					message={__('Failed to load discussion', 'power-course')}
				/>
			)}

			{!isLoading && !isError && 0 === thread.length && (
				<div className="text-gray-500">
					{__('No messages yet', 'power-course')}
				</div>
			)}

			{thread.length > 0 && (
				<div
					ref={listRef}
					className="flex flex-col gap-3 overflow-y-auto"
					style={{ maxHeight: 320 }}
				>
					{thread.map((message) => (
						<ThreadMessage key={message.id} message={message} />
					))}
				</div>
			)}

			<div className="mt-3 flex gap-2">
				<Input
					value={draft}
					onChange={(event) => setDraft(event.target.value)}
					onPressEnter={handlePressEnter}
					placeholder={__('Type a message...', 'power-course')}
					maxLength={MESSAGE_MAX_LENGTH}
				/>
				<Button
					type="primary"
					icon={<SendOutlined />}
					loading={isPosting}
					disabled={!draft.trim()}
					onClick={handleSend}
				>
					{__('Send', 'power-course')}
				</Button>
			</div>
		</div>
	)
}

export const SubmissionThread = memo(SubmissionThreadComponent)

import {
	PlusCircleOutlined,
	UserAddOutlined,
	PlayCircleOutlined,
	CheckCircleOutlined,
	CheckCircleFilled,
	UserDeleteOutlined,
	UserSwitchOutlined,
	RocketOutlined,
	CloseCircleOutlined,
	FileDoneOutlined,
	ClockCircleOutlined,
} from '@ant-design/icons'
import { TimelineItemProps } from 'antd'

import { TriggerAt } from '@/components/emails/SendCondition/enum'

import { TimelineLogType, TimelineItemConfig } from '../types'

const timelineItemMapper: Record<TimelineLogType, TimelineItemConfig> = {
	[TriggerAt.COURSE_GRANTED]: {
		color: '#91caff',
		icon: <UserAddOutlined />,
	},
	[TriggerAt.COURSE_FINISH]: {
		color: '#0958d9',
		icon: <CheckCircleFilled />,
	},
	[TriggerAt.COURSE_LAUNCH]: {
		color: '#f759ab',
		icon: <RocketOutlined />,
	},
	[TriggerAt.CHAPTER_ENTER]: {
		color: '#4096ff',
		icon: <PlayCircleOutlined />,
	},
	[TriggerAt.CHAPTER_FINISH]: {
		color: '#0958d9',
		icon: <CheckCircleOutlined />,
	},
	[TriggerAt.ORDER_CREATED]: {
		color: '#91caff',
		icon: <PlusCircleOutlined />,
	},
	[TriggerAt.CHAPTER_UNFINISHED]: {
		color: '#ffa940',
		icon: <CloseCircleOutlined />,
	},
	[TriggerAt.COURSE_REMOVED]: {
		color: '#ff4d4f',
		icon: <UserDeleteOutlined />,
	},
	[TriggerAt.UPDATE_STUDENT]: {
		color: '#9254de',
		icon: <UserSwitchOutlined />,
	},
	// 作業類時機點（Issue #131）目前只用於 email trigger，後端尚未寫入學員歷程 log；
	// 但 TimelineLogType 是以 TriggerAt 全集推導的 Record，缺項會型別不合，
	// 故一併補上對應的顏色與 icon，未來若補寫 log 即可直接渲染。
	[TriggerAt.ASSIGNMENT_GRADED]: {
		color: '#13c2c2',
		icon: <FileDoneOutlined />,
	},
	[TriggerAt.ASSIGNMENT_DUE_SOON]: {
		color: '#faad14',
		icon: <ClockCircleOutlined />,
	},
}

export class TimelineItemAdapter {
	private static readonly mapper: Record<TimelineLogType, TimelineItemConfig> =
		timelineItemMapper

	constructor(
		public readonly log_type: TimelineLogType,
		public readonly title: string,
		public readonly created_at: string
	) {
		this.validateLogType(log_type)
	}

	public get itemProps(): TimelineItemProps {
		return {
			color: this.color,
			dot: this.icon,
			children: (
				<>
					<p className="mt-0 mb-1">{this.title}</p>
					<p className="my-0 text-xs text-gray-400">{this.created_at}</p>
				</>
			),
		}
	}

	get color(): string {
		return TimelineItemAdapter.mapper?.[this.log_type]?.color
	}

	get icon(): React.ReactNode {
		return TimelineItemAdapter.mapper?.[this.log_type]?.icon
	}

	private validateLogType(log_type: TimelineLogType): void {
		if (!TimelineItemAdapter.mapper?.[log_type]) {
			console.error(`Invalid timeline log_type: ${log_type}`)
		}
	}
}

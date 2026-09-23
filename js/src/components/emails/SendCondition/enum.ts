// eslint-disable-next-line no-shadow
export enum TriggerAt {
	FIELD_NAME = 'trigger_at',
	COURSE_GRANTED = 'course_granted',
	COURSE_FINISH = 'course_finish',
	COURSE_LAUNCH = 'course_launch',
	CHAPTER_ENTER = 'chapter_enter',
	CHAPTER_FINISH = 'chapter_finish',
	ASSIGNMENT_GRADED = 'assignment_graded',
	ASSIGNMENT_DUE_SOON = 'assignment_due_soon',
	ORDER_CREATED = 'order_created', // 目前 email 沒有這個 trigger
	CHAPTER_UNFINISHED = 'chapter_unfinished', // 目前 email 沒有這個 trigger
	COURSE_REMOVED = 'course_removed', // 目前 email 沒有這個 trigger
	UPDATE_STUDENT = 'update_student', // 目前 email 沒有這個 trigger
}

export type TTriggerAt = (typeof TriggerAt)[keyof typeof TriggerAt]

/**
 * 作業類觸發時機（Issue #131 DEC-11）
 *
 * 這兩個 trigger 的發信對象由作業本身（批改結果 / 截止時間）決定，
 * 不經過「章節 → 觸發條件 → 延遲寄送」那一串課程進度條件，
 * 故選到它們時整組條件欄位要隱藏。
 */
export const ASSIGNMENT_TRIGGER_AT_LIST: TTriggerAt[] = [
	TriggerAt.ASSIGNMENT_GRADED,
	TriggerAt.ASSIGNMENT_DUE_SOON,
]

/**
 * 判斷觸發時機是否為作業類 trigger
 *
 * @param triggerAt 表單內的 trigger_at 值（未載入時可能為 undefined）
 * @return 是否為作業類 trigger
 */
export function isAssignmentTriggerAt(
	triggerAt: TTriggerAt | undefined
): boolean {
	if (!triggerAt) {
		return false
	}
	return ASSIGNMENT_TRIGGER_AT_LIST.includes(triggerAt)
}

// eslint-disable-next-line no-shadow
export enum TriggerCondition {
	FIELD_NAME = 'trigger_condition',
	EACH = 'each',
	ALL = 'all',
	QUANTITY_GREATER_THAN = 'qty_greater_than',
}

// eslint-disable-next-line no-shadow
export enum SendingType {
	FIELD_NAME = 'type',
	NOW = 'send_now',
	LATER = 'send_later',
}

// eslint-disable-next-line no-shadow
export enum SendingUnit {
	FIELD_NAME = 'unit',
	DAY = 'day',
	HOUR = 'hour',
	MINUTE = 'minute',
}

// eslint-disable-next-line no-shadow
export enum TriggerAt {
	FIELD_NAME = 'trigger_at',
	COURSE_GRANTED = 'course_granted',
	COURSE_FINISH = 'course_finish',
	COURSE_LAUNCH = 'course_launch',
	CHAPTER_ENTER = 'chapter_enter',
	CHAPTER_FINISH = 'chapter_finish',
	ORDER_CREATED = 'order_created', // 目前 email 沒有這個 trigger
	CHAPTER_UNFINISHED = 'chapter_unfinished', // 目前 email 沒有這個 trigger
	COURSE_REMOVED = 'course_removed', // 目前 email 沒有這個 trigger
	UPDATE_STUDENT = 'update_student', // 目前 email 沒有這個 trigger
}

export type TTriggerAt = (typeof TriggerAt)[keyof typeof TriggerAt]

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

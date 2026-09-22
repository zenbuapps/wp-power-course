/**
 * 章節作業管理型別定義（Issue #131 P7）
 *
 * 欄位對齊後端 `J7\PowerCourse\Resources\Assignment\Model\Assignment::to_array()`
 * 與 specs/api/api.yml components.schemas.Assignment。
 */

/** 作業題型旗標（對應後端 postmeta 的 yes / no） */
export const YES_NO = {
	YES: 'yes',
	NO: 'no',
} as const

/** yes / no 旗標型別 */
export type TYesNo = (typeof YES_NO)[keyof typeof YES_NO]

/** 作業（REST 回應的單筆作業） */
export type TAssignment = {
	/** 作業 post ID */
	id: number
	/** 所屬章節 ID */
	chapter_id: number
	/** 所屬課程 ID */
	course_id: number
	/** 作業標題 */
	title: string
	/** 作業說明（HTML） */
	instructions: string
	/** 允許文字繳交 */
	allow_text: TYesNo
	/** 允許檔案繳交 */
	allow_file: TYesNo
	/** 文字必填 */
	required_text: TYesNo
	/** 檔案必填 */
	required_file: TYesNo
	/** 附件數量上限 */
	max_files: number
	/** 截止時間 10 位 timestamp，0 = 無截止 */
	due_at: number
	/** 同章節排序鍵 */
	menu_order: number
	/** 建立時間（站台時區） */
	created_at: string
	/** 更新時間（站台時區） */
	updated_at: string
}

/**
 * 新增 / 編輯作業表單的欄位值
 *
 * 題型旗標由 FiSwitch 的 normalize 轉成 yes / no；
 * 未被使用者碰過的 Switch 會維持 FiSwitch 的 boolean 預設值，
 * 故型別為 `TYesNo | boolean`，送出前一律過 `toYesNo()`。
 */
export type TAssignmentFormValues = {
	/** 作業標題 */
	title: string
	/** 允許文字繳交 */
	allow_text: TYesNo | boolean
	/** 文字必填 */
	required_text: TYesNo | boolean
	/** 允許檔案繳交 */
	allow_file: TYesNo | boolean
	/** 檔案必填 */
	required_file: TYesNo | boolean
	/** 附件數量上限 */
	max_files: number
	/** 截止時間 10 位 timestamp，DatePicker 清空時為 undefined */
	due_at?: number
	/** 所屬章節 ID */
	chapter_id: number
}

/** 建立 / 更新作業送出的 request body */
export type TAssignmentPayload = {
	/** 所屬章節 ID */
	chapter_id: number
	/** 作業標題 */
	title: string
	/** 作業說明（HTML） */
	instructions: string
	/** 允許文字繳交 */
	allow_text: TYesNo
	/** 允許檔案繳交 */
	allow_file: TYesNo
	/** 文字必填 */
	required_text: TYesNo
	/** 檔案必填 */
	required_file: TYesNo
	/** 附件數量上限 */
	max_files: number
	/** 截止時間 10 位 timestamp，0 = 無截止 */
	due_at: number
}

/**
 * 把表單的 Switch 值正規化為後端要的 yes / no
 *
 * @param value FiSwitch 可能吐出 boolean（未變更過）或 'yes' / 'no'（變更過）
 */
export const toYesNo = (value: TYesNo | boolean | undefined): TYesNo =>
	value === YES_NO.YES || true === value ? YES_NO.YES : YES_NO.NO

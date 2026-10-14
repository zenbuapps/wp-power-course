/**
 * Email 變數對照表：`{變數名稱}` → 給站長看的中文說明
 *
 * 由後端 `Replace\*::get_localized_schemas()` 產生，
 * 經 `power-email/emails/options` 端點回傳。
 */
export type TEmailSchema = Record<string, string>

/**
 * `power-email/emails/options` 端點的回應內容
 *
 * `assignment_schema` 為 Issue #131 P5 新增（作業信件可用變數）。
 */
export type TEmailOptions = {
	/** 是否啟用「手動寄送給指定使用者」功能（'yes' / 'no'） */
	enable_manual_send_email: string
	course_schema: TEmailSchema
	user_schema: TEmailSchema
	chapter_schema: TEmailSchema
	assignment_schema: TEmailSchema
}

# WooCommerce 訂單系統

## 描述
WooCommerce 訂單狀態變更後自動觸發課程開通流程的系統 Actor。監聽訂單狀態 hook，當狀態變更為設定的觸發狀態時執行課程授權。

## 關鍵屬性
- 觸發來源：WooCommerce `woocommerce_order_status_{trigger}` action hook
- 預設觸發狀態：`completed`（可在設定中修改為 `processing` 等）
- 處理邏輯：解析訂單中的課程商品 → 讀取 `bind_courses_data` → 對每個課程執行 `AddStudentToCourse`
- 訂閱支援：監聽 `woocommerce_subscription_payment_complete` 處理續訂

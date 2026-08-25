<?php
/**
 * AccessPass REST API（Issue #252）
 *
 * 註冊 power-course/access-passes/* 端點，提供課程權限包 CRUD、停用、掛載到商品。
 * 對映 specs/api/api.yml 的 /access-passes 段（6 endpoint）。
 *
 * 業務邏輯委派給 Service\Crud / Service\Query；本類別僅負責：
 *   1. 解析 / 清洗 request 參數
 *   2. 將 Service 拋出的 \RuntimeException 轉成對映的 WP_Error（400 / 403 / 404）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Core;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Service\Query;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;
use J7\PowerCourse\Resources\AccessPass\Service\Holdings;
use J7\PowerCourse\Resources\AccessPass\Service\Scope;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass as AccessPassModel;

/**
 * Class Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint:string,method:string,permission_callback?: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint' => 'access-passes',
			'method'   => 'get',
		],
		[
			'endpoint' => 'access-passes',
			'method'   => 'post',
		],
		[
			'endpoint' => 'access-passes',
			'method'   => 'delete',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)',
			'method'   => 'get',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)',
			'method'   => 'put',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/disable',
			'method'   => 'post',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/attach',
			'method'   => 'post',
		],
		// --- 後台手動發放 / 撤銷 / 持有名單（沿用預設權限：manage_options || manage_woocommerce）---
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/users',
			'method'   => 'get',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/grant',
			'method'   => 'post',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/revoke',
			'method'   => 'post',
		],
		// --- 前台學員視角（登入即可，只回自己的資料）---
		// 註：'me' 不會被 (?P<id>\d+) 搶走——後者只匹配純數字。
		[
			'endpoint'            => 'access-passes/me',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		[
			'endpoint'            => 'access-passes/(?P<id>\d+)/courses',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_pass_courses_permission' ],
		],
	];

	/**
	 * 權限包列表
	 *
	 * 支援 query param：status=active|disabled。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response
	 */
	public function get_access_passes_callback( $request ): \WP_REST_Response { // phpcs:ignore
		\nocache_headers();

		$params = $request->get_query_params();
		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$list = Query::list( $params );
		return new \WP_REST_Response( $list );
	}

	/**
	 * 建立權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$args = $this->get_body_args( $request );

		try {
			$id = Crud::create( $args );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'rest_invalid_param', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'create_success',
				'message' => \__( 'Access pass created', 'power-course' ),
				'data'    => [ 'id' => $id ],
			]
		);
	}

	/**
	 * 批次刪除權限包（需二次確認，真正收回已購用戶觀看權）
	 *
	 * 對映 api.yml /access-passes DELETE（行 6295-6349）契約：
	 *   - req body：{ ids: int[]（minItems 1）, confirm: bool（必須 true）}，兩者皆 required
	 *   - resp 200：{ success: true, deleted_ids: int[], affected_user_count: int }
	 *   - resp 400：ValidationError（ids 空 / confirm 非 true / pass 不存在）
	 *   - resp 403：Precondition（保留給 Service 拋出的停用類前置失敗）
	 *
	 * 逐筆委派 Service\Crud::delete（不碰 avl_course_ids，OR 疊加互不影響），
	 * 累加 affected_user_count、收集 deleted_ids。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_access_passes_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$args = $this->get_body_args( $request );

		// === 前置（參數）：ids 不可為空陣列 ===
		$ids = isset( $args['ids'] ) && \is_array( $args['ids'] ) ? $args['ids'] : [];
		$ids = \array_values(
			\array_filter(
				\array_map( static fn( $id ): int => \absint( (int) $id ), $ids ),
				static fn( int $id ): bool => $id > 0
			)
		);
		if ( empty( $ids ) ) {
			return new \WP_Error( 'rest_invalid_param', \__( 'ids cannot be empty', 'power-course' ), [ 'status' => 400 ] );
		}

		// === 前置（參數）：confirm 必須為 true（二次確認）===
		$confirm = ! empty( $args['confirm'] ) && \in_array( $args['confirm'], [ true, 'true', '1', 1 ], true );
		if ( true !== $confirm ) {
			return new \WP_Error( 'rest_invalid_param', \__( 'confirm must be true', 'power-course' ), [ 'status' => 400 ] );
		}

		$deleted_ids         = [];
		$affected_user_count = 0;

		foreach ( $ids as $id ) {
			try {
				$result               = Crud::delete( $id, true );
				$affected_user_count += (int) $result['affected_user_count'];
				$deleted_ids[]        = $id;
			} catch ( \RuntimeException $e ) {
				return $this->to_wp_error( $e );
			}
		}

		return new \WP_REST_Response(
			[
				'success'             => true,
				'deleted_ids'         => $deleted_ids,
				'affected_user_count' => $affected_user_count,
			]
		);
	}

	/**
	 * 取得單一權限包
	 *
	 * 對映 Edit 頁 useForm(edit) 的 getOne：GET /access-passes/{id}。
	 * 回傳 Model::to_array()（scope / limit / status / term_ids / course_ids），
	 * 供表單回填；找不到（或非 pc_access_pass）時回 404。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_access_passes_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id          = (int) $request['id'];
		$access_pass = AccessPass::instance( $id );

		if ( null === $access_pass ) {
			return new \WP_Error( 'not_found', \__( 'Access pass not found', 'power-course' ), [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( $access_pass->to_array() );
	}

	/**
	 * 更新權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_access_passes_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id   = (int) $request['id'];
		$args = $this->get_body_args( $request );

		try {
			$updated_id = Crud::update( $id, $args );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => \__( 'Access pass updated', 'power-course' ),
				'data'    => [ 'id' => $updated_id ],
			]
		);
	}

	/**
	 * 停用權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_disable_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id = (int) $request['id'];

		try {
			$disabled_id = Crud::disable( $id );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'disable_success',
				'message' => \__( 'Access pass disabled', 'power-course' ),
				'data'    => [ 'id' => $disabled_id ],
			]
		);
	}

	/**
	 * 掛載權限包到商品
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_attach_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id         = (int) $request['id'];
		$args       = $this->get_body_args( $request );
		$product_id = isset( $args['product_id'] ) ? \absint( (int) $args['product_id'] ) : 0;

		try {
			Crud::attach_to_product( $id, $product_id );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'success'        => true,
				'product_id'     => $product_id,
				'access_pass_id' => $id,
			]
		);
	}

	/**
	 * 權限包持有學員名單（後台「持有學員」Tab）
	 *
	 * 支援 query param：page（1 起算，預設 1）、per_page（預設 20，上限 100）。
	 * 回應為 { items, total, total_pages }。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_access_passes_with_id_users_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id = (int) $request['id'];
		if ( ! AccessPassModel::instance( $id ) instanceof AccessPassModel ) {
			return new \WP_Error( 'not_found', '課程權限包不存在', [ 'status' => 404 ] );
		}

		$params   = $request->get_query_params();
		$page     = isset( $params['page'] ) ? \max( 1, (int) $params['page'] ) : 1;
		$per_page = isset( $params['per_page'] ) ? (int) $params['per_page'] : 20;
		$per_page = \min( 100, \max( 1, $per_page ) );

		$result = Holdings::get_holders_of_pass( $id, $page, $per_page );

		// 回傳物件而非裸陣列：前端用 useCustom 取這支（非標準 resource list），
		// 拿不到 response header，分頁資訊必須放在 body 裡。header 一併保留給其他 API 消費者。
		$response = new \WP_REST_Response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}

	/**
	 * 批次手動發放權限包給指定學員
	 *
	 * 請求 body：{ user_ids: int[] }。到期日一律依通行證的 limit_type 自動計算，不接受逐次指定——
	 * 期限是通行證的屬性，不是發放動作的屬性。
	 *
	 * 通行證層級的失敗（不存在 / 已停用 / 跟隨訂閱）先一次擋掉並回對映狀態碼；
	 * 進入迴圈後只剩使用者層級的失敗（使用者不存在），逐筆記入 failed 不中斷其他人。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_grant_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id       = (int) $request['id'];
		$args     = $this->get_body_args( $request );
		$user_ids = $this->sanitize_user_ids( $args );

		if ( ! $user_ids ) {
			return new \WP_Error( 'rest_invalid_param', 'user_ids 不可為空', [ 'status' => 400 ] );
		}

		// 通行證層級前置驗證：整批的結果都一樣，先問一次拿到明確錯誤
		try {
			Grant::assert_grantable( $id );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		$granted_by = \get_current_user_id();
		$granted    = [];
		$failed     = [];

		foreach ( $user_ids as $user_id ) {
			try {
				Grant::grant_manually( $user_id, $id, $granted_by );
				$granted[] = $user_id;
			} catch ( \RuntimeException $e ) {
				$failed[] = [
					'user_id' => $user_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return new \WP_REST_Response(
			[
				'code'           => 'grant_success',
				'message'        => \__( 'Access pass granted', 'power-course' ),
				'access_pass_id' => $id,
				'granted_ids'    => $granted,
				'granted_count'  => \count( $granted ),
				'failed'         => $failed,
			]
		);
	}

	/**
	 * 批次撤銷指定學員的權限包持有關係
	 *
	 * 請求 body：{ user_ids: int[] }。只刪 pc_user_access_pass 的對應列，
	 * 絕不碰 avl_course_ids / pc_avl_coursemeta——學員單獨購買的課程不受影響。
	 *
	 * 撤銷「本來就沒持有」不算錯誤（冪等），計入 not_found_ids 供 UI 提示。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_revoke_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id = (int) $request['id'];
		if ( ! AccessPassModel::instance( $id ) instanceof AccessPassModel ) {
			return new \WP_Error( 'not_found', '課程權限包不存在', [ 'status' => 404 ] );
		}

		$args     = $this->get_body_args( $request );
		$user_ids = $this->sanitize_user_ids( $args );

		if ( ! $user_ids ) {
			return new \WP_Error( 'rest_invalid_param', 'user_ids 不可為空', [ 'status' => 400 ] );
		}

		$revoked   = [];
		$not_found = [];

		foreach ( $user_ids as $user_id ) {
			if ( Grant::revoke( $user_id, $id ) ) {
				$revoked[] = $user_id;
			} else {
				$not_found[] = $user_id;
			}
		}

		return new \WP_REST_Response(
			[
				'code'           => 'revoke_success',
				'message'        => \__( 'Access pass revoked', 'power-course' ),
				'access_pass_id' => $id,
				'revoked_ids'    => $revoked,
				'revoked_count'  => \count( $revoked ),
				'not_found_ids'  => $not_found,
			]
		);
	}

	/**
	 * 目前登入者持有的所有通行證（前台「我的通行證」）
	 *
	 * 已失效的也會回傳（is_valid=false）——學員需要知道「我買過但已到期」。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response
	 */
	public function get_access_passes_me_callback( $request ): \WP_REST_Response { // phpcs:ignore
		\nocache_headers();

		return new \WP_REST_Response( Holdings::get_for_user( \get_current_user_id() ) );
	}

	/**
	 * 指定通行證涵蓋的課程清單（分頁）
	 *
	 * 正向展開 scope 規則（Service\Scope），與 Gate 的反向判定共用同一份子分類展開。
	 * query param：page（預設 1）、per_page（預設 12，上限 100）。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_access_passes_with_id_courses_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id   = (int) $request['id'];
		$pass = AccessPassModel::instance( $id );
		if ( ! $pass instanceof AccessPassModel ) {
			return new \WP_Error( 'not_found', '課程權限包不存在', [ 'status' => 404 ] );
		}

		$params   = $request->get_query_params();
		$page     = isset( $params['page'] ) ? \max( 1, (int) $params['page'] ) : 1;
		$per_page = isset( $params['per_page'] ) ? (int) $params['per_page'] : 12;
		$per_page = \min( 100, \max( 1, $per_page ) );

		$total      = Scope::count_courses( $pass );
		$course_ids = Scope::get_course_ids( $pass, $per_page, ( $page - 1 ) * $per_page );

		$items = [];
		foreach ( $course_ids as $course_id ) {
			$items[] = [
				'id'        => $course_id,
				'name'      => (string) \get_the_title( $course_id ),
				'permalink' => (string) \get_permalink( $course_id ),
				'image_url' => (string) \get_the_post_thumbnail_url( $course_id, 'medium' ),
			];
		}

		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) \ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * 權限檢查：已登入即可（前台學員視角端點）
	 *
	 * 這類端點只回「目前登入者自己」的資料，故不需要任何後台能力。
	 *
	 * @return bool|\WP_Error true 代表有權限；WP_Error 代表拒絕（含 HTTP status）。
	 */
	public static function check_logged_in_permission() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				\__( 'You are not currently logged in.', 'power-course' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * 權限檢查：可檢視「這張通行證涵蓋哪些課」
	 *
	 * 放行條件為兩者之一：
	 *   1. 後台管理者（manage_options / manage_woocommerce）
	 *   2. 目前登入者確實持有這張通行證（不論是否已到期——到期者也該看得到自己失去了什麼）
	 *
	 * 不放行任意登入者的理由：通行證的涵蓋範圍等於站主的商品組合策略，不該對未持有者公開。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return bool|\WP_Error true 代表有權限；WP_Error 代表拒絕（含 HTTP status）。
	 */
	public static function check_pass_courses_permission( $request ) {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				\__( 'You are not currently logged in.', 'power-course' ),
				[ 'status' => 401 ]
			);
		}

		if ( \current_user_can( 'manage_options' ) || \current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$pass_id = (int) $request['id'];
		$rows    = Repository::find_by_user( \get_current_user_id() );
		foreach ( $rows as $row ) {
			if ( (int) ( $row->pass_id ?? 0 ) === $pass_id ) {
				return true;
			}
		}

		return new \WP_Error(
			'rest_forbidden',
			\__( 'Sorry, you are not allowed to do that.', 'power-course' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * 從 request body 清洗出 user_ids 整數陣列
	 *
	 * 接受 int[] 或字串陣列（form-encoded 送上來會是字串），去重、濾掉非正整數。
	 *
	 * @param array<string, mixed> $args 已 sanitize 的 body 參數
	 * @return array<int> 清洗後的 user id 清單
	 */
	private function sanitize_user_ids( array $args ): array {
		$raw = $args['user_ids'] ?? [];
		if ( ! \is_array( $raw ) ) {
			$raw = [ $raw ];
		}

		$ids = [];
		foreach ( $raw as $value ) {
			$id = \absint( (int) $value );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return \array_values( \array_unique( $ids ) );
	}

	/**
	 * 將 Service 拋出的 RuntimeException 轉成對映 HTTP 狀態碼的 WP_Error
	 *
	 * 對映規則（對照 api.yml /access-passes 段）：
	 *   - 「不存在」訊息 → 404 not_found
	 *   - 「停用 / 不可掛」訊息 → 403 rest_forbidden（disabled 不可掛新商品）
	 *   - 其餘參數錯誤 → 400 rest_invalid_param
	 *
	 * @param \RuntimeException $e 例外
	 * @return \WP_Error
	 */
	private function to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( false !== \mb_strpos( $message, '不存在' ) ) {
			return new \WP_Error( 'not_found', $message, [ 'status' => 404 ] );
		}
		if ( false !== \mb_strpos( $message, '停用' ) ) {
			return new \WP_Error( 'rest_forbidden', $message, [ 'status' => 403 ] );
		}
		return new \WP_Error( 'rest_invalid_param', $message, [ 'status' => 400 ] );
	}

	/**
	 * 取得並清洗 request body 參數
	 *
	 * 接受 JSON body 或 form-encoded body，自動 sanitize_text_field_deep。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return array<string, mixed>
	 */
	private function get_body_args( $request ): array {
		$body = $request->get_json_params();
		if ( ! \is_array( $body ) || empty( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! \is_array( $body ) ) {
			$body = [];
		}

		/** @var array<string, mixed> $sanitized */
		$sanitized = WP::sanitize_text_field_deep( $body, false );
		return $sanitized;
	}
}

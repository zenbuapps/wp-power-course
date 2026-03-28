<?php
/**
 * Email API
 */

declare(strict_types=1);

namespace J7\PowerCourse\PowerEmail\Resources\Email;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\General;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Replace;

use J7\Powerhouse\Settings\Model\Settings as PowerhouseSettings;


/**
 * Class Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-email';

	/**
	 * APIs
	 *
	 * @var array{endpoint:string,method:string,permission_callback: callable|null }[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected $apis = [
		[
			'endpoint'            => 'emails',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/(?P<id>\d+)',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails',
			'method'              => 'delete',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/send-now',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/send-schedule',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/(?P<id>\d+)',
			'method'              => 'delete',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/options',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'emails/scheduled-actions',
			'method'              => 'get',
			'permission_callback' => null,
		],
	];


	/**
	 * Copied from ActionScheduler_ListTable
	 *
	 * @var array<int, array{seconds: int, names: array{0: string, 1: string, singular: string, plural: string, context: null, domain: string|null}}>
	 */
	protected static $time_periods = [];

	/** Constructor */
	public function __construct() {
		parent::__construct();
		/** @var array<int, array{seconds: int, names: array{0: string, 1: string, singular: string, plural: string, context: null, domain: string|null}}> $time_periods */
		$time_periods       = [
			[
				'seconds' => YEAR_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s year', '%s years', 'woocommerce' ),
			],
			[
				'seconds' => MONTH_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s month', '%s months', 'woocommerce' ),
			],
			[
				'seconds' => WEEK_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s week', '%s weeks', 'woocommerce' ),
			],
			[
				'seconds' => DAY_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s day', '%s days', 'woocommerce' ),
			],
			[
				'seconds' => HOUR_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s hour', '%s hours', 'woocommerce' ),
			],
			[
				'seconds' => MINUTE_IN_SECONDS,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s minute', '%s minutes', 'woocommerce' ),
			],
			[
				'seconds' => 1,
				/* translators: %s: amount of time */
				'names'   => _n_noop( '%s second', '%s seconds', 'woocommerce' ),
			],
		];
		self::$time_periods = $time_periods;
	}

	/**
	 * Get emails callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function get_emails_callback( $request ) { // phpcs:ignore

		// 未來有做篩選器才需要開啟
		$params = $request->get_query_params();
		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'post_type'      => EmailCPT::POST_TYPE,
			'posts_per_page' => '20',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'orderby'        => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
		];

		// 未來有做篩選器材需要開啟
		$args = \wp_parse_args(
		$params,
		$default_args,
		);

		$results = new \WP_Query( $args );

		$total       = $results->found_posts;
		$total_pages = $results->max_num_pages;

		/** @var array<int> $post_ids */
		$post_ids = $results->posts;

		$emails = array_values(array_map( fn( int $post_id ) => new EmailResource( $post_id, false, true ), $post_ids ));

		$response = new \WP_REST_Response( $emails );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}


	/**
	 * Get email callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function get_emails_with_id_callback( $request ) { // phpcs:ignore
		$id = $request['id'];

		$email = new EmailResource( (int) $id, true, true );

		$response = new \WP_REST_Response( $email );

		return $response;
	}


	/**
	 * 處理並分離產品資訊
	 *
	 * 根據請求分離產品資訊，並處理描述欄位。
	 *
	 * @param \WP_REST_Request $request 包含產品資訊的請求對象。
	 * @throws \Exception 當找不到商品時拋出異常。.
	 * @return array{data: array<string, mixed>, meta_data: array<string, mixed>} 包含資料和元數據的陣列。
	 */
	private function separator( $request ): array {
		$body_params = $request->get_body_params();

		$body_params = WP::converter( $body_params );

		$skip_keys   = [
			'post_content', // email html 內容
			'post_excerpt', // email json 內容
		];
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep($body_params, true, $skip_keys);

		// 將 '[]' 轉為 []
		$body_params = General::parse( $body_params );

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = WP::separator( $body_params, 'post' );

		return [
			'data'      => $data,
			'meta_data' => $meta_data,
		];
	}

	/**
	 * Post Email callback
	 * 創建電子郵件
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_emails_callback( $request ): \WP_REST_Response|\WP_Error {

		$post_id = \wp_insert_post(
			[
				'post_type'    => EmailCPT::POST_TYPE,
				'post_title'   => __('New Email', 'power-email'),
				'post_content' => '',
				'post_status'  => 'draft',
				'post_author'  => \get_current_user_id(),
				'meta_input'   => [
					'trigger_at' => 'course_granted',
				],
			]
			);

		if (\is_wp_error($post_id)) { // @phpstan-ignore-line
			return $post_id;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'create_success',
				'message' => '創建成功',
				'data'    => [
					'id' => $post_id,
				],
			]
			);
	}

	/**
	 * Patch Email callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_emails_with_id_callback( $request ): \WP_REST_Response|\WP_Error {

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = $this->separator( $request );

		$data['meta_input'] = $meta_data;
		$data['ID']         = $request['id'];

		// 使用 wp_slash 防止 JSON 跳脫字元在儲存時被過濾掉 再次編碼
		$data['post_excerpt'] = \wp_slash( (string) ( $data['post_excerpt'] ?? '' ) );

		/** @var array{ID?: int, post_author?: int, post_date?: string, post_date_gmt?: string, post_content?: string, post_content_filtered?: string, post_title?: string, post_excerpt?: string} $data */
		$update_result = \wp_update_post($data);

		if ( \is_wp_error( $update_result ) ) { // @phpstan-ignore-line
			return $update_result;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => '更新成功',
				'data'    => [
					'id' => $update_result,
				],
			]
		);
	}

	/**
	 * Post Email Send Now callback
	 * 立即發送電子郵件
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_emails_send_now_callback( $request ): \WP_REST_Response|\WP_Error {
		$body_params = $request->get_json_params();

		WP::include_required_params( $body_params, [ 'email_ids', 'user_ids' ] );

		$email_ids = $body_params['email_ids'];
		$user_ids  = $body_params['user_ids'];

		$action_id = \as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[
				'email_ids' => $email_ids,
				'user_ids'  => $user_ids,
			],
			At::AS_GROUP
			);

		return new \WP_REST_Response(
			[
				'code'    => 'send_success',
				'message' => '發送成功',
				'data'    => [
					'action_id' => $action_id,
				],
			]
			);
	}



	/**
	 * Post Email Send Schedule callback
	 * 排程發送電子郵件
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_emails_send_schedule_callback( $request ): \WP_REST_Response|\WP_Error {
		$body_params = $request->get_json_params();

		WP::include_required_params( $body_params, [ 'email_ids', 'user_ids', 'timestamp' ] );

		$email_ids = $body_params['email_ids'];
		$user_ids  = $body_params['user_ids'];
		$timestamp = (int) $body_params['timestamp'];

		$action_id = \as_schedule_single_action(
			$timestamp,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => $email_ids,
				'user_ids'  => $user_ids,
			],
			At::AS_GROUP
			);

		return new \WP_REST_Response(
			[
				'code'    => 'schedule_success',
				'message' => '排程成功',
				'data'    => [
					'action_id' => $action_id,
				],
			]
			);
	}

	/**
	 * Delete Email callback
	 * 刪除電子郵件
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @throws \Exception 當刪除電子郵件失敗時拋出異常
	 */
	public function delete_emails_callback( $request ): \WP_REST_Response {
		$body_params = $request->get_json_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		/** @var array<int|string> $ids */
		$ids = (array) ( $body_params['ids'] ?? [] );

		foreach ($ids as $id) {
			$result = \wp_trash_post( (int) $id );
			if (!$result) {
				throw new \Exception(__('刪除電子郵件資料失敗', 'power-email') . ' #' . (string) $id);
			}
		}

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => '刪除成功',
				'data'    => [
					'ids' => $ids,
				],
			]
		);
	}

	/**
	 * Delete Email callback
	 * 刪除電子郵件
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_emails_with_id_callback( $request ): \WP_REST_Response {
		$id = (int) $request['id'];

		\wp_trash_post( $id );

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => '刪除成功',
				'data'    => [
					'id' => $id,
				],
			]
		);
	}

	/**
	 * Get Email Options callback
	 * 取得電子郵件選項
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_emails_options_callback( $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'enable_manual_send_email' => PowerhouseSettings::instance()->enable_manual_send_email,
				'course_schema'            => Replace\Course::get_schemas(),
				'user_schema'              => Replace\User::get_schemas(),
				'chapter_schema'           => Replace\Chapter::get_schemas(),
			]
		);
	}

	/**
	 * Get Email Scheduled Actions callback
	 * 取得排程動作
	 *
	 * @see as_get_scheduled_actions in woocommerce 或看 ActionScheduler_ListTable
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_emails_scheduled_actions_callback( $request ): \WP_REST_Response {

		$url_params = $request->get_query_params();

		$per_page = $url_params['posts_per_page'] ?? 20;
		$paged    = $url_params['paged'] ?? 1;

		$args = [
			'group'    => At::AS_GROUP, // EmailCPT::AS_HOOK,
			'status'   => '', // ActionScheduler_Store::STATUS_COMPLETE or ActionScheduler_Store::STATUS_PENDING.
			'per_page' => $per_page,                  // -1 表示取得所有
			'order'    => 'DESC',
			'offset'   => $per_page * ( $paged - 1 ),
			// 'group' => 'your_group',           // 指定 action group

			// 以下從 ActionScheduler_ListTable 觀察到的
			// 'orderby'  => 'schedule',
			// 'search'   => 'power_email_send_',
		];

		[
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
			'items'       => $items,
		] = $this->as_get_scheduled_actions( $args );

		$response = new \WP_REST_Response( $items );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total_items );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Get Scheduled Actions
	 *
	 * @see ActionScheduler_ListTable prepare_items
	 *
	 * @param array<string, mixed> $args 查詢參數
	 * @param string               $return_format OBJECT | ARRAY_A
	 * @return array{total_items: int, per_page: int, total_pages: int, items: list<array<string, mixed>>}
	 */
	private function as_get_scheduled_actions( $args = [], $return_format = OBJECT ) {
		if ( ! \ActionScheduler::is_initialized( __FUNCTION__ ) ) {
			return [
				'total_items' => 0,
				'per_page'    => 0,
				'total_pages' => 0,
				'items'       => [],
			];
		}

		/** @var \ActionScheduler_DBStore $store */
		$store = \ActionScheduler::store();
		/** @var \ActionScheduler_DBLogger $logger */
		$logger = \ActionScheduler::logger();

		/**
			 * Change query arguments to query for past-due actions.
			 * Past-due actions have the 'pending' status and are in the past.
			 * This is needed because registering 'past-due' as a status is overkill.
			 */
		if ( 'past-due' === $args['status'] ) {
			$args['status'] = \ActionScheduler_Store::STATUS_PENDING;
			$args['date']   = \as_get_datetime_object();
		}

		$items = [];

		$total_items = (int) $store->query_actions( $args, 'count' );

		$status_labels = $store->get_status_labels();

		/** @var array<int, int> $action_ids */
		$action_ids = $store->query_actions( $args );
		foreach ( $action_ids as $action_id ) {
			try {
				$action = $store->fetch_action( $action_id );
			} catch ( \Exception $e ) {
				continue;
			}
			if ( is_a( $action, 'ActionScheduler_NullAction' ) ) {
				continue;
			}

			/**
			 * @var \ActionScheduler_SimpleSchedule $schedule
			 */
			$schedule      = $action->get_schedule();
			$schedule_text = $schedule->get_date()?->format('Y-m-d H:i:s');

			$logs        = $logger->get_logs( $action_id );
			$log_entries = $this->get_log_entries_html( $logs );

			$items[ $action_id ] = [
				'id'          => $action_id,
				'hook'        => $action->get_hook(),
				'status_name' => $store->get_status( $action_id ),
				'status'      => $status_labels[ $store->get_status( $action_id ) ],
				'args'        => $action->get_args(),
				'group'       => $action->get_group(),
				'log_entries' => $log_entries,
				'claim_id'    => $store->get_claim_id( $action_id ),
				'recurrence'  => $this->get_recurrence( $action ),
				'schedule'    => $schedule_text,
			];
		}

		$per_page = (int) $args['per_page'];
		return [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / max( $per_page, 1 ) ),
			'items'       => array_values( $items ),
		];
	}


	/**
	 * Returns the recurrence of an action or 'Non-repeating'. The output is human readable.
	 *
	 * @param \ActionScheduler_Action $action Action object.
	 *
	 * @return string
	 */
	protected function get_recurrence( $action ) {
		$schedule = $action->get_schedule();
		if ( $schedule->is_recurring() && method_exists( $schedule, 'get_recurrence' ) ) {
			$recurrence = $schedule->get_recurrence();

			if ( is_numeric( $recurrence ) ) {
				/* translators: %s: time interval */
				return sprintf( __( 'Every %s', 'woocommerce' ), self::human_interval( (int) $recurrence ) );
			} else {
				return (string) $recurrence;
			}
		}

		return __( 'Non-repeating', 'woocommerce' );
	}
	/**
	 * Convert an interval of seconds into a two part human friendly string.
	 *
	 * The WordPress human_time_diff() function only calculates the time difference to one degree, meaning
	 * even if an action is 1 day and 11 hours away, it will display "1 day". This function goes one step
	 * further to display two degrees of accuracy.
	 *
	 * Inspired by the Crontrol::interval() function by Edward Dale: https://wordpress.org/plugins/wp-crontrol/
	 *
	 * @param int $interval A interval in seconds.
	 * @param int $periods_to_include Depth of time periods to include, e.g. for an interval of 70, and $periods_to_include of 2, both minutes and seconds would be included. With a value of 1, only minutes would be included.
	 * @return string A human friendly string representation of the interval.
	 */
	private static function human_interval( $interval, $periods_to_include = 2 ) {

		if ( $interval <= 0 ) {
			return __( 'Now!', 'woocommerce' );
		}

		$output           = '';
		$num_time_periods = count( self::$time_periods );

		for ( $time_period_index = 0, $periods_included = 0, $seconds_remaining = $interval; $time_period_index < $num_time_periods && $seconds_remaining > 0 && $periods_included < $periods_to_include; $time_period_index++ ) {

			$periods_in_interval = (int) floor( $seconds_remaining / self::$time_periods[ $time_period_index ]['seconds'] );

			if ( $periods_in_interval > 0 ) {
				if ( ! empty( $output ) ) {
					$output .= ' ';
				}
				$output            .= sprintf( translate_nooped_plural( self::$time_periods[ $time_period_index ]['names'], $periods_in_interval, 'action-scheduler' ), $periods_in_interval );
				$seconds_remaining -= $periods_in_interval * self::$time_periods[ $time_period_index ]['seconds'];
				++$periods_included;
			}
		}

		return $output;
	}


	/**
	 * Prints the logs entries inline. We do so to avoid loading Javascript and other hacks to show it in a modal.
	 *
	 * @param array<int, \ActionScheduler_LogEntry> $logs Log entries.
	 * @return string
	 */
	public function get_log_entries_html( array $logs ) {

		$log_entries_html = '<ol>';

		$timezone = new \DateTimezone( 'UTC' );

		foreach ( $logs as $log_entry ) {
			$log_entries_html .= $this->get_log_entry_html( $log_entry, $timezone );
		}

		$log_entries_html .= '</ol>';

		return $log_entries_html;
	}

	/**
	 * Prints the logs entries inline. We do so to avoid loading Javascript and other hacks to show it in a modal.
	 *
	 * @param \ActionScheduler_LogEntry $log_entry Log entry object.
	 * @param \DateTimezone             $timezone Timestamp.
	 * @return string
	 */
	protected function get_log_entry_html( \ActionScheduler_LogEntry $log_entry, \DateTimezone $timezone ) {
		$date = $log_entry->get_date();
		$date->setTimezone( $timezone );
		return sprintf( '<li><strong>%s</strong><br/>%s</li>', esc_html( $date->format( 'Y-m-d H:i:s O' ) ), esc_html( $log_entry->get_message() ) );
	}
}

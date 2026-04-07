<?php
/**
 * ActionScheduler stubs for PHPStan
 *
 * @phpstan-ignore-file
 */

class ActionScheduler {
	/**
	 * @param string $function_name
	 * @return bool
	 */
	public static function is_initialized( $function_name = '' ) {
		return true;
	}

	/**
	 * @return ActionScheduler_DBStore
	 */
	public static function store() {
		return new ActionScheduler_DBStore();
	}

	/**
	 * @return ActionScheduler_DBLogger
	 */
	public static function logger() {
		return new ActionScheduler_DBLogger();
	}
}

class ActionScheduler_Store {
	const STATUS_COMPLETE = 'complete';
	const STATUS_PENDING  = 'pending';
	const STATUS_RUNNING  = 'in-progress';
	const STATUS_FAILED   = 'failed';
	const STATUS_CANCELED = 'canceled';
}

class ActionScheduler_DBStore extends ActionScheduler_Store {
	/**
	 * @param array<string, mixed> $query
	 * @param string               $query_type
	 * @return array<int, int>|int
	 */
	public function query_actions( $query = [], $query_type = 'select' ) {
		return [];
	}

	/**
	 * @return array<string, string>
	 */
	public function get_status_labels() {
		return [];
	}

	/**
	 * @param int $action_id
	 * @return ActionScheduler_Action
	 */
	public function fetch_action( $action_id ) {
		return new ActionScheduler_Action();
	}

	/**
	 * @param int $action_id
	 * @return string
	 */
	public function get_status( $action_id ) {
		return '';
	}

	/**
	 * @param int $action_id
	 * @return int
	 */
	public function get_claim_id( $action_id ) {
		return 0;
	}
}

class ActionScheduler_Logger {
	/**
	 * @param int $action_id
	 * @return array<int, ActionScheduler_LogEntry>
	 */
	public function get_logs( $action_id ) {
		return [];
	}
}

class ActionScheduler_DBLogger extends ActionScheduler_Logger {
}

class ActionScheduler_Schedule_Abstract {
	/**
	 * @return \DateTime|null
	 */
	public function get_date() {
		return null;
	}

	/**
	 * @return bool
	 */
	public function is_recurring() {
		return false;
	}
}

class ActionScheduler_SimpleSchedule extends ActionScheduler_Schedule_Abstract {
}

class ActionScheduler_Action {
	/**
	 * @return ActionScheduler_Schedule_Abstract
	 */
	public function get_schedule() {
		return new ActionScheduler_Schedule_Abstract();
	}

	/**
	 * @return string
	 */
	public function get_hook() {
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_args() {
		return [];
	}

	/**
	 * @return string
	 */
	public function get_group() {
		return '';
	}
}

class ActionScheduler_NullAction extends ActionScheduler_Action {
}

class ActionScheduler_LogEntry {
	/**
	 * @return \DateTime
	 */
	public function get_date() {
		return new \DateTime();
	}

	/**
	 * @return string
	 */
	public function get_message() {
		return '';
	}
}

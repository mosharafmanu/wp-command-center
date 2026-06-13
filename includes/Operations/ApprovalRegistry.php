<?php
/**
 * Step 78 — Approval Runtime action registry.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ApprovalRegistry {

	const A_REQUEST_CREATE  = 'request_create';
	const A_REQUEST_LIST    = 'request_list';
	const A_REQUEST_GET     = 'request_get';
	const A_REQUEST_APPROVE = 'request_approve';
	const A_REQUEST_REJECT  = 'request_reject';
	const A_REQUEST_CANCEL  = 'request_cancel';
	const A_QUEUE_LIST      = 'queue_list';
	const A_QUEUE_GET       = 'queue_get';
	const A_QUEUE_RUN       = 'queue_run';
	const A_QUEUE_CANCEL    = 'queue_cancel';
	const A_QUEUE_RETRY     = 'queue_retry';
	const A_RESULTS_LIST    = 'results_list';
	const A_RESULTS_GET     = 'results_get';

	const ACTIONS = [
		self::A_REQUEST_CREATE, self::A_REQUEST_LIST, self::A_REQUEST_GET,
		self::A_REQUEST_APPROVE, self::A_REQUEST_REJECT, self::A_REQUEST_CANCEL,
		self::A_QUEUE_LIST, self::A_QUEUE_GET, self::A_QUEUE_RUN, self::A_QUEUE_CANCEL, self::A_QUEUE_RETRY,
		self::A_RESULTS_LIST, self::A_RESULTS_GET,
	];
}

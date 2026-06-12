<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class CommentsRegistry {

	const RISK_LOW    = 'low';
	const RISK_MEDIUM = 'medium';
	const RISK_HIGH   = 'high';

	const ACTION_LIST      = 'comment_list';
	const ACTION_GET       = 'comment_get';
	const ACTION_APPROVE   = 'comment_approve';
	const ACTION_UNAPPROVE = 'comment_unapprove';
	const ACTION_SPAM      = 'comment_spam';
	const ACTION_TRASH     = 'comment_trash';
	const ACTION_DELETE    = 'comment_delete';
	const ACTION_REPLY     = 'comment_reply';

	const ACTIONS = [
		'comment_list', 'comment_get', 'comment_approve', 'comment_unapprove',
		'comment_spam', 'comment_trash', 'comment_delete', 'comment_reply',
	];

	const ACTION_RISK = [
		self::ACTION_LIST      => self::RISK_LOW,
		self::ACTION_GET       => self::RISK_LOW,
		self::ACTION_APPROVE   => self::RISK_MEDIUM,
		self::ACTION_UNAPPROVE => self::RISK_MEDIUM,
		self::ACTION_SPAM      => self::RISK_MEDIUM,
		self::ACTION_REPLY     => self::RISK_MEDIUM,
		self::ACTION_TRASH     => self::RISK_HIGH,
		self::ACTION_DELETE    => self::RISK_HIGH,
	];

	const ACTION_APPROVAL = [
		self::ACTION_LIST      => false,
		self::ACTION_GET       => false,
		self::ACTION_APPROVE   => true,
		self::ACTION_UNAPPROVE => true,
		self::ACTION_SPAM      => true,
		self::ACTION_REPLY     => true,
		self::ACTION_TRASH     => true,
		self::ACTION_DELETE    => true,
	];

	// Keyed by the bare action names passed to CommentsRuntimeManager::store_rollback()
	// ('trash'/'delete'), not the comment_* action constants above. Deletes use
	// wp_delete_comment( $id, true ) (force, permanent) and cannot be restored,
	// so only 'trash' is rollback-eligible.
	const ACTION_ROLLBACK = [
		'trash' => true,
	];

	public static function get_risk( string $action ): string {
		return self::ACTION_RISK[ $action ] ?? self::RISK_MEDIUM;
	}

	public static function requires_approval( string $action ): bool {
		return self::ACTION_APPROVAL[ $action ] ?? true;
	}

	public static function supports_rollback( string $action ): bool {
		return self::ACTION_ROLLBACK[ $action ] ?? false;
	}
}

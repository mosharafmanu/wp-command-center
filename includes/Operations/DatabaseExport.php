<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class DatabaseExport {

	public function export(): string|\WP_Error {
		return new \WP_Error( 'not_implemented', __( 'Database export is not yet implemented.', 'wp-command-center' ) );
	}
}

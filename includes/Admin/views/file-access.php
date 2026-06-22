<?php
defined( 'ABSPATH' ) || exit;

use WPCommandCenter\AiAgent\CodeSearch;
use WPCommandCenter\AiAgent\FileAccessApi;

$path  = isset( $_GET['path'] ) ? trim( wp_unslash( $_GET['path'] ), '/' ) : '';
$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

$page_url = static function ( array $args = [] ): string {
	return esc_url( add_query_arg( array_merge( [ 'page' => 'wpcc-file-access' ], $args ), admin_url( 'admin.php' ) ) );
};

$render_breadcrumbs = static function ( string $path ) use ( $page_url ): void {
	$segments = '' === $path ? [] : explode( '/', $path );
	$built    = '';
	?>
	<p class="wpcc-breadcrumbs">
		<a href="<?php echo $page_url(); ?>"><?php esc_html_e( 'wp-content', 'wp-command-center' ); ?></a>
		<?php foreach ( $segments as $segment ) : ?>
			<?php $built = '' === $built ? $segment : $built . '/' . $segment; ?>
			/ <a href="<?php echo $page_url( [ 'path' => $built ] ); ?>"><?php echo esc_html( $segment ); ?></a>
		<?php endforeach; ?>
	</p>
	<?php
};
?>
<div class="wrap wpcc-wrap">
	<h1><?php esc_html_e( 'File Access', 'wp-command-center' ); ?></h1>
	<p><?php esc_html_e( 'Read-only file browser and code search across themes, plugins, and mu-plugins for AI agent investigation.', 'wp-command-center' ); ?></p>

	<form method="get" class="wpcc-search-form">
		<input type="hidden" name="page" value="wpcc-file-access" />
		<?php if ( '' !== $path ) : ?>
			<input type="hidden" name="path" value="<?php echo esc_attr( $path ); ?>" />
		<?php endif; ?>
		<label for="wpcc-search-q" class="screen-reader-text"><?php esc_html_e( 'Search code', 'wp-command-center' ); ?></label>
		<input type="search" id="wpcc-search-q" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'Search file contents…', 'wp-command-center' ); ?>" class="regular-text" />
		<?php submit_button( __( 'Search', 'wp-command-center' ), 'secondary', '', false ); ?>
		<?php if ( '' !== $query ) : ?>
			<a class="button" href="<?php echo $page_url( [ 'path' => $path ] ); ?>"><?php esc_html_e( 'Clear Search', 'wp-command-center' ); ?></a>
		<?php endif; ?>
		<?php if ( '' !== $path ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: directory path being searched */
					esc_html__( 'Search is scoped to: %s', 'wp-command-center' ),
					'<code>' . esc_html( $path ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
	</form>

	<?php if ( '' !== $query ) : ?>

		<?php
		$results = ( new CodeSearch() )->search( $query, [ 'path' => $path ] );
		?>

		<?php if ( is_wp_error( $results ) ) : ?>

			<div class="wpcc-cds-notice wpcc-cds-notice--danger"><p><?php echo esc_html( $results->get_error_message() ); ?></p></div>

		<?php elseif ( empty( $results['matches'] ) ) : ?>

			<div class="wpcc-cds-empty">
				<div class="wpcc-cds-empty__title">
				<?php
				printf(
					/* translators: 1: search term, 2: number of files scanned */
					esc_html__( 'No matches for "%1$s" (%2$d files scanned).', 'wp-command-center' ),
					esc_html( $results['query'] ),
					(int) $results['files_scanned']
				);
				?>
				</div>
			</div>

		<?php else : ?>

			<p class="wpcc-scan-meta">
				<?php
				printf(
					/* translators: 1: number of matches, 2: number of files scanned */
					esc_html__( '%1$d match(es) across %2$d files scanned.', 'wp-command-center' ),
					count( $results['matches'] ),
					(int) $results['files_scanned']
				);

				if ( $results['truncated'] ) {
					echo ' &mdash; ' . esc_html__( 'results truncated.', 'wp-command-center' );
				}
				?>
			</p>

			<?php
			$grouped = [];

			foreach ( $results['matches'] as $match ) {
				$grouped[ $match['file'] ][] = $match;
			}
			?>

			<?php foreach ( $grouped as $file => $file_matches ) : ?>
				<h3><a href="<?php echo $page_url( [ 'path' => $file ] ); ?>"><?php echo esc_html( $file ); ?></a></h3>
				<pre class="wpcc-search-matches"><?php foreach ( $file_matches as $match ) : ?><span class="wpcc-search-match"><span class="wpcc-search-match__line"><?php echo (int) $match['line']; ?>:</span> <?php echo esc_html( $match['text'] ); ?>
</span><?php endforeach; ?></pre>
			<?php endforeach; ?>

		<?php endif; ?>

	<?php else : ?>

		<?php $render_breadcrumbs( $path ); ?>

		<?php
		$file_api = new FileAccessApi();
		$listing  = null;
		$file     = null;

		if ( '' === $path ) {
			$listing = $file_api->list_directory( '' );
		} else {
			$result = $file_api->read( $path );

			if ( is_wp_error( $result ) && 'wpcc_is_directory' === $result->get_error_code() ) {
				$listing = $file_api->list_directory( $path );
			} elseif ( is_wp_error( $result ) ) {
				$file = $result;
			} else {
				$file = $result;
			}
		}
		?>

		<?php if ( $file instanceof \WP_Error ) : ?>

			<div class="wpcc-cds-notice wpcc-cds-notice--danger"><p><?php echo esc_html( $file->get_error_message() ); ?></p></div>

		<?php elseif ( is_array( $file ) ) : ?>

			<p class="wpcc-scan-meta">
				<?php
				printf(
					/* translators: 1: file size, 2: last modified date/time */
					esc_html__( 'Size: %1$s — Last modified: %2$s', 'wp-command-center' ),
					esc_html( size_format( $file['size'] ) ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file['modified'] ) )
				);

				if ( $file['truncated'] ) {
					echo ' &mdash; ' . esc_html__( 'showing the first 1 MB of a larger file.', 'wp-command-center' );
				}
				?>
			</p>

			<p class="wpcc-actions">
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcc-patches', 'path' => $file['path'] ], admin_url( 'admin.php' ) ) . '#create-patch' ); ?>"><?php esc_html_e( 'Create Patch for This File', 'wp-command-center' ); ?></a>
			</p>

			<pre class="wpcc-file-viewer"><code><?php echo esc_html( $file['contents'] ); ?></code></pre>

		<?php elseif ( is_wp_error( $listing ) ) : ?>

			<div class="wpcc-cds-notice wpcc-cds-notice--danger"><p><?php echo esc_html( $listing->get_error_message() ); ?></p></div>

		<?php elseif ( is_array( $listing ) ) : ?>

			<table class="widefat striped wpcc-cds-table wpcc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Size', 'wp-command-center' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'wp-command-center' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( null !== $listing['parent'] ) : ?>
					<tr>
						<td>
							<a href="<?php echo $page_url( [ 'path' => $listing['parent'] ] ); ?>">.. <?php esc_html_e( '(parent directory)', 'wp-command-center' ); ?></a>
						</td>
						<td><?php esc_html_e( 'Directory', 'wp-command-center' ); ?></td>
						<td>—</td>
						<td>—</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $listing['entries'] as $entry ) : ?>
					<tr>
						<td>
							<a href="<?php echo $page_url( [ 'path' => $entry['path'] ] ); ?>">
								<?php echo esc_html( $entry['name'] ); ?><?php echo 'dir' === $entry['type'] ? '/' : ''; ?>
							</a>
						</td>
						<td><?php echo 'dir' === $entry['type'] ? esc_html__( 'Directory', 'wp-command-center' ) : esc_html( strtoupper( $entry['extension'] ) ?: __( 'File', 'wp-command-center' ) ); ?></td>
						<td><?php echo null === $entry['size'] ? '—' : esc_html( size_format( $entry['size'] ) ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['modified'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $listing['entries'] ) && null === $listing['parent'] ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No accessible directories found.', 'wp-command-center' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

		<?php endif; ?>

	<?php endif; ?>
</div>

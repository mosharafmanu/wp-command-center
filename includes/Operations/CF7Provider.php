<?php
namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class CF7Provider implements FormsProvider {

	public function is_available(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	public function get_name(): string {
		return 'Contact Form 7';
	}

	public function list_forms( array $params ): array {
		$per_page = min( 100, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $params['page'] ?? 1 ) );
		$posts    = get_posts( [ 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => $per_page, 'paged' => $page, 'post_status' => 'any' ] );
		$items = []; foreach ( $posts as $p ) $items[] = $this->summarize( $p );
		return [ 'items' => $items, 'total' => wp_count_posts( 'wpcf7_contact_form' )->publish ?? 0 ];
	}

	public function get_form( string $id ): ?array {
		$p = get_post( (int) $id );
		if ( ! $p || 'wpcf7_contact_form' !== $p->post_type ) return null;
		$meta = get_post_meta( $p->ID );
		$form = $this->summarize( $p );
		$form['form_markup'] = $meta['_form'][0] ?? '';
		$form['mail'] = isset( $meta['_mail'] ) ? maybe_unserialize( $meta['_mail'][0] ) : [];
		$form['mail_2'] = isset( $meta['_mail_2'] ) ? maybe_unserialize( $meta['_mail_2'][0] ) : [];
		$form['messages'] = isset( $meta['_messages'] ) ? maybe_unserialize( $meta['_messages'][0] ) : [];
		return $form;
	}

	public function search_forms( string $query ): array {
		$posts = get_posts( [ 'post_type' => 'wpcf7_contact_form', 's' => $query, 'posts_per_page' => 50 ] );
		return [ 'items' => array_map( [ $this, 'summarize' ], $posts ), 'total' => count( $posts ) ];
	}

	public function create_form( array $data ): ?array {
		$title = sanitize_text_field( (string) ( $data['title'] ?? 'New Form' ) );
		$template = sanitize_text_field( (string) ( $data['template'] ?? 'contact_basic' ) );
		$form_markup = $this->get_template( $template );
		$post_id = wp_insert_post( [ 'post_type' => 'wpcf7_contact_form', 'post_title' => $title, 'post_status' => 'publish' ] );
		if ( ! $post_id ) return null;
		update_post_meta( $post_id, '_form', $form_markup );
		update_post_meta( $post_id, '_mail', $this->default_mail() );
		$mail2 = $this->default_mail();
		$mail2['active'] = false;
		update_post_meta( $post_id, '_mail_2', $mail2 );
		return [ 'id' => $post_id, 'title' => $title, 'template' => $template ];
	}

	public function update_form( string $id, array $data ): ?array {
		$updates = [];
		if ( isset( $data['title'] ) ) $updates['post_title'] = sanitize_text_field( (string) $data['title'] );
		if ( isset( $data['status'] ) ) $updates['post_status'] = sanitize_key( (string) $data['status'] );
		if ( ! empty( $updates ) ) { $updates['ID'] = (int) $id; wp_update_post( $updates ); }
		if ( isset( $data['form_markup'] ) ) update_post_meta( (int) $id, '_form', $data['form_markup'] );
		return $this->get_form( $id );
	}

	public function duplicate_form( string $id ): ?array {
		$orig = get_post( (int) $id );
		if ( ! $orig ) return null;
		$new_id = wp_insert_post( [ 'post_type' => 'wpcf7_contact_form', 'post_title' => $orig->post_title . ' (Copy)', 'post_status' => 'publish' ] );
		if ( ! $new_id ) return null;
		foreach ( get_post_meta( (int) $id ) as $k => $v ) update_post_meta( $new_id, $k, maybe_unserialize( $v[0] ) );
		return [ 'id' => $new_id, 'title' => get_the_title( $new_id ), 'original_id' => (int) $id ];
	}

	public function delete_form( string $id ): ?array {
		$title = get_the_title( (int) $id );
		$result = wp_delete_post( (int) $id, true );
		return $result ? [ 'id' => (int) $id, 'title' => $title ] : null;
	}

	public function activate_form( string $id ): ?array {
		wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'publish' ] );
		return $this->summarize( get_post( (int) $id ) );
	}

	public function deactivate_form( string $id ): ?array {
		wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'draft' ] );
		return $this->summarize( get_post( (int) $id ) );
	}

	public function list_entries( string $form_id, array $params ): array {
		$per_page = min( 100, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $params['page'] ?? 1 ) );
		$db = $this->cfdb();
		$total = $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$db->prefix}db7_forms WHERE form_post_id = %d", (int) $form_id ) );
		$rows  = $db->get_results( $db->prepare( "SELECT * FROM {$db->prefix}db7_forms WHERE form_post_id = %d LIMIT %d OFFSET %d", (int) $form_id, $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		return [ 'items' => $rows ?: [], 'total' => (int) $total, 'page' => $page ];
	}

	public function get_entry( string $entry_id, string $form_id ): ?array {
		$db = $this->cfdb();
		return $db->get_row( $db->prepare( "SELECT * FROM {$db->prefix}db7_forms WHERE form_id = %d", (int) $entry_id ), ARRAY_A );
	}

	public function search_entries( string $query ): array {
		$db = $this->cfdb();
		$rows = $db->get_results( $db->prepare( "SELECT * FROM {$db->prefix}db7_forms WHERE form_value LIKE %s LIMIT 50", '%' . $db->esc_like( $query ) . '%' ), ARRAY_A );
		return [ 'items' => $rows ?: [], 'total' => count( $rows ?: [] ) ];
	}

	public function export_entries( string $form_id ): array {
		$db = $this->cfdb();
		$rows = $db->get_results( $db->prepare( "SELECT * FROM {$db->prefix}db7_forms WHERE form_post_id = %d ORDER BY form_date DESC LIMIT 1000", (int) $form_id ), ARRAY_A );
		return [ 'form_id' => (int) $form_id, 'count' => count( $rows ?: [] ), 'entries' => $rows ?: [] ];
	}

	public function submission_stats(): array {
		$db = $this->cfdb(); $t = $db->prefix . 'db7_forms';
		$total = $db->get_var( "SELECT COUNT(*) FROM $t" );
		$today = $db->get_var( "SELECT COUNT(*) FROM $t WHERE DATE(FROM_UNIXTIME(form_date)) = CURDATE()" );
		$week  = $db->get_var( "SELECT COUNT(*) FROM $t WHERE YEARWEEK(FROM_UNIXTIME(form_date)) = YEARWEEK(CURDATE())" );
		$month = $db->get_var( "SELECT COUNT(*) FROM $t WHERE MONTH(FROM_UNIXTIME(form_date)) = MONTH(CURDATE()) AND YEAR(FROM_UNIXTIME(form_date)) = YEAR(CURDATE())" );
		return [ 'total' => (int) $total, 'today' => (int) $today, 'this_week' => (int) $week, 'this_month' => (int) $month ];
	}

	public function analyze_form( string $form_id ): array {
		$form = $this->get_form( $form_id );
		if ( ! $form ) return [ 'error' => 'Form not found' ];
		$issues = [];
		$mail = $form['mail'] ?? [];
		if ( empty( $mail['recipient'] ) || ! is_email( $mail['recipient'] ) ) $issues[] = [ 'type' => 'email', 'severity' => 'high', 'message' => 'Missing or invalid admin email recipient' ];
		if ( empty( $mail['sender'] ) ) $issues[] = [ 'type' => 'email', 'severity' => 'medium', 'message' => 'Missing sender email' ];
		if ( ! isset( $mail['use_html'] ) || ! $mail['use_html'] ) $issues[] = [ 'type' => 'email', 'severity' => 'low', 'message' => 'HTML email not enabled' ];
		$markup = $form['form_markup'] ?? '';
		if ( ! str_contains( $markup, 'required' ) && ! str_contains( $markup, '*' ) ) $issues[] = [ 'type' => 'validation', 'severity' => 'medium', 'message' => 'No required fields defined' ];
		if ( ! str_contains( $markup, '[recaptcha]' ) && ! str_contains( $markup, 'captcha' ) ) $issues[] = [ 'type' => 'spam', 'severity' => 'medium', 'message' => 'No spam protection (reCAPTCHA) configured' ];
		return [ 'form_id' => (int) $form_id, 'title' => $form['title'], 'issue_count' => count( $issues ), 'issues' => $issues ];
	}

	public function get_notification( string $form_id ): ?array {
		$mail = get_post_meta( (int) $form_id, '_mail', true );
		$mail2 = get_post_meta( (int) $form_id, '_mail_2', true );
		return [ 'admin' => $mail, 'autoresponder' => $mail2 ];
	}

	public function update_notification( string $form_id, array $data ): ?array {
		if ( isset( $data['admin'] ) ) update_post_meta( (int) $form_id, '_mail', $this->sanitize_mail_config( $data['admin'] ) );
		if ( isset( $data['autoresponder'] ) ) update_post_meta( (int) $form_id, '_mail_2', $this->sanitize_mail_config( $data['autoresponder'] ) );
		return $this->get_notification( $form_id );
	}

	/**
	 * Strip CR/LF from single-line mail fields to prevent header injection
	 * via the recipient/sender values stored for CF7's mailer.
	 */
	private function sanitize_mail_config( array $mail ): array {
		foreach ( [ 'recipient', 'sender' ] as $field ) {
			if ( isset( $mail[ $field ] ) && is_string( $mail[ $field ] ) ) {
				$mail[ $field ] = str_replace( [ "\r", "\n" ], '', $mail[ $field ] );
			}
		}
		return $mail;
	}

	public function test_notification( string $form_id ): ?array {
		$mail = get_post_meta( (int) $form_id, '_mail', true );
		if ( empty( $mail['recipient'] ) ) return [ 'sent' => false, 'error' => 'No recipient configured' ];
		$sent = wp_mail( $mail['recipient'], 'WPCC Notification Test — ' . get_the_title( (int) $form_id ), 'This is a test notification from WP Command Center.' );
		return [ 'sent' => $sent, 'recipient' => $mail['recipient'] ];
	}

	private function summarize( \WP_Post $p ): array {
		return [ 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'modified' => $p->post_modified, 'shortcode' => '[contact-form-7 id="' . $p->ID . '" title="' . esc_attr( $p->post_title ) . '"]' ];
	}

	private function get_template( string $tpl ): string {
		$templates = [
			'contact_basic' => "<label>Your name\n[text* your-name]</label>\n<label>Your email\n[email* your-email]</label>\n<label>Subject\n[text your-subject]</label>\n<label>Your message\n[textarea your-message]</label>\n[submit \"Send\"]",
			'newsletter' => "<label>Your email\n[email* your-email]</label>\n[submit \"Subscribe\"]",
			'quote_request' => "<label>Your name\n[text* your-name]</label>\n<label>Your email\n[email* your-email]</label>\n<label>Service needed\n[select service \"Web Design\" \"Development\" \"SEO\" \"Other\"]</label>\n<label>Details\n[textarea your-details]</label>\n[submit \"Get Quote\"]",
		];
		return $templates[ $tpl ] ?? $templates['contact_basic'];
	}

	private function default_mail(): array {
		return [ 'subject' => '[your-subject]', 'sender' => '[your-name] <wordpress@' . wp_parse_url( get_site_url(), PHP_URL_HOST ) . '>', 'body' => 'From: [your-name] <[your-email]>\nSubject: [your-subject]\n\nMessage Body:\n[your-message]', 'recipient' => get_option( 'admin_email' ), 'additional_headers' => '', 'attachments' => '', 'use_html' => false, 'exclude_blank' => false ];
	}

	private function cfdb(): \wpdb { global $wpdb; return $wpdb; }
}

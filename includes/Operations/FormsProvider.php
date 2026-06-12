<?php
namespace WPCommandCenter\Operations;

interface FormsProvider {
	public function is_available(): bool;
	public function get_name(): string;
	public function list_forms( array $params ): array;
	public function get_form( string $id ): ?array;
	public function search_forms( string $query ): array;
	public function create_form( array $data ): ?array;
	public function update_form( string $id, array $data ): ?array;
	public function duplicate_form( string $id ): ?array;
	public function delete_form( string $id ): ?array;
	public function activate_form( string $id ): ?array;
	public function deactivate_form( string $id ): ?array;
	public function list_entries( string $form_id, array $params ): array;
	public function get_entry( string $entry_id, string $form_id ): ?array;
	public function search_entries( string $query ): array;
	public function export_entries( string $form_id ): array;
	public function submission_stats(): array;
	public function analyze_form( string $form_id ): array;
	public function get_notification( string $form_id ): ?array;
	public function update_notification( string $form_id, array $data ): ?array;
	public function test_notification( string $form_id ): ?array;
}

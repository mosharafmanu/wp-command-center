<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\WooProductAccessor;
use WPCommandCenter\Rollback\OptionListRollbackStore;

defined( 'ABSPATH' ) || exit;

final class WooCommerceRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->error( 'wpcc_woo_inactive', __( 'WooCommerce is not active.', 'wp-command-center' ) );
		}
		$action = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $action, WooCommerceRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_woo_action', __( 'Invalid WooCommerce action.', 'wp-command-center' ) );
		}
		return match ( $action ) {
			WooCommerceRegistry::ACTION_PRODUCT_LIST      => $this->product_list( $payload ),
			WooCommerceRegistry::ACTION_PRODUCT_GET       => $this->product_get( $payload ),
			WooCommerceRegistry::ACTION_PRODUCT_SEARCH    => $this->product_search( $payload ),
			WooCommerceRegistry::ACTION_PRODUCT_CREATE    => $this->product_create( $payload, $context ),
			WooCommerceRegistry::ACTION_PRODUCT_UPDATE    => $this->product_update( $payload, $context ),
			WooCommerceRegistry::ACTION_PRODUCT_DELETE    => $this->product_delete( $payload, $context ),
			WooCommerceRegistry::ACTION_PRODUCT_PUBLISH   => $this->product_publish( $payload, $context ),
			WooCommerceRegistry::ACTION_PRODUCT_UNPUBLISH => $this->product_unpublish( $payload, $context ),
			WooCommerceRegistry::ACTION_PRODUCT_DUPLICATE => $this->product_duplicate( $payload, $context ),
			WooCommerceRegistry::ACTION_STOCK_GET         => $this->stock_get( $payload ),
			WooCommerceRegistry::ACTION_STOCK_UPDATE      => $this->stock_update( $payload, $context ),
			WooCommerceRegistry::ACTION_STOCK_BULK_UPDATE => $this->stock_bulk_update( $payload, $context ),
			WooCommerceRegistry::ACTION_PRICE_GET         => $this->price_get( $payload ),
			WooCommerceRegistry::ACTION_PRICE_UPDATE      => $this->price_update( $payload, $context ),
			WooCommerceRegistry::ACTION_SALE_PRICE_UPDATE => $this->sale_price_update( $payload, $context ),
			WooCommerceRegistry::ACTION_CATEGORY_ASSIGN   => $this->category_assign( $payload, $context ),
			WooCommerceRegistry::ACTION_CATEGORY_REMOVE   => $this->category_remove( $payload, $context ),
			WooCommerceRegistry::ACTION_CATEGORY_LIST     => $this->category_list( $payload ),
			WooCommerceRegistry::ACTION_ATTRIBUTE_ASSIGN  => $this->attribute_assign( $payload, $context ),
			WooCommerceRegistry::ACTION_ATTRIBUTE_REMOVE  => $this->attribute_remove( $payload, $context ),
			WooCommerceRegistry::ACTION_ATTRIBUTE_LIST    => $this->attribute_list( $payload ),
			WooCommerceRegistry::ACTION_VARIATION_LIST    => $this->variation_list( $payload ),
			WooCommerceRegistry::ACTION_VARIATION_GET     => $this->variation_get( $payload ),
			WooCommerceRegistry::ACTION_VARIATION_CREATE  => $this->variation_create( $payload, $context ),
			WooCommerceRegistry::ACTION_VARIATION_UPDATE  => $this->variation_update( $payload, $context ),
			WooCommerceRegistry::ACTION_VARIATION_DELETE  => $this->variation_delete( $payload, $context ),
			WooCommerceRegistry::ACTION_ORDER_LIST        => $this->order_list( $payload ),
			WooCommerceRegistry::ACTION_ORDER_GET         => $this->order_get( $payload ),
			WooCommerceRegistry::ACTION_ORDER_SEARCH      => $this->order_search( $payload ),
			WooCommerceRegistry::ACTION_ORDER_UPDATE        => $this->order_update( $payload, $context ),
			WooCommerceRegistry::ACTION_ORDER_NOTE_ADD      => $this->order_note_add( $payload, $context ),
			WooCommerceRegistry::ACTION_ORDER_STATUS_CHANGE => $this->order_status_change( $payload, $context ),
			WooCommerceRegistry::ACTION_REFUND_CREATE       => $this->refund_create( $payload, $context ),
			WooCommerceRegistry::ACTION_CUSTOMER_GET        => $this->customer_get( $payload ),
			WooCommerceRegistry::ACTION_CUSTOMER_SEARCH     => $this->customer_search( $payload ),
			WooCommerceRegistry::ACTION_COUPON_LIST       => $this->coupon_list( $payload ),
			WooCommerceRegistry::ACTION_COUPON_GET        => $this->coupon_get( $payload ),
			WooCommerceRegistry::ACTION_COUPON_CREATE     => $this->coupon_create( $payload, $context ),
			WooCommerceRegistry::ACTION_COUPON_UPDATE     => $this->coupon_update( $payload, $context ),
			WooCommerceRegistry::ACTION_COUPON_DELETE     => $this->coupon_delete( $payload, $context ),
			default => $this->error( 'wpcc_unknown_woo_action', __( 'Unknown WooCommerce action.', 'wp-command-center' ) ),
		};
	}

	// ── Products ──

	private function product_list( array $payload ): array {
		$per_page = min( 100, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$args = [ 'limit' => $per_page, 'page' => $page, 'paginate' => true ];
		if ( isset( $payload['status'] ) ) $args['status'] = sanitize_text_field( (string) $payload['status'] );
		if ( isset( $payload['category'] ) ) $args['category'] = [ sanitize_text_field( (string) $payload['category'] ) ];

		$result = wc_get_products( $args );
		$items = [];
		foreach ( $result->products as $p ) $items[] = $this->format_product( $p );
		$this->audit->record( 'product.list', [ 'count' => count( $items ) ] );
		return [ 'action' => 'product_list', 'items' => $items, 'total' => $result->total, 'page' => $page ];
	}

	private function product_get( array $payload ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$this->audit->record( 'product.get', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'product_get', 'product' => $this->format_product( $p ) ];
	}

	private function product_search( array $payload ): array {
		$s = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );
		if ( '' === $s ) return $this->error( 'wpcc_empty_search', __( 'Search term is required.', 'wp-command-center' ) );
		$result = wc_get_products( [ 's' => $s, 'limit' => 50, 'paginate' => true ] );
		$items = []; foreach ( $result->products as $p ) $items[] = $this->format_product( $p );
		return [ 'action' => 'product_search', 'items' => $items, 'total' => $result->total ];
	}

	private function product_create( array $payload, array $context ): array {
		$name = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		if ( '' === $name ) return $this->error( 'wpcc_missing_name', __( 'Product name is required.', 'wp-command-center' ) );

		$type = sanitize_key( (string) ( $payload['type'] ?? 'simple' ) );
		$p    = match ( $type ) {
			'variable' => new \WC_Product_Variable(),
			'grouped'  => new \WC_Product_Grouped(),
			'external' => new \WC_Product_External(),
			default    => new \WC_Product_Simple(),
		};
		$p->set_name( $name );
		$p->set_status( isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'draft' );
		$this->apply_product_fields( $p, $payload );
		$id = $p->save();

		$rollback_id = $this->store_rollback( $id, 'product_create', [], $context );
		$this->audit->record( 'product.created', [ 'product_id' => $id, 'name' => $name, 'type' => $type ] );
		return [ 'action' => 'product_create', 'product_id' => $id, 'name' => $name, 'type' => $type, 'rollback_id' => $rollback_id ];
	}

	private function product_update( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$id = $p->get_id();

		// PROGRAM-4 / P4.6 — capture ONLY the fields this call touches, BEFORE the write
		// (field-scoped, drift-aware delta via the RollbackDelta core). Replaces the full
		// 16-field snapshot/restore that clobbered siblings on layered edits (F-1).
		$accessor = new WooProductAccessor();
		$touched  = $this->product_touched_fields( $payload );
		$prior    = RollbackDelta::capture( $accessor, $id, $touched );

		$this->apply_product_fields( $p, $payload );
		$p->save();

		$after = [];
		foreach ( $touched as $field ) {
			$after[ $field ] = $accessor->read_field( $id, $field );
		}
		$rollback_id = $this->store_product_delta( $id, $touched, $prior, $after, $context );

		$this->audit->record( 'product.updated', [ 'product_id' => $id ] );
		return [ 'action' => 'product_update', 'product_id' => $id, 'rollback_id' => $rollback_id ];
	}

	/**
	 * PROGRAM-4 / P4.6 — the unified product fields this update writes, derived to match
	 * apply_product_fields() exactly so capture/after/restore cover precisely what changed.
	 *
	 * Boundary note: WooCommerce may implicitly recalc stock_status on save when a
	 * stock_quantity change crosses the no-stock threshold. That implicit shift is captured
	 * only when the payload also sends stock_status (its field-scoped contract). This is
	 * still strictly safer than the old full-object restore, which clobbered every sibling.
	 *
	 * @return string[]
	 */
	private function product_touched_fields( array $payload ): array {
		$touched = [];
		foreach ( [ 'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'status', 'stock_status', 'image_id', 'gallery_image_ids' ] as $f ) {
			if ( isset( $payload[ $f ] ) ) $touched[] = $f;
		}
		if ( isset( $payload['categories'] ) ) $touched[] = 'category_ids';
		if ( isset( $payload['tags'] ) )       $touched[] = 'tag_ids';
		if ( isset( $payload['attributes'] ) && is_array( $payload['attributes'] ) ) $touched[] = 'attributes';
		if ( array_key_exists( 'manage_stock', $payload ) ) {
			$touched[] = 'manage_stock';
			if ( ! empty( $payload['manage_stock'] ) && isset( $payload['stock_quantity'] ) ) $touched[] = 'stock_quantity';
		}
		return $touched;
	}

	/**
	 * PROGRAM-4 / P4.6 — persist a v2 field-scoped delta record for product_update into the
	 * shared wpcc_woo_rollbacks option (FIFO cap 200). Legacy full-object before_state
	 * records remain readable; all other actions keep using store_rollback().
	 */
	private function store_product_delta( int $id, array $touched, array $prior, array $after, array $context ): string {
		$rid    = wp_generate_uuid4();
		$record = RollbackDelta::build_record( $touched, $prior, $after, $context, [
			'id'          => $rid,
			'entity_id'   => $id,
			'entity_type' => 'product',
			'action'      => 'product_update',
		] );
		( new OptionListRollbackStore( 'wpcc_woo_rollbacks', 200 ) )->persist( $id, $rid, $record );
		return $rid;
	}

	private function product_delete( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $this->format_product( $p );
		$this->store_rollback( $p->get_id(), 'product_delete', $before, $context );
		$name = $p->get_name();
		$p->delete( ! empty( $payload['force'] ) );
		$this->audit->record( 'product.deleted', [ 'product_id' => $before['id'], 'name' => $name ] );
		return [ 'action' => 'product_delete', 'product_id' => $before['id'], 'name' => $name ];
	}

	private function product_publish( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $p->get_status();
		$p->set_status( 'publish' );
		$p->save();
		$this->store_rollback( $p->get_id(), 'product_publish', [ 'status' => $before ], $context );
		$this->audit->record( 'product.published', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'product_publish', 'product_id' => $p->get_id() ];
	}

	private function product_unpublish( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $p->get_status();
		$p->set_status( 'draft' );
		$p->save();
		$this->store_rollback( $p->get_id(), 'product_unpublish', [ 'status' => $before ], $context );
		$this->audit->record( 'product.unpublished', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'product_unpublish', 'product_id' => $p->get_id() ];
	}

	private function product_duplicate( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		if ( ! class_exists( '\WC_Admin_Duplicate_Product' ) ) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		}
		$duplicate = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( $p );
		if ( ! $duplicate || ! $duplicate->get_id() ) return $this->error( 'wpcc_duplicate_failed', __( 'Failed to duplicate product.', 'wp-command-center' ) );
		$dup_id = $duplicate->get_id();
		$this->store_rollback( $dup_id, 'product_create', [], $context );
		return [ 'action' => 'product_duplicate', 'product_id' => $dup_id, 'original_id' => $p->get_id() ];
	}

	// ── Inventory ──

	private function stock_get( array $payload ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		return [ 'action' => 'stock_get', 'product_id' => $p->get_id(), 'stock_quantity' => $p->get_stock_quantity(), 'stock_status' => $p->get_stock_status(), 'manage_stock' => $p->get_manage_stock() ];
	}

	private function stock_update( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = [ 'stock' => $p->get_stock_quantity(), 'status' => $p->get_stock_status() ];
		$qty = (int) ( $payload['quantity'] ?? 0 );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $qty );
		$p->save();
		$this->store_rollback( $p->get_id(), 'stock_update', $before, $context );
		$this->audit->record( 'stock.updated', [ 'product_id' => $p->get_id(), 'quantity' => $qty ] );
		return [ 'action' => 'stock_update', 'product_id' => $p->get_id(), 'quantity' => $qty ];
	}

	private function stock_bulk_update( array $payload, array $context ): array {
		$updates = (array) ( $payload['updates'] ?? [] );
		$results = [];
		foreach ( $updates as $u ) {
			$p = wc_get_product( (int) ( $u['product_id'] ?? 0 ) );
			if ( ! $p ) continue;
			$before = [ 'stock' => $p->get_stock_quantity() ];
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) ( $u['quantity'] ?? 0 ) );
			$p->save();
			$this->store_rollback( $p->get_id(), 'stock_update', $before, $context );
			$results[] = [ 'product_id' => $p->get_id(), 'quantity' => (int) ( $u['quantity'] ?? 0 ) ];
		}
		$this->audit->record( 'stock.bulk_updated', [ 'count' => count( $results ) ] );
		return [ 'action' => 'stock_bulk_update', 'updated' => $results ];
	}

	// ── Pricing ──

	private function price_get( array $payload ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		return [ 'action' => 'price_get', 'product_id' => $p->get_id(), 'regular_price' => $p->get_regular_price(), 'sale_price' => $p->get_sale_price(), 'price' => $p->get_price() ];
	}

	private function price_update( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = [ 'regular' => $p->get_regular_price(), 'sale' => $p->get_sale_price() ];
		$p->set_regular_price( (string) ( $payload['regular_price'] ?? '' ) );
		$p->save();
		$this->store_rollback( $p->get_id(), 'price_update', $before, $context );
		$this->audit->record( 'price.updated', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'price_update', 'product_id' => $p->get_id(), 'regular_price' => $p->get_regular_price() ];
	}

	private function sale_price_update( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = [ 'regular' => $p->get_regular_price(), 'sale' => $p->get_sale_price() ];
		$p->set_sale_price( (string) ( $payload['sale_price'] ?? '' ) );
		$p->save();
		$this->store_rollback( $p->get_id(), 'price_update', $before, $context );
		$this->audit->record( 'price.updated', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'sale_price_update', 'product_id' => $p->get_id(), 'sale_price' => $p->get_sale_price() ];
	}

	// ── Categories ──

	private function category_list( array $payload ): array {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$items = []; foreach ( $terms as $t ) $items[] = [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ];
		return [ 'action' => 'product_category_list', 'categories' => $items, 'total' => count( $items ) ];
	}

	private function category_assign( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $p->get_category_ids();
		if ( is_numeric( $payload['category_id'] ?? null ) )
			$p->set_category_ids( array_merge( $before, [ (int) $payload['category_id'] ] ) );
		else
			wp_set_object_terms( $p->get_id(), sanitize_text_field( (string) ( $payload['category'] ?? '' ) ), 'product_cat', true );
		$p->save();
		$this->store_rollback( $p->get_id(), 'category_assign', [ 'category_ids' => $before ], $context );
		return [ 'action' => 'product_category_assign', 'product_id' => $p->get_id(), 'categories' => $p->get_category_ids() ];
	}

	private function category_remove( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $p->get_category_ids();
		$cid = (int) ( $payload['category_id'] ?? 0 );
		$p->set_category_ids( array_diff( $before, [ $cid ] ) );
		$p->save();
		$this->store_rollback( $p->get_id(), 'category_remove', [ 'category_ids' => $before ], $context );
		return [ 'action' => 'product_category_remove', 'product_id' => $p->get_id(), 'categories' => $p->get_category_ids() ];
	}

	// ── Attributes ──

	private function attribute_list( array $payload ): array {
		$attrs = wc_get_attribute_taxonomies();
		$items = []; foreach ( $attrs as $a ) $items[] = [ 'id' => $a->attribute_id, 'name' => $a->attribute_label, 'slug' => $a->attribute_name ];
		return [ 'action' => 'product_attribute_list', 'attributes' => $items, 'total' => count( $items ) ];
	}

	private function attribute_assign( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$name = sanitize_text_field( (string) ( $payload['attribute_name'] ?? '' ) );
		$val  = sanitize_text_field( (string) ( $payload['value'] ?? '' ) );
		if ( '' === $name ) return $this->error( 'wpcc_missing_attribute', __( 'Attribute name is required.', 'wp-command-center' ) );
		$before = $p->get_attributes();
		$attrs = $p->get_attributes();
		$attrs[ sanitize_title( $name ) ] = [ 'name' => $name, 'value' => $val, 'is_visible' => true, 'is_variation' => false, 'is_taxonomy' => false ];
		$p->set_attributes( $attrs );
		$p->save();
		$this->store_rollback( $p->get_id(), 'attribute_assign', [ 'attributes' => $before ], $context );
		return [ 'action' => 'product_attribute_assign', 'product_id' => $p->get_id() ];
	}

	private function attribute_remove( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$slug = sanitize_title( (string) ( $payload['attribute_name'] ?? '' ) );
		$before = $p->get_attributes();
		$attrs = $p->get_attributes();
		unset( $attrs[ $slug ] );
		$p->set_attributes( $attrs );
		$p->save();
		$this->store_rollback( $p->get_id(), 'attribute_remove', [ 'attributes' => $before ], $context );
		return [ 'action' => 'product_attribute_remove', 'product_id' => $p->get_id() ];
	}

	// ── Variations ──

	private function variation_list( array $payload ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p || ! $p->is_type( 'variable' ) ) return $this->error( 'wpcc_not_variable', __( 'Product is not variable.', 'wp-command-center' ) );
		$vars = $p->get_available_variations();
		$items = []; foreach ( $vars as $v ) $items[] = [ 'id' => $v['variation_id'], 'attributes' => $v['attributes'] ?? [], 'price' => $v['display_price'] ?? 0 ];
		return [ 'action' => 'variation_list', 'product_id' => $p->get_id(), 'variations' => $items, 'total' => count( $items ) ];
	}

	private function variation_get( array $payload ): array {
		$v = wc_get_product( (int) ( $payload['variation_id'] ?? 0 ) );
		if ( ! $v || ! $v->is_type( 'variation' ) ) return $this->error( 'wpcc_variation_not_found', __( 'Variation not found.', 'wp-command-center' ) );
		return [ 'action' => 'variation_get', 'variation' => $this->format_product( $v ) ];
	}

	private function variation_create( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p || ! $p->is_type( 'variable' ) ) return $this->error( 'wpcc_not_variable', __( 'Parent must be a variable product.', 'wp-command-center' ) );
		$v = new \WC_Product_Variation();
		$v->set_parent_id( $p->get_id() );
		if ( isset( $payload['regular_price'] ) ) $v->set_regular_price( (string) $payload['regular_price'] );
		if ( isset( $payload['attributes'] ) ) $v->set_attributes( (array) $payload['attributes'] );
		$v->set_status( 'publish' );
		$id = $v->save();
		$this->store_rollback( $id, 'variation_create', [], $context );
		$this->audit->record( 'variation.created', [ 'variation_id' => $id, 'parent_id' => $p->get_id() ] );
		return [ 'action' => 'variation_create', 'variation_id' => $id, 'parent_id' => $p->get_id() ];
	}

	private function variation_update( array $payload, array $context ): array {
		$v = wc_get_product( (int) ( $payload['variation_id'] ?? 0 ) );
		if ( ! $v || ! $v->is_type( 'variation' ) ) return $this->error( 'wpcc_variation_not_found', __( 'Variation not found.', 'wp-command-center' ) );
		if ( isset( $payload['regular_price'] ) ) $v->set_regular_price( (string) $payload['regular_price'] );
		if ( isset( $payload['stock_quantity'] ) ) { $v->set_manage_stock( true ); $v->set_stock_quantity( (int) $payload['stock_quantity'] ); }
		$v->save();
		$this->audit->record( 'variation.updated', [ 'variation_id' => $v->get_id() ] );
		return [ 'action' => 'variation_update', 'variation_id' => $v->get_id() ];
	}

	private function variation_delete( array $payload, array $context ): array {
		$v = wc_get_product( (int) ( $payload['variation_id'] ?? 0 ) );
		if ( ! $v || ! $v->is_type( 'variation' ) ) return $this->error( 'wpcc_variation_not_found', __( 'Variation not found.', 'wp-command-center' ) );
		$before = $this->format_product( $v );
		$this->store_rollback( $v->get_id(), 'variation_delete', $before, $context );
		$v->delete( true );
		$this->audit->record( 'variation.deleted', [ 'variation_id' => $before['id'] ] );
		return [ 'action' => 'variation_delete', 'variation_id' => $before['id'] ];
	}

	// ── Orders (read-only) ──

	private function order_list( array $payload ): array {
		$per_page = min( 100, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$query = new \WC_Order_Query( [ 'limit' => $per_page, 'page' => $page, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] );
		$ids = $query->get_orders();
		$items = []; foreach ( $ids as $id ) { $o = wc_get_order( $id ); $items[] = $this->format_order( $o ); }
		$this->audit->record( 'order.list', [ 'count' => count( $items ) ] );
		return [ 'action' => 'order_list', 'items' => $items, 'total' => count( $items ), 'page' => $page ];
	}

	private function order_get( array $payload ): array {
		$o = wc_get_order( (int) ( $payload['order_id'] ?? 0 ) );
		if ( ! $o ) return $this->error( 'wpcc_order_not_found', __( 'Order not found.', 'wp-command-center' ) );
		return [ 'action' => 'order_get', 'order' => $this->format_order( $o ) ];
	}

	private function order_search( array $payload ): array {
		$s = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );
		if ( '' === $s ) return $this->error( 'wpcc_empty_search', __( 'Search term is required.', 'wp-command-center' ) );
		$query = new \WC_Order_Query( [ 'limit' => 50, 'return' => 'ids' ] );
		$ids = $query->get_orders();
		$items = []; foreach ( $ids as $id ) { $o = wc_get_order( $id ); if ( false !== stripos( $o->get_billing_email() . $o->get_billing_first_name(), $s ) ) $items[] = $this->format_order( $o ); }
		return [ 'action' => 'order_search', 'items' => $items, 'total' => count( $items ) ];
	}

	// ── STEP 94 — order + customer management ──

	private const ORDER_BILLING_FIELDS = [ 'first_name', 'last_name', 'email', 'phone', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];

	private function order_update( array $payload, array $context ): array {
		$o = wc_get_order( (int) ( $payload['order_id'] ?? 0 ) );
		if ( ! $o ) return $this->error( 'wpcc_order_not_found', __( 'Order not found.', 'wp-command-center' ) );

		$before = [ 'customer_note' => $o->get_customer_note(), 'billing' => [] ];
		foreach ( self::ORDER_BILLING_FIELDS as $f ) { $before['billing'][ $f ] = $o->{"get_billing_$f"}(); }

		if ( isset( $payload['customer_note'] ) ) {
			$o->set_customer_note( sanitize_textarea_field( (string) $payload['customer_note'] ) );
		}
		if ( isset( $payload['billing'] ) && is_array( $payload['billing'] ) ) {
			foreach ( self::ORDER_BILLING_FIELDS as $f ) {
				if ( isset( $payload['billing'][ $f ] ) ) {
					$o->{"set_billing_$f"}( sanitize_text_field( (string) $payload['billing'][ $f ] ) );
				}
			}
		}
		$o->save();

		$rollback_id = $this->store_rollback( $o->get_id(), 'order_update', $before, $context );
		$this->audit->record( 'order.updated', [ 'order_id' => $o->get_id() ] );
		return [ 'action' => 'order_update', 'order_id' => $o->get_id(), 'rollback_id' => $rollback_id ];
	}

	private function order_note_add( array $payload, array $context ): array {
		$o = wc_get_order( (int) ( $payload['order_id'] ?? 0 ) );
		if ( ! $o ) return $this->error( 'wpcc_order_not_found', __( 'Order not found.', 'wp-command-center' ) );
		$note = sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) );
		if ( '' === $note ) return $this->error( 'wpcc_empty_note', __( 'A note is required.', 'wp-command-center' ) );
		$is_customer = ! empty( $payload['customer_note'] );

		$note_id = $o->add_order_note( $note, $is_customer ? 1 : 0, false );

		$rollback_id = $this->store_rollback( $o->get_id(), 'order_note_add', [ 'note_id' => $note_id ], $context );
		$this->audit->record( 'order.note_added', [ 'order_id' => $o->get_id(), 'note_id' => $note_id, 'customer_note' => $is_customer ] );
		return [ 'action' => 'order_note_add', 'order_id' => $o->get_id(), 'note_id' => $note_id, 'customer_note' => $is_customer, 'rollback_id' => $rollback_id ];
	}

	private function order_status_change( array $payload, array $context ): array {
		$o = wc_get_order( (int) ( $payload['order_id'] ?? 0 ) );
		if ( ! $o ) return $this->error( 'wpcc_order_not_found', __( 'Order not found.', 'wp-command-center' ) );
		$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			return $this->error( 'wpcc_invalid_order_status', sprintf( __( 'Invalid order status: %s', 'wp-command-center' ), esc_html( $status ) ) );
		}

		$before = [ 'status' => $o->get_status() ];
		$o->update_status( $status, sanitize_text_field( (string) ( $payload['note'] ?? '' ) ), true );

		$rollback_id = $this->store_rollback( $o->get_id(), 'order_status_change', $before, $context );
		$this->audit->record( 'order.status_changed', [ 'order_id' => $o->get_id(), 'from' => $before['status'], 'to' => $status ] );
		return [ 'action' => 'order_status_change', 'order_id' => $o->get_id(), 'status' => $o->get_status(), 'previous_status' => $before['status'], 'rollback_id' => $rollback_id ];
	}

	private function refund_create( array $payload, array $context ): array {
		$o = wc_get_order( (int) ( $payload['order_id'] ?? 0 ) );
		if ( ! $o ) return $this->error( 'wpcc_order_not_found', __( 'Order not found.', 'wp-command-center' ) );
		$amount = isset( $payload['amount'] ) ? (string) $payload['amount'] : (string) $o->get_remaining_refund_amount();
		if ( (float) $amount <= 0 ) return $this->error( 'wpcc_invalid_refund_amount', __( 'Refund amount must be greater than zero.', 'wp-command-center' ) );

		$refund = wc_create_refund( [
			'order_id' => $o->get_id(),
			'amount'   => $amount,
			'reason'   => sanitize_text_field( (string) ( $payload['reason'] ?? '' ) ),
		] );
		if ( is_wp_error( $refund ) ) {
			return $this->error( 'wpcc_refund_failed', $refund->get_error_message() );
		}

		$rollback_id = $this->store_rollback( $refund->get_id(), 'refund_create', [ 'order_id' => $o->get_id() ], $context );
		$this->audit->record( 'order.refunded', [ 'order_id' => $o->get_id(), 'refund_id' => $refund->get_id(), 'amount' => $amount ] );
		return [ 'action' => 'refund_create', 'order_id' => $o->get_id(), 'refund_id' => $refund->get_id(), 'amount' => $amount, 'rollback_id' => $rollback_id ];
	}

	private function customer_get( array $payload ): array {
		$id = (int) ( $payload['customer_id'] ?? 0 );
		if ( $id <= 0 && ! empty( $payload['email'] ) ) {
			$u = get_user_by( 'email', sanitize_email( (string) $payload['email'] ) );
			$id = $u ? (int) $u->ID : 0;
		}
		if ( $id <= 0 ) return $this->error( 'wpcc_customer_not_found', __( 'Customer not found.', 'wp-command-center' ) );
		try { $c = new \WC_Customer( $id ); } catch ( \Exception $e ) { return $this->error( 'wpcc_customer_not_found', __( 'Customer not found.', 'wp-command-center' ) ); }
		if ( ! $c->get_id() ) return $this->error( 'wpcc_customer_not_found', __( 'Customer not found.', 'wp-command-center' ) );

		$this->audit->record( 'customer.get', [ 'customer_id' => $id ] );
		return [ 'action' => 'customer_get', 'customer' => $this->format_customer( $c ) ];
	}

	private function customer_search( array $payload ): array {
		$s = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );
		if ( '' === $s ) return $this->error( 'wpcc_empty_search', __( 'Search term is required.', 'wp-command-center' ) );
		$users = get_users( [ 'search' => '*' . $s . '*', 'search_columns' => [ 'user_login', 'user_email', 'display_name' ], 'number' => 50, 'fields' => [ 'ID' ] ] );
		$items = [];
		foreach ( $users as $u ) {
			try { $c = new \WC_Customer( (int) $u->ID ); if ( $c->get_id() ) $items[] = $this->format_customer( $c ); } catch ( \Exception $e ) { /* skip */ }
		}
		return [ 'action' => 'customer_search', 'customers' => $items, 'total' => count( $items ) ];
	}

	private function format_customer( \WC_Customer $c ): array {
		return [
			'id'          => $c->get_id(),
			'email'       => $c->get_email(),
			'first_name'  => $c->get_first_name(),
			'last_name'   => $c->get_last_name(),
			'username'    => $c->get_username(),
			'order_count' => $c->get_order_count(),
			'total_spent' => $c->get_total_spent(),
			'date_registered' => $c->get_date_created() ? $c->get_date_created()->date( 'Y-m-d' ) : '',
		];
	}

	// ── Coupons ──

	private function coupon_list( array $payload ): array {
		$per_page = min( 50, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$query = new \WC_Coupon_Data_Store_CPT();
		// Use WP_Query for coupons
		$wpq = new \WP_Query( [ 'post_type' => 'shop_coupon', 'posts_per_page' => $per_page, 'paged' => $page ] );
		$items = []; foreach ( $wpq->posts as $post ) { $c = new \WC_Coupon( $post->ID ); $items[] = $this->format_coupon( $c ); }
		return [ 'action' => 'coupon_list', 'items' => $items, 'total' => $wpq->found_posts, 'page' => $page ];
	}

	private function coupon_get( array $payload ): array {
		$c = new \WC_Coupon( (int) ( $payload['coupon_id'] ?? 0 ) );
		if ( ! $c->get_id() ) return $this->error( 'wpcc_coupon_not_found', __( 'Coupon not found.', 'wp-command-center' ) );
		return [ 'action' => 'coupon_get', 'coupon' => $this->format_coupon( $c ) ];
	}

	private function coupon_create( array $payload, array $context ): array {
		$code = sanitize_text_field( (string) ( $payload['code'] ?? '' ) );
		if ( '' === $code ) return $this->error( 'wpcc_missing_code', __( 'Coupon code is required.', 'wp-command-center' ) );
		$c = new \WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( sanitize_key( (string) ( $payload['discount_type'] ?? 'fixed_cart' ) ) );
		$c->set_amount( (float) ( $payload['amount'] ?? 0 ) );
		$id = $c->save();
		$this->store_rollback( $id, 'coupon_create', [], $context );
		$this->audit->record( 'coupon.created', [ 'coupon_id' => $id, 'code' => $code ] );
		return [ 'action' => 'coupon_create', 'coupon_id' => $id, 'code' => $code ];
	}

	private function coupon_update( array $payload, array $context ): array {
		$c = new \WC_Coupon( (int) ( $payload['coupon_id'] ?? 0 ) );
		if ( ! $c->get_id() ) return $this->error( 'wpcc_coupon_not_found', __( 'Coupon not found.', 'wp-command-center' ) );
		if ( isset( $payload['amount'] ) ) $c->set_amount( (float) $payload['amount'] );
		if ( isset( $payload['discount_type'] ) ) $c->set_discount_type( sanitize_key( (string) $payload['discount_type'] ) );
		$c->save();
		$this->audit->record( 'coupon.updated', [ 'coupon_id' => $c->get_id() ] );
		return [ 'action' => 'coupon_update', 'coupon_id' => $c->get_id() ];
	}

	private function coupon_delete( array $payload, array $context ): array {
		$c = new \WC_Coupon( (int) ( $payload['coupon_id'] ?? 0 ) );
		if ( ! $c->get_id() ) return $this->error( 'wpcc_coupon_not_found', __( 'Coupon not found.', 'wp-command-center' ) );
		$before = $this->format_coupon( $c );
		$this->store_rollback( $c->get_id(), 'coupon_delete', $before, $context );
		$c->delete( true );
		$this->audit->record( 'coupon.deleted', [ 'coupon_id' => $before['id'] ] );
		return [ 'action' => 'coupon_delete', 'coupon_id' => $before['id'] ];
	}

	// ── Helpers ──

	private function format_product( \WC_Product $p ): array {
		return [ 'id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(),
			'regular_price' => $p->get_regular_price(), 'sale_price' => $p->get_sale_price(),
			'price' => $p->get_price(), 'stock_quantity' => $p->get_stock_quantity(),
			'stock_status' => $p->get_stock_status(), 'status' => $p->get_status(),
			'categories' => wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'type' => $p->get_type(), 'description' => wp_trim_words( $p->get_description(), 20 ),
			'permalink' => get_permalink( $p->get_id() ) ];
	}

	private function format_order( \WC_Order $o ): array {
		return [ 'id' => $o->get_id(), 'status' => $o->get_status(),
			'total' => $o->get_total(), 'currency' => $o->get_currency(),
			'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'email' => $o->get_billing_email(), 'date' => $o->get_date_created()->date( 'Y-m-d H:i:s' ),
			'items_count' => $o->get_item_count(), 'payment_method' => $o->get_payment_method_title() ];
	}

	private function format_coupon( \WC_Coupon $c ): array {
		return [ 'id' => $c->get_id(), 'code' => $c->get_code(),
			'discount_type' => $c->get_discount_type(), 'amount' => $c->get_amount(),
			'usage_limit' => $c->get_usage_limit(), 'usage_count' => $c->get_usage_count(),
			'date_expires' => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : null ];
	}

	public function rollback( array $payload, array $context = [] ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'wp-command-center' ) );
		$rollbacks = get_option( 'wpcc_woo_rollbacks', [] );
		$rec = null; $idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( $r['id'] === $rollback_id ) { $rec = $r; $idx = $i; break; } }
		if ( ! $rec ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		if ( $rec['rollback_applied'] ) return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		$entity_id = $rec['entity_id'];
		$action    = $rec['action'];
		$etype     = $rec['entity_type'] ?? 'product';

		// PROGRAM-4 / P4.6 — field-scoped, drift-aware delta restore for v2 product_update
		// records. Only 'complete' is terminal; partial/conflict stay retryable and report
		// truthfully. Legacy before_state records (and every other action) fall through.
		if ( 'product_update' === $action && 2 === (int) ( $rec['version'] ?? 0 ) && isset( $rec['fields'] ) && is_array( $rec['fields'] ) ) {
			return $this->rollback_product_delta( $rec, $idx, $rollbacks, $context );
		}

		$before = $rec['before_state'];

		switch ( $etype ) {
			case 'product':
				if ( in_array( $action, [ 'product_create', 'variation_create', 'coupon_create' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) $p->delete( true ); }
				elseif ( 'product_update' === $action ) { $p = wc_get_product( $entity_id ); if ( $p ) $this->restore_product( $p, $before ); }
				elseif ( 'product_delete' === $action ) { wp_publish_post( $entity_id ); }
				elseif ( in_array( $action, [ 'product_publish', 'product_unpublish' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_status( $before['status'] ); $p->save(); } }
				elseif ( 'stock_update' === $action ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_stock_quantity( $before['stock'] ); $p->save(); } }
				elseif ( 'price_update' === $action ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_regular_price( $before['regular'] ); $p->set_sale_price( $before['sale'] ); $p->save(); } }
				elseif ( in_array( $action, [ 'category_assign', 'category_remove' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_category_ids( $before['category_ids'] ?? [] ); $p->save(); } }
				elseif ( in_array( $action, [ 'attribute_assign', 'attribute_remove' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_attributes( $before['attributes'] ?? [] ); $p->save(); } }
				// STEP 94 — order/refund rollbacks (entity_type defaults to product).
				elseif ( 'order_update' === $action ) {
					$o = wc_get_order( $entity_id );
					if ( $o ) {
						if ( isset( $before['customer_note'] ) ) $o->set_customer_note( (string) $before['customer_note'] );
						if ( isset( $before['billing'] ) && is_array( $before['billing'] ) ) {
							foreach ( self::ORDER_BILLING_FIELDS as $f ) {
								if ( isset( $before['billing'][ $f ] ) ) $o->{"set_billing_$f"}( (string) $before['billing'][ $f ] );
							}
						}
						$o->save();
					}
				}
				elseif ( 'order_status_change' === $action ) {
					$o = wc_get_order( $entity_id );
					if ( $o && isset( $before['status'] ) ) $o->update_status( (string) $before['status'], __( 'Rolled back by WP Command Center.', 'wp-command-center' ), true );
				}
				elseif ( 'order_note_add' === $action ) {
					if ( ! empty( $before['note_id'] ) ) wp_delete_comment( (int) $before['note_id'], true );
				}
				elseif ( 'refund_create' === $action ) {
					$refund = wc_get_order( $entity_id );
					if ( $refund instanceof \WC_Order_Refund ) $refund->delete( true );
				}
				break;
			case 'coupon':
				if ( 'coupon_delete' === $action ) { wp_publish_post( $entity_id ); }
				break;
			case 'variation':
				if ( 'variation_delete' === $action ) { wp_publish_post( $entity_id ); }
				break;
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_woo_rollbacks', $rollbacks );
		return [ 'action' => 'woocommerce_rollback', 'rollback_id' => $rollback_id, 'entity_id' => $entity_id ];
	}

	/**
	 * PROGRAM-4 / P4.6 — restore a v2 product_update delta record via the RollbackDelta
	 * core + WooProductAccessor. Marks the record applied only on a complete restore;
	 * partial/conflict return an error envelope and stay retryable.
	 *
	 * @param array<string,mixed>       $rec
	 * @param int                       $idx
	 * @param array<int,array<string,mixed>> $rollbacks
	 */
	private function rollback_product_delta( array $rec, int $idx, array $rollbacks, array $context ): array {
		$id = (int) $rec['entity_id'];
		if ( ! wc_get_product( $id ) ) {
			return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		}

		$o = RollbackDelta::restore( new WooProductAccessor(), $id, $rec['fields'] );

		if ( 'complete' === $o['status'] ) {
			$rollbacks[ $idx ]['rollback_applied'] = true;
			$rollbacks[ $idx ]['applied_at']       = time();
			update_option( 'wpcc_woo_rollbacks', $rollbacks );
		}

		$this->audit->record( 'product.rollback', [
			'product_id'      => $id,
			'status'          => $o['status'],
			'restored_fields' => $o['restored'],
			'skipped_fields'  => $o['skipped'],
		] );

		return RollbackDelta::result( [
			'action'      => 'woocommerce_rollback',
			'rollback_id' => $rec['id'],
			'entity_id'   => $id,
			'product_id'  => $id,
		], $o );
	}

	private function store_rollback( int $id, string $action, array $before, array $context ): string {
		if ( ! WooCommerceRegistry::supports_rollback( $action ) ) return '';
		$etype = in_array( $action, [ 'coupon_create', 'coupon_update', 'coupon_delete' ] ) ? 'coupon'
			: ( in_array( $action, [ 'variation_create', 'variation_update', 'variation_delete' ] ) ? 'variation' : 'product' );
		$rollbacks = get_option( 'wpcc_woo_rollbacks', [] );
		$rid = wp_generate_uuid4();
		$rollbacks[] = [ 'id' => $rid, 'entity_id' => $id, 'entity_type' => $etype, 'action' => $action,
			'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'session_id' => $context['session_id'] ?? null, 'task_id' => $context['task_id'] ?? null ];
		if ( count( $rollbacks ) > 200 ) $rollbacks = array_slice( $rollbacks, -200 );
		update_option( 'wpcc_woo_rollbacks', $rollbacks );
		return $rid;
	}

	// ── STEP 93 — shared product field application ───────────────

	/**
	 * Apply the full product data model (title, descriptions, pricing, SKU,
	 * inventory, categories, tags, images, attributes) to a WC_Product. Only
	 * supplied keys are written.
	 */
	private function apply_product_fields( \WC_Product $p, array $payload ): void {
		if ( isset( $payload['name'] ) )              $p->set_name( sanitize_text_field( (string) $payload['name'] ) );
		if ( isset( $payload['description'] ) )       $p->set_description( wp_kses_post( (string) $payload['description'] ) );
		if ( isset( $payload['short_description'] ) ) $p->set_short_description( wp_kses_post( (string) $payload['short_description'] ) );
		if ( isset( $payload['sku'] ) )               $p->set_sku( sanitize_text_field( (string) $payload['sku'] ) );
		if ( isset( $payload['regular_price'] ) )     $p->set_regular_price( (string) $payload['regular_price'] );
		if ( isset( $payload['sale_price'] ) )        $p->set_sale_price( (string) $payload['sale_price'] );
		if ( isset( $payload['status'] ) )            $p->set_status( sanitize_key( (string) $payload['status'] ) );

		if ( array_key_exists( 'manage_stock', $payload ) ) {
			$manage = ! empty( $payload['manage_stock'] );
			$p->set_manage_stock( $manage );
			if ( $manage && isset( $payload['stock_quantity'] ) ) $p->set_stock_quantity( (int) $payload['stock_quantity'] );
		}
		if ( isset( $payload['stock_status'] ) )      $p->set_stock_status( sanitize_key( (string) $payload['stock_status'] ) );

		if ( isset( $payload['categories'] ) )        $p->set_category_ids( $this->resolve_terms( (array) $payload['categories'], 'product_cat' ) );
		if ( isset( $payload['tags'] ) )              $p->set_tag_ids( $this->resolve_terms( (array) $payload['tags'], 'product_tag' ) );
		if ( isset( $payload['image_id'] ) )          $p->set_image_id( (int) $payload['image_id'] );
		if ( isset( $payload['gallery_image_ids'] ) ) $p->set_gallery_image_ids( array_map( 'intval', (array) $payload['gallery_image_ids'] ) );
		if ( isset( $payload['attributes'] ) && is_array( $payload['attributes'] ) ) {
			$p->set_attributes( $this->build_attributes( $payload['attributes'] ) );
		}
	}

	/** Resolve category/tag names or IDs to term IDs, creating missing terms. */
	private function resolve_terms( array $terms, string $taxonomy ): array {
		$ids = [];
		foreach ( $terms as $t ) {
			if ( is_numeric( $t ) ) { $ids[] = (int) $t; continue; }
			$name = sanitize_text_field( (string) $t );
			if ( '' === $name ) continue;
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			} else {
				$res = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $res ) ) $ids[] = (int) $res['term_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Restore a product from a legacy full-object before_state snapshot (P4.6 retained the
	 * restore side for pre-migration product_update records; new records use the delta core).
	 */
	private function restore_product( \WC_Product $p, array $b ): void {
		if ( isset( $b['name'] ) )              $p->set_name( (string) $b['name'] );
		if ( isset( $b['description'] ) )       $p->set_description( (string) $b['description'] );
		if ( isset( $b['short_description'] ) ) $p->set_short_description( (string) $b['short_description'] );
		if ( isset( $b['sku'] ) )               $p->set_sku( (string) $b['sku'] );
		if ( isset( $b['regular_price'] ) )     $p->set_regular_price( (string) $b['regular_price'] );
		if ( isset( $b['sale_price'] ) )        $p->set_sale_price( (string) $b['sale_price'] );
		if ( isset( $b['status'] ) )            $p->set_status( (string) $b['status'] );
		if ( isset( $b['manage_stock'] ) ) {
			$p->set_manage_stock( (bool) $b['manage_stock'] );
			if ( $b['manage_stock'] ) $p->set_stock_quantity( $b['stock_quantity'] );
		}
		if ( isset( $b['stock_status'] ) )      $p->set_stock_status( (string) $b['stock_status'] );
		if ( isset( $b['category_ids'] ) )      $p->set_category_ids( (array) $b['category_ids'] );
		if ( isset( $b['tag_ids'] ) )           $p->set_tag_ids( (array) $b['tag_ids'] );
		if ( array_key_exists( 'image_id', $b ) ) $p->set_image_id( (int) $b['image_id'] );
		if ( isset( $b['gallery_image_ids'] ) ) $p->set_gallery_image_ids( (array) $b['gallery_image_ids'] );
		if ( isset( $b['attributes'] ) )        $p->set_attributes( (array) $b['attributes'] );
		$p->save();
	}

	/** Build WC_Product_Attribute objects from a simple array spec. */
	private function build_attributes( array $attrs ): array {
		$out = [];
		foreach ( $attrs as $a ) {
			if ( ! is_array( $a ) || empty( $a['name'] ) ) continue;
			$obj = new \WC_Product_Attribute();
			$obj->set_name( sanitize_text_field( (string) $a['name'] ) );
			$obj->set_options( array_map( 'sanitize_text_field', (array) ( $a['options'] ?? [] ) ) );
			$obj->set_visible( ! isset( $a['visible'] ) || ! empty( $a['visible'] ) );
			$obj->set_variation( ! empty( $a['variation'] ) );
			$out[] = $obj;
		}
		return $out;
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}

<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

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
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		if ( isset( $payload['regular_price'] ) ) $p->set_regular_price( (string) $payload['regular_price'] );
		if ( isset( $payload['sale_price'] ) ) $p->set_sale_price( (string) $payload['sale_price'] );
		if ( isset( $payload['description'] ) ) $p->set_description( sanitize_textarea_field( $payload['description'] ) );
		if ( isset( $payload['sku'] ) ) $p->set_sku( sanitize_text_field( (string) $payload['sku'] ) );
		if ( isset( $payload['status'] ) ) $p->set_status( sanitize_key( (string) $payload['status'] ) );
		else $p->set_status( 'draft' );
		if ( ! empty( $payload['manage_stock'] ) ) { $p->set_manage_stock( true ); $p->set_stock_quantity( (int) ( $payload['stock_quantity'] ?? 0 ) ); }
		$id = $p->save();
		$this->store_rollback( $id, 'product_create', [], $context );
		$this->audit->record( 'product.created', [ 'product_id' => $id, 'name' => $name ] );
		return [ 'action' => 'product_create', 'product_id' => $id, 'name' => $name ];
	}

	private function product_update( array $payload, array $context ): array {
		$p = wc_get_product( (int) ( $payload['product_id'] ?? 0 ) );
		if ( ! $p ) return $this->error( 'wpcc_product_not_found', __( 'Product not found.', 'wp-command-center' ) );
		$before = $this->format_product( $p );
		if ( isset( $payload['name'] ) ) $p->set_name( sanitize_text_field( (string) $payload['name'] ) );
		if ( isset( $payload['regular_price'] ) ) $p->set_regular_price( (string) $payload['regular_price'] );
		if ( isset( $payload['description'] ) ) $p->set_description( sanitize_textarea_field( $payload['description'] ) );
		$p->save();
		$this->store_rollback( $p->get_id(), 'product_update', $before, $context );
		$this->audit->record( 'product.updated', [ 'product_id' => $p->get_id() ] );
		return [ 'action' => 'product_update', 'product_id' => $p->get_id() ];
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
		$before    = $rec['before_state'];
		$etype     = $rec['entity_type'] ?? 'product';

		switch ( $etype ) {
			case 'product':
				if ( in_array( $action, [ 'product_create', 'variation_create', 'coupon_create' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) $p->delete( true ); }
				elseif ( 'product_delete' === $action ) { wp_publish_post( $entity_id ); }
				elseif ( in_array( $action, [ 'product_publish', 'product_unpublish' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_status( $before['status'] ); $p->save(); } }
				elseif ( 'stock_update' === $action ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_stock_quantity( $before['stock'] ); $p->save(); } }
				elseif ( 'price_update' === $action ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_regular_price( $before['regular'] ); $p->set_sale_price( $before['sale'] ); $p->save(); } }
				elseif ( in_array( $action, [ 'category_assign', 'category_remove' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_category_ids( $before['category_ids'] ?? [] ); $p->save(); } }
				elseif ( in_array( $action, [ 'attribute_assign', 'attribute_remove' ] ) ) { $p = wc_get_product( $entity_id ); if ( $p ) { $p->set_attributes( $before['attributes'] ?? [] ); $p->save(); } }
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

	private function store_rollback( int $id, string $action, array $before, array $context ): void {
		if ( ! WooCommerceRegistry::supports_rollback( $action ) ) return;
		$etype = in_array( $action, [ 'coupon_create', 'coupon_update', 'coupon_delete' ] ) ? 'coupon'
			: ( in_array( $action, [ 'variation_create', 'variation_update', 'variation_delete' ] ) ? 'variation' : 'product' );
		$rollbacks = get_option( 'wpcc_woo_rollbacks', [] );
		$rollbacks[] = [ 'id' => wp_generate_uuid4(), 'entity_id' => $id, 'entity_type' => $etype, 'action' => $action,
			'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'session_id' => $context['session_id'] ?? null, 'task_id' => $context['task_id'] ?? null ];
		if ( count( $rollbacks ) > 200 ) $rollbacks = array_slice( $rollbacks, -200 );
		update_option( 'wpcc_woo_rollbacks', $rollbacks );
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}

<?php
/**
 * Step 19 — WooCommerce Product Seeder Operation.
 *
 * Creates simple WooCommerce products using native WooCommerce CRUD APIs.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class WooProductSeed {

	/**
	 * Run the WooCommerce product seeding operation.
	 *
	 * @param array{
	 *     name: string,
	 *     sku?: string,
	 *     regular_price: string,
	 *     sale_price?: string,
	 *     short_description?: string,
	 *     description?: string,
	 *     status?: string,
	 *     stock_quantity?: int,
	 *     manage_stock?: bool,
	 *     categories?: string[]
	 * } $params
	 * @param array $context Optional metadata.
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'wpcc_woo_inactive', __( 'WooCommerce is not active.', 'wp-command-center' ) );
		}

		$name           = sanitize_text_field( $params['name'] ?? '' );
		$sku            = sanitize_text_field( $params['sku'] ?? '' );
		$regular_price  = sanitize_text_field( $params['regular_price'] ?? '0' );
		$sale_price     = sanitize_text_field( $params['sale_price'] ?? '' );
		$short_desc     = wp_kses_post( $params['short_description'] ?? '' );
		$description    = wp_kses_post( $params['description'] ?? '' );
		$status         = sanitize_key( $params['status'] ?? 'draft' );
		$manage_stock   = (bool) ( $params['manage_stock'] ?? false );
		$stock_quantity = (int) ( $params['stock_quantity'] ?? 0 );
		$categories     = (array) ( $params['categories'] ?? [] );

		if ( empty( $name ) ) {
			return new \WP_Error( 'wpcc_missing_product_name', __( 'Product name is required.', 'wp-command-center' ) );
		}

		if ( ! in_array( $status, [ 'draft', 'publish' ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_product_status', __( 'Invalid product status. Supported: draft, publish.', 'wp-command-center' ) );
		}

		if ( ! is_numeric( $regular_price ) || (float) $regular_price < 0 ) {
			return new \WP_Error( 'wpcc_invalid_product_price', __( 'Invalid regular price.', 'wp-command-center' ) );
		}

		if ( '' !== $sale_price && ( ! is_numeric( $sale_price ) || (float) $sale_price < 0 ) ) {
			return new \WP_Error( 'wpcc_invalid_product_sale_price', __( 'Invalid sale price.', 'wp-command-center' ) );
		}

		if ( ! empty( $sku ) ) {
			$existing_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_id ) {
				return new \WP_Error( 'wpcc_duplicate_sku', sprintf( __( 'Product with SKU "%s" already exists.', 'wp-command-center' ), $sku ) );
			}
		}

		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( $status );
			$product->set_regular_price( $regular_price );

			if ( '' !== $sale_price ) {
				$product->set_sale_price( $sale_price );
			}

			if ( ! empty( $sku ) ) {
				$product->set_sku( $sku );
			}

			$product->set_short_description( $short_desc );
			$product->set_description( $description );
			$product->set_manage_stock( $manage_stock );

			if ( $manage_stock ) {
				$product->set_stock_quantity( max( 0, $stock_quantity ) );
			}

			// Categories
			if ( ! empty( $categories ) ) {
				$term_ids = [];
				foreach ( $categories as $cat_name ) {
					$term = get_term_by( 'name', $cat_name, 'product_cat' );
					if ( ! $term ) {
						$new_term = wp_insert_term( $cat_name, 'product_cat' );
						if ( ! is_wp_error( $new_term ) ) {
							$term_ids[] = (int) $new_term['term_id'];
						}
					} else {
						$term_ids[] = (int) $term->term_id;
					}
				}
				$product->set_category_ids( $term_ids );
			}

			$product_id = $product->save();

			if ( ! $product_id ) {
				return new \WP_Error( 'wpcc_woo_save_failed', __( 'Failed to save WooCommerce product.', 'wp-command-center' ) );
			}

			return [
				'product_id'     => $product_id,
				'sku'            => $sku,
				'product_name'   => $name,
				'category_count' => count( $categories ),
			];

		} catch ( \Exception $e ) {
			return new \WP_Error( 'wpcc_woo_exception', $e->getMessage() );
		}
	}
}

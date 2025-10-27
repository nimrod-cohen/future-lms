<?php
/**
 * WooCommerce Auto Product Creation for Future LMS
 *
 * This class handles automatic creation of WooCommerce products when courses are created
 * in the Future LMS plugin.
 */

namespace FutureLMS\woocommerce;

use FutureLMS\classes\Course;
use FutureLMS\classes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAutoProduct {

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'maybe_init_hooks' ] );
	}

	public function maybe_init_hooks() {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		$this->init_hooks();
	}

	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	private function init_hooks() {
		// Hook into course creation/update
		add_action( 'future-lms/course_saved', [ $this, 'auto_create_product_for_course' ], 10, 2 );
		
		// Add admin notice for WooCommerce dependency
		add_action( 'admin_notices', [ $this, 'check_woocommerce_dependency' ] );
	}

	public function auto_create_product_for_course( $course_id, $post ) {
		if ( ! $this->is_auto_creation_enabled() ) {
			return;
		}

		if ( wp_is_post_autosave( $course_id ) || wp_is_post_revision( $course_id ) ) {
			return;
		}

		$create_for_drafts = Settings::get( 'auto_create_products_for_drafts' ) === 'yes';
		if ( $post->post_status !== 'publish' && ! $create_for_drafts ) {
			return;
		}

		$existing_product_id = WCIntegration::get_linked_product_for_course( $course_id );
		if ( $existing_product_id ) {
			$this->update_existing_product( $existing_product_id, $course_id );
			return;
		}

		$this->create_new_product( $course_id );
	}


	private function is_auto_creation_enabled() {
		return Settings::get( 'auto_create_woocommerce_products' ) === 'yes';
	}

	private function create_new_product( $course_id ) {
		try {
			$course = new Course( $course_id );
			
			$course_title = $course->raw( 'post_title' );
			$course_description = $course->raw( 'post_content' );
			$short_description = $course->raw( 'short_description' ) ?? wp_trim_words( $course_description, 20 );
			$price = $course->raw( 'full_price' );
			$discount_price = $course->raw( 'discount_price' );

			// Skip if no price is set (optional - you might want to create free products too)
			if ( empty( $price ) ) {
				return;
			}

			$product_data = [
				'post_title'   => $course_title,
				'post_content' => $course_description,
				'post_excerpt' => $short_description,
				'post_status'  => 'publish',
				'post_type'    => 'product',
				'post_author'  => get_current_user_id(),
			];

			$product_id = wp_insert_post( $product_data );

			if ( is_wp_error( $product_id ) ) {
				return;
			}


			wp_set_object_terms( $product_id, 'course', 'product_type' );
			
			update_post_meta( $product_id, '_linked_course_id', $course_id );

			$regular_price = floatval( $price );
			update_post_meta( $product_id, '_regular_price', $regular_price );
			update_post_meta( $product_id, '_price', $regular_price );

            // Set sale price if discount exists
			if ( ! empty( $discount_price ) && floatval( $discount_price ) < $regular_price ) {
				$sale_price = floatval( $discount_price );
				update_post_meta( $product_id, '_sale_price', $sale_price );
				update_post_meta( $product_id, '_price', $sale_price );
			}

			update_post_meta( $product_id, '_auto_enroll', 'yes' );

			$default_class_id = $course->raw( 'default_class' );
			if ( $default_class_id ) {
				update_post_meta( $product_id, '_default_class_id', $default_class_id );
			}

			do_action( 'future-lms/woocommerce_product_created', $product_id, $course_id );
		} catch ( Exception $e ) {
		  FutureLMS::log( 'Error creating WooCommerce product ' . $product_id . ' for course ' . $course_id . ': ' . $e->getMessage() );
		}
	}

	private function update_existing_product( $product_id, $course_id ) {
		try {
			$course = new Course( $course_id );
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				return;
			}

			$course_title = $course->raw( 'post_title' );
			$course_description = $course->raw( 'post_content' );
			$short_description = $course->raw( 'short_description' );

			wp_update_post( [
				'ID'           => $product_id,
				'post_title'   => $course_title,
				'post_content' => $course_description,
				'post_excerpt' => $short_description,
			] );

			$price = $course->raw( 'full_price' );
			$discount_price = $course->raw( 'discount_price' );

			if ( ! empty( $price ) ) {
				$regular_price = floatval( $price );
				$product->set_regular_price( $regular_price );
				$product->set_price( $regular_price );

				// Update sale price if discount exists
				if ( ! empty( $discount_price ) && floatval( $discount_price ) < $regular_price ) {
					$sale_price = floatval( $discount_price );
					$product->set_sale_price( $sale_price );
					$product->set_price( $sale_price );
				} else {
					$product->set_sale_price( '' );
				}

				$product->save();
			}

			$default_class_id = $course->raw( 'default_class' );
			if ( $default_class_id ) {
				update_post_meta( $product_id, '_default_class_id', $default_class_id );
			}

			FutureLMS::log( 'Successfully updated WooCommerce product ' . $product_id . ' for course ' . $course_id );

			do_action( 'future-lms/woocommerce_product_updated', $product_id, $course_id );
		} catch ( Exception $e ) {
			FutureLMS::log( 'Error updating WooCommerce product ' . $product_id . ' for course ' . $course_id . ': ' . $e->getMessage() );
		}
	}

	public function check_woocommerce_dependency() {
		if ( ! $this->is_woocommerce_active() && Settings::get( 'auto_create_woocommerce_products' ) === 'yes' ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong>Future LMS:</strong> 
					<?php _e( 'WooCommerce auto-product creation is enabled but WooCommerce is not active. Please install and activate WooCommerce to use this feature.', 'future-lms' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Create products for existing courses (utility method)
	 * This can be called manually or via admin action
	 */
	public static function create_products_for_existing_courses() {
		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return false;
		}

		$courses = get_posts( [
			'post_type'      => 'course',
			'posts_per_page' => -1,
			'post_status'    => [ 'publish', 'draft' ]
		] );

		$created_count = 0;
		$skipped_count = 0;

		foreach ( $courses as $course ) {
			$existing_product_id = WCIntegration::get_linked_product_for_course( $course->ID );
			if ( $existing_product_id ) {
				$skipped_count++;
				continue;
			}

      $auto_product = new self();
			$auto_product->create_new_product( $course->ID );
			$created_count++;
		}

		return [
			'created' => $created_count,
			'skipped' => $skipped_count,
			'total'   => count( $courses )
		];
	}


}

new WCAutoProduct();

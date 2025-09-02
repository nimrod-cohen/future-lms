<?php
/**
 * WooCommerce Integration for Future LMS
 *
 * This file handles the integration between WooCommerce and Future LMS
 * - Links courses to WooCommerce products
 * - Automatically enrolls students when they purchase courses
 * - Creates student accounts if they don't exist
 * - Assigns students to appropriate classes
 */

namespace FutureLMS\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCommerceIntegration {

	public function __construct() {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'woocommerce_not_active' ] );

			return;
		}

		$this->init_hooks();
	}

	private function is_woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( function_exists( 'WC' ) ) {
			return true;
		}

		if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Show notice if WooCommerce is not active
	 */
	public function woocommerce_not_active() {
		?>
      <div class="notice notice-error">
        <p><strong>Future LMS Error:</strong> WooCommerce is NOT active! The current implementation for Future LMS requires Woocommerce to work properly.</p>
      </div>
		<?php
	}

	private function init_hooks() {
		add_action( 'init', [ $this, 'load_custom_product_class' ], 20 );

		add_filter( 'product_type_selector', [ $this, 'add_course_product_type' ], 10 );
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_course_product_tab' ], 10 );
		add_action( 'woocommerce_product_data_panels', [ $this, 'add_course_product_settings' ], 10 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_course_product_meta' ], 10 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'sync_course_price' ], 20 );
		add_filter( 'woocommerce_product_class', [ $this, 'load_course_product_class' ], 10, 2 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'force_course_add_to_cart' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );

	}

	public function add_course_product_type( $types ) {
		$types['course'] = __( 'Course', 'future-lms' );

		return $types;
	}

	public function add_course_product_tab( $tabs ) {
		$tabs['course'] = [
			'label'    => __( 'Course Settings', 'future-lms' ),
			'target'   => 'course_product_data',
			'class'    => [ 'show_if_course' ],
			'priority' => 21
		];

		return $tabs;
	}

	public function add_course_product_settings() {
		global $post;
		?>
      <div id="course_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
			<?php
			// Course selection
			$courses = get_posts( [
				'post_type'      => 'course',
				'posts_per_page' => - 1,
				'orderby'        => 'title',
				'order'          => 'ASC'
			] );

			$selected_course = get_post_meta( $post->ID, '_linked_course_id', true );

			woocommerce_wp_select( [
				'id'          => '_linked_course_id',
				'label'       => __( 'Linked Course', 'future-lms' ),
				'description' => __( 'Select the course this product represents', 'future-lms' ),
				'desc_tip'    => true,
				'options'     => $this->get_courses_options( $courses ),
				'value'       => $selected_course
			] );

			// Auto-enrollment checkbox
			woocommerce_wp_checkbox( [
				'id'          => '_auto_enroll',
				'label'       => __( 'Auto-enroll on purchase', 'future-lms' ),
				'description' => __( 'Automatically enroll students in the course when purchased', 'future-lms' ),
				'desc_tip'    => true,
				'value'       => get_post_meta( $post->ID, '_auto_enroll', true )
			] );

			// Default class selection
			if ( $selected_course ) {
				$classes = get_posts( [
					'post_type'      => 'class',
					'posts_per_page' => - 1,
					'meta_query'     => [
						[
							'key'   => '_course_id',
							'value' => $selected_course
						]
					]
				] );

				$default_class = get_post_meta( $post->ID, '_default_class_id', true );

				woocommerce_wp_select( [
					'id'          => '_default_class_id',
					'label'       => __( 'Default Class', 'future-lms' ),
					'description' => __( 'Select the default class for enrollment (optional)', 'future-lms' ),
					'desc_tip'    => true,
					'options'     => $this->get_classes_options( $classes ),
					'value'       => $default_class
				] );
			}
			?>
        </div>
      </div>
		<?php
	}

	private function get_courses_options( $courses ) {
		$options = [ '' => __( 'Select a course', 'future-lms' ) ];

		foreach ( $courses as $course ) {
			$options[ $course->ID ] = $course->post_title;
		}

		return $options;
	}

	private function get_classes_options( $classes ) {
		$options = [ '' => __( 'No default class', 'future-lms' ) ];

		foreach ( $classes as $class ) {
			$options[ $class->ID ] = $class->post_title;
		}

		return $options;
	}

	public function save_course_product_meta( $post_id ) {

		if ( isset( $_POST['_linked_course_id'] ) ) {
			update_post_meta( $post_id, '_linked_course_id', sanitize_text_field( $_POST['_linked_course_id'] ) );
		}

		$auto_enroll = isset( $_POST['_auto_enroll'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_auto_enroll', $auto_enroll );

		if ( isset( $_POST['_default_class_id'] ) ) {
			update_post_meta( $post_id, '_default_class_id', sanitize_text_field( $_POST['_default_class_id'] ) );
		}
	}

	public function sync_course_price( $post_id ) {

		$product_type = get_post_meta( $post_id, '_product_type', true );
		if ( $product_type !== 'course' ) {
			return;
		}

		$linked_course_id = get_post_meta( $post_id, '_linked_course_id', true );
		if ( ! $linked_course_id ) {
			return;
		}

		$course_price = get_post_meta( $linked_course_id, 'full_price', true );
		if ( ! empty( $course_price ) ) {
			update_post_meta( $post_id, '_regular_price', $course_price );
			update_post_meta( $post_id, '_price', $course_price );

			$discount_price = get_post_meta( $linked_course_id, 'discount_price', true );
			if ( ! empty( $discount_price ) && $discount_price < $course_price ) {
				update_post_meta( $post_id, '_sale_price', $discount_price );
				update_post_meta( $post_id, '_price', $discount_price );
			}
		}
	}

	public function load_course_product_class( $classname, $product_type ) {
		if ( $product_type === 'course' ) {
			return 'WC_Product_Course';
		}

		return $classname;
	}

	public function load_custom_product_class() {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		$class_file = plugin_dir_path( __FILE__ ) . 'class-wc-product-course.php';
		if ( file_exists( $class_file ) ) {
			include_once $class_file;
		}
	}

	public function force_course_add_to_cart() {
		global $product;

		if ( ! $product || $product->get_type() !== 'course' ) {
			return;
		}

		error_log( 'WooCommerce Integration: Forcing add to cart form for course product ' . $product->get_id() );

		// Remove default add to cart
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

		// Add our custom add to cart form
		?>
      <form class="cart"
            action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>"
            method="post" enctype='multipart/form-data'>
		  <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

        <button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>"
                class="single_add_to_cart_button button alt wp-element-button">
			<?php echo esc_html( __( 'Add to Cart', 'woocommerce' ) ); ?>
        </button>

		  <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
      </form>
		<?php
	}

	public function enqueue_admin_styles( $hook ) {
		global $post_type;

		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		if ( $post_type !== 'product' ) {
			return;
		}

		// Add inline CSS to show course product type
		?>
      <style>
          .product-type-option[data-value="course"] {
              display: block !important;
          }

          .show_if_course {
              display: block !important;
          }
      </style>
		<?php
	}
}

new WooCommerceIntegration();

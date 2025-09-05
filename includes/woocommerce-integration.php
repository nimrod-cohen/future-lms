<?php
/**
 * WooCommerce Integration for Future LMS
 *
 * This file handles the integration between WooCommerce and Future LMS
 * - Links courses to WooCommerce products
 * - Automatically enrolls students when they purchase courses
 * - Assigns students to appropriate classes
 */

namespace FutureLMS\includes;

use FutureLMS\classes\Student;
use FutureLMS\FutureLMS;
use FutureLMS\classes\Course;

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

		add_filter( 'product_type_selector', [ $this, 'add_course_product_type' ], 10 );
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_course_product_tab' ], 10 );
		add_action( 'woocommerce_product_data_panels', [ $this, 'add_course_product_settings' ], 10 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_course_product_meta' ], 10 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'sync_course_price' ], 20 );
		add_filter( 'woocommerce_product_class', [ $this, 'add_course_product_class' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );

		// Enroll students when order is completed (virtual product / card payment)
		add_action( 'woocommerce_order_status_completed', [ $this, 'enroll_order_items' ], 10, 1 );
	}

	public function add_course_product_class( $classname, $product_type ) {
		if ( $product_type === 'course' ) {
			// Load the class file if not already loaded
			$class_file = plugin_dir_path( __FILE__ ) . 'class-wc-product-course.php';
			if ( file_exists( $class_file ) && ! class_exists( 'WC_Product_Course' ) ) {
				include_once $class_file;
			}
			return 'WC_Product_Course';
		}

		return $classname;
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
		$product = wc_get_product( $post->ID );
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

			$selected_course = $product->get_linked_course_id();

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
				'value'       => ( $product->get_auto_enroll() ? 'yes' : 'no' )
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

				$default_class = $product->get_default_class_id();

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

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		if ( isset( $_POST['_linked_course_id'] ) ) {
			$product->update_meta_data( '_linked_course_id', sanitize_text_field( $_POST['_linked_course_id'] ) );
		}

		$auto_enroll = isset( $_POST['_auto_enroll'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_auto_enroll', $auto_enroll );

		if ( isset( $_POST['_default_class_id'] ) ) {
			$product->update_meta_data( '_default_class_id', sanitize_text_field( $_POST['_default_class_id'] ) );
		}

		$product->save();
	}

	public function sync_course_price( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product || $product->get_type() !== 'course' ) {
			return;
		}

		$linked_course_id = $product->get_meta( '_linked_course_id' );
		if ( ! $linked_course_id ) {
			return;
		}

		$course_price = get_post_meta( $linked_course_id, 'full_price', true );
		if ( ! empty( $course_price ) ) {
			$product->update_meta_data( '_regular_price', $course_price );
			$product->update_meta_data( '_price', $course_price );

			$discount_price = get_post_meta( $linked_course_id, 'discount_price', true );
			if ( ! empty( $discount_price ) && $discount_price < $course_price ) {
				$product->update_meta_data( '_sale_price', $discount_price );
				$product->update_meta_data( '_price', $discount_price );
			}
			$product->save();
		}
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

	public function enroll_order_items( $order_id ) {
		// prevent duplicate processing when status changes multiple times
		if ( get_post_meta( $order_id, '_future_lms_enrollment_done', true ) === 'yes' ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			return;
		}

		$phone      = $order->get_billing_phone();
		$first_name = trim( (string) $order->get_billing_first_name() );
		$last_name  = trim( (string) $order->get_billing_last_name() );
		$name       = trim( $first_name . ' ' . $last_name );

		// allow filtering of email/phone 
		$email = apply_filters( 'future-lms/student_email', $email );
		$phone = apply_filters( 'future-lms/student_phone', $phone );

		$student = Student::create( $email, '', $email );
		if ( ! $student ) {
			return;
		}
		$student_id = $student->get_id();

		if ( ! empty( $name ) ) {
			wp_update_user( [ 'ID' => $student_id, 'display_name' => $name ] );
		}
		if ( ! empty( $phone ) ) {
			update_user_meta( $student_id, 'user_phone', $phone );
		}

		$student   = $student;
		$processed = [];

	  foreach ( $order->get_items() as $item_id => $item ) {
		  $product = $item->get_product();
		  if ( ! $product ) {
			  continue;
		  }

		  $is_course_type = ( method_exists( $product, 'get_type' ) && $product->get_type() === 'course' );
		  $course_id      = $product->get_linked_course_id();

		  if ( ! $is_course_type && ! $course_id ) {
			  continue; // not a course product, skip
		  }

		  $auto_enroll = $product->get_auto_enroll();

		  if ( ! $course_id || ! $auto_enroll ) {
			  continue;
		  }
		  if ( isset( $processed[ $course_id ] ) ) {
			  continue; // already processed this course for this order
		  }

		  $class_id = $product->get_default_class_id();
	    if ( ! $class_id ) {
		    $classes = Course::get_classes( $course_id, null );
		    if ( ! empty( $classes ) && ! empty( $classes[0]['id'] ) ) {
			    $class_id = (int) $classes[0]['id'];
		    }
	    }

	    if ( $class_id ) {
			  $old_class = $student->get_class( $course_id );

			  if ( ! $old_class ) {
				  $student->subscribe_to_class( $class_id, true );
			  } elseif ( (int) $old_class['id'] !== $class_id ) {
				  $student->subscribe_to_class( (int) $old_class['id'], false );
				  $student->subscribe_to_class( $class_id, true );
			  }

			  $sum = (float) $item->get_total();
			  $transaction_id = $order->get_transaction_id();
			  if ( ! $transaction_id ) {
				  $transaction_id = 'order-' . $order_id . '-item-' . $item_id;
			  }
			  $method  = (string) $order->get_payment_method();
			  $comment = sprintf( 'Order #%d - %s', $order_id, $item->get_name() );

			  $payment_id = $student->save_payment( $course_id, $class_id, $sum, $transaction_id, $method, $comment );

			  do_action( 'future-lms/payment_notification', [
				  'course_id'      => $course_id,
				  'student_id'     => $student_id,
				  'class_id'       => $class_id,
				  'sum'            => $sum,
				  'transaction_id' => $transaction_id,
				  'payment_id'     => $payment_id,
				  'payment_method' => $method,
				  'comment'        => $comment,
			  ] );

			  // mark the order item as enrolled for reference using the item API
			  $item->add_meta_data( '_future_lms_enrolled', 'yes', true );
			  $item->save();
		  } else {
			// No default class configured -> notify admins and skip enrollment
			FutureLMS::notify_admins(
				'Enrollment skipped: no default class',
				'No class found for course product ' . $product->get_id() . ' (course ' . $course_id . ') Order ID:' . $order_id . '. Skipping enrollment.'
			);
		  }

			$processed[ $course_id ] = true;
		}

		$order->update_meta_data( '_future_lms_enrollment_done', 'yes' );
		$order->save();
	}
}

new WooCommerceIntegration();

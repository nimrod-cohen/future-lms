<?php
/**
 * Custom Course Product Class for WooCommerce
 *
 * This file defines the WC_Product_Course class that extends WC_Product
 * to handle course products in WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Product' ) ) {
	return;
}

class WC_Product_Course extends WC_Product {

	public function __construct( $product ) {
		parent::__construct( $product );
	}

	public function get_type() {
		return 'course';
	}

	public function save() {
		update_post_meta( $this->get_id(), '_product_type', 'course' );
		parent::save();
	}

	public function get_linked_course_id() {
		$val = $this->get_meta( '_linked_course_id' );
		return is_numeric( $val ) ? (int) $val : 0;
	}

	public function get_auto_enroll() {
		return $this->get_meta( '_auto_enroll' ) === 'yes';
	}

	public function get_default_class_id() {
		$val = $this->get_meta( '_default_class_id' );
		return is_numeric( $val ) ? (int) $val : 0;
	}

	public function is_purchasable() {
		return true;
	}

	public function is_virtual() {
		return true;
	}

	public function is_downloadable() {
		return false;
	}

	public function needs_shipping() {
		return false;
	}

	public function get_stock_status( $context = 'view' ) {
		return 'instock';
	}
}

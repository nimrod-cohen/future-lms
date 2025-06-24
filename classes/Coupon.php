<?php
namespace FutureLMS\classes;

use FutureLMS\FutureLMS;

class Coupon {
  private static $instance;

  public static function get_instance() {
    if (!isset(self::$instance)) {
      self::$instance = new Coupon();
    }
    return self::$instance;
  }

  protected function __construct() {
    add_action("wp_ajax_save_coupon", [$this, "save"]);
    add_action("wp_ajax_delete_coupon", [$this, "delete"]);
    add_action("wp_ajax_get_coupons", [$this, "all"]);
  }

  public function delete() {
    global $wpdb;
    $prefix = FutureLMS::TABLE_PREFIX();

    $sql = 'update '.$prefix.'coupons set deleted = 1 where id = %d';
    $sql = $wpdb->prepare($sql, $_POST["coupon_id"]);
    $wpdb->query($sql);
    echo json_encode(['error' => false]);
    die();
  }

  public function save() {
    global $wpdb;
    $prefix = FutureLMS::TABLE_PREFIX();

    $couponId = isset($_POST["coupon_id"]) ? $_POST["coupon_id"] : false;

    if (!$couponId) {
      $sql = 'select count(1) from '.$prefix.'coupons where code = %s and deleted = 0';
      $sql = $wpdb->prepare($sql, $_POST["code"]);
      $exists = $wpdb->get_var($sql) != "0";

      if ($exists) {
        echo json_encode(["error" => true, "message" => "Coupon code already exists"]);
        die;
      }

      $result = $wpdb->insert($prefix."coupons", [
        "code" => $_POST["code"],
        "deleted" => 0,
        "expires" => $_POST["expires"] . ' 23:59:59',
        "course_id" => $_POST["course"],
        "global" => $_POST["global"] == "true",
        "email" => $_POST["email"],
        "price" => $_POST["price"],
        "comment" => $_POST["comment"]
      ]);
    } else {
      $result = $wpdb->update($prefix."coupons", [
        "code" => $_POST["code"],
        "deleted" => 0,
        "expires" => $_POST["expires"] . ' 23:59:59',
        "course_id" => $_POST["course"],
        "global" => $_POST["global"] == "true",
        "email" => $_POST["email"],
        "price" => $_POST["price"],
        "comment" => $_POST["comment"]
      ], ["id" => $couponId]
      );
    }

    if ($result == 1) {
      echo json_encode(["error" => false, "message" => "Coupon code " . $_POST["code"] . " saved successfully", "coupon" => $_POST["code"]]);
      die;
    } else {
      echo json_encode(["error" => true, "message" => "Failed to create or update coupon"]);
      die;
    }
  }

  public function byCode($promo) {
    global $wpdb;
    $prefix = FutureLMS::TABLE_PREFIX();

    $sql = "SELECT c.id, c.code, c.email, c.global, c.course_id, DATE_FORMAT(c.expires, '%Y-%m-%d') as expires, c.price, p.post_title as course, c.comment
    FROM ".$prefix."coupons c
    INNER JOIN ".$wpdb->prefix."posts p on p.id = c.course_id
    WHERE deleted = 0
    AND expires > CURRENT_TIMESTAMP
    AND c.code = '" . $promo . "'";
    $row = $wpdb->get_row($sql, ARRAY_A);
    if (!$row) {
      return false;
    }

    $row["global"] = $row["global"] == "1" ? true : false;
    return $row;
  }

  public function all() {
    global $wpdb;
    $prefix = FutureLMS::TABLE_PREFIX();

    $sql = "SELECT c.id, c.code, c.email, c.global, c.course_id, DATE_FORMAT(c.expires, '%Y-%m-%d') as expires, c.price, p.post_title as course, c.comment
    FROM ".$prefix."coupons c
    INNER JOIN ".$wpdb->prefix."posts p on p.id = c.course_id
    WHERE deleted = 0
    ORDER BY id desc";
    $result = $wpdb->get_results($sql, ARRAY_A);
    echo json_encode($result);
    die;
  }
}

$coupons = Coupon::get_instance();

?>
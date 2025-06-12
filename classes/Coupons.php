<?php
class Coupons {
  private static $instance;

  public static function get_instance() {
    if (!isset(self::$instance)) {
      self::$instance = new Coupons();
    }
    return self::$instance;
  }

  protected function __construct() {
    add_action("wp_ajax_save_coupon", [$this, "saveCoupon"]);
    add_action("wp_ajax_delete_coupon", [$this, "deleteCoupon"]);
    add_action("wp_ajax_get_coupons", [$this, "getCoupons"]);
    add_action("wp_ajax_get_partner_coupons", [$this, "getPartnerCoupons"]);
  }

  public function deleteCoupon() {
    global $wpdb;

    $sql = 'update wp_coupons set deleted = 1 where id = %d';
    $sql = $wpdb->prepare($sql, $_POST["coupon_id"]);
    $wpdb->query($sql);
    echo json_encode(['error' => false]);
    die();
  }

  public function saveCoupon() {
    global $wpdb;

    $couponId = isset($_POST["coupon_id"]) ? $_POST["coupon_id"] : false;

    if (!$couponId) {
      $sql = 'select count(1) from wp_coupons where code = %s and deleted = 0';
      $sql = $wpdb->prepare($sql, $_POST["code"]);
      $exists = $wpdb->get_var($sql) != "0";

      if ($exists) {
        echo json_encode(["error" => true, "message" => "Coupon code already exists"]);
        die;
      }

      $result = $wpdb->insert("wp_coupons", [
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
      $result = $wpdb->update("wp_coupons", [
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
    $sql = "SELECT c.id, c.code, c.email, c.global, c.course_id, DATE_FORMAT(c.expires, '%Y-%m-%d') as expires, c.price, p.post_title as course, c.comment
    FROM wp_coupons c
    INNER JOIN wp_posts p on p.id = c.course_id
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

  public function getCoupons() {
    global $wpdb;
    $sql = "SELECT c.id, c.code, c.email, c.global, c.course_id, DATE_FORMAT(c.expires, '%Y-%m-%d') as expires, c.price, p.post_title as course, c.comment
    FROM wp_coupons c
    INNER JOIN wp_posts p on p.id = c.course_id
    WHERE deleted = 0
    ORDER BY id desc";
    $result = $wpdb->get_results($sql, ARRAY_A);
    echo json_encode($result);
    die;
  }

  public function getPartnerCoupons() {
    global $wpdb;
    $sql = "SELECT pc.id, pc.code, pc.name, pc.phone, pc.email, pc.tpid, pc.used, pc.use_date, pc.user_id, pc.deal_id, pc.partner_id,
      pc.creation_date, p.display_name AS `partner`, p.name as partner_name,
      pd.data AS deal_data
      FROM wp_vi_partner_coupons pc
      INNER JOIN wp_vi_partner_deals pd ON pd.partner_id = pc.partner_id AND pd.id = pc.deal_id
      INNER JOIN wp_vi_partners p ON p.id = pc.partner_id
      WHERE pd.deleted = 0
      AND p.deleted = 0
      ORDER BY pc.creation_date desc";
    $result = $wpdb->get_results($sql, ARRAY_A);
    echo json_encode($result);
    die;
  }
}

$coupons = Coupons::get_instance();

?>
<?php

namespace FutureLMS\classes;

class Course extends BaseObject {
  public function __construct($course_id_or_params = null) {
    parent::__construct('course', $course_id_or_params);
  }

  public static function get_course_price_box($args) {
    $format = isset($args) && isset($args["format"]) ? $args["format"] :
        "<span class='course-price'>
          <span style='text-decoration:line-through; margin:0 8px;'>{full_price}</span>
          <span style='font-weight:bold'>{discount_price}</span>
        </span>";
    $course_id = isset($args) && isset($args["course_id"]) ? $args["course_id"] : false;

    if (!$course_id) {
        return "";
    }

    $course = new Course($course_id);

    $full_price = floatval($course->field("full_price"));
    $full_price_txt = $course->formatter->formatCurrency($full_price, get_option('future-lms_currency', 'ILS'));
    $discount_price = floatval($course->field("discount_price"));
    $discount_price_txt = $course->formatter->formatCurrency($discount_price, get_option('future-lms_currency', 'ILS'));

    if(!empty($discount_price)) {
      $discount_price = floatval($discount_price);
    }

    $payments = isset($args["payments"]) ? intval($args["payments"]) : 1;

    $result = preg_replace("/{full_price}/", $full_price_txt, $format);
    $result = preg_replace("/{discount_price}/", $discount_price_txt, $result);
    $result = preg_replace("/{payment_price}/", number_format(ceil($discount_price / $payments)), $result);
    $result = preg_replace("/{payments}/", $payments, $result);
    $result = preg_replace("/{discount_pct}/", $discount_price > 0 ? (round((1 - ($discount_price / $full_price)) * 100))."%" : "", $result);

    return $result;
  }

  public function modules()
  {
      return BaseObject::factory("module", ["where" => "course = " . $this->raw("ID"), "orderby" => "order.meta_value ASC", "limit" => -1]);
  }

  public static function get_classes($courseId, $search)
  {
      $where = 'course.id = ' . $courseId;
      if ($search) {
          $where .= " AND t.post_title like '%" . $search . "%'";
      }
      $classes = new SchoolClass(['limit' => 0, 'where' => $where, 'orderby' => 'start_date DESC']);
      $result = [];

      foreach ($classes->results() as $row) {
        $result[] = [
            'id' => $row->ID,
            'title' => $row->post_title
        ];
      }

      return $result;
  }

  public static function get_courses_tree($courses = null, $enabledOnly = true) {
    global $wpdb;

    $sql = "SELECT pcourse.id AS course_id, pcourse.post_title AS course_name, pcourse.post_status,
    pmprice.meta_value AS full_price, pmurl.meta_value AS course_page_url, pmcurl.meta_value AS charge_url
    FROM ".$wpdb->prefix."posts pcourse
    INNER JOIN ".$wpdb->prefix."postmeta pmprice ON pmprice.post_id = pcourse.id AND pmprice.meta_key = 'full_price'
    LEFT OUTER JOIN ".$wpdb->prefix."postmeta pmurl ON pmurl.post_id = pcourse.id AND pmurl.meta_key = 'course_page_url'
    LEFT OUTER JOIN ".$wpdb->prefix."postmeta pmcurl ON pmcurl.post_id = pcourse.id AND pmcurl.meta_key = 'charge_url'
    WHERE pcourse.post_type = 'course'
    AND pcourse.post_status <> 'trash' ";

    if ($enabledOnly) {
      $sql .= "AND pcourse.post_status = 'publish'";
    }
    if ($courses) {
      $sql .= " AND pcourse.id IN (" . implode(",", $courses) . ")";
    }

    $sql .= " ORDER BY pcourse.post_title";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $result = [];

    foreach ($rows as $row) {
      $course_id = $row["course_id"];

      //get all post id metas
      $course_meta = get_post_meta($course_id);
      //map all metas to a single array
      $course_meta = array_map(function ($meta) {
        return is_array($meta) ? $meta[0] : $meta;
      }, $course_meta);

      $result[$course_id] = $course_meta;
      $result[$course_id]["total"] = 0;
      $result[$course_id]["ID"] = $course_id;
      $result[$course_id]["enabled"] = $row["post_status"] == "publish";
      $result[$course_id]["name"] = $row["course_name"];
      $result[$course_id]["price"] = $row["full_price"];
      $result[$course_id]["course_page_url"] = empty($row["course_page_url"]) ? get_permalink($course_id) : $row["course_page_url"];
      $result[$course_id]["charge_url"] = $row["charge_url"];
      $result[$course_id]["course_image"] = !empty($course_meta["_thumbnail_id"]) ? wp_get_attachment_image_url($course_meta["_thumbnail_id"], 'full') : null;

      $course = &$result[$course_id];
      $course["modules"] = [];

      $sql = "SELECT pmodule.post_title AS 'module_name', pmodule.ID AS 'module_id',
        pm6.meta_value AS module_order, pmodule.post_status,
        case when pm5.meta_value = '1' then true else false end as count_progress,
        case when pm7.meta_value = '1' then true else false end as intro_module,
        pm8.meta_value AS teaser
        FROM ".$wpdb->prefix."posts pmodule
        INNER JOIN ".$wpdb->prefix."postmeta pm2 ON pm2.post_id = pmodule.id AND pm2.meta_key = 'course' AND pm2.meta_value = $course_id
        LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm5 ON pm5.post_id = pmodule.ID AND pm5.meta_key = 'count_progress'
        LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm6 ON pm6.post_id = pmodule.ID AND pm6.meta_key = 'order'
        LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm7 ON pm7.post_id = pmodule.ID AND pm7.meta_key = 'intro_module'
        LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm8 ON pm8.post_id = pmodule.ID AND pm8.meta_key = 'teaser'
        WHERE pmodule.post_type = 'module'
        AND pmodule.post_status <> 'trash' 
        ";

      if ($enabledOnly) {
        $sql .= " AND pmodule.post_status = 'publish'";
      }

      $sql .= " ORDER BY module_order";

      $moduleRows = $wpdb->get_results($sql, ARRAY_A);

      foreach ($moduleRows as $moduleRow) {
        $moduleId = $moduleRow["module_id"];
        $course["modules"][$moduleId] = [];
        $module = &$course["modules"][$moduleId];
        $module["name"] = $moduleRow["module_name"];
        $module["count_progress"] = $moduleRow["count_progress"] == "1";
        $module["intro_module"] = $moduleRow["intro_module"] == "1";
        $module["order"] = $moduleRow["module_order"];
        $module["teaser"] = $moduleRow["teaser"] ?? '';
        $module["enabled"] = $moduleRow["post_status"] == "publish";

        $module["lessons"] = [];

        $sql = "SELECT plesson.post_title AS 'lesson_name', plesson.ID AS lesson_id, pm2.meta_value AS video_list,
          pm3.meta_value AS lesson_number, plesson.post_status, pm4.meta_value AS teaser
          FROM ".$wpdb->prefix."posts plesson
          INNER JOIN ".$wpdb->prefix."postmeta pm1 ON pm1.post_id = plesson.id AND pm1.meta_key = 'module' AND pm1.meta_value = $moduleId
          LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm2 ON pm2.post_id = plesson.id AND pm2.meta_key = 'video_list'
          LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm3 ON pm3.post_id = plesson.ID AND pm3.meta_key = 'lesson_number'
          LEFT OUTER JOIN ".$wpdb->prefix."postmeta pm4 ON pm4.post_id = plesson.ID AND pm4.meta_key = 'teaser'
          WHERE plesson.post_type = 'lesson'
          AND plesson.post_status <> 'trash' ";

        if ($enabledOnly) {
          $sql .= " AND plesson.post_status = 'publish'";
        }
        $sql .= " ORDER BY lesson_number";

        $lessonRows = $wpdb->get_results($sql, ARRAY_A);

        foreach ($lessonRows as $lessonRow) {
          $lessonId = $lessonRow["lesson_id"];
          $module["lessons"][$lessonId] = [];
          $lesson = &$module["lessons"][$lessonId];
          $lesson["name"] = $lessonRow["lesson_name"];
          $lesson["order"] = $lessonRow["lesson_number"];
          $lesson["enabled"] = $lessonRow["post_status"] == "publish";
          $lesson["teaser"] = $lessonRow["teaser"] ?? '';

          $videos = $lessonRow["video_list"];
          $videos = json_decode(empty($videos) ? "[]" : $videos, true);

          $lesson["videos"] = [];
          if (empty($videos)) {
            $lesson["videos"] = ["text"];
            $course["total"] += $moduleRow["count_progress"] == "1" ? 100 : 0;
          } else {
            foreach ($videos as $video) {
              $lesson["videos"][] = $video["video_id"];
              $course["total"] += $moduleRow["count_progress"] == "1" ? 100 : 0;
            }
          }
        }
      }
    }

    return $result;
  }

  public function has_tag($tag): bool {
    $tags = $this->field("tags") ?? '';
    $tags = explode(",", $tags);
    $tags = array_map('trim', $tags);
    return in_array($tag, $tags);
  }

  public function get_featured_image($size = 'thumbnail') {
    $image = $this->field('_thumbnail_id');
    $genericImage = plugin_dir_url(__FILE__) . 'assets/images/generic-course-placeholder.png';
    $found = true;
    //check if image exists
    if(empty($image) || !is_numeric($image)) {
      $found = false;
      $image = $genericImage;
    } else {
      $image = wp_get_attachment_image_src($image, $size);
      if (empty($image) || !is_array($image)) {
        $found = false;
        $image = $genericImage;
      } else {
        $image = $image[0]; //get the URL
      }
    }

    return apply_filters('future-lms/course_image', $image, $found, $this->raw("ID"), $size);
  }
}
?>
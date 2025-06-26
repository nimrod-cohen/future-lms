<?php 
namespace FutureLMS\classes;
use FutureLMS\FutureLMS;
use FutureLMS\classes\BaseObject;

  /*
  Should've been named Class but its a reserved word in PHP
  */
  class SchoolClass extends BaseObject {
    public function __construct($class_id_or_params = null) {
        parent::__construct('class', $class_id_or_params);
    }

    public static function students($courseId, $classId, $search = null, $month = null, $year = null)
    {
        global $wpdb;

        $where = '1 = 1';
        $search = '%' . $wpdb->esc_like($search) . '%';

        if (!empty($courseId)) {
            $where .= ' AND courses.id = ' . $courseId;
        }

        if (!empty($classId)) {
            $where .= ' AND class_id = ' . $classId;
        }

        if (!empty($search)) {
            $where .= " AND ( u.display_name like %s OR u.user_email like %s)";
        } else {
            $search = 1;
            $where .= " AND (%d = %d) ";
        }
        if (!empty($month)) {
            $where .= " AND ( month(cs.registration_date) = " . $month . " AND year(cs.registration_date) = " . $year . ")";
        }

        $sql = "select u.id, u.user_email, u.display_name, IFNULL(um.meta_value,'') as phone, cs.registration_date, cs.class_id, classes.post_title as class_name, courses.id as course_id, courses.post_title as course_name
      from " . FutureLMS::TABLE_PREFIX() . "class_to_students cs
      inner join ".$wpdb->prefix."users u on u.id = cs.student_id
      inner join ".$wpdb->prefix."posts classes on classes.id = cs.class_id
      inner join ".$wpdb->prefix."postmeta wpm on wpm.post_id = cs.class_id and wpm.meta_key = 'course'
      inner join ".$wpdb->prefix."posts courses on courses.id = wpm.meta_value
      left outer join ".$wpdb->prefix."usermeta um on cs.student_id = um.user_id and um.meta_key = 'user_phone'
      where " . $where . "
      order by cs.registration_date desc";

        $sql = $wpdb->prepare($sql, $search, $search);

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function get_classes_tree($classes = null, $enabledOnly = true)
    {
      if (empty($classes)) return [];

      global $wpdb;

      $sql = "SELECT pclass.id AS class_id, pclass.post_title AS class_name, pclass.post_status
    FROM " . $wpdb->prefix . "posts pclass
    WHERE pclass.post_type = 'class'
    AND pclass.post_status <> 'trash' ";

      if ($enabledOnly) {
        $sql .= "AND pclass.post_status = 'publish'";
      }
      if ($classes) {
        $sql .= " AND pclass.id IN (" . implode(",", $classes) . ")";
      }

      $sql .= " ORDER BY pclass.post_title";

      $rows = $wpdb->get_results($sql, ARRAY_A);
      $result = [];

      foreach ($rows as $row) {
        $class_id = $row["class_id"];

        //get all post id metas
        $class_meta = get_post_meta($class_id);
        //map all metas to a single array
        $class_meta = array_map(function ($meta) {
          return is_array($meta) ? $meta[0] : $meta;
        }, $class_meta);

        $result[$class_id] = $class_meta;
        $result[$class_id]["total"] = 0;
        $result[$class_id]["ID"] = $class_id;
        $result[$class_id]["enabled"] = $row["post_status"] == "publish";
        $result[$class_id]["name"] = $row["class_name"];
        $result[$class_id]["course"] = $class_meta["course"];
        $result[$class_id]["lessons_json"] = $class_meta["lessons"];

        $class = &$result[$class_id];
        $courseId = $class["course"];
        $class["modules"] = [];
        $class["lessons"] = [];


        $sql = "SELECT pmodule.post_title AS 'module_name', pmodule.ID AS 'module_id',
        pm6.meta_value AS module_order, pmodule.post_status,
        case when pm5.meta_value = '1' then true else false end as count_progress,
        case when pm7.meta_value = '1' then true else false end as intro_module,
        pm8.meta_value AS teaser
        FROM ".$wpdb->prefix."posts pmodule
       INNER JOIN wp_postmeta pm_course ON pm_course.post_id = pmodule.ID AND pm_course.meta_key = 'course' AND pm_course.meta_value = $courseId
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
          $class["modules"][$moduleId] = [];
          $module = &$class["modules"][$moduleId];
          $module["name"] = $moduleRow["module_name"];
          $module["count_progress"] = $moduleRow["count_progress"] == "1";
          $module["intro_module"] = $moduleRow["intro_module"] == "1";
          $module["order"] = $moduleRow["module_order"];
          $module["teaser"] = $moduleRow["teaser"] ?? '';
          $module["enabled"] = $moduleRow["post_status"] == "publish";

          $module["lessons"] = [];

          $sql = "SELECT plesson.post_title AS 'lesson_name', plesson.ID AS lesson_id, pm2.meta_value AS video_list,
          pm3.meta_value AS lesson_number, plesson.post_status, pm4.meta_value AS teaser
          FROM " . $wpdb->prefix . "posts plesson
          INNER JOIN " . $wpdb->prefix . "postmeta pm1 ON pm1.post_id = plesson.id AND pm1.meta_key = 'module' AND pm1.meta_value = $moduleId
          LEFT OUTER JOIN " . $wpdb->prefix . "postmeta pm2 ON pm2.post_id = plesson.id AND pm2.meta_key = 'video_list'
          LEFT OUTER JOIN " . $wpdb->prefix . "postmeta pm3 ON pm3.post_id = plesson.ID AND pm3.meta_key = 'lesson_number'
          LEFT OUTER JOIN " . $wpdb->prefix . "postmeta pm4 ON pm4.post_id = plesson.ID AND pm4.meta_key = 'teaser'
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
            $lesson["id"] = $lessonId;
            $lesson["name"] = $lessonRow["lesson_name"];
            $lesson["order"] = $lessonRow["lesson_number"];
            $lesson["enabled"] = $lessonRow["post_status"] == "publish";
            $lesson["teaser"] = $lessonRow["teaser"] ?? '';
            $videos = $lessonRow["video_list"];
            $videos = json_decode(empty($videos) ? "[]" : $videos, true);

            $lesson["videos"] = [];
            if (empty($videos)) {
              $lesson["videos"] = ["text"];

            } else {
              foreach ($videos as $video) {
                $lesson["videos"][] = $video["video_id"];
              }
            }
          }
        }
      }

      return $result;
    }
  }
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
            $where .= " AND ( u.display_name like %s OR u.user_email like %s OR um.meta_value like %s)";
        } else {
            $search = 1;
            $where .= " AND (%d = %d AND %d = %d) ";
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

        $sql = $wpdb->prepare($sql, $search, $search, $search);

        return $wpdb->get_results($sql, ARRAY_A);
    }
  
    /**
     * A class is either self-paced (every lesson open) or paced (each lesson
     * opens on its own date & hour).
     */
    public static function is_paced($classId) {
        return get_post_meta($classId, 'paced', true) == '1';
    }

    /**
     * Whether a class's schedule lets a lesson open.
     *
     * Self-paced: always. Paced: $classLessons is the class's `lessons` meta,
     * decoded - [{id, open_at}] with open_at in site time, "Y-m-d H:i"; the
     * lesson opens at that moment. An older manual `open` flag still counts.
     * A lesson with no date stays closed.
     */
    public static function is_lesson_released($classLessons, $lessonId, $paced) {
        if (!$paced) {
            return true;
        }
        $entry = self::find_lesson_entry($classLessons, $lessonId);
        if (!empty($entry['open_at'])) {
            return current_time('Y-m-d H:i') >= $entry['open_at'];
        }
        return !empty($entry['open']);
    }

    public static function student_count($classId) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT student_id) FROM " . FutureLMS::TABLE_PREFIX() . "class_to_students WHERE class_id = %d",
            $classId
        ));
    }

    public static function find_lesson_entry($classLessons, $lessonId) {
        foreach ((array) $classLessons as $entry) {
            if ((int) ($entry['id'] ?? 0) === (int) $lessonId) {
                return $entry;
            }
        }
        return null;
    }
  }

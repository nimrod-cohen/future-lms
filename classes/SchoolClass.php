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
  }
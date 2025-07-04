<?php

namespace FutureLMS\classes;
use FutureLMS\FutureLMS;

class Student
{
    private $_studentId = null;
    private $_attendence_info = null;

    function __construct($studentId)
    {
        $this->_studentId = $studentId;
    }

    public function attendence_info()
    {
        if (!empty($this->_attendence_info)) {
            return $this->_attendence_info;
        }

        global $wpdb;

        $sql = $wpdb->prepare("SELECT p.id as id, p2.post_title as `course_name`, pm4.meta_value as shortname, pm.meta_value as course_id, pm2.meta_value as start_date, cts.registration_date, pm3.meta_value as lessons
          from " . $wpdb->prefix . "posts p
          inner join " .   FutureLMS::TABLE_PREFIX() . "class_to_students cts on cts.class_id = p.id
          inner join " .   $wpdb->prefix . "postmeta pm on pm.meta_key = 'course' and pm.post_id = p.id
          INNER JOIN " .   $wpdb->prefix . "posts p2 on p2.id = pm.meta_value
          left outer join " .   $wpdb->prefix . "postmeta pm2 on pm2.meta_key = 'start_date' and pm2.post_id = p.id
          left outer join " .   $wpdb->prefix . "postmeta pm3 on pm3.meta_key = 'lessons' and pm3.post_id = p.id
          left outer join " .   $wpdb->prefix . "postmeta pm4 on pm4.meta_key = 'short_name' and pm4.post_id = p2.id
          WHERE p.post_type = 'class'
          AND p.post_status = 'publish'
          AND cts.student_id = %d", $this->_studentId);

        $courses = $wpdb->get_results($sql, ARRAY_A);
        $this->_attendence_info = $courses;

        return $this->_attendence_info;
    }

    public function get_class($courseId)
    {
        $attending_courses = $this->attendence_info();

        foreach ($attending_courses as $attendedCourse) {
            if ($attendedCourse['course_id'] == $courseId) {
                return $attendedCourse;
            }

        }
        return null;
    }

    public function set_progress($data)
    {
        update_user_meta($this->_studentId, 'course_progress', json_encode($data));
    }

    public function get_progress()
    {
        $data = get_user_meta($this->_studentId, 'course_progress', true);

        if (empty($data)) {
            return ["courses" => []];
        }

        return json_decode($data, true);
    }

    public function subscribe_to_class($classId, $subscribe)
    {
        global $wpdb;
        if ($subscribe) {
            $sql = "REPLACE INTO " .   FutureLMS::TABLE_PREFIX() . "class_to_students (student_id, class_id, registration_date)
          values (%d, %d, CURRENT_TIMESTAMP)";
        } else {
            $sql = "DELETE FROM " .   FutureLMS::TABLE_PREFIX() . "class_to_students WHERE student_id = %d and class_id = %d";
        }
        $sql = $wpdb->prepare($sql, $this->_studentId, $classId);
        $wpdb->query($sql);
    }

    public static function get_payments($year, $month)
    {
        global $wpdb;

        $sql = "SELECT p.*, u.user_email, um.meta_value as affiliate_id, aff.display_name as affiliate_name, course.post_title as course_name
    FROM " .   FutureLMS::TABLE_PREFIX() . "payments p
    LEFT OUTER JOIN ".$wpdb->prefix."users u on u.ID = p.student_id
    LEFT OUTER JOIN ".$wpdb->prefix."usermeta um on u.ID = um.user_id and um.meta_key = 'affiliate_id'
    LEFT OUTER JOIN ".$wpdb->prefix."users aff on aff.ID = um.meta_value
    LEFT OUTER JOIN ".$wpdb->prefix."posts course ON course.id = p.course_id
    WHERE year(payment_date) = %d
    AND month(payment_date) = %d
    AND deleted = 0";
        $sql = $wpdb->prepare($sql, $year, $month);
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function save_payment($courseId, $classId, $sum, $transRef, $processor, $comment)
    {
        global $wpdb;
        $sql = "INSERT INTO " . FutureLMS::TABLE_PREFIX() . "payments (student_id, course_id, class_id, payment_date, transaction_ref, sum, processor, comment, deleted)
          values (%d, %d, %d, CURRENT_TIMESTAMP, '%s', %f, '%s', '%s', 0)";
        $sql = $wpdb->prepare($sql, $this->_studentId, $courseId, $classId, $transRef, $sum, $processor, $comment);
        $wpdb->query($sql);
        return $wpdb->insert_id;
    }

    public static function get_payment($paymentId)
    {
        global $wpdb;
        $sql = "SELECT p.*, u.user_email, um.meta_value as affiliate_id, aff.display_name as affiliate_name
    FROM " . FutureLMS::TABLE_PREFIX() . "payments p
    LEFT OUTER JOIN ".$wpdb->prefix."users u on u.ID = p.student_id
    LEFT OUTER JOIN ".$wpdb->prefix."usermeta um on u.ID = um.user_id and um.meta_key = 'affiliate_id'
    LEFT OUTER JOIN ".$wpdb->prefix."users aff on aff.ID = um.meta_value
    WHERE p.id = %d";
        $sql = $wpdb->prepare($sql, $paymentId);
        return $wpdb->get_row($sql, ARRAY_A);
    }

    public static function delete_payment($paymentId)
    {
        global $wpdb;
        $sql = "UPDATE " . FutureLMS::TABLE_PREFIX() . "payments SET deleted = 1 WHERE id = %d";
        $sql = $wpdb->prepare($sql, $paymentId);
        $wpdb->query($sql);
    }

    //check if the user attends this course
    public function is_attending_class($courseId, $classId)
    {
        $class = $this->get_class($courseId);
        return intval($class["id"]) == $classId;
    }

    //check if the user attends this course
    public function is_attending_course($courseId)
    {
        return $this->get_class($courseId) !== null;
    }

    public function get_class_lessons_by_course_id($courseId)
    {
        $course = $this->get_class($courseId);

        if ($course == null) {
            return false;
        }

        $lessons = json_decode($course["lessons"], true);

        return $lessons;
    }

    //get last open class
    public function get_next_lesson($courseId)
    {
        $lessons = $this->get_class_lessons_by_course_id($courseId);

        if (!$lessons) {
            return null;
        }

        //find the next non-open class
        $lesson = array_reduce($lessons, function ($result, $item) {
            return $item["open"] === true ? $item : $result;
        });

        if (empty($lesson)) {
            return $lessons[0]["id"];
        }

        return $lesson["id"];
    }

    public function is_lesson_open($courseId, $lessonId)
    {
        $lessons = $this->get_class_lessons_by_course_id($courseId);

        //if not managed assume open
        if (!$lessons) {
            return true;
        }

        $lesson = array_reduce($lessons, function ($result, $item) use ($lessonId) {
            return intval($item["id"]) === intval($lessonId) ? $item : $result;
        });

        //if lesson doesn't exist - assume open
        if (empty($lesson)) {
            return true;
        }

        return !empty($lesson["open"]) && $lesson["open"] == true;
    }

    public function get_module_lessons($moduleId)
    {
        return new Lesson(["where" => "module.id = " . $moduleId, "orderby" => "lesson_number.meta_value ASC", "limit" => -1]);
    }

    public function get_lesson_notes($lessonId)
    {
        global $wpdb;
        $sql = "SELECT notes
          FROM " . FutureLMS::TABLE_PREFIX() . "student_notes
          WHERE student_id = %d AND lesson_id = %d";
        $sql = $wpdb->prepare($sql, $this->_studentId, $lessonId);
        return $wpdb->get_var($sql);
    }

    public function set_lesson_notes($lessonId, $notes)
    {
        global $wpdb;
        $sql = "REPLACE INTO " . FutureLMS::TABLE_PREFIX() . "student_notes (student_id, lesson_id, notes)
          values (%d, %d, '%s')";
        $sql = $wpdb->prepare($sql, $this->_studentId, $lessonId, $notes);
        $wpdb->query($sql);
    }
}
<?php

namespace FutureLMS\classes;

class DBManager
{
    private $_studentId = null;
    private $_courses = null;

    function __construct($studentId)
    {
        $this->_studentId = $studentId;
    }

    public function getStudentCourses()
    {
        if (!empty($this->_courses)) {
            return $this->_courses;
        }

        global $wpdb;

        $sql = $wpdb->prepare("SELECT p.id as id, p2.post_title as `course_name`, pm4.meta_value as shortname, pm.meta_value as course_id, pm2.meta_value as start_date, cts.registration_date, pm3.meta_value as lessons
    from " . $wpdb->prefix . "posts p
    inner join " . $wpdb->prefix . "course_to_students cts on cts.class_id = p.id
    inner join " . $wpdb->prefix . "postmeta pm on pm.meta_key = 'course' and pm.post_id = p.id
    INNER JOIN " . $wpdb->prefix . "posts p2 on p2.id = pm.meta_value
    left outer join " . $wpdb->prefix . "postmeta pm2 on pm2.meta_key = 'start_date' and pm2.post_id = p.id
    left outer join " . $wpdb->prefix . "postmeta pm3 on pm3.meta_key = 'lessons' and pm3.post_id = p.id
    left outer join " . $wpdb->prefix . "postmeta pm4 on pm4.meta_key = 'short_name' and pm4.post_id = p2.id
    WHERE p.post_type = 'class'
    AND p.post_status = 'publish'
    AND cts.student_id = %d", $this->_studentId);
        $this->_courses = $wpdb->get_results($sql, ARRAY_A);

        return $this->_courses;
    }

    public function getClass($courseId)
    {
        $attendingCourses = $this->getStudentCourses();

        foreach ($attendingCourses as $attendedCourse) {
            if ($attendedCourse["course_id"] == $courseId) {
                return $attendedCourse;
            }

        }
        return null;
    }

    public function setStudentProgress($data)
    {
        update_user_meta($this->_studentId, 'course_progress', json_encode($data));
    }

    public function getStudentProgress()
    {
        $data = get_user_meta($this->_studentId, 'course_progress', true);

        if (empty($data)) {
            return ["courses" => []];
        }

        return json_decode($data, true);
    }

    public function subscribeToClass($classId, $subscribe)
    {
        global $wpdb;
        if ($subscribe) {
            $sql = "REPLACE INTO " . $wpdb->prefix . "course_to_students (student_id, class_id, registration_date)
          values (%d, %d, CURRENT_TIMESTAMP)";
        } else {
            $sql = "DELETE FROM " . $wpdb->prefix . "course_to_students WHERE student_id = %d and class_id = %d";
        }
        $sql = $wpdb->prepare($sql, $this->_studentId, $classId);
        $wpdb->query($sql);
    }

    public static function getPayments($year, $month)
    {
        global $wpdb;
        $sql = "SELECT p.*, u.user_email, um.meta_value as affiliate_id, aff.display_name as affiliate_name, course.post_title as course_name
    FROM " . $wpdb->prefix . "payments p
    LEFT OUTER JOIN wp_users u on u.ID = p.student_id
    LEFT OUTER JOIN wp_usermeta um on u.ID = um.user_id and um.meta_key = 'affiliate_id'
    LEFT OUTER JOIN wp_users aff on aff.ID = um.meta_value
    LEFT OUTER JOIN wp_posts course ON course.id = p.course_id
    WHERE year(payment_date) = %d
    AND month(payment_date) = %d
    AND deleted = 0";
        $sql = $wpdb->prepare($sql, $year, $month);
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function savePayment($courseId, $classId, $sum, $transRef, $processor, $comment)
    {
        global $wpdb;
        $sql = "INSERT INTO " . $wpdb->prefix . "payments (student_id, course_id, class_id, payment_date, transaction_ref, sum, processor, comment, deleted)
          values (%d, %d, %d, CURRENT_TIMESTAMP, '%s', %f, '%s', '%s', 0)";
        $sql = $wpdb->prepare($sql, $this->_studentId, $courseId, $classId, $transRef, $sum, $processor, $comment);
        $wpdb->query($sql);
        return $wpdb->insert_id;
    }

    public static function getPayment($paymentId)
    {
        global $wpdb;
        $sql = "SELECT p.*, u.user_email, um.meta_value as affiliate_id, aff.display_name as affiliate_name
    FROM " . $wpdb->prefix . "payments p
    LEFT OUTER JOIN wp_users u on u.ID = p.student_id
    LEFT OUTER JOIN wp_usermeta um on u.ID = um.user_id and um.meta_key = 'affiliate_id'
    LEFT OUTER JOIN wp_users aff on aff.ID = um.meta_value
    WHERE p.id = %d";
        $sql = $wpdb->prepare($sql, $paymentId);
        return $wpdb->get_row($sql, ARRAY_A);
    }

    public static function deletePayment($paymentId)
    {
        global $wpdb;
        $sql = "UPDATE " . $wpdb->prefix . "payments SET deleted = 1 WHERE id = %d";
        $sql = $wpdb->prepare($sql, $paymentId);
        $wpdb->query($sql);
    }

    //check if the user attends this course
    public function isAttendingClass($courseId, $classId)
    {
        $class = $this->getClass($courseId);
        return intval($class["id"]) == $classId;
    }

    //check if the user attends this course
    public function isAttending($courseId)
    {
        return $this->getClass($courseId) !== null;
    }

    public function getClassLessonsByCourse($courseId)
    {
        $course = $this->getClass($courseId);

        if ($course == null) {
            return false;
        }

        $lessons = json_decode($course["lessons"], true);

        return $lessons;
    }

    public static function getCourseClasses($courseId, $search)
    {
        $where = 'course.id = ' . $courseId;
        if ($search) {
            $where .= " AND t.post_title like '%" . $search . "%'";
        }
        $classes = pods('Class', ['limit' => 0, 'where' => $where, 'orderby' => 'start_date DESC']);
        $result = [];

        while ($classes->fetch()) {
            $row = $classes->row();
            $result[] = [
                'id' => $row["ID"],
                'title' => $row["post_title"]
            ];
        }

        return $result;
    }

    //get last open class
    public function getNextLesson($courseId)
    {
        $lessons = $this->getClassLessonsByCourse($courseId);

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

    public function isLessonOpen($courseId, $lessonId)
    {
        $lessons = $this->getClassLessonsByCourse($courseId);

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

    public function getCourseModules($courseId)
    {
        return pods("module", ["where" => "course.id = " . $courseId, "orderby" => "order.meta_value ASC", "limit" => -1]);
    }

    public function getModuleLessons($moduleId)
    {
        return pods("lesson", ["where" => "module.id = " . $moduleId, "orderby" => "lesson_number.meta_value ASC", "limit" => -1]);
    }

    public static function getClassStudents($courseId, $classId, $search = null, $month = null, $year = null)
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
      from wp_course_to_students cs
      inner join wp_users u on u.id = cs.student_id
      inner join wp_posts classes on classes.id = cs.class_id
      inner join wp_postmeta wpm on wpm.post_id = cs.class_id and wpm.meta_key = 'course'
      inner join wp_posts courses on courses.id = wpm.meta_value
      left outer join wp_usermeta um on cs.student_id = um.user_id and um.meta_key = 'user_phone'
      where " . $where . "
      order by cs.registration_date desc";

        $sql = $wpdb->prepare($sql, $search, $search);

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function getStudentNotes($lessonId)
    {
        global $wpdb;
        $sql = "SELECT notes
    FROM " . $wpdb->prefix . "student_notes
    WHERE student_id = %d AND lesson_id = %d";
        $sql = $wpdb->prepare($sql, $this->_studentId, $lessonId);
        return $wpdb->get_var($sql);
    }

    public function setStudentNotes($lessonId, $notes)
    {
        global $wpdb;
        $sql = "REPLACE INTO " . $wpdb->prefix . "student_notes (student_id, lesson_id, notes)
          values (%d, %d, '%s')";
        $sql = $wpdb->prepare($sql, $this->_studentId, $lessonId, $notes);
        $wpdb->query($sql);
    }
}
<?php

use FutureLMS;

class FutureLMSQuery
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

    public static function installVersion()
    {
        global $wpdb;

        $curr = get_option(FutureLMS::VALUE_SCHOOL_VERSION, 0);

        $charsetCollate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if ($curr < 1) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "CREATE TABLE $tableName (
        student_id mediumint(9) NOT NULL,
        class_id mediumint(9) NOT NULL,
        registration_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (student_id, class_id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, 1);
        }

        if ($curr < 2) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` CHANGE `course_instance_id` `class_id` MediumInt( 9 ) NOT NULL";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, 2);
        }

        if ($curr < 3) {
            $tableName = $wpdb->prefix . "payments";
            $sql = "CREATE TABLE $tableName (
        id MEDIUMINT NOT NULL AUTO_INCREMENT,
        student_id mediumint(9) NOT NULL,
        course_id mediumint(9) NOT NULL,
        class_id mediumint(9) NOT NULL,
        payment_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        transaction_ref varchar(100),
        sum decimal(10,2) NOT NULL,
        PRIMARY KEY  (id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, 3);
        }

        if (version_compare($curr, '4.0.1', '<')) {
            $tableName = $wpdb->prefix . "payments";
            $sql = "CREATE TABLE $tableName (
        id MEDIUMINT NOT NULL AUTO_INCREMENT,
        student_id mediumint(9) NOT NULL,
        course_id mediumint(9) NOT NULL,
        class_id mediumint(9) NOT NULL,
        payment_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        transaction_ref varchar(100),
        sum decimal(10,2) NOT NULL,
        processor varchar(100) NOT NULL DEFAULT '',
        comment varchar(1000) NOT NULL DEFAULT '',
        PRIMARY KEY  (id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '4.0.1');
        }

        if (version_compare($curr, '4.0.2', '<')) {
            $tableName = $wpdb->prefix . "payments";
            $sql = "CREATE TABLE $tableName (
        id MEDIUMINT NOT NULL AUTO_INCREMENT,
        student_id mediumint(9) NOT NULL,
        course_id mediumint(9) NOT NULL,
        class_id mediumint(9) NOT NULL,
        payment_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        transaction_ref varchar(100),
        sum decimal(10,2) NOT NULL,
        processor varchar(100) NOT NULL DEFAULT '',
        comment varchar(1000) NOT NULL DEFAULT '',
        deleted tinyint NOT NULL DEFAULT 0,
        PRIMARY KEY  (id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '4.0.2');
        }

        /*
        add course price shortcode
         */
        if (version_compare($curr, '4.0.3', '<')) {
            //version bump, no db changes
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '4.0.3');
        }

        if (version_compare($curr, '4.1.0', '<')) {
            $tableName = $wpdb->prefix . "coupons";
            $sql = "CREATE TABLE $tableName (
        id MEDIUMINT NOT NULL AUTO_INCREMENT,
        code nvarchar(50) NOT NULL,
        course_id mediumint(9) NOT NULL,
        `global` tinyint NOT NULL DEFAULT 0,
        expires datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        email nvarchar(255) NOT NULL,
        price decimal(10,2) NOT NULL,
        deleted tinyint NOT NULL DEFAULT 0,
        PRIMARY KEY  (id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '4.1.0');
        }

        if (version_compare($curr, '4.1.1', '<')) {
            $tableName = $wpdb->prefix . "coupons";
            $sql = "CREATE TABLE $tableName (
        id MEDIUMINT NOT NULL AUTO_INCREMENT,
        code nvarchar(50) NOT NULL,
        course_id mediumint(9) NOT NULL,
        `global` tinyint NOT NULL DEFAULT 0,
        expires datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        email nvarchar(255) NOT NULL,
        price decimal(10,2) NOT NULL,
        deleted tinyint NOT NULL DEFAULT 0,
        comment nvarchar(1000),
        PRIMARY KEY  (id)
      ) $charsetCollate;";

            dbDelta($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '4.1.1');
        }

        if (version_compare($curr, '5.0.7', '<')) {
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.0.7');
        }

        if (version_compare($curr, '5.2.0', '<')) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` ADD `progress` nvarchar(1000) NOT NULL";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.0');
        }

        if (version_compare($curr, '5.2.1', '<')) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` DROP `progress`";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.1');
        }

        if (version_compare($curr, '5.2.3', '<')) {
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.3');
        }

        if (version_compare($curr, '5.2.4', '<')) {
            $tableName = $wpdb->prefix . "student_notes";
            $sql = "CREATE TABLE $tableName (
        student_id mediumint(9) NOT NULL,
        lesson_id mediumint(9) NOT NULL,
        notes nvarchar(4000) NULL,
        PRIMARY KEY  (student_id, lesson_id)
      ) $charsetCollate;";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.4');
        }

        if (version_compare($curr, '5.2.7', '<')) {
            $sql = "CREATE TABLE IF NOT EXISTS `wp_vi_integrations`(
        `id` Int( 0 ) NOT NULL,
        `name` VarChar( 255 ) CHARACTER SET utf16 COLLATE utf16_general_ci NOT NULL,
        `auth` VarChar( 255 ) CHARACTER SET utf16 COLLATE utf16_general_ci NOT NULL,
        PRIMARY KEY ( `id` ) )
      CHARACTER SET = utf16
      COLLATE = utf16_general_ci
      ENGINE = InnoDB
      AUTO_INCREMENT = 1;";
            $wpdb->query($sql);

            $sql = "CREATE INDEX `index_id` USING BTREE ON `wp_vi_integrations`( `id` );";
            $wpdb->query($sql);

            $sql = "ALTER TABLE `wp_vi_integrations` MODIFY `id` Int( 0 ) AUTO_INCREMENT NOT NULL; ";
            $wpdb->query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `wp_vi_partner_coupons`(
        `id` Int( 255 ) AUTO_INCREMENT NOT NULL,
        `partner_id` Int( 255 ) NOT NULL,
        `code` VarChar( 8 ) NOT NULL,
        `name` VarChar( 255 ) NOT NULL,
        `phone` VarChar( 255 ) NOT NULL,
        `used` Bit( 1 ) NOT NULL,
        PRIMARY KEY ( `id` ) )
        ENGINE = InnoDB;";
            $wpdb->query($sql);

            $sql = "CREATE INDEX `index_partner_id` ON `wp_vi_partner_coupons`( `partner_id` );";
            $wpdb->query($sql);

            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `use_date` DateTime NULL;";
            $wpdb->query($sql);
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `creation_date` DateTime NOT NULL default CURRENT_TIMESTAMP;";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.7');
        }

        if (version_compare($curr, '5.2.8', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `deal_id` Int NOT NULL;";
            $wpdb->query($sql);
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.8');
        }

        if (version_compare($curr, '5.2.9', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `user_id` Int NULL;";
            $wpdb->query($sql);
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.9');
        }

        if (version_compare($curr, '5.2.10', '<')) {
            $sql = "CREATE TABLE IF NOT EXISTS `wp_vi_partner_deals`(
        `id` Int( 255 ) AUTO_INCREMENT NOT NULL,
        `partner_id` Int( 255 ) NOT NULL,
        `data` VarChar( 255 ) NOT NULL,
        `deleted` Bit( 1 ) NOT NULL DEFAULT 0,
        PRIMARY KEY ( `id` ) )
        ENGINE = InnoDB;";
            $wpdb->query($sql);
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.10');
        }

        if (version_compare($curr, '5.2.11', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `email` nvarchar(255) NULL;";
            $wpdb->query($sql);
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.2.11');
        }

        if (version_compare($curr, '5.3.0', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `tpid` nvarchar(255) NULL;";
            $wpdb->query($sql);
            $sql = "ALTER TABLE `wp_vi_partner_coupons` MODIFY `phone` VarChar(255) NULL;";
            $wpdb->query($sql);

            update_option(ValueSchool::VALUE_SCHOOL_VERSION, '5.3.0');
        }

        if (version_compare($curr, '5.3.1', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` MODIFY `name` VarChar( 255 ) NULL;";
            $wpdb->query($sql);
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.3.1');
        }

        if (version_compare($curr, '5.5.3', '<')) {
            $sql = "CREATE TABLE IF NOT EXISTS `wp_vi_partners`(
        `id` Int( 255 ) AUTO_INCREMENT NOT NULL,
        `name` VarChar( 255 ) NOT NULL,
        `display_name` VarChar( 255 ) NOT NULL,
        `deleted` Bit( 1 ) NOT NULL DEFAULT 0,
        PRIMARY KEY ( `id` ) )
        ENGINE = InnoDB;";
            $wpdb->query($sql);

            $sql = "ALTER TABLE `wp_vi_integrations` DROP COLUMN `name`";
            $wpdb->query($sql);

            $sql = "ALTER TABLE `wp_vi_integrations` ADD COLUMN `partner_id` Int( 0 ) NOT NULL;";
            $wpdb->query($sql);

            update_option(FutureLMS::VALUE_SCHOOL_VERSION, '5.5.3');
        }

        //KEEP THIS AT THE END
        $lastVersion = FutureLMS::version();
        if (version_compare($curr, $lastVersion, '<')) {
            update_option(FutureLMS::VALUE_SCHOOL_VERSION, $lastVersion);
        }
    }
}
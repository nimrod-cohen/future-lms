<?php 
namespace FutureLMS\classes;
use FutureLMS\FutureLMS;

class VersionManager {
  const SCHOOL_VERSION = 'future_lms_version';
  public static function install_version()
  {
    global $wpdb;

    $prefix = FutureLMS::TABLE_PREFIX();

    $dbVersion = get_option(self::SCHOOL_VERSION, '0');
    $currentVersion = FutureLMS::version();

    $charsetCollate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    if( version_compare($dbVersion, '1.0.0', '<') ) {
        //fresh install
        $tableName = $prefix . "class_to_students";
        $sql = "CREATE TABLE IF NOT EXISTS $tableName (
          `student_id` MediumInt( 0 ) NOT NULL,
          `class_id` MediumInt( 0 ) NOT NULL,
          `registration_date` DateTime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY ( `student_id`, `class_id` ) )
          $charsetCollate";
        dbDelta($sql);

        $tableName = $prefix . "student_notes";
        $sql = "CREATE TABLE IF NOT EXISTS $tableName (
          `student_id` MediumInt( 0 ) NOT NULL,
          `lesson_id` MediumInt( 0 ) NOT NULL,
          `notes` TEXT DEFAULT NULL,
          PRIMARY KEY ( `student_id`, `lesson_id` ) )
          $charsetCollate"; 
        dbDelta($sql);

        update_option(self::SCHOOL_VERSION, '1.0.0');
        return;
    }

    if( version_compare($dbVersion, '1.1.0', '<') ) {
        $tableName = $prefix . "progress";
        $sql = "CREATE TABLE IF NOT EXISTS $tableName (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          user_id BIGINT(20) NOT NULL,
          course_id BIGINT(20) NOT NULL,
          module_id BIGINT(20) NOT NULL,
          lesson_id BIGINT(20) NOT NULL,
          video_id VARCHAR(191) NOT NULL,
          percent INT(11) NOT NULL,
          seconds INT(11) NOT NULL,
          updated_at DATETIME NOT NULL,
          UNIQUE KEY user_course_module_lesson_video (user_id, course_id, module_id, lesson_id, video_id),
          INDEX idx_user (user_id),
          INDEX idx_course (course_id)
        ) $charsetCollate";
        dbDelta($sql);

        // Copy data from old vi_progress table if it exists
        $oldTable = $wpdb->prefix . 'vi_progress';
        if ($wpdb->get_var("SHOW TABLES LIKE '$oldTable'") === $oldTable) {
            $wpdb->query("INSERT IGNORE INTO $tableName
                (id, user_id, course_id, module_id, lesson_id, video_id, percent, seconds, updated_at)
                SELECT id, user_id, course_id, module_id, lesson_id, video_id, percent, seconds, updated_at
                FROM $oldTable");
        }

        update_option(self::SCHOOL_VERSION, '1.1.0');
    }

    //KEEP THIS AT THE END
    if (version_compare($dbVersion, $currentVersion, '<')) {
        update_option(self::SCHOOL_VERSION, $currentVersion);
    }
  }
}
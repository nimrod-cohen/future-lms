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

    //KEEP THIS AT THE END
    if (version_compare($dbVersion, $currentVersion, '<')) {
        update_option(self::SCHOOL_VERSION, $currentVersion);
    }
  }
}
<?php 
namespace FutureLMS\classes;
use FutureLMS\FutureLMS;

class VersionManager {
  const SCHOOL_VERSION = 'future_lms_version';
  public static function install_version()
  {
    global $wpdb;

    $prefix = FutureLMS::TABLE_PREFIX();

    $curr = get_option(self::SCHOOL_VERSION, 0);

    $charsetCollate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    if ($curr < 1) {
        $tableName = $prefix . "class_to_students";
        $sql = "CREATE TABLE $tableName (
          student_id mediumint(9) NOT NULL,
          class_id mediumint(9) NOT NULL,
          registration_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
          PRIMARY KEY  (student_id, class_id)
        ) $charsetCollate;";

        dbDelta($sql);

        update_option(self::SCHOOL_VERSION, 1);
    }

    if ($curr < 2) {
        $tableName = $prefix . "class_to_students";
        $sql = "ALTER TABLE `$tableName` CHANGE `course_instance_id` `class_id` MediumInt( 9 ) NOT NULL";
        $wpdb->query($sql);

        update_option(self::SCHOOL_VERSION, 2);
    }

    if ($curr < 3) {
        $tableName = $prefix . "payments";
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

        update_option(self::SCHOOL_VERSION, 3);
    }

    if (version_compare($curr, '4.0.1', '<')) {
        $tableName = $prefix . "payments";
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

        update_option(self::SCHOOL_VERSION, '4.0.1');
    }

    if (version_compare($curr, '4.0.2', '<')) {
        $tableName = $prefix . "payments";
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

        update_option(self::SCHOOL_VERSION, '4.0.2');
    }

    /*
    add course price shortcode
      */
    if (version_compare($curr, '4.0.3', '<')) {
        //version bump, no db changes
        update_option(self::SCHOOL_VERSION, '4.0.3');
    }

    if (version_compare($curr, '4.1.0', '<')) {
        $tableName = $prefix . "coupons";
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

        update_option(self::SCHOOL_VERSION, '4.1.0');
    }

    if (version_compare($curr, '4.1.1', '<')) {
        $tableName = $prefix . "coupons";
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

        update_option(self::SCHOOL_VERSION, '4.1.1');
    }

    if (version_compare($curr, '5.0.7', '<')) {
        update_option(self::SCHOOL_VERSION, '5.0.7');
    }

    if (version_compare($curr, '5.2.0', '<')) {
        $tableName = $prefix . "class_to_students";
        $sql = "ALTER TABLE `$tableName` ADD `progress` nvarchar(1000) NOT NULL";
        $wpdb->query($sql);

        update_option(self::SCHOOL_VERSION, '5.2.0');
    }

    if (version_compare($curr, '5.2.1', '<')) {
        $tableName = $prefix . "class_to_students";
        $sql = "ALTER TABLE `$tableName` DROP `progress`";
        $wpdb->query($sql);

        update_option(self::SCHOOL_VERSION, '5.2.1');
    }

    if (version_compare($curr, '5.2.3', '<')) {
        update_option(self::SCHOOL_VERSION, '5.2.3');
    }

    if (version_compare($curr, '5.2.4', '<')) {
        $tableName = $prefix . "student_notes";
        $sql = "CREATE TABLE $tableName (
          student_id mediumint(9) NOT NULL,
          lesson_id mediumint(9) NOT NULL,
          notes nvarchar(4000) NULL,
          PRIMARY KEY  (student_id, lesson_id)) $charsetCollate;";
        $wpdb->query($sql);

        update_option(self::SCHOOL_VERSION, '5.2.4');
    }

    if (version_compare($curr, '5.5.3', '<')) {
        update_option(self::SCHOOL_VERSION, '5.5.3');
    }

    if (version_compare($curr, '5.6.0', '<')) {
      $sql = "RENAME TABLE wp_coupons TO {$prefix}coupons";
      $wpdb->query($sql);
      $sql = "RENAME TABLE wp_course_to_students TO {$prefix}class_to_students";
      $wpdb->query($sql);
      $sql = "RENAME TABLE wp_payments TO {$prefix}payments";
      $wpdb->query($sql);
      $sql = "RENAME TABLE wp_student_notes TO {$prefix}student_notes";
      $wpdb->query($sql);

      update_option(self::SCHOOL_VERSION, '5.6.0');
    }

    //KEEP THIS AT THE END
    $lastVersion = FutureLMS::version();
    if (version_compare($curr, $lastVersion, '<')) {
        update_option(self::SCHOOL_VERSION, $lastVersion);
    }
  }
}
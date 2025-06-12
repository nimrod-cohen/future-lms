<?php 
namespace FutureLMS\classes;
use FutureLMS\FutureLMS;

class VersionManager {
      const SCHOOL_VERSION = 'valschool_version';

    public static function installVersion()
    {
        global $wpdb;

        $curr = get_option(self::SCHOOL_VERSION, 0);

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

            update_option(self::SCHOOL_VERSION, 1);
        }

        if ($curr < 2) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` CHANGE `course_instance_id` `class_id` MediumInt( 9 ) NOT NULL";
            $wpdb->query($sql);

            update_option(self::SCHOOL_VERSION, 2);
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

            update_option(self::SCHOOL_VERSION, 3);
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

            update_option(self::SCHOOL_VERSION, '4.0.1');
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

            update_option(self::SCHOOL_VERSION, '4.1.0');
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

            update_option(self::SCHOOL_VERSION, '4.1.1');
        }

        if (version_compare($curr, '5.0.7', '<')) {
            update_option(self::SCHOOL_VERSION, '5.0.7');
        }

        if (version_compare($curr, '5.2.0', '<')) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` ADD `progress` nvarchar(1000) NOT NULL";
            $wpdb->query($sql);

            update_option(self::SCHOOL_VERSION, '5.2.0');
        }

        if (version_compare($curr, '5.2.1', '<')) {
            $tableName = $wpdb->prefix . "course_to_students";
            $sql = "ALTER TABLE `$tableName` DROP `progress`";
            $wpdb->query($sql);

            update_option(self::SCHOOL_VERSION, '5.2.1');
        }

        if (version_compare($curr, '5.2.3', '<')) {
            update_option(self::SCHOOL_VERSION, '5.2.3');
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

            update_option(self::SCHOOL_VERSION, '5.2.4');
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

            update_option(self::SCHOOL_VERSION, '5.2.7');
        }

        if (version_compare($curr, '5.2.8', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `deal_id` Int NOT NULL;";
            $wpdb->query($sql);
            update_option(self::SCHOOL_VERSION, '5.2.8');
        }

        if (version_compare($curr, '5.2.9', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `user_id` Int NULL;";
            $wpdb->query($sql);
            update_option(self::SCHOOL_VERSION, '5.2.9');
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
            update_option(self::SCHOOL_VERSION, '5.2.10');
        }

        if (version_compare($curr, '5.2.11', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `email` nvarchar(255) NULL;";
            $wpdb->query($sql);
            update_option(self::SCHOOL_VERSION, '5.2.11');
        }

        if (version_compare($curr, '5.3.0', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` ADD COLUMN `tpid` nvarchar(255) NULL;";
            $wpdb->query($sql);
            $sql = "ALTER TABLE `wp_vi_partner_coupons` MODIFY `phone` VarChar(255) NULL;";
            $wpdb->query($sql);

            update_option(self::SCHOOL_VERSION, '5.3.0');
        }

        if (version_compare($curr, '5.3.1', '<')) {
            $sql = "ALTER TABLE `wp_vi_partner_coupons` MODIFY `name` VarChar( 255 ) NULL;";
            $wpdb->query($sql);
            update_option(self::SCHOOL_VERSION, '5.3.1');
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

            update_option(self::SCHOOL_VERSION, '5.5.3');
        }



        //KEEP THIS AT THE END
        $lastVersion = FutureLMS::version();
        if (version_compare($curr, $lastVersion, '<')) {
            update_option(self::SCHOOL_VERSION, $lastVersion);
        }
    }
}
<?php
use FutureLMS\classes\VersionManager;
?>

<header class="flms-appbar">
    <div class="flms-brand">
        <span class="flms-name">Future LMS</span>
        <span class="version"><?php echo esc_html(get_option(VersionManager::SCHOOL_VERSION, 0)); ?></span>
        <span class="flms-site"><?php echo esc_html(get_bloginfo('name')); ?></span>
    </div>
    <a class="ui small basic button flms-back" href="<?php echo esc_url(admin_url()); ?>">
        <span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>Back to WordPress
    </a>
</header>
<div class="ui top attached tabular menu">
    <a class="item active" data-tab="students">Students</a>
    <a class="item" data-tab="courses">
        <span>Courses</span>
        <span class="action-bar">
      <span class="tooltip" data-content="Add course" data-variation="mini" data-inverted="">
        <i class='plus icon green square outline' data-action='add-course'></i>
      </span>
    </span>
    </a>
    <a class="item" data-tab="classes">
        <span>Classes</span>
        <span class="action-bar">
            <span class="tooltip" data-content="Add class" data-variation="mini" data-inverted="">
                <i class='plus icon green square outline' data-action='add-class'></i>
            </span>
        </span>
    </a>
    <a class="item" data-tab="mailer">Email</a>
    <a class="item" data-tab="settings">Settings</a>
</div>
<div class="ui bottom attached tab segment active" data-tab="students">
    <div class='tab-header'>
        <h4 class="ui header">Student list</h4>
    </div>
    <?php include_once 'students.php'?>
</div>
<div class="ui bottom attached tab segment" data-tab="courses">
    <?php include_once 'courses.php'?>
</div>
<div class="ui bottom attached tab segment" data-tab="classes">
    <div class='tab-header'>
        <h4 class="ui header">Classes</h4>
        <p class="description">Choose whether a class is self-paced or opens its lessons on set dates</p>
    </div>
    <?php include_once 'classes.php'?>
</div>
<div class="ui bottom attached tab segment" data-tab="mailer">
    <div class='tab-header'>
        <h4 class="ui header">Email sender</h4>
        <p class="description">Send email to class students</p>
    </div>
    <?php include_once 'mailer.php'?>
</div>
<div class="ui bottom attached tab segment" data-tab="settings">
    <div class='tab-header'>
        <h4 class="ui header">Settings</h4>
        <?php include_once 'settings.php'?>
    </div>
</div>

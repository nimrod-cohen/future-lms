<?php
use FutureLMS\classes\VersionManager;
?>

<div class="ui top attached tabular menu">
    <a class="item active" data-tab="students">Students</a>
    <a class="item" data-tab="courses">
        <span>Courses</span>
        <span class="action-bar">
      <span data-tooltip="Add course" data-variation="mini" data-inverted="">
        <i class='plus icon green square outline' data-action='add-course'></i>
      </span>
    </span>
    </a>
    <a class="item" data-tab="classes">
        <span>Classes</span>
        <span class="action-bar">
            <span data-tooltip="Add class" data-variation="mini" data-inverted="">
                <i class='plus icon green square outline' data-action='add-class'></i>
            </span>
        </span>
    </a>
    <a class="item" data-tab="mailer">Email</a>
    <a class="item" data-tab="settings">Settings</a>
    <div>Future LMS
        <span class="version"><?php echo get_option(VersionManager::SCHOOL_VERSION, 0); ?></span>
    </div>
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
        <h4 class="ui header">Classes management</h4>
        <p class="description">Open classes by course progress</p>
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

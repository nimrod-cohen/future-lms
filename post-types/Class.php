<?php

function register_class_post_type() {
    $args = array(
        'label' => 'All Classes',
        'public' => true,
        'show_in_menu' => 'edit.php?post_type=course', // Nest under Courses
        'show_in_rest' => false, // Gutenberg disable
        'supports' => array('title', 'editor'),
        'menu_position' => 20,
        'menu_icon' => 'dashicons-groups',
        'show_ui' => true
    );
    register_post_type('class', $args);
}
add_action('init', 'register_class_post_type');

// Register meta box
add_action('add_meta_boxes', function() {
    add_meta_box(
        'class_details',
        'Class Details',
        'render_class_meta_box',
        'class'
    );
});

// Render meta box
function render_class_meta_box($post) {
    wp_nonce_field('class_meta_nonce', '_class_nonce');
    $meta = get_post_meta($post->ID);
    ?>

    <div style="display: flex; flex-direction: column;">
        <!-- Course Relationship -->
        <p>
            <label><strong>Course:</strong><br>
              <?php
              $courses = get_posts(array(
                'post_type' => 'course',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
              ));
              $current_course = $meta['course'][0] ?? '';
              ?>
                <select name="course" style="width:100%">
                    <option value="">Select Course</option>
                  <?php foreach ($courses as $course) : ?>
                      <option value="<?= $course->ID ?>" <?= selected($current_course, $course->ID) ?>>
                        <?= esc_html($course->post_title) ?>
                      </option>
                  <?php endforeach; ?>
                </select>
            </label>
        </p>

        <!-- Start Date -->
        <p>
            <label><strong>Start Date:</strong><br>
                <input type="datetime-local"
                       name="start_date"
                       value="<?= esc_attr($meta['start_date'][0] ?? '') ?>">
            </label>
        </p>
        <!-- Pacing -->
        <p>
            <label><strong>Pacing:</strong><br>
                <?php $paced = ($meta['paced'][0] ?? '') == '1'; ?>
                <select name="paced" style="width:100%">
                    <option value="0" <?= selected(!$paced) ?>>Self-paced - every lesson open</option>
                    <option value="1" <?= selected($paced) ?>>Paced - lessons open on set dates</option>
                </select>
            </label>
            <span class="description">Lesson dates are set in Future LMS → Classes.</span>
        </p>
    </div>
    <?php
}

// Save meta data
add_action('save_post_class', function($post_id) {
    if (!isset($_POST['_class_nonce']) || !wp_verify_nonce($_POST['_class_nonce'], 'class_meta_nonce')) return;

    // Save course relationship
    if (isset($_POST['course'])) {
        update_post_meta($post_id, 'course', sanitize_text_field($_POST['course']));
    }

    // Save start date
    if (isset($_POST['start_date'])) {
        update_post_meta($post_id, 'start_date', sanitize_text_field($_POST['start_date']));
    }

    // Save pacing (lesson dates are set in Future LMS → Classes)
    if (isset($_POST['paced'])) {
        update_post_meta($post_id, 'paced', $_POST['paced'] == '1' ? '1' : '0');
    }
});
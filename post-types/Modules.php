<?php

function register_module_post_type() {
    $args = array(
        'label' => 'All Modules',
        'public' => true,
        'show_in_menu' => 'edit.php?post_type=course', // Nest under Courses
        'show_in_rest' => false, // Disable Gutenberg
        'supports' => array('title', 'editor'),
        'menu_position' => 15, // Below Classes
        'menu_icon' => 'dashicons-category',
        'show_ui' => true
    );
    register_post_type('module', $args);
}
add_action('init', 'register_module_post_type');

// Register meta box
add_action('add_meta_boxes', function() {
    add_meta_box(
        'module_details',
        'Module Details',
        'render_module_meta_box',
        'module'
    );
});

// Render meta box
function render_module_meta_box($post) {
    wp_nonce_field('module_meta_nonce', '_module_nonce');
    $meta = get_post_meta($post->ID);
    ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Left Column -->
        <div>
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
        </div>

        <!-- Right Column -->
        <div>
            <!-- Teaser name -->
            <p>
                <label><strong>Teaser name:</strong><br>
                    <input type="text" name="teaser" value="<?= esc_attr($meta['teaser'][0] ?? '') ?>">
                    <small class="description">If you wish to display a less revealing name.</small>
                </label>
            </p>

            <!-- Order -->
            <p>
                <label><strong>Order:</strong><br>
                    <input type="number" name="order" value="<?= esc_attr($meta['order'][0] ?? '') ?>">
                </label>
            </p>

            <!-- Count Progress -->
            <p>
                <label>
                    <input type="checkbox" name="count_progress" value="1" <?= checked($meta['count_progress'][0] ?? 0, 1) ?>>
                    <strong>Count progress</strong>
                </label>
            </p>
        </div>
    </div>
    <?php
}

// Save meta data
add_action('save_post_module', function($post_id) {
    if (!isset($_POST['_module_nonce']) || !wp_verify_nonce($_POST['_module_nonce'], 'module_meta_nonce')) return;

    // Save course relationship
    if (isset($_POST['course'])) {
        update_post_meta($post_id, 'course', sanitize_text_field($_POST['course']));
    }

    // Save order
    if (isset($_POST['order'])) {
        update_post_meta($post_id, 'order', intval($_POST['order']));
    }
    // Save teaser name
    if (isset($_POST['teaser'])) {
        update_post_meta($post_id, 'teaser', sanitize_text_field($_POST['teaser']));
    }

    // Save checkbox
    update_post_meta($post_id, 'count_progress', isset($_POST['count_progress']) ? 1 : 0);
});


// Improved column display
add_action('manage_module_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'order':
            $order = get_post_meta($post_id, 'order', true);
            echo $order !== '' ? (int)$order : '—'; // Explicitly handle empty values
            break;
    }
}, 10, 2);
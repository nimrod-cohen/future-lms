<?php

function register_lesson_post_type() {
    $args = array(
        'label' => 'All Lessons',
        'public' => true,
        'show_in_menu' => 'edit.php?post_type=course', // Nest under Courses
        'show_in_rest' => false, // Gutenberg disable
        'supports' => array('title', 'editor', 'thumbnail'),
        'menu_position' => 25, // Below Classes
        'menu_icon' => 'dashicons-video-alt3',
        'has_archive' => true,
        'show_ui' => true
    );
    register_post_type('lesson', $args);
}
add_action('init', 'register_lesson_post_type');

// Register meta box
add_action('add_meta_boxes', function() {
    add_meta_box(
        'lesson_details',
        'Lesson Details',
        'render_lesson_meta_box',
        'lesson'
    );
});

// Render meta box
function render_lesson_meta_box($post) {
    wp_nonce_field('lesson_meta_nonce', '_lesson_nonce');
    $meta = get_post_meta($post->ID);
    ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <!-- Left Column -->
        <div>
            <!-- Module Relationship -->
            <p>
                <label><strong>Module:</strong><br>
                    <?php
                    $modules = get_posts(array(
                        'post_type' => 'module',
                        'posts_per_page' => -1
                    ));
                    $current_module = $meta['module'][0] ?? '';
                    ?>
                    <select name="module" style="width:100%">
                        <option value="">Select Module</option>
                        <?php foreach ($modules as $module) : ?>
                            <option value="<?= $module->ID ?>" <?= selected($current_module, $module->ID) ?>>
                                <?= esc_html($module->post_title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>

            <!-- Lesson Number -->
            <p>
                <label><strong>Lesson Number:</strong><br>
                    <input type="number" name="lesson_number" value="<?= esc_attr($meta['lesson_number'][0] ?? '') ?>">
                </label>
            </p>

            <!-- Presentation -->
            <p>
                <label><strong>Presentation:</strong><br>
                    <?php
                    $presentation_id = $meta['presentation'][0] ?? '';
                    echo wp_get_attachment_image($presentation_id, 'thumbnail', false, array('style' => 'max-height:100px;'));
                    ?>
                    <input type="hidden" name="presentation" id="presentation" value="<?= esc_attr($presentation_id) ?>">
                    <button type="button" class="button" id="upload_presentation">Select File</button>
                </label>
            </p>
            <!-- Teaser -->
            <p>
                <label><strong>Teaser:</strong><br>
                    <input type="text" name="teaser" value="<?= esc_attr($meta['teaser'][0] ?? '') ?>">
                    <small class="description">If you wish to display a less revealing name.</small>
                </label>
            </p>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Video List -->
            <p>
                <label><strong>Video List (one per line):</strong><br>
                    <textarea name="video_list" style="width:100%; height:120px;"><?= esc_textarea($meta['video_list'][0] ?? '') ?></textarea>
                </label>
            </p>
        </div>
    </div>

    <!-- Homework (WYSIWYG) -->
    <div style="margin-bottom: 20px;">
        <label><strong>Homework:</strong></label>
        <?php
        wp_editor(
            $meta['homework'][0] ?? '',
            'homework',
            array(
                'textarea_name' => 'homework',
                'media_buttons' => true,
                'teeny' => true
            )
        );
        ?>
    </div>

    <!-- Additional Files (WYSIWYG) -->
    <div>
        <label><strong>Additional Files:</strong></label>
        <?php
        wp_editor(
            $meta['additional_files'][0] ?? '',
            'additional_files',
            array(
                'textarea_name' => 'additional_files',
                'media_buttons' => true,
                'teeny' => true
            )
        );
        ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Media uploader for presentation
            $('#upload_presentation').click(function() {
                var frame = wp.media({
                    title: 'Select Presentation',
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#presentation').val(attachment.id);
                    $(this).parent().find('img').remove();
                    $(this).before('<img src="'+attachment.url+'" style="max-height:100px;">');
                });
                frame.open();
            });
        });
    </script>
    <?php
}

// Save meta data
add_action('save_post_lesson', function($post_id) {
    if (!isset($_POST['_lesson_nonce']) || !wp_verify_nonce($_POST['_lesson_nonce'], 'lesson_meta_nonce')) return;

    $fields = [
        'module',
        'teaser',
        'lesson_number',
        'presentation',
        'video_list',
        'homework',
        'additional_files'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
          if(in_array($field, ['homework', 'additional_files'])) {
              // For WYSIWYG fields, sanitize with wp_kses_post
              update_post_meta($post_id, $field, wp_kses_post($_POST[$field]));
          } else {
            update_post_meta($post_id, $field, $_POST[$field]);
          }
        }
    }
});
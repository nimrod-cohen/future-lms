<?php

function register_course_post_type()
{
    $args = array(
        'label' => 'All Courses',
        'public' => true,
        'show_in_rest' => false, // Gutenberg disable
        'supports' => ['title', 'editor', 'thumbnail', 'author'],
        'menu_icon' => 'dashicons-book-alt',
        'has_archive' => true,
        'show_ui' => true
    );
    register_post_type('course', $args);
}

add_action('init', 'register_course_post_type');

// Register meta box
add_action('add_meta_boxes', function () {
  add_meta_box(
    'course_details',
    'Course Details',
    'render_course_meta_box',
    'course',
    'normal',
    'high'
  );
});

// Render meta box
function render_course_meta_box($post)
{
    wp_nonce_field('course_meta_nonce', '_course_nonce');
    $meta = get_post_meta($post->ID);
    ?>
    <style>
        .future-lms-meta-box {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 16px 24px;
            align-items: center;
            max-width: 700px;
        }

        .future-lms-meta-box p {
            display: contents;
        }

        .future-lms-meta-box-label,
        .future-lms-meta-box label > strong {
            font-weight: bold;
            display: block;
        }

        .future-lms-meta-box input[type="text"],
        .future-lms-meta-box input[type="url"],
        .future-lms-meta-box input[type="color"],
        .future-lms-meta-box textarea {
            width: 100%;
            max-width: 350px;
        }
        .future-lms-meta-box #remove_icon_button {
          display: none;
          background:#dc2626;
          color:#fff;
          border:none;
          padding:4px 10px;
          margin-left:8px;
          font-size:18px;
          line-height:1;
          border-radius:4px;
          cursor:pointer;
          &.visible {
            display: inline-block;
          }
        }
        .future-lms-meta-box .signature-preview img {
          max-width: 150px;
          max-height: 80px;
          display: block;
          margin-bottom: 6px;
        }
        .future-lms-meta-box .remove-signature {
          background:#dc2626;
          color:#fff;
          border:none;
          padding:4px 10px;
          margin-left:8px;
          font-size:12px;
          border-radius:4px;
          cursor:pointer;
        }
    </style>
    <div class="future-lms-meta-box">
        <!-- Third Party Reference -->
        <p>
            <label><strong>Course Code:</strong></label>
            <input type="text" name="course_code"
                   value="<?= esc_attr($meta['course_code'][0] ?? '') ?>">
        </p>
        <!-- Course Page URL -->
        <p>
            <label><strong>Course Page URL:</strong></label>
            <input type="url" name="course_page_url" value="<?= esc_url($meta['course_page_url'][0] ?? '') ?>">
        </p>
        <!-- Short Name -->
        <p>
            <label><strong>Short Name:</strong></label>
            <input type="text" name="short_name" value="<?= esc_attr($meta['short_name'][0] ?? '') ?>">
        </p>
        <!-- Course Duration -->
        <p>
            <label><strong>Course Duration (hours):</strong></label>
            <input type="text" name="course_duration" value="<?= esc_attr($meta['course_duration'][0] ?? '') ?>">
        </p>
        <!-- Short Description -->
        <p>
            <label><strong>Short Description:</strong></label>
            <textarea name="short_description" style="height:100px;"><?= esc_textarea($meta['short_description'][0] ?? '') ?></textarea>
        </p>
        <!-- What You'll Learn -->
        <p>
            <label for="what_you_learn"><strong>What You will Learn:</strong></label>
            <div style="display: flex; flex-direction: column;">
              <textarea id="what_you_learn" name="what_you_learn" style="height:100px;"><?= esc_textarea($meta['what_you_learn'][0] ?? '') ?></textarea>
              <p class="description">Please enter each point on a new line.</p>
            </div>
        </p>
        <!-- Tags -->
        <p>
            <label><strong>Tags (comma separated):</strong></label>
            <input type="text" name="tags" value="<?= esc_attr($meta['tags'][0] ?? '') ?>">
        </p>
        <!-- Color -->
        <p>
            <span class="future-lms-meta-box-label"><strong>Color:</strong></span>
            <span style="display: flex; align-items: center; gap: 16px;">
                <input type="color" name="color" value="<?= esc_attr($meta['color'][0] ?? '#aabbcc') ?>">
            </span>
        </p>
        <!-- Initial Student Count -->
        <p>
            <label><strong>Initial Student Count:</strong></label>
            <input type="number" name="initial_student_count" min="0" value="<?= esc_attr($meta['initial_student_count'][0] ?? '0') ?>">
        </p>
        <!-- Enable Diploma -->
        <p>
            <label><strong><?php _e('Enable Diploma', 'future-lms'); ?>:</strong></label>
            <input type="checkbox" name="diploma_enabled" value="1" <?php checked($meta['diploma_enabled'][0] ?? '0', '1'); ?>>
        </p>
        <!-- Lecturer Name -->
        <p>
            <label><strong><?php _e('Lecturer Name', 'future-lms'); ?>:</strong></label>
            <input type="text" name="lecturer_name" value="<?= esc_attr($meta['lecturer_name'][0] ?? '') ?>">
        </p>
        <!-- Lecturer Signature -->
        <p>
            <span class="future-lms-meta-box-label"><strong><?php _e('Lecturer Signature', 'future-lms'); ?>:</strong></span>
            <span>
                <input type="hidden" name="lecturer_signature" id="lecturer_signature" value="<?= esc_attr($meta['lecturer_signature'][0] ?? '') ?>">
                <div class="signature-preview" id="signature_preview">
                    <?php
                    $sigId = $meta['lecturer_signature'][0] ?? '';
                    if ($sigId) {
                        $sigUrl = wp_get_attachment_image_url($sigId, 'medium');
                        if ($sigUrl) echo '<img src="' . esc_url($sigUrl) . '" />';
                    }
                    ?>
                </div>
                <button type="button" class="button" id="select_signature_button"><?php _e('Select Image', 'future-lms'); ?></button>
                <button type="button" class="remove-signature" id="remove_signature_button" style="display:<?= !empty($sigId) ? 'inline-block' : 'none' ?>;"><?php _e('Remove', 'future-lms'); ?></button>
            </span>
        </p>
    </div>
    <script>
    jQuery(function($){
        var frame;
        $('#select_signature_button').on('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: '<?php _e('Select Image', 'future-lms'); ?>', multiple: false, library: { type: 'image' } });
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                $('#lecturer_signature').val(attachment.id);
                $('#signature_preview').html('<img src="' + attachment.url + '" />');
                $('#remove_signature_button').show();
            });
            frame.open();
        });
        $('#remove_signature_button').on('click', function(){
            $('#lecturer_signature').val('');
            $('#signature_preview').html('');
            $(this).hide();
        });
    });
    </script>
    <?php
}

// Save meta data
add_action('save_post_course', function ($post_id, $post) {
  if (isset($_POST['_course_nonce']) && wp_verify_nonce($_POST['_course_nonce'], 'course_meta_nonce')) {
    $fields = [
        'currency',
        'course_code',
        'course_page_url',
        'short_name',
        'course_duration',
        'short_description',
        'what_you_learn',
        'tags',
        'color',
        'initial_student_count',
        'lecturer_name',
        'lecturer_signature'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($post_id, $field, sanitize_textarea_field($_POST[$field]));
        }
    }

    // Checkbox: save '1' if checked, '0' if unchecked
    update_post_meta($post_id, 'diploma_enabled', isset($_POST['diploma_enabled']) ? '1' : '0');
  }
}, 10, 2);
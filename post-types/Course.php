<?php

function register_course_post_type()
{
    $args = array(
        'label' => 'All Courses',
        'public' => true,
        'show_in_rest' => false, // Gutenberg disable
        'supports' => array('title', 'editor', 'thumbnail'),
        'menu_icon' => 'dashicons-book-alt',
        'has_archive' => true,
        'show_ui' => false
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

    <div class="future-lms-meta-box">
        <!-- Full Price -->
        <p>
            <label class="future-lms-meta-box-label"><strong>Full Price:</strong>
                <input type="text" name="full_price" value="<?= esc_attr($meta['full_price'][0] ?? '') ?>">
            </label>
        </p>

        <!-- Third Party Reference -->
        <p>
            <label><strong>Third Party Reference:</strong>
                <input type="text" name="third_party_reference"
                       value="<?= esc_attr($meta['third_party_reference'][0] ?? '') ?>">
            </label>
        </p>

        <!-- Course Page URL -->
        <p>
            <label><strong>Course Page URL:</strong>
                <input type="url" name="course_page_url" value="<?= esc_url($meta['course_page_url'][0] ?? '') ?>">
            </label>
        </p>

        <!-- Short Name -->
        <p>
            <label><strong>Short Name:</strong>
                <input type="text" name="short_name" value="<?= esc_attr($meta['short_name'][0] ?? '') ?>">
            </label>
        </p>

        <!-- Show in Lead Form -->
        <p>
            <label>
                <strong>Show in lead form</strong>
                <input type="checkbox" name="show_in_lead_form"
                       value="1" <?= checked($meta['show_in_lead_form'][0] ?? 0, 1) ?>
            </label>
        </p>

        <!-- Charge URL -->
        <p>
            <label><strong>Charge URL:</strong>
                <input type="url" name="charge_url" value="<?= esc_url($meta['charge_url'][0] ?? '') ?>">
            </label>
        </p>

        <!-- Shop Description -->
        <p>
            <label><strong>Shop Description:</strong>
                <textarea name="shop_description"
                          style="width:100%;height:100px;"><?= esc_textarea($meta['shop_description'][0] ?? '') ?></textarea>
            </label>
        </p>

        <!-- Tags -->
        <p>
            <label><strong>Tags (comma separated):</strong>
                <input type="text" name="tags" value="<?= esc_attr($meta['tags'][0] ?? '') ?>" style="width:100%">
            </label>
        </p>

        <!-- Course Icon -->
        <p>
            <label><strong>Course Icon:</strong><br>
                <?php
                $icon_id = $meta['course_icon'][0] ?? '';
                echo $icon_id ? wp_get_attachment_image($icon_id, 'thumbnail', false, array('style' => 'max-height:100px;')) : '';
                ?>
                <input type="hidden" name="course_icon" id="course_icon" value="<?= esc_attr($icon_id) ?>">
                <button type="button" class="button" id="upload_icon_button">Select/Upload Icon</button>
        </p>
    </div>
    <script>
        jQuery(document).ready(function ($) {
            $('#upload_icon_button').click(function () {
                var frame = wp.media({
                    title: 'Select Course Icon',
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#course_icon').val(attachment.id);
                    $(this).parent().find('img').remove();
                    $(this).before('<img src="' + attachment.url + '" style="max-height:100px;">');
                });
                frame.open();
            });
        });
    </script>
    <?php
}

// Save meta data
add_action('save_post_course', function ($post_id) {
    if (!isset($_POST['_course_nonce']) || !wp_verify_nonce($_POST['_course_nonce'], 'course_meta_nonce')) return;

    $fields = [
        'full_price',
        'currency',
        'third_party_reference',
        'course_page_url',
        'short_name',
        'charge_url',
        'shop_description',
        'tags',
        'course_icon'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }

    // Checkbox needs special handling
    update_post_meta($post_id, 'show_in_lead_form', isset($_POST['show_in_lead_form']) ? 1 : 0);
});
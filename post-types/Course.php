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
    </style>
    <div class="future-lms-meta-box">
        <!-- Full Price -->
        <p>
            <label class="future-lms-meta-box-label"><strong>Full Price:</strong></label>
            <input type="text" name="full_price" value="<?= esc_attr($meta['full_price'][0] ?? '') ?>">
        </p>
        <!-- Discount Price -->
        <p>
            <label class="future-lms-meta-box-label"><strong>Discount Price:</strong></label>
            <input type="text" name="discount_price" value="<?= esc_attr($meta['discount_price'][0] ?? '') ?>">
        </p>
        <!-- Third Party Reference -->
        <p>
            <label><strong>Third Party Reference:</strong></label>
            <input type="text" name="third_party_reference"
                   value="<?= esc_attr($meta['third_party_reference'][0] ?? '') ?>">
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
        <!-- Charge URL -->
        <p>
            <label><strong>Charge URL:</strong></label>
            <input type="url" name="charge_url" value="<?= esc_url($meta['charge_url'][0] ?? '') ?>">
        </p>
        <!-- Short Description -->
        <p>
            <label><strong>Short Description:</strong></label>
            <textarea name="short_description" style="height:100px;"><?= esc_textarea($meta['short_description'][0] ?? '') ?></textarea>
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
    </div>
    <?php
}

// Save meta data
add_action('save_post_course', function ($post_id, $post) {
  if (isset($_POST['_course_nonce']) && wp_verify_nonce($_POST['_course_nonce'], 'course_meta_nonce')) {
    $fields = [
        'full_price',
        'discount_price',
        'currency',
        'third_party_reference',
        'course_page_url',
        'short_name',
        'charge_url',
        'short_description',
        'tags',
        'color'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }
  }
  do_action('future-lms/course_saved', $post_id, $post);
}, 10, 2);
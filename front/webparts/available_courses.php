<h2 class="courses-header"><?php

use FutureLMS\FutureLMS;

 _e("Course store","future-lms"); ?></h2>
<div class="available-courses course-list">
<?php

$sorted_courses = ["regular" => [], "featured" => []];
foreach ($available_courses as $course) {
  $featured = $course->has_tag('featured');
  $sorted_courses[$featured ? 'featured' : 'regular'][] = $course;
}
$sorted_courses = array_merge($sorted_courses['featured'], $sorted_courses['regular']);

foreach ($sorted_courses as $course) {
  //get post thumbnail
  $courseImage = $course->get_featured_image('full');
  $courseUrl = $course->raw("course_page_url");

  if (!$courseUrl) {
    $currentUrl = wp_unslash(esc_url_raw(add_query_arg(null, null)));
    $courseUrl = add_query_arg('pg', 'course-details', $currentUrl);
  }

  $author = $course->display('author');

  $tags = $course->raw("tags");
  //strip html tags
  $tags = strip_tags($tags);
  //strip spaces and split by comma
  $tags = explode(",", $tags);
  $tags = array_map('trim', $tags); //trim each tag
  $featured = in_array('featured', $tags);

  ?>
  <div class="course-card <?php echo $featured ? 'featured' : ''; ?>" data-course-id="<?php echo $course->ID; ?>" data-featured='<?php echo $featured ? 'featured' : ''; ?>'>
    <img class='course-image' src='<?php echo $courseImage; ?>'></img>
    <div class="course-details">
      <span class='course-name'><?php echo $course->post_title; ?></span>
      <span class='course-author'><?php echo $author; ?></span>
      <?php include "short_description.php"; ?>
      <?php 
      FutureLMS::get_template_part('price_box.php', ['course' => $course]);
      ?>
      <form method="POST" action="<?php echo $courseUrl; ?>">
        <input type="hidden" name="course_id" value="<?php echo $course->ID; ?>">
        <button type="submit" class='add-to-cart' href="<?php echo $courseUrl; ?>">
          <span><?php echo __("Course details","future-lms"); ?></span>
          <img src='data:image/svg+xml,<svg fill="%23FFFFFF" version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 455.297 455.297" xml:space="preserve"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"><g><circle cx="65.993" cy="417.586" r="35"></circle><path d="M30.993,322.586v30h182.879c-5.914-9.267-10.676-19.335-14.094-30H30.993z"></path><path d="M323.059,183.727c-54.826,0-99.431,44.604-99.431,99.429s44.604,99.429,99.431,99.429 c54.825,0,99.429-44.604,99.429-99.429S377.884,183.727,323.059,183.727z M384.559,298.157h-46.5v46.5h-30v-46.5h-46.5v-30h46.5 v-46.5h30v46.5h46.5V298.157z"></path><path d="M393.673,2.711l-12.294,75H0l25.888,158.454c2.833,17.282,19.479,31.422,36.992,31.422h131.688 c7.715-64.052,62.392-113.859,128.49-113.859c26.887,0,51.884,8.244,72.6,22.333l23.496-143.349h36.142v-30H393.673z"></path><path d="M323.059,412.586c-12.147,0-23.907-1.686-35.062-4.829c-0.912,3.118-1.404,6.416-1.404,9.829c0,19.33,15.67,35,35,35 c19.33,0,35-15.67,35-35c0-3.145-0.421-6.19-1.2-9.089C345.054,411.166,334.219,412.586,323.059,412.586z"></path></g></g></svg>'></img>
        </button>
      </form>
    </div>
  </div>
<?php
}?>
</div>
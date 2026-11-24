<?php

use FutureLMS\classes\EmbeddedImages;
use FutureLMS\Tests\AjaxTestCase;

/**
 * A picture pasted into the lesson editor arrives as a data: URI. Sanitizing
 * strips the "data:" and leaves an address that breaks the classroom, so the
 * save turns each one into a media-library file first.
 *
 * @group ajax
 */
class EmbeddedImagesTest extends AjaxTestCase {
  // A 1x1 PNG.
  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

  public function tear_down() {
    // The files land in the real uploads folder; don't leave them behind.
    $this->remove_added_uploads();
    parent::tear_down();
  }

  private function pasted($b64 = self::PNG, $type = 'image/png') {
    return '<p>before <img src="data:' . $type . ';base64,' . $b64 . '" alt="shot"> after</p>';
  }

  public function test_a_pasted_image_becomes_a_media_library_file() {
    $lesson = self::factory()->post->create(['post_type' => 'lesson']);

    $html = EmbeddedImages::store($this->pasted(), $lesson);

    $this->assertStringNotContainsString('base64', $html);
    $this->assertMatchesRegularExpression('~<p>before <img src="http[^"]+/pasted-[0-9a-f]{12}(-\d+)?\.png" alt="shot"> after</p>~', $html);

    $attachments = get_posts(['post_type' => 'attachment', 'post_parent' => $lesson, 'post_status' => 'inherit']);
    $this->assertCount(1, $attachments);
    $this->assertSame(base64_decode(self::PNG), file_get_contents(get_attached_file($attachments[0]->ID)));
  }

  public function test_the_stored_address_survives_sanitizing() {
    $html = wp_kses_post(EmbeddedImages::store($this->pasted()));

    $this->assertMatchesRegularExpression('~src="http[^"]+\.png"~', $html);
  }

  public function test_content_without_pasted_images_is_untouched() {
    $html = '<p>text <img src="https://example.com/a.png" alt=""> <a href="https://example.com">data:image/ in prose</a></p>';

    $this->assertSame($html, EmbeddedImages::store($html));
  }

  public function test_something_that_is_not_an_image_is_dropped_not_stored() {
    $before = wp_count_posts('attachment')->inherit;

    $html = EmbeddedImages::store($this->pasted(base64_encode('<?php echo 1;')));

    $this->assertStringContainsString('<img src="" alt="shot">', $html);
    $this->assertSame($before, wp_count_posts('attachment')->inherit);
  }

  public function test_an_svg_is_dropped() {
    $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->assertStringContainsString('<img src="" alt="shot">', EmbeddedImages::store($this->pasted($svg, 'image/svg+xml')));
  }

  public function test_saving_a_lesson_keeps_its_pasted_image_working() {
    $this->as_admin();
    [, , $lessons] = $this->make_course_with_lessons(1);

    $this->ajax('edit_lesson', ['lesson_id' => $lessons[0], 'name' => 'Lesson', 'content' => wp_slash($this->pasted()), 'homework' => wp_slash($this->pasted())]);

    foreach ([get_post($lessons[0])->post_content, get_post_meta($lessons[0], 'homework', true)] as $saved) {
      $this->assertStringNotContainsString('base64', $saved);
      $this->assertMatchesRegularExpression('~<img src="http[^"]+/pasted-[0-9a-f]{12}(-\d+)?\.png" alt="shot">~', $saved);
    }
  }
}

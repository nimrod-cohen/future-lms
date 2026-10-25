<?php

use FutureLMS\FutureLMS;
use FutureLMS\Tests\AjaxTestCase;

/**
 * The search box in the Courses tab: courses, modules and lessons by title or
 * id, and lessons by the Vimeo video they play (id or link).
 *
 * @group ajax
 */
class CatalogSearchTest extends AjaxTestCase {

  private $course, $module, $lessonA, $lessonB, $other;

  public function set_up() {
    parent::set_up();
    $this->as_admin();

    $this->course = $this->make_course();
    wp_update_post(['ID' => $this->course, 'post_title' => 'אסטרטגיות השקעה 2026']);
    $this->module = $this->make_module($this->course, 2);
    wp_update_post(['ID' => $this->module, 'post_title' => 'Value basics']);
    $this->lessonA = $this->titled_lesson($this->module, 1, 'Reading a balance sheet', ['111', '555000111']);
    $this->lessonB = $this->titled_lesson($this->module, 2, 'מניות ערך', ['555000111']);

    $otherCourse = $this->make_course();
    wp_update_post(['ID' => $otherCourse, 'post_title' => 'Options workshop']);
    $this->other = $this->titled_lesson($this->make_module($otherCourse, 1), 1, 'Calls and puts', ['999']);
  }

  private function titled_lesson($moduleId, $number, $title, array $videoIds) {
    $lesson = $this->make_lesson($moduleId, $number);
    wp_update_post(['ID' => $lesson, 'post_title' => $title]);
    $list = array_map(function ($id, $i) {
      return ['order' => (string) ($i + 1), 'caption' => '', 'video_id' => $id];
    }, $videoIds, array_keys($videoIds));
    update_post_meta($lesson, 'video_list', wp_slash(json_encode($list)));
    return $lesson;
  }

  private function search($q) {
    $res = $this->ajax('search_catalog', ['q' => $q]);
    $this->assertTrue($res['success'], 'search failed: ' . json_encode($res));
    return $res['data'];
  }

  private function keys(array $results) {
    return array_map(function ($r) { return "{$r['type']}:{$r['id']}:{$r['match']}"; }, $results);
  }

  public function test_vimeo_id_reads_bare_ids_and_links() {
    $this->assertSame('813709147', FutureLMS::vimeo_id('813709147'));
    $this->assertSame('813709147', FutureLMS::vimeo_id('  813709147 '));
    $this->assertSame('813709147', FutureLMS::vimeo_id('https://vimeo.com/813709147'));
    $this->assertSame('813709147', FutureLMS::vimeo_id('https://vimeo.com/813709147/abc123def'));
    $this->assertSame('813709147', FutureLMS::vimeo_id('https://player.vimeo.com/video/813709147?h=abc&badge=0'));
    $this->assertSame('813709147', FutureLMS::vimeo_id('https://vimeo.com/channels/staffpicks/813709147'));
    $this->assertSame('', FutureLMS::vimeo_id('https://youtube.com/watch?v=123'));
    $this->assertSame('', FutureLMS::vimeo_id('lesson one'));
  }

  public function test_finds_courses_modules_and_lessons_by_part_of_the_title() {
    $this->assertSame(["lesson:{$this->lessonA}:title"], $this->keys($this->search('balance')['results']));
    $this->assertSame(["module:{$this->module}:title"], $this->keys($this->search('value bas')['results']), 'case-insensitive');
    $this->assertSame(["lesson:{$this->lessonB}:title"], $this->keys($this->search('מניות')['results']), 'Hebrew titles');
    $this->assertSame(["course:{$this->course}:title"], $this->keys($this->search('אסטרטגיות')['results']));
  }

  public function test_a_lesson_result_says_where_it_sits() {
    $hit = $this->search('balance')['results'][0];
    $this->assertSame('Reading a balance sheet', $hit['name']);
    $this->assertSame($this->course, $hit['course_id']);
    $this->assertSame('אסטרטגיות השקעה 2026', $hit['course_name']);
    $this->assertSame($this->module, $hit['module_id']);
    $this->assertSame('Value basics', $hit['module_name']);
    $this->assertSame(1, $hit['lesson_number']);
    $this->assertTrue($hit['enabled']);
  }

  public function test_titles_come_back_as_typed_not_as_html_entities() {
    wp_update_post(['ID' => $this->course, 'post_title' => 'להטיס את העו"ש - "Cash" course']);
    $hit = $this->search('להטיס')['results'][0];
    $this->assertSame('להטיס את העו"ש - "Cash" course', $hit['name']);
    $this->assertSame('להטיס את העו"ש - "Cash" course', $this->search('balance')['results'][0]['course_name']);
  }

  public function test_finds_each_kind_by_its_id() {
    foreach (['course' => $this->course, 'module' => $this->module, 'lesson' => $this->lessonA] as $type => $id) {
      $first = $this->search((string) $id)['results'][0];
      $this->assertSame("$type:$id:id", "{$first['type']}:{$first['id']}:{$first['match']}");
    }
  }

  public function test_an_id_that_is_something_else_finds_nothing_by_id() {
    $user = self::factory()->user->create();
    $results = $this->search((string) $user)['results'];
    $this->assertNotContains('id', array_column($results, 'match'));
  }

  public function test_finds_lessons_by_video_id_or_link() {
    foreach (['555000111', 'https://vimeo.com/555000111', 'https://player.vimeo.com/video/555000111?h=x'] as $q) {
      $data = $this->search($q);
      $this->assertSame('555000111', $data['video_id']);
      $this->assertSame(["lesson:{$this->lessonA}:video", "lesson:{$this->lessonB}:video"], $this->keys($data['results']), $q);
      $this->assertSame(2, $data['results'][0]['position'], 'second video of lesson A');
      $this->assertSame(2, $data['results'][0]['video_count']);
    }
  }

  public function test_a_number_matches_ids_videos_and_titles_with_the_strongest_first() {
    // "2026" is in the course's title; give a lesson that video id too.
    $withVideo = $this->titled_lesson($this->module, 3, 'Year review', ['2026']);
    $keys = $this->keys($this->search('2026')['results']);
    $this->assertSame(["lesson:$withVideo:video", "course:{$this->course}:title"], $keys);
  }

  public function test_each_item_is_listed_once() {
    // Lesson A's id is also a video id of another lesson: A is listed once,
    // as an id match, and the other lesson as a video match.
    $this->titled_lesson($this->module, 4, 'Echo', [(string) $this->lessonA]);
    $results = $this->search((string) $this->lessonA)['results'];
    $this->assertSame(1, count(array_filter($results, function ($r) { return $r['id'] === $this->lessonA; })));
    $this->assertSame('id', $results[0]['match']);
  }

  public function test_whole_video_ids_only() {
    $this->assertSame([], $this->search('5550001')['results']);
  }

  public function test_drafts_are_found_and_flagged_trash_is_not() {
    wp_update_post(['ID' => $this->lessonB, 'post_status' => 'draft']);
    $hit = $this->search('מניות')['results'][0];
    $this->assertFalse($hit['enabled']);

    wp_update_post(['ID' => $this->lessonA, 'post_status' => 'trash']);
    $this->assertSame([], $this->search('balance')['results']);

    // A lesson under a trashed course is gone too.
    $courseOfOther = get_post_meta(get_post_meta($this->other, 'module', true), 'course', true);
    wp_update_post(['ID' => $courseOfOther, 'post_status' => 'trash']);
    $this->assertSame([], $this->search('Calls')['results']);
    $this->assertSame([], $this->search('999')['results']);
  }

  public function test_rejects_a_one_letter_query_but_not_a_one_digit_id() {
    $res = $this->ajax('search_catalog', ['q' => 'a']);
    $this->assertFalse($res['success']);
    $this->assertSame('Type at least 2 characters', $res['data']['message']);
    $this->assertTrue($this->ajax('search_catalog', ['q' => '7'])['success']);
  }

  public function test_students_cannot_search() {
    wp_set_current_user($this->make_student());
    $this->assertFalse($this->ajax('search_catalog', ['q' => 'balance'])['success']);
  }
}

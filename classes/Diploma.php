<?php

namespace FutureLMS\classes;

use Dompdf\Dompdf;
use Dompdf\Options;

class Diploma {
  const ELIGIBILITY_THRESHOLD = 80;

  public static function isEligible(int $studentId, int $courseId): bool {
    $tree = Course::get_courses_tree([$courseId]);
    $progress = ProgressManager::getCourseProgress($studentId, $courseId, $tree);
    $percent = $progress['percent'] ?? 0;

    $eligible = $percent >= self::ELIGIBILITY_THRESHOLD;

    return apply_filters('future-lms/diploma/eligible', $eligible, $studentId, $courseId, $percent);
  }

  public static function isEnabled(int $courseId): bool {
    $enabled = get_post_meta($courseId, 'diploma_enabled', true);
    return $enabled === '1';
  }

  public static function getDefaultData(int $studentId, int $courseId): array {
    $user = get_userdata($studentId);
    $studentName = $user ? $user->display_name : '';

    $course = new Course($courseId);
    $courseName = $course->raw('name');

    $totalSeconds = (int) get_post_meta($courseId, 'course_total_duration', true);
    $hours = $totalSeconds > 0 ? (string) round($totalSeconds / 3600) : '';

    $logoUrl = '';
    $logoAttachmentId = apply_filters('future-lms/diploma_logo', get_theme_mod('custom_logo'));
    if ($logoAttachmentId) {
      $logoPath = get_attached_file($logoAttachmentId);
      if ($logoPath && file_exists($logoPath)) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $mime = $mimeMap[$ext] ?? 'image/png';
        $logoUrl = "data:{$mime};base64," . base64_encode(file_get_contents($logoPath));
      }
    }

    $date = date_i18n('j/m/Y');

    $lecturerName = get_post_meta($courseId, 'lecturer_name', true) ?: '';

    $lecturerSignatureUrl = '';
    $sigAttachmentId = intval(get_post_meta($courseId, 'lecturer_signature', true));
    if ($sigAttachmentId > 0) {
      $sigPath = get_attached_file($sigAttachmentId);
      if ($sigPath && file_exists($sigPath)) {
        $ext = strtolower(pathinfo($sigPath, PATHINFO_EXTENSION));
        $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $mime = $mimeMap[$ext] ?? 'image/png';
        $lecturerSignatureUrl = "data:{$mime};base64," . base64_encode(file_get_contents($sigPath));
      }
    }

    return [
      'student_name'         => $studentName,
      'course_name'          => $courseName,
      'hours'                => $hours,
      'date'                 => $date,
      'logo_url'             => $logoUrl,
      'lecturer_name'        => $lecturerName,
      'lecturer_signature'   => $lecturerSignatureUrl,
    ];
  }

  public static function generate(int $studentId, int $courseId): void {
    // Suppress any PHP notices/warnings from corrupting PDF output
    $prevErrorReporting = error_reporting();
    error_reporting(0);

    try {
      $data = self::getDefaultData($studentId, $courseId);
      $data = apply_filters('future-lms/diploma/data', $data, $studentId, $courseId);

      $html = self::buildHtml($data, $studentId, $courseId);
      $html = apply_filters('future-lms/diploma/html', $html, $data, $studentId, $courseId);

      $options = new Options();
      $options->set('isRemoteEnabled', false);
      $options->set('defaultFont', 'DejaVu Sans');
      $options->set('isFontSubsettingEnabled', true);

      $dompdf = new Dompdf($options);
      $dompdf->loadHtml($html, 'UTF-8');
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();

      $pdfOutput = $dompdf->output();
      $filename = sanitize_file_name($data['course_name'] . ' - ' . __('Certificate', 'future-lms')) . '.pdf';

      // Write to temp file, then serve via readfile to avoid buffer issues
      $tmpFile = tempnam(sys_get_temp_dir(), 'diploma_');
      file_put_contents($tmpFile, $pdfOutput);
      unset($pdfOutput);

      // Clear ALL output buffers to discard any stray output
      while (ob_get_level()) {
        ob_end_clean();
      }

      // Check if headers were already sent (would corrupt the PDF)
      if (headers_sent($hsFile, $hsLine)) {
        error_log("Diploma: headers already sent at $hsFile:$hsLine");
        unlink($tmpFile);
        error_reporting($prevErrorReporting);
        wp_die("Diploma error: headers already sent at $hsFile:$hsLine", 500);
        return;
      }

      header('Content-Type: application/pdf');
      header('Content-Length: ' . filesize($tmpFile));
      header('Content-Disposition: inline; filename="' . $filename . '"');
      header('Cache-Control: private, max-age=0, must-revalidate');
      header('Pragma: public');

      readfile($tmpFile);
      unlink($tmpFile);
      error_reporting($prevErrorReporting);
      exit;
    } catch (\Throwable $e) {
      error_reporting($prevErrorReporting);
      while (ob_get_level()) {
        ob_end_clean();
      }
      $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
      error_log('Diploma generation error: ' . $msg);
      wp_die('<pre>Diploma generation failed: ' . esc_html($msg) . '</pre>', 500);
    }
  }

  /**
   * Reverse Hebrew text for Dompdf which doesn't support the Unicode BiDi algorithm.
   * Dompdf lays out all glyphs LTR, so we must produce a visually-reversed string:
   * - Reverse overall word/token order (RTL line)
   * - Reverse characters within each Hebrew word (so they appear correct when rendered LTR)
   * - Keep LTR tokens (Latin, digits) as-is
   */
  private static function fixHebrew(string $text): string {
    // Split into HTML tags and text segments, preserving tags
    $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';

    // Collect tags and text segments separately so we can reverse text while preserving tag positions
    // Strategy: extract all text segments, fix them, put back
    // For simplicity with inline tags like <strong>, we process the full string:
    // split by tags, reverse text segments' word order, rejoin

    // First, gather all tokens (tags + words) in order
    $tokens = [];
    foreach ($parts as $part) {
      if (preg_match('/^<[^>]+>$/', $part)) {
        $tokens[] = ['type' => 'tag', 'value' => $part];
      } else {
        // Split text into words by spaces
        $words = preg_split('/( +)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($words as $word) {
          if ($word === '') continue;
          if (preg_match('/^ +$/', $word)) {
            $tokens[] = ['type' => 'space', 'value' => $word];
          } else {
            $tokens[] = ['type' => 'word', 'value' => $word];
          }
        }
      }
    }

    // Now we need to reverse the visual order.
    // Group tokens into "tag-wrapped segments" — a tag and its adjacent word belong together.
    // e.g. <strong>word</strong> should stay as a unit.
    // Strategy: reverse the entire tokens array, then fix Hebrew words and flip tag pairs.
    $reversed = array_reverse($tokens);

    foreach ($reversed as &$token) {
      if ($token['type'] === 'word') {
        // Reverse characters within Hebrew words so they display correctly in LTR rendering
        if (preg_match('/\p{Hebrew}/u', $token['value'])) {
          $chars = preg_split('//u', $token['value'], -1, PREG_SPLIT_NO_EMPTY);
          $token['value'] = implode('', array_reverse($chars));
        }
      } elseif ($token['type'] === 'tag') {
        // Swap opening/closing tags since we reversed order
        // <strong> becomes </strong> and vice versa
        if (preg_match('#^</([^>]+)>$#', $token['value'], $m)) {
          $token['value'] = '<' . $m[1] . '>';
        } elseif (preg_match('#^<([^/][^>]*)>$#', $token['value'], $m)) {
          $token['value'] = '</' . $m[1] . '>';
        }
      }
    }
    unset($token);

    foreach ($reversed as $token) {
      $result .= $token['value'];
    }

    return $result;
  }

  public static function buildHtmlPublic(array $data, int $studentId, int $courseId): string {
    return self::buildHtml($data, $studentId, $courseId);
  }

  private static function buildHtml(array $data, int $studentId, int $courseId): string {
    $title = __('Certificate', 'future-lms');
    $certifiesText = __('This certifies that', 'future-lms');
    $completedText = __('has participated and successfully completed', 'future-lms');

    $hoursHtml = '';
    if (!empty($data['hours'])) {
      $hoursLine = sprintf(
        __('comprising %s hours of study', 'future-lms'),
        esc_html($data['hours'])
      );
      $hoursHtml = '<div class="hours-line">' . self::fixHebrew($hoursLine) . '</div>';
    }

    $logoHtml = '';
    if (!empty($data['logo_url'])) {
      $logoHtml = '<img src="' . $data['logo_url'] . '" style="max-width: 270px; max-height: 120px;" />';
    }

    // Award ribbon seal image
    $sealPath = plugin_dir_path(dirname(__FILE__)) . 'assets/images/seal.png';
    $sealDataUri = '';
    if (file_exists($sealPath)) {
      $sealDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($sealPath));
    }

    // Parchment noise texture tile
    $parchmentPath = plugin_dir_path(dirname(__FILE__)) . 'assets/images/parchment-tile.png';
    $parchmentBg = '';
    if (file_exists($parchmentPath)) {
      $parchmentBg = 'data:image/png;base64,' . base64_encode(file_get_contents($parchmentPath));
    }

    // Bottom flourish SVG as data URI
    $flourishSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 50" width="400" height="50">'
      . '<path d="M50,25 Q100,0 150,25 Q170,35 200,25 Q230,15 250,25 Q300,50 350,25" fill="none" stroke="#b08d57" stroke-width="1.5"/>'
      . '<path d="M50,25 Q100,50 150,25 Q170,15 200,25 Q230,35 250,25 Q300,0 350,25" fill="none" stroke="#b08d57" stroke-width="1.5"/>'
      . '<circle cx="200" cy="25" r="4" fill="#b08d57"/>'
      . '<circle cx="188" cy="25" r="2" fill="#b08d57"/>'
      . '<circle cx="212" cy="25" r="2" fill="#b08d57"/>'
      . '</svg>';
    $flourishDataUri = 'data:image/svg+xml;base64,' . base64_encode($flourishSvg);

    $dateLabel = __('Date', 'future-lms');

    // A4 landscape: 842pt x 595pt
    $html = '<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
<meta charset="UTF-8">
<style>
  @page {
    margin: 0;
    size: A4 landscape;
  }
  body {
    font-family: "DejaVu Sans", sans-serif;
    direction: rtl;
    text-align: center;
    margin: 0;
    padding: 0;
    background-color: #e8dcc8;
    background-image: url("' . $parchmentBg . '");
    background-repeat: repeat;
  }
  .outer-border {
    position: fixed;
    top: 16px;
    left: 16px;
    right: 16px;
    bottom: 16px;
    border: 3px solid #b08d57;
  }
  .inner-border {
    position: fixed;
    top: 28px;
    left: 28px;
    right: 28px;
    bottom: 28px;
    border: 1px solid #b08d57;
  }
  .content {
    position: fixed;
    top: 44px;
    left: 44px;
    right: 44px;
    bottom: 44px;
    text-align: center;
  }
  .logo {
    margin-top: 12px;
    margin-bottom: 0;
  }
  .title {
    font-size: 55px;
    font-weight: bold;
    color: #3a3a3a;
    margin: 10px 0 18px;
  }
  .certifies-text {
    font-size: 26px;
    color: #3a3a3a;
    margin: 8px 0;
  }
  .student-name {
    font-size: 36px;
    font-weight: bold;
    color: #3a3a3a;
    margin: 8px 0 0;
    padding-bottom: 4px;
  }
  .name-underline {
    width: 350px;
    height: 2px;
    background: #3a3a3a;
    margin: 0 auto 14px;
  }
  .completed-text {
    font-size: 26px;
    color: #3a3a3a;
    margin: 10px 0;
  }
  .course-name {
    font-size: 44px;
    font-weight: bold;
    color: #3a3a3a;
    margin: 10px 0;
  }
  .hours-line {
    font-size: 26px;
    color: #3a3a3a;
    margin: 8px 0;
  }
  .date-block {
    position: fixed;
    bottom: 60px;
    left: 80px;
    text-align: center;
  }
  .date-value {
    font-size: 21px;
    color: #3a3a3a;
    padding-bottom: 3px;
    border-bottom: 1px solid #3a3a3a;
    display: inline-block;
    margin-bottom: 4px;
  }
  .date-label {
    font-size: 18px;
    color: #555;
  }
  .seal {
    position: fixed;
    bottom: 30px;
    right: 50px;
    width: 130px;
    height: 130px;
  }
  .flourish {
    position: fixed;
    bottom: 32px;
    left: 50%;
    margin-left: -150px;
    width: 300px;
    height: 40px;
  }
  .signature-block {
    position: fixed;
    bottom: 60px;
    right: 200px;
    text-align: center;
  }
  .signature-block img {
    max-width: 150px;
    max-height: 70px;
    display: block;
    margin: 0 auto 3px;
  }
  .signature-line {
    width: 160px;
    height: 0;
    border-bottom: 1px solid #3a3a3a;
    margin: 0 auto 4px;
    padding-bottom: 3px;
  }
  .signature-name {
    font-size: 18px;
    color: #555;
  }
</style>
</head>
<body>
  <div class="outer-border"></div>
  <div class="inner-border"></div>
  <div class="content">
    <div class="logo">' . $logoHtml . '</div>
    <div class="title">' . self::fixHebrew(esc_html($title)) . '</div>
    <div class="certifies-text">' . self::fixHebrew(esc_html($certifiesText)) . '</div>
    <div class="student-name">' . self::fixHebrew(esc_html($data['student_name'])) . '</div>
    <div class="name-underline"></div>
    <div class="completed-text">' . self::fixHebrew(esc_html($completedText)) . '</div>
    <div class="course-name">' . self::fixHebrew(esc_html($data['course_name'])) . '</div>
    ' . $hoursHtml . '
  </div>
  <div class="date-block">
    <div class="date-value">' . esc_html($data['date']) . '</div>
    <div class="date-label">' . self::fixHebrew(esc_html($dateLabel)) . '</div>
  </div>
  <img class="seal" src="' . $sealDataUri . '" />
  <img class="flourish" src="' . $flourishDataUri . '" />';

    if (!empty($data['lecturer_name']) || !empty($data['lecturer_signature'])) {
      $sigImgHtml = '';
      if (!empty($data['lecturer_signature'])) {
        $sigImgHtml = '<img src="' . $data['lecturer_signature'] . '" />';
      }
      $sigNameHtml = '';
      if (!empty($data['lecturer_name'])) {
        $sigNameHtml = '<div class="signature-name">' . self::fixHebrew(esc_html($data['lecturer_name'])) . '</div>';
      }
      $html .= '
  <div class="signature-block">
    ' . $sigImgHtml . '
    <div class="signature-line"></div>
    ' . $sigNameHtml . '
  </div>';
    }

    $html .= '
</body>
</html>';

    return $html;
  }
}

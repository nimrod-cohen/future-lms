<?php

namespace FutureLMS\classes;

/**
 * Images pasted straight into the editor arrive as data: URIs inside the HTML.
 * wp_kses_post() doesn't allow the data: protocol and strips just that prefix,
 * leaving src="image/png;base64,…". A browser reads that as a relative address
 * and requests a URL hundreds of KB long, which drops its connection to the
 * site and fails every server call in flight (the students' "server call
 * failed" dialog).
 *
 * So before sanitizing, each embedded image is saved to the media library and
 * the content points at the file instead.
 */
class EmbeddedImages {
  // What getimagesizefromstring() reports => the extension to save under.
  // Raster formats only: an SVG can carry script.
  private const TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
  ];

  /**
   * Replaces every embedded image in $html with a media-library file.
   * Anything that isn't a real image of a known type loses its src, so no
   * broken address is ever stored.
   */
  public static function store(string $html, int $parentId = 0): string {
    if (stripos($html, 'data:image/') === false) return $html;

    // preg returns null on failure (e.g. PCRE limits on a huge image); keep the
    // content rather than wiping the lesson.
    return preg_replace_callback(
      '/(\ssrc\s*=\s*)(["\'])\s*data:image\/[a-z0-9.+-]+;base64,([^"\']*)\2/i',
      function ($m) use ($parentId) {
        $url = self::save(base64_decode(preg_replace('/\s+/', '', $m[3]), true), $parentId);
        return $m[1] . $m[2] . ($url ? esc_url($url) : '') . $m[2];
      },
      $html
    ) ?? $html;
  }

  private static function save($bits, int $parentId): ?string {
    if (!$bits) return null;

    $info = @getimagesizefromstring($bits);
    $ext = self::TYPES[$info['mime'] ?? ''] ?? null;
    if (!$ext) return null;

    $upload = wp_upload_bits('pasted-' . substr(md5($bits), 0, 12) . '.' . $ext, null, $bits);
    if (!empty($upload['error'])) return null;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachmentId = wp_insert_attachment([
      'post_mime_type' => $info['mime'],
      'post_title' => pathinfo($upload['file'], PATHINFO_FILENAME),
      'post_status' => 'inherit',
    ], $upload['file'], $parentId);
    if (!$attachmentId || is_wp_error($attachmentId)) return $upload['url'];

    wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $upload['file']));
    return $upload['url'];
  }
}

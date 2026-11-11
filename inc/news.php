<?php
/**
 * MDTV+ Noticias — storage JSON, slug, sanitizador, imágenes GD
 * PHP 8+ · sin dependencias · zona America/Santiago
 */

declare(strict_types=1);

const NEWS_SITE_ORIGIN   = 'https://mdtvmas.cl';
const NEWS_DIR           = __DIR__ . '/../data/news';
const NEWS_POSTS_DIR     = __DIR__ . '/../data/news/posts';
const NEWS_INDEX_PATH    = __DIR__ . '/../data/news/index.json';
const NEWS_UPLOAD_DIR    = __DIR__ . '/../assets/uploads/news';
const NEWS_UPLOAD_PUBLIC = 'assets/uploads/news';
const NEWS_AUTHOR        = 'Redacción MDTV+';
const NEWS_ID_RE         = '/^[0-9]{8}-[a-f0-9]{6}$/';
const NEWS_SLUG_RE       = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
const NEWS_YT_RE         = '/^[a-zA-Z0-9_-]{11}$/';
const NEWS_MAX_COVER_BYTES  = 8 * 1024 * 1024;
const NEWS_MAX_INLINE_BYTES = 5 * 1024 * 1024;

const NEWS_CATEGORIES = [
    'Fútbol',
    'Tercera División',
    'Deporte Regional',
    'Institucional',
    'Transmisiones',
];

/* ---------- Timezone ---------- */

function news_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('America/Santiago');
    }
    return $tz;
}

function news_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', news_tz());
}

/* ---------- Validation helpers ---------- */

function news_valid_id(string $id): bool
{
    return (bool)preg_match(NEWS_ID_RE, $id);
}

function news_valid_slug(string $slug): bool
{
    return $slug !== ''
        && strlen($slug) <= 80
        && (bool)preg_match(NEWS_SLUG_RE, $slug);
}

function news_ensure_dirs(): void
{
    foreach ([NEWS_DIR, NEWS_POSTS_DIR, NEWS_UPLOAD_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function news_atomic_write(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

function news_abs_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return NEWS_SITE_ORIGIN . '/';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return NEWS_SITE_ORIGIN . '/' . ltrim($path, '/');
}

function news_public_url(string $slug): string
{
    return NEWS_SITE_ORIGIN . '/noticias/' . $slug;
}

/* ---------- Slug ---------- */

function news_slugify(string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return 'noticia';
    }

    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
    ];
    $s = strtr($title, $map);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($converted) && $converted !== '' && !str_contains($converted, '?')) {
            $s = $converted;
        }
    }

    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = preg_replace('/-+/', '-', $s) ?? '';
    if ($s === '') {
        $s = 'noticia';
    }
    if (strlen($s) > 80) {
        $s = rtrim(substr($s, 0, 80), '-');
    }
    return $s !== '' ? $s : 'noticia';
}

function news_unique_slug(string $slug, ?string $exceptId = null): string
{
    $slug = news_valid_slug($slug) ? $slug : news_slugify($slug);
    $base = $slug;
    $n = 2;
    while (true) {
        $existing = news_find_by_slug($slug, false);
        if ($existing === null || ($exceptId !== null && ($existing['id'] ?? '') === $exceptId)) {
            return $slug;
        }
        $suffix = '-' . $n;
        $trim = 80 - strlen($suffix);
        $slug = rtrim(substr($base, 0, max(1, $trim)), '-') . $suffix;
        $n++;
        if ($n > 200) {
            return $base . '-' . bin2hex(random_bytes(2));
        }
    }
}

/* ---------- Storage ---------- */

function news_post_path(string $id): string
{
    return NEWS_POSTS_DIR . '/' . $id . '.json';
}

function news_load_index(): array
{
    if (!is_file(NEWS_INDEX_PATH)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(NEWS_INDEX_PATH), true);
    return is_array($data) ? $data : [];
}

function news_load_post(string $id): ?array
{
    if (!news_valid_id($id)) {
        return null;
    }
    $path = news_post_path($id);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function news_find_by_slug(string $slug, bool $onlyVisible = false): ?array
{
    if (!news_valid_slug($slug)) {
        return null;
    }
    foreach (news_load_index() as $row) {
        if (!is_array($row) || ($row['slug'] ?? '') !== $slug) {
            continue;
        }
        $id = (string)($row['id'] ?? '');
        $post = news_load_post($id);
        if ($post === null) {
            return null;
        }
        if ($onlyVisible && !news_is_visible($post)) {
            return null;
        }
        return $post;
    }
    // Fallback: scan posts if index incomplete
    news_ensure_dirs();
    foreach (glob(NEWS_POSTS_DIR . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && ($data['slug'] ?? '') === $slug) {
            if ($onlyVisible && !news_is_visible($data)) {
                return null;
            }
            return $data;
        }
    }
    return null;
}

function news_summary(array $post): array
{
    $cover = is_array($post['cover'] ?? null) ? $post['cover'] : [];
    return [
        'id'           => (string)($post['id'] ?? ''),
        'slug'         => (string)($post['slug'] ?? ''),
        'title'        => (string)($post['title'] ?? ''),
        'excerpt'      => (string)($post['excerpt'] ?? ''),
        'category'     => (string)($post['category'] ?? ''),
        'cover'        => [
            'full' => (string)($cover['full'] ?? ''),
            'card' => (string)($cover['card'] ?? ''),
            'og'   => (string)($cover['og'] ?? ''),
            'alt'  => (string)($cover['alt'] ?? ''),
        ],
        'status'       => (string)($post['status'] ?? 'draft'),
        'published_at' => (string)($post['published_at'] ?? ''),
        'created_at'   => (string)($post['created_at'] ?? ''),
        'updated_at'   => (string)($post['updated_at'] ?? ''),
        'author'       => (string)($post['author'] ?? NEWS_AUTHOR),
    ];
}

function news_rebuild_index(): bool
{
    news_ensure_dirs();
    $rows = [];
    foreach (glob(NEWS_POSTS_DIR . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }
        $rows[] = news_summary($data);
    }
    usort($rows, static function (array $a, array $b): int {
        $ta = (string)($a['published_at'] ?? '');
        $tb = (string)($b['published_at'] ?? '');
        if ($ta === $tb) {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        }
        return strcmp($tb, $ta);
    });
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return news_atomic_write(NEWS_INDEX_PATH, $json . "\n");
}

function news_save_post(array $post): bool
{
    $id = (string)($post['id'] ?? '');
    if (!news_valid_id($id)) {
        return false;
    }
    news_ensure_dirs();
    $json = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    if (!news_atomic_write(news_post_path($id), $json . "\n")) {
        return false;
    }
    return news_rebuild_index();
}

function news_delete_cover_files(array $cover): void
{
    foreach (['full', 'card', 'og'] as $key) {
        $rel = (string)($cover[$key] ?? '');
        if ($rel === '' || !str_starts_with($rel, NEWS_UPLOAD_PUBLIC . '/')) {
            continue;
        }
        $abs = __DIR__ . '/../' . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

function news_delete_post(string $id): bool
{
    if (!news_valid_id($id)) {
        return false;
    }
    $post = news_load_post($id);
    $path = news_post_path($id);
    if ($post !== null && is_array($post['cover'] ?? null)) {
        news_delete_cover_files($post['cover']);
    }
    // Inline images for this post
    foreach (glob(NEWS_UPLOAD_DIR . '/' . $id . '-inline-*.*') ?: [] as $img) {
        @unlink($img);
    }
    // Cover variants by prefix
    foreach (glob(NEWS_UPLOAD_DIR . '/' . $id . '-*.*') ?: [] as $img) {
        @unlink($img);
    }
    if (is_file($path)) {
        @unlink($path);
    }
    return news_rebuild_index();
}

function news_is_visible(array $p): bool
{
    if (($p['status'] ?? '') !== 'published') {
        return false;
    }
    $iso = trim((string)($p['published_at'] ?? ''));
    if ($iso === '') {
        return false;
    }
    try {
        $pub = new DateTimeImmutable($iso);
        return $pub <= news_now();
    } catch (Throwable) {
        return false;
    }
}

function news_visible(int $limit = 0, int $offset = 0): array
{
    $out = [];
    foreach (news_load_index() as $row) {
        if (!is_array($row) || !news_is_visible($row)) {
            continue;
        }
        $out[] = $row;
    }
    if ($offset > 0) {
        $out = array_slice($out, $offset);
    }
    if ($limit > 0) {
        $out = array_slice($out, 0, $limit);
    }
    return $out;
}

function news_related(array $post, int $limit = 3): array
{
    $id = (string)($post['id'] ?? '');
    $cat = (string)($post['category'] ?? '');
    $same = [];
    $other = [];
    foreach (news_visible() as $row) {
        if (($row['id'] ?? '') === $id) {
            continue;
        }
        if ($cat !== '' && ($row['category'] ?? '') === $cat) {
            $same[] = $row;
        } else {
            $other[] = $row;
        }
    }
    return array_slice(array_merge($same, $other), 0, $limit);
}

function news_new_id(): string
{
    return date('Ymd') . '-' . bin2hex(random_bytes(3));
}

/* ---------- HTML sanitize ---------- */

function news_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowed = [
        'p' => [],
        'h2' => [],
        'h3' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'a' => ['href'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'br' => [],
        'figure' => ['data-yt'],
        'img' => ['src', 'alt'],
        'figcaption' => [],
    ];

    $wrapper = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="root">'
        . $html
        . '</div></body></html>';

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
        // Fallback without those flags
        $doc = new DOMDocument();
        $doc->loadHTML($wrapper);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('root');
    if (!$root) {
        return '';
    }

    news_sanitize_node($root, $doc, $allowed);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function news_sanitize_node(DOMNode $node, DOMDocument $doc, array $allowed): void
{
    if (!($node instanceof DOMElement) && !($node instanceof DOMDocument)) {
        return;
    }

    $children = [];
    foreach ($node->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if ($child instanceof DOMText || $child instanceof DOMCdataSection) {
            continue;
        }
        if ($child instanceof DOMComment) {
            $child->parentNode?->removeChild($child);
            continue;
        }
        if (!($child instanceof DOMElement)) {
            $child->parentNode?->removeChild($child);
            continue;
        }

        $tag = strtolower($child->tagName);

        // Drop dangerous outright
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'svg', 'math'], true)) {
            $child->parentNode?->removeChild($child);
            continue;
        }

        if (!isset($allowed[$tag])) {
            // Unwrap: keep children
            news_sanitize_node($child, $doc, $allowed);
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        // Strip disallowed attributes
        $attrs = [];
        if ($child->hasAttributes()) {
            foreach ($child->attributes as $attr) {
                $attrs[] = $attr->name;
            }
        }
        foreach ($attrs as $attrName) {
            $lower = strtolower($attrName);
            if (str_starts_with($lower, 'on') || $lower === 'style' || $lower === 'class') {
                $child->removeAttribute($attrName);
                continue;
            }
            if (!in_array($lower, $allowed[$tag], true)) {
                $child->removeAttribute($attrName);
            }
        }

        // Validate specific attrs
        if ($tag === 'a') {
            $href = trim($child->getAttribute('href'));
            if ($href === '' || !preg_match('#^(https?://|mailto:)#i', $href)) {
                $child->removeAttribute('href');
            } else {
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
                $child->setAttribute('target', '_blank');
            }
        }

        if ($tag === 'img') {
            $src = trim($child->getAttribute('src'));
            if ($src === '' || !str_starts_with($src, NEWS_UPLOAD_PUBLIC . '/')) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            $alt = $child->getAttribute('alt');
            $child->setAttribute('alt', $alt);
            $child->setAttribute('loading', 'lazy');
            $child->setAttribute('decoding', 'async');
        }

        if ($tag === 'figure') {
            $yt = trim($child->getAttribute('data-yt'));
            if ($yt !== '') {
                if (!preg_match(NEWS_YT_RE, $yt)) {
                    $child->removeAttribute('data-yt');
                } else {
                    $child->setAttribute('data-yt', $yt);
                    $child->setAttribute('contenteditable', 'false');
                }
            }
        }

        news_sanitize_node($child, $doc, $allowed);
    }
}

function news_reading_minutes(string $html): int
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($text === '') {
        return 1;
    }
    $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    return max(1, (int)ceil($words / 200));
}

function news_render_body(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Convert YouTube figures into click-to-play facades
    $html = preg_replace_callback(
        '/<figure\b([^>]*)\bdata-yt=(["\'])([a-zA-Z0-9_-]{11})\2([^>]*)>(.*?)<\/figure>/is',
        static function (array $m): string {
            $id = $m[3];
            $thumb = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
            return '<figure class="yt-facade" data-yt="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="button" class="yt-facade__btn" aria-label="Reproducir video de YouTube">'
                . '<img src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="Video de YouTube" width="480" height="360" loading="lazy" decoding="async">'
                . '<span class="yt-facade__play" aria-hidden="true"></span>'
                . '</button>'
                . '</figure>';
        },
        $html
    );

    return $html ?? '';
}

/* ---------- Image processing (GD) ---------- */

function news_detect_image_mime(string $tmpPath): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath) ?: '';
        if ($mime !== '') {
            return $mime;
        }
    }
    if (function_exists('mime_content_type')) {
        return mime_content_type($tmpPath) ?: '';
    }
    $info = @getimagesize($tmpPath);
    return is_array($info) ? (string)($info['mime'] ?? '') : '';
}

/**
 * @return array{ok:bool,error?:string,mime?:string,ext?:string}
 */
function news_validate_uploaded_image(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir el archivo (código ' . (int)($file['error'] ?? 0) . ')'];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        $mb = (int)ceil($maxBytes / (1024 * 1024));
        return ['ok' => false, 'error' => "La imagen supera {$mb} MB"];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo temporal inválido'];
    }
    $mime = news_detect_image_mime($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Solo se permiten JPG, PNG o WEBP'];
    }
    return ['ok' => true, 'mime' => $mime, 'ext' => $allowed[$mime]];
}

function news_gd_from_file(string $path): ?\GdImage
{
    $mime = news_detect_image_mime($path);
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };
    return $img instanceof \GdImage ? $img : null;
}

function news_exif_orient(\GdImage $img, string $path): \GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    if (!is_array($exif) || empty($exif['Orientation'])) {
        return $img;
    }
    $orientation = (int)$exif['Orientation'];
    $rotated = match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => false,
    };
    if ($rotated instanceof \GdImage) {
        imagedestroy($img);
        return $rotated;
    }
    return $img;
}

function news_image_resize_max(\GdImage $src, int $maxW): \GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= $maxW) {
        return $src;
    }
    $nw = $maxW;
    $nh = (int)max(1, round($h * ($maxW / $w)));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    return $dst;
}

function news_image_cover_crop(\GdImage $src, int $tw, int $th): \GdImage
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $srcRatio = $sw / max(1, $sh);
    $dstRatio = $tw / $th;

    if ($srcRatio > $dstRatio) {
        $cropH = $sh;
        $cropW = (int)round($sh * $dstRatio);
        $sx = (int)max(0, ($sw - $cropW) / 2);
        $sy = 0;
    } else {
        $cropW = $sw;
        $cropH = (int)round($sw / $dstRatio);
        $sx = 0;
        $sy = (int)max(0, ($sh - $cropH) / 2);
    }

    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $cropW, $cropH);
    return $dst;
}

function news_save_jpeg(\GdImage $img, string $path, int $quality = 82): bool
{
    return imagejpeg($img, $path, $quality);
}

function news_save_card(\GdImage $img, string $basePathNoExt): string
{
    // Prefer WebP; fall back to JPEG
    if (function_exists('imagewebp')) {
        $path = $basePathNoExt . '.webp';
        if (@imagewebp($img, $path, 80)) {
            return $path;
        }
    }
    $path = $basePathNoExt . '.jpg';
    news_save_jpeg($img, $path, 82);
    return $path;
}

/**
 * Load + EXIF-correct source image from disk.
 */
function news_load_oriented(string $tmpPath): ?\GdImage
{
    $src = news_gd_from_file($tmpPath);
    if ($src === null) {
        return null;
    }
    return news_exif_orient($src, $tmpPath);
}

/**
 * @return array{full:string,card:string,og:string}|null
 */
function news_process_cover(string $tmpPath, string $id): ?array
{
    if (!news_valid_id($id)) {
        return null;
    }
    news_ensure_dirs();
    $prefix = NEWS_UPLOAD_DIR . '/' . $id;

    // Full: max 1600 wide
    $fullSrc = news_load_oriented($tmpPath);
    if ($fullSrc === null) {
        return null;
    }
    $fullImg = news_image_resize_max($fullSrc, 1600);
    $fullPath = $prefix . '-1600.jpg';
    if (!news_save_jpeg($fullImg, $fullPath, 85)) {
        imagedestroy($fullImg);
        return null;
    }
    imagedestroy($fullImg);

    // OG: 1200x630 center crop (JPEG — WhatsApp/Facebook)
    $ogSrc = news_load_oriented($tmpPath);
    if ($ogSrc === null) {
        return null;
    }
    $ogImg = news_image_cover_crop($ogSrc, 1200, 630);
    imagedestroy($ogSrc);
    $ogPath = $prefix . '-og.jpg';
    if (!news_save_jpeg($ogImg, $ogPath, 82)) {
        imagedestroy($ogImg);
        return null;
    }
    imagedestroy($ogImg);

    // Card: 16:9 ~800 wide (WebP with JPEG fallback)
    $cardSrc = news_load_oriented($tmpPath);
    if ($cardSrc === null) {
        return null;
    }
    $cardCrop = news_image_cover_crop($cardSrc, 800, 450);
    imagedestroy($cardSrc);
    $cardAbs = news_save_card($cardCrop, $prefix . '-800');
    imagedestroy($cardCrop);

    return [
        'full' => NEWS_UPLOAD_PUBLIC . '/' . basename($fullPath),
        'card' => NEWS_UPLOAD_PUBLIC . '/' . basename($cardAbs),
        'og'   => NEWS_UPLOAD_PUBLIC . '/' . basename($ogPath),
    ];
}

function news_process_inline_image(string $tmpPath, string $id): ?string
{
    if (!news_valid_id($id)) {
        return null;
    }
    news_ensure_dirs();
    $src = news_gd_from_file($tmpPath);
    if ($src === null) {
        return null;
    }
    $src = news_exif_orient($src, $tmpPath);
    $src = news_image_resize_max($src, 1200);
    $name = $id . '-inline-' . bin2hex(random_bytes(4)) . '.jpg';
    $abs = NEWS_UPLOAD_DIR . '/' . $name;
    if (!news_save_jpeg($src, $abs, 85)) {
        imagedestroy($src);
        return null;
    }
    imagedestroy($src);
    return NEWS_UPLOAD_PUBLIC . '/' . $name;
}

function news_format_date_es(string $iso): string
{
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(news_tz());
        return $dt->format('d') . ' de ' . [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ][(int)$dt->format('n')] . ' de ' . $dt->format('Y');
    } catch (Throwable) {
        return $iso;
    }
}

function news_iso_to_datetime_local(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(news_tz());
        return $dt->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return substr($iso, 0, 16);
    }
}

/**
 * datetime-local → ISO with America/Santiago offset at that moment
 */
function news_datetime_local_to_iso(string $local): string
{
    $local = trim($local);
    if ($local === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $local)) {
        return $local;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
        $local .= ':00';
    }
    try {
        $dt = new DateTimeImmutable($local, news_tz());
        return $dt->format('c');
    } catch (Throwable) {
        return $local;
    }
}

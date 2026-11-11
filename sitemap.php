<?php
/**
 * MDTV+ — sitemap.xml dinámico
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/news.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');

$urls = [];

$urls[] = [
    'loc'        => NEWS_SITE_ORIGIN . '/',
    'lastmod'    => news_now()->format('Y-m-d'),
    'changefreq' => 'hourly',
    'priority'   => '1.0',
];

$urls[] = [
    'loc'        => NEWS_SITE_ORIGIN . '/noticias',
    'lastmod'    => news_now()->format('Y-m-d'),
    'changefreq' => 'daily',
    'priority'   => '0.8',
];

foreach (news_visible() as $p) {
    $slug = (string)($p['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $last = (string)($p['updated_at'] ?? $p['published_at'] ?? '');
    $lastmod = news_now()->format('Y-m-d');
    try {
        if ($last !== '') {
            $lastmod = (new DateTimeImmutable($last))->format('Y-m-d');
        }
    } catch (Throwable) {
        // keep today
    }
    $urls[] = [
        'loc'        => news_public_url($slug),
        'lastmod'    => $lastmod,
        'changefreq' => 'weekly',
        'priority'   => '0.7',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <lastmod><?= htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></lastmod>
    <changefreq><?= htmlspecialchars($u['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></changefreq>
    <priority><?= htmlspecialchars($u['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></priority>
  </url>
<?php endforeach; ?>
</urlset>

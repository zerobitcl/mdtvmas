<?php
/**
 * MDTV+ — Feed JSON público de últimas noticias (home)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/news.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');

$posts = news_visible(6, 0);
$out = [];
foreach ($posts as $p) {
    $cover = is_array($p['cover'] ?? null) ? $p['cover'] : [];
    $out[] = [
        'id'           => (string)($p['id'] ?? ''),
        'slug'         => (string)($p['slug'] ?? ''),
        'title'        => (string)($p['title'] ?? ''),
        'excerpt'      => (string)($p['excerpt'] ?? ''),
        'category'     => (string)($p['category'] ?? ''),
        'published_at' => (string)($p['published_at'] ?? ''),
        'cover'        => [
            'card' => (string)($cover['card'] ?? ''),
            'og'   => (string)($cover['og'] ?? ''),
            'alt'  => (string)($cover['alt'] ?? ''),
        ],
    ];
}

echo json_encode(
    ['ok' => true, 'posts' => $out],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

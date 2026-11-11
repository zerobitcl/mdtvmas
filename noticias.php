<?php
/**
 * MDTV+ — Listado de noticias (SSR)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/news.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$catParam = trim((string)($_GET['cat'] ?? ''));
$catFilter = in_array($catParam, NEWS_CATEGORIES, true) ? $catParam : '';

$all = news_visible();
if ($catFilter !== '') {
    $all = array_values(array_filter(
        $all,
        static fn(array $p): bool => ($p['category'] ?? '') === $catFilter
    ));
}

$total = count($all);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$posts = array_slice($all, $offset, $perPage);

$featured = null;
$grid = $posts;
if ($page === 1 && $catFilter === '' && $posts !== []) {
    $featured = $posts[0];
    $grid = array_slice($posts, 1);
}

$canonical = news_abs_url('noticias');
$queryParts = [];
if ($catFilter !== '') {
    $queryParts[] = 'cat=' . rawurlencode($catFilter);
}
if ($page > 1) {
    $queryParts[] = 'page=' . $page;
}
if ($queryParts) {
    $canonical .= '?' . implode('&', $queryParts);
}

$ogTitle = $catFilter !== ''
    ? ('Noticias de ' . $catFilter . ' | MDTV+')
    : 'Noticias | MDTV+ — Deporte en Atacama';
$ogDesc = 'Lo último del fútbol y el deporte en la Región de Atacama. Noticias oficiales de MDTV+.';
$ogImage = news_abs_url('assets/img/logo.png');
if ($featured && !empty($featured['cover']['og'])) {
    $ogImage = news_abs_url((string)$featured['cover']['og']);
}

header('Cache-Control: public, max-age=60');

function news_list_url(int $page, string $cat = ''): string
{
    $parts = [];
    if ($cat !== '') {
        $parts[] = 'cat=' . rawurlencode($cat);
    }
    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }
    $q = $parts ? ('?' . implode('&', $parts)) : '';
    return '/noticias' . $q;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= h($ogTitle) ?></title>
  <meta name="description" content="<?= h($ogDesc) ?>">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="<?= h($canonical) ?>">
  <?php if ($page > 1): ?>
  <link rel="prev" href="<?= h(news_abs_url(ltrim(news_list_url($page - 1, $catFilter), '/'))) ?>">
  <?php endif; ?>
  <?php if ($page < $totalPages): ?>
  <link rel="next" href="<?= h(news_abs_url(ltrim(news_list_url($page + 1, $catFilter), '/'))) ?>">
  <?php endif; ?>
  <meta name="theme-color" content="#07090E">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="MDTV+">
  <meta property="og:title" content="<?= h($ogTitle) ?>">
  <meta property="og:description" content="<?= h($ogDesc) ?>">
  <meta property="og:url" content="<?= h($canonical) ?>">
  <meta property="og:locale" content="es_CL">
  <meta property="og:image" content="<?= h($ogImage) ?>">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($ogTitle) ?>">
  <meta name="twitter:description" content="<?= h($ogDesc) ?>">
  <meta name="twitter:image" content="<?= h($ogImage) ?>">

  <link rel="icon" type="image/png" href="/assets/img/favicon.png">
  <link rel="apple-touch-icon" href="/assets/img/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/news.css">
</head>
<body>
  <header class="news-site-header">
    <div class="news-site-header__inner">
      <a href="/" class="logo" aria-label="MDTV+ Inicio">
        <img class="logo__img" src="/assets/img/logo.png" alt="MDTV+" width="120" height="120" decoding="async">
      </a>
      <nav class="news-site-header__nav" aria-label="Navegación">
        <a href="/noticias">Noticias</a>
        <a class="is-live" href="/#envivo">En Vivo</a>
      </nav>
    </div>
  </header>

  <main class="news-listing">
    <div class="section-head">
      <p class="eyebrow">Noticias</p>
      <h1 id="noticias-heading" style="font-family:var(--font-display);font-weight:800;font-size:clamp(1.5rem,4vw,2.1rem);letter-spacing:-.03em">
        Lo último del deporte en Atacama
      </h1>
    </div>

    <nav class="news-cats" aria-label="Categorías">
      <a href="/noticias" class="<?= $catFilter === '' ? 'is-active' : '' ?>">Todas</a>
      <?php foreach (NEWS_CATEGORIES as $cat): ?>
      <a href="<?= h(news_list_url(1, $cat)) ?>" class="<?= $catFilter === $cat ? 'is-active' : '' ?>"><?= h($cat) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($total === 0): ?>
    <div class="news-empty">
      <p>Pronto publicaremos las primeras noticias.</p>
      <a class="btn btn--primary" href="/#envivo" style="margin-top:12px">Ver transmisión en vivo</a>
    </div>
    <?php else: ?>

      <?php if ($featured): ?>
      <a class="news-featured" href="/noticias/<?= h((string)$featured['slug']) ?>">
        <div class="news-featured__media">
          <?php $fThumb = (string)($featured['cover']['card'] ?? $featured['cover']['full'] ?? $featured['cover']['og'] ?? ''); ?>
          <?php if ($fThumb !== ''): ?>
          <img src="/<?= h($fThumb) ?>" alt="<?= h((string)($featured['cover']['alt'] ?? $featured['title'] ?? '')) ?>" width="1200" height="675" fetchpriority="high" decoding="async">
          <?php endif; ?>
        </div>
        <div class="news-featured__body">
          <div class="news-featured__cat"><?= h((string)($featured['category'] ?? '')) ?></div>
          <h2 class="news-featured__title"><?= h((string)($featured['title'] ?? '')) ?></h2>
          <?php if (!empty($featured['excerpt'])): ?>
          <p class="news-featured__excerpt"><?= h((string)$featured['excerpt']) ?></p>
          <?php endif; ?>
          <div class="news-featured__meta"><?= h(news_format_date_es((string)($featured['published_at'] ?? ''))) ?></div>
        </div>
      </a>
      <?php endif; ?>

      <?php if ($grid): ?>
      <div class="news-grid">
        <?php foreach ($grid as $p): ?>
        <a class="news-card" href="/noticias/<?= h((string)$p['slug']) ?>">
          <div class="news-card__thumb">
            <?php $thumb = (string)($p['cover']['card'] ?? $p['cover']['og'] ?? ''); ?>
            <?php if ($thumb !== ''): ?>
            <img src="/<?= h($thumb) ?>" alt="" width="800" height="450" loading="lazy" decoding="async">
            <?php endif; ?>
          </div>
          <div class="news-card__body">
            <span class="news-card__cat"><?= h((string)($p['category'] ?? '')) ?></span>
            <h2 class="news-card__title"><?= h((string)($p['title'] ?? '')) ?></h2>
            <span class="news-card__meta"><?= h(news_format_date_es((string)($p['published_at'] ?? ''))) ?></span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($totalPages > 1): ?>
      <nav class="news-pagination" aria-label="Paginación">
        <?php if ($page > 1): ?>
        <a href="<?= h(news_list_url($page - 1, $catFilter)) ?>" rel="prev">← Anterior</a>
        <?php endif; ?>
        <span aria-current="page"><?= (int)$page ?> / <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a href="<?= h(news_list_url($page + 1, $catFilter)) ?>" rel="next">Siguiente →</a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <div class="site-footer__inner">
      <div class="site-footer__brand">
        <img class="logo__img logo__img--footer" src="/assets/img/logo.png" alt="MDTV+" width="120" height="120" loading="lazy" decoding="async">
        <p>La Señal Oficial del Deporte en Atacama</p>
      </div>
      <nav class="site-footer__nav" aria-label="Enlaces">
        <a href="/">Inicio</a>
        <a href="/noticias">Noticias</a>
        <a href="/#envivo">En Vivo</a>
        <a href="/#comercial">Anuncia Aquí</a>
      </nav>
      <p class="site-footer__copy">© <?= date('Y') ?> MDTV+. Todos los derechos reservados.</p>
    </div>
  </footer>
</body>
</html>

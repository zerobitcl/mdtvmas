<?php
/**
 * MDTV+ — Página de noticia (SSR para Open Graph / WhatsApp / Facebook)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/news.php';

/* Sesión solo para preview de borradores (mismo nombre que admin) */
$mdtvHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if ($mdtvHttps) {
    ini_set('session.cookie_secure', '1');
}
session_name('MDTVSTUDIO');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function noticia_is_admin(): bool
{
    return !empty($_SESSION['mdtv_studio_auth']);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Canonical redirect: noticia.php?slug=x → /noticias/x
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$slugParam = trim((string)($_GET['slug'] ?? ''));
if ($slugParam !== '' && preg_match('#/noticia\.php#', $requestUri)) {
    if (news_valid_slug($slugParam)) {
        header('Location: /noticias/' . $slugParam, true, 301);
        exit;
    }
}

$slug = $slugParam;
if (!news_valid_slug($slug)) {
    http_response_code(404);
    $notFound = true;
    $post = null;
    $isPreview = false;
} else {
    $post = news_find_by_slug($slug, false);
    $isPreview = false;
    $notFound = false;

    if ($post === null) {
        $notFound = true;
        http_response_code(404);
    } elseif (!news_is_visible($post)) {
        if (noticia_is_admin()) {
            $isPreview = true;
        } else {
            $notFound = true;
            $post = null;
            http_response_code(404);
        }
    }
}

if ($isPreview) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
} elseif (!$notFound) {
    header('Cache-Control: public, max-age=60');
}

$related = (!$notFound && $post) ? news_related($post, 3) : [];
$reading = (!$notFound && $post) ? news_reading_minutes((string)($post['body_html'] ?? '')) : 1;

$title = $notFound ? 'Noticia no encontrada | MDTV+' : ((string)$post['title'] . ' | MDTV+');
$excerpt = $notFound ? 'La noticia que buscas no existe o aún no está publicada.' : (string)($post['excerpt'] ?? '');
$canonical = $notFound ? news_abs_url('noticias') : news_public_url((string)$post['slug']);
$ogImage = (!$notFound && !empty($post['cover']['og']))
    ? news_abs_url((string)$post['cover']['og'])
    : news_abs_url('assets/img/logo.png');
$coverFull = (!$notFound) ? (string)($post['cover']['full'] ?? '') : '';
$coverCard = (!$notFound) ? (string)($post['cover']['card'] ?? '') : '';
$coverAlt = (!$notFound) ? (string)($post['cover']['alt'] ?? ($post['title'] ?? '')) : '';
$publishedAt = (!$notFound) ? (string)($post['published_at'] ?? '') : '';
$updatedAt = (!$notFound) ? (string)($post['updated_at'] ?? $publishedAt) : '';
$category = (!$notFound) ? (string)($post['category'] ?? '') : '';
$author = (!$notFound) ? (string)($post['author'] ?? NEWS_AUTHOR) : NEWS_AUTHOR;
$bodyHtml = (!$notFound) ? news_render_body((string)($post['body_html'] ?? '')) : '';

$jsonLd = null;
if (!$notFound && $post) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => (string)$post['title'],
        'description' => $excerpt,
        'image' => [$ogImage],
        'datePublished' => $publishedAt,
        'dateModified' => $updatedAt,
        'author' => [
            '@type' => 'Organization',
            'name' => $author,
        ],
        'publisher' => [
            '@id' => 'https://mdtvmas.cl/#organization',
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $canonical,
        ],
        'articleSection' => $category,
        'inLanguage' => 'es-CL',
    ];
}

function news_share_buttons(string $modifier = ''): void
{
    $isRail = ($modifier === 'share-rail');
    $cls = $isRail ? 'share-rail' : ('share-bar' . ($modifier !== '' ? ' ' . $modifier : ''));
    echo '<div class="' . h($cls) . '" role="group" aria-label="Compartir">';
    if (!$isRail && $modifier !== 'share-bar--sticky') {
        echo '<div class="share-bar__label">Compartir</div>';
    }
    echo '<a class="share-btn share-btn--wa" data-share="whatsapp" href="#" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><span>WhatsApp</span></a>';
    echo '<a class="share-btn share-btn--fb" data-share="facebook" href="#" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><span>Facebook</span></a>';
    echo '<a class="share-btn share-btn--x" data-share="x" href="#" target="_blank" rel="noopener noreferrer" aria-label="X"><span>X</span></a>';
    echo '<button type="button" class="share-btn" data-share="copy"><span>Copiar</span></button>';
    echo '<button type="button" class="share-btn" data-share="native"><span>Compartir</span></button>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($excerpt) ?>">
  <?php if ($isPreview || $notFound): ?>
  <meta name="robots" content="noindex, nofollow">
  <?php else: ?>
  <meta name="robots" content="index, follow">
  <?php endif; ?>
  <link rel="canonical" href="<?= h($canonical) ?>">
  <meta name="theme-color" content="#07090E">

  <meta property="og:type" content="<?= $notFound ? 'website' : 'article' ?>">
  <meta property="og:site_name" content="MDTV+">
  <meta property="og:title" content="<?= h($notFound ? 'Noticia no encontrada | MDTV+' : (string)$post['title']) ?>">
  <meta property="og:description" content="<?= h($excerpt) ?>">
  <meta property="og:url" content="<?= h($canonical) ?>">
  <meta property="og:locale" content="es_CL">
  <meta property="og:image" content="<?= h($ogImage) ?>">
  <meta property="og:image:secure_url" content="<?= h($ogImage) ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="<?= h($coverAlt !== '' ? $coverAlt : 'MDTV+') ?>">
  <?php if (!$notFound && $publishedAt !== ''): ?>
  <meta property="article:published_time" content="<?= h($publishedAt) ?>">
  <meta property="article:modified_time" content="<?= h($updatedAt) ?>">
  <meta property="article:section" content="<?= h($category) ?>">
  <?php endif; ?>

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($notFound ? 'MDTV+' : (string)$post['title']) ?>">
  <meta name="twitter:description" content="<?= h($excerpt) ?>">
  <meta name="twitter:image" content="<?= h($ogImage) ?>">

  <link rel="icon" type="image/png" href="/assets/img/favicon.png">
  <link rel="apple-touch-icon" href="/assets/img/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/news.css">
  <?php if (!$notFound && $coverFull !== ''): ?>
  <link rel="preload" as="image" href="/<?= h(ltrim($coverCard !== '' ? $coverCard : $coverFull, '/')) ?>" fetchpriority="high">
  <?php endif; ?>
  <?php if ($jsonLd): ?>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
</head>
<body>
  <?php if ($isPreview): ?>
  <div class="news-preview-banner">VISTA PREVIA — esta noticia no está publicada</div>
  <?php endif; ?>

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

  <?php if ($notFound): ?>
  <main class="news-404">
    <div>
      <h1>Noticia no encontrada</h1>
      <p>Puede haber sido retirada o aún no está publicada.</p>
      <a class="btn btn--primary" href="/noticias">Ver todas las noticias</a>
    </div>
  </main>
  <?php else: ?>
  <div class="article-layout">
    <aside aria-label="Compartir">
      <?php news_share_buttons('share-rail'); ?>
    </aside>

    <main class="article">
      <p class="article__eyebrow"><?= h($category) ?></p>
      <h1 class="article__title"><?= h((string)$post['title']) ?></h1>
      <?php if ($excerpt !== ''): ?>
      <p class="article__excerpt"><?= h($excerpt) ?></p>
      <?php endif; ?>
      <div class="article__meta">
        <span><?= h($author) ?></span>
        <?php if ($publishedAt !== ''): ?>
        <time datetime="<?= h($publishedAt) ?>"><?= h(news_format_date_es($publishedAt)) ?></time>
        <?php endif; ?>
        <span><?= (int)$reading ?> min de lectura</span>
      </div>

      <?php if ($coverFull !== '' || $coverCard !== ''): ?>
      <figure class="article__cover">
        <picture>
          <?php if ($coverCard !== '' && str_ends_with($coverCard, '.webp')): ?>
          <source type="image/webp" srcset="/<?= h($coverCard) ?>">
          <?php endif; ?>
          <img
            src="/<?= h($coverFull !== '' ? $coverFull : $coverCard) ?>"
            alt="<?= h($coverAlt) ?>"
            width="1600"
            height="900"
            fetchpriority="high"
            decoding="async"
          >
        </picture>
      </figure>
      <?php endif; ?>

      <?php news_share_buttons(); ?>

      <div class="article__body">
        <?= $bodyHtml ?>
      </div>

      <aside class="article-cta" aria-label="Ver transmisión en vivo">
        <h2 class="article-cta__title">Mira el próximo partido EN VIVO en MDTV+</h2>
        <p class="article-cta__text">La señal oficial del deporte en Atacama. Gratis, en vivo y en mdtvmas.cl.</p>
        <div class="article-cta__actions">
          <a class="btn btn--primary" href="/#envivo">Ir a En Vivo</a>
          <a class="btn btn--subscribe" href="https://www.youtube.com/@mdtvmas?sub_confirmation=1" target="_blank" rel="noopener noreferrer">Suscríbete en YouTube</a>
        </div>
      </aside>

      <?php if ($related): ?>
      <section class="article-related" aria-labelledby="related-heading">
        <h2 class="article-related__title" id="related-heading">Más noticias</h2>
        <div class="article-related__grid">
          <?php foreach ($related as $r): ?>
          <a class="news-card" href="/noticias/<?= h((string)$r['slug']) ?>">
            <div class="news-card__thumb">
              <?php
                $thumb = (string)($r['cover']['card'] ?? $r['cover']['og'] ?? '');
                if ($thumb !== ''):
              ?>
              <img src="/<?= h($thumb) ?>" alt="" width="800" height="450" loading="lazy" decoding="async">
              <?php endif; ?>
            </div>
            <div class="news-card__body">
              <span class="news-card__cat"><?= h((string)($r['category'] ?? '')) ?></span>
              <h3 class="news-card__title"><?= h((string)($r['title'] ?? '')) ?></h3>
              <span class="news-card__meta"><?= h(news_format_date_es((string)($r['published_at'] ?? ''))) ?></span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>

  <?php news_share_buttons('share-bar--sticky'); ?>
  <?php endif; ?>

  <footer class="site-footer" style="margin-top:40px">
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

  <script src="/assets/js/news.js" defer></script>
</body>
</html>

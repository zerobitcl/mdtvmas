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
    $ico = static function (string $path): string {
        return '<svg class="share-btn__ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="' . $path . '"/></svg>';
    };
    // Paths compactos (WhatsApp / Facebook / X / copy / share)
    $wa = 'M12.04 2C6.58 2 2.15 6.4 2.15 11.84c0 1.98.58 3.82 1.58 5.4L2 22l4.92-1.67a9.86 9.86 0 0 0 5.12 1.4h.01c5.46 0 9.89-4.4 9.89-9.84C21.94 6.4 17.5 2 12.04 2m0 17.94h-.01a8.1 8.1 0 0 1-4.13-1.13l-.3-.18-3.05 1.01 1.03-2.97-.2-.31a8.08 8.08 0 0 1-1.24-4.32c0-4.48 3.67-8.12 8.19-8.12 4.5 0 8.18 3.64 8.18 8.12 0 4.48-3.68 8.1-8.18 8.1m4.49-6.07c-.25-.12-1.46-.72-1.69-.8-.22-.09-.39-.12-.55.12-.16.25-.63.8-.77.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.15-.25-.02-.39.11-.51.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.55-1.33-.76-1.82-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.43.06-.65.31-.22.25-.86.84-.86 2.05s.88 2.38 1 2.55c.12.17 1.75 2.67 4.24 3.74 1.49.64 2.08.7 2.82.59.43-.07 1.46-.6 1.66-1.17.21-.58.21-1.07.14-1.17-.06-.11-.23-.17-.48-.29';
    $fb = 'M14 8.5h2.5V5H14c-2.2 0-4 1.8-4 4v1.5H7.5V14H10v6h3.5v-6H16l.5-3.5H13.5V9c0-.3.2-.5.5-.5z';
    $x = 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.727-8.835L1.254 2.25H8.08l4.253 5.622L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z';
    $copy = 'M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z';
    $share = 'M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z';

    echo '<div class="' . h($cls) . '" role="group" aria-label="Compartir">';
    if (!$isRail && $modifier !== 'share-bar--sticky') {
        echo '<div class="share-bar__label">Compartir</div>';
    }
    echo '<a class="share-btn share-btn--wa" data-share="whatsapp" href="#" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">' . $ico($wa) . '<span>WhatsApp</span></a>';
    echo '<a class="share-btn share-btn--fb" data-share="facebook" href="#" target="_blank" rel="noopener noreferrer" aria-label="Facebook">' . $ico($fb) . '<span>Facebook</span></a>';
    echo '<a class="share-btn share-btn--x" data-share="x" href="#" target="_blank" rel="noopener noreferrer" aria-label="X">' . $ico($x) . '<span>X</span></a>';
    echo '<button type="button" class="share-btn" data-share="copy" aria-label="Copiar link">' . $ico($copy) . '<span>Copiar</span></button>';
    echo '<button type="button" class="share-btn" data-share="native" aria-label="Compartir">' . $ico($share) . '<span>Compartir</span></button>';
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

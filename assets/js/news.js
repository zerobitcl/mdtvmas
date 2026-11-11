/**
 * MDTV+ Noticias — compartir + fachada YouTube
 */
(() => {
  "use strict";

  function $(sel, root = document) {
    return root.querySelector(sel);
  }

  function $$(sel, root = document) {
    return [...root.querySelectorAll(sel)];
  }

  function canonicalUrl() {
    const link = document.querySelector('link[rel="canonical"]');
    return link?.href || window.location.href.split("?")[0].split("#")[0];
  }

  function withUtm(base, source) {
    const u = new URL(base);
    u.searchParams.set("utm_source", source);
    u.searchParams.set("utm_medium", "social");
    u.searchParams.set("utm_campaign", "noticias");
    return u.toString();
  }

  function toast(msg) {
    let el = $("#news-toast");
    if (!el) {
      el = document.createElement("div");
      el.id = "news-toast";
      el.setAttribute("role", "status");
      el.style.cssText =
        "position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:90;background:#053820;border:1px solid rgba(0,230,118,.55);color:#b8ffd6;padding:12px 16px;border-radius:12px;font-weight:700;font-size:.85rem;opacity:0;transition:opacity .25s;pointer-events:none;max-width:90vw;text-align:center";
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.opacity = "1";
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
      el.style.opacity = "0";
    }, 2200);
  }

  async function copyUrl(url) {
    try {
      await navigator.clipboard.writeText(url);
      toast("Link copiado");
    } catch {
      prompt("Copia el link:", url);
    }
  }

  function bindShare() {
    const title = document.title.replace(/\s*\|\s*MDTV\+.*$/, "") || "MDTV+";
    const base = canonicalUrl();

    $$("[data-share]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        const kind = btn.getAttribute("data-share");
        if (kind === "copy") {
          e.preventDefault();
          copyUrl(base);
          return;
        }
        if (kind === "native") {
          e.preventDefault();
          if (navigator.share) {
            try {
              await navigator.share({ title, url: base, text: title });
            } catch {
              /* cancelled */
            }
          } else {
            copyUrl(base);
          }
          return;
        }
        // Anchors with href already set for WA/FB/X
      });
    });

    // Hide native share if unsupported
    if (!navigator.share) {
      $$('[data-share="native"]').forEach((b) => {
        b.hidden = true;
      });
    }

    // Fill social hrefs
    const wa = withUtm(base, "whatsapp");
    const fb = withUtm(base, "facebook");
    const x = withUtm(base, "x");
    $$('a[data-share="whatsapp"]').forEach((a) => {
      a.href =
        "https://wa.me/?text=" + encodeURIComponent(title + " " + wa);
    });
    $$('a[data-share="facebook"]').forEach((a) => {
      a.href =
        "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(fb);
    });
    $$('a[data-share="x"]').forEach((a) => {
      a.href =
        "https://twitter.com/intent/tweet?url=" +
        encodeURIComponent(x) +
        "&text=" +
        encodeURIComponent(title);
    });
  }

  function bindYoutubeFacades() {
    $$(".yt-facade").forEach((fig) => {
      const id = fig.getAttribute("data-yt");
      if (!id || !/^[a-zA-Z0-9_-]{11}$/.test(id)) return;
      const btn = fig.querySelector(".yt-facade__btn");
      if (!btn) return;
      btn.addEventListener("click", () => {
        const iframe = document.createElement("iframe");
        iframe.src =
          "https://www.youtube-nocookie.com/embed/" +
          encodeURIComponent(id) +
          "?autoplay=1&rel=0";
        iframe.title = "YouTube";
        iframe.allow =
          "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
        iframe.allowFullscreen = true;
        fig.innerHTML = "";
        fig.appendChild(iframe);
      });
    });
  }

  function init() {
    bindShare();
    bindYoutubeFacades();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

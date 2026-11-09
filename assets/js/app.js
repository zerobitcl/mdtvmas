/**
 * MDTV+ — Broadcast Platform Phase 1
 * Vanilla ES6+ · config-driven · demo-ready
 */

(() => {
  "use strict";

  /* ------------------------------------------------------------------
   * Fallback config (file:// / offline safety)
   * Must mirror data/config.json exactly for local demos
   * ------------------------------------------------------------------ */
  const FALLBACK_CONFIG = {
    channel_info: {
      name: "MDTV+",
      tagline: "La Señal Oficial del Deporte en Atacama",
      whatsapp: "+56962397613",
      email_contacto: "contacto@mdtvmas.cl",
      email_ventas: "ventas@mdtvmas.cl",
      youtube_handle: "@mdtvmas",
      youtube_url: "https://www.youtube.com/@mdtvmas",
      youtube_subscribe: "https://www.youtube.com/@mdtvmas?sub_confirmation=1",
      instagram: "https://www.instagram.com/md.tvmas/",
      facebook: "https://www.facebook.com/profile.php?id=61561203236561",
      website: "https://mdtvmas.cl",
      region: "Región de Atacama, Chile",
    },
    live_stream: {
      is_live: true,
      youtube_id: "jfKfPfyJRdk",
      match_title: "Deportes Vallenar vs Municipal Mejillones",
      tournament: "Tercera División B - Grupo Norte | Fecha 14",
      stadium: "Estadio Municipal Nelson Rojas, Vallenar",
      home_team: {
        name: "Deportes Vallenar",
        short: "VAL",
        color: "#00843D",
        score: 2,
      },
      away_team: {
        name: "Mun. Mejillones",
        short: "MEJ",
        color: "#0038A8",
        score: 1,
      },
      match_status_badge: "EN VIVO • 2° TIEMPO",
      next_match_iso: "2026-11-08T16:00:00-03:00",
      next_match_title: "Deportes Copiapó B vs Atlético Copiapó",
    },
    sponsors: [
      {
        name: "MD Climatización",
        category: "Presentador Oficial",
        cta: "Venta, mantención y reparación de aire acondicionado. Cotiza hoy.",
        phone: "+56962397613",
        whatsapp:
          "https://wa.me/56962397613?text=Hola%2C%20vi%20MD%20Climatizaci%C3%B3n%20en%20MDTV%2B%20y%20quiero%20cotizar",
        logo_url: "assets/img/sponsor-md-climatizacion.jpg",
        tier: "presenter",
      },
      {
        name: "Ilustre Municipalidad de Vallenar",
        category: "Auspiciador Gold",
        cta: "Apoyando el deporte local de Atacama.",
        phone: "",
        whatsapp: "",
        tier: "gold",
      },
      {
        name: "Comercial Atacama",
        category: "Auspiciador Gold",
        cta: "Todo para tu negocio y tu familia en la Región.",
        phone: "",
        whatsapp: "",
        tier: "gold",
      },
      {
        name: "Espacio Disponible",
        category: "Anuncia Aquí",
        cta: "Tu marca puede aparecer aquí ante miles de hinchas.",
        phone: "",
        whatsapp:
          "https://wa.me/56962397613?text=Hola%20MDTV%2B%2C%20quiero%20anunciar%20mi%20marca%20en%20las%20transmisiones%20en%20vivo",
        tier: "available",
      },
    ],
    upcoming_matches: [
      {
        date: "2026-11-08",
        time: "16:00",
        home: "Deportes Copiapó B",
        away: "Atlético Copiapó",
        tournament: "Tercera División B - Grupo Norte",
        stadium: "Estadio Luis Valenzuela Hermosilla, Copiapó",
        iso: "2026-11-08T16:00:00-03:00",
      },
      {
        date: "2026-11-15",
        time: "15:30",
        home: "Provincial Ovalle",
        away: "Deportes Vallenar",
        tournament: "Tercera División B - Grupo Norte",
        stadium: "Estadio Diaguita, Ovalle",
        iso: "2026-11-15T15:30:00-03:00",
      },
      {
        date: "2026-11-22",
        time: "17:00",
        home: "Municipal Mejillones",
        away: "Deportes Tocopilla",
        tournament: "Tercera División B - Grupo Norte",
        stadium: "Estadio Municipal de Mejillones",
        iso: "2026-11-22T17:00:00-03:00",
      },
    ],
    vod_matches: [
      {
        title: "Deportes Vallenar 3-1 Provincial Ovalle",
        date: "2026-10-25",
        tournament: "Tercera División B - Fecha 12",
        thumbnail:
          "https://images.unsplash.com/photo-1574629810360-7efbbe195018?w=800&q=80",
        youtube_id: "jfKfPfyJRdk",
      },
      {
        title: "Mun. Mejillones 2-2 Deportes Copiapó B",
        date: "2026-10-18",
        tournament: "Tercera División B - Fecha 11",
        thumbnail:
          "https://images.unsplash.com/photo-1459865264687-595d652de67e?w=800&q=80",
        youtube_id: "jfKfPfyJRdk",
      },
      {
        title: "Atlético Copiapó 0-2 Deportes Vallenar",
        date: "2026-10-11",
        tournament: "Tercera División B - Fecha 10",
        thumbnail:
          "https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=800&q=80",
        youtube_id: "jfKfPfyJRdk",
      },
      {
        title: "Deportes Tocopilla 1-4 Mun. Mejillones",
        date: "2026-10-04",
        tournament: "Tercera División B - Fecha 9",
        thumbnail:
          "https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=800&q=80",
        youtube_id: "jfKfPfyJRdk",
      },
    ],
  };

  /* ------------------------------------------------------------------
   * State
   * ------------------------------------------------------------------ */
  const state = {
    config: null,
    isLive: true,
    theaterMode: false,
    chatHidden: false,
    watchingReplay: false,
    replayTitle: "",
    countdownTimer: null,
    syncTimer: null,
    activeYoutubeId: null,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const MONTHS_ES = [
    "ENE", "FEB", "MAR", "ABR", "MAY", "JUN",
    "JUL", "AGO", "SEP", "OCT", "NOV", "DIC",
  ];

  /* ------------------------------------------------------------------
   * Data load
   * ------------------------------------------------------------------ */
  async function loadConfig() {
    try {
      const res = await fetch(`data/config.json?t=${Date.now()}`, {
        cache: "no-store",
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      return data;
    } catch (err) {
      console.warn("[MDTV+] Usando fallback local:", err.message);
      return structuredClone
        ? structuredClone(FALLBACK_CONFIG)
        : JSON.parse(JSON.stringify(FALLBACK_CONFIG));
    }
  }

  /* ------------------------------------------------------------------
   * YouTube helpers
   * ------------------------------------------------------------------ */
  function ytEmbedUrl(videoId, { autoplay = 0 } = {}) {
    const params = new URLSearchParams({
      autoplay: String(autoplay),
      mute: autoplay ? "1" : "0",
      rel: "0",
      modestbranding: "1",
      playsinline: "1",
      enablejsapi: "1",
    });
    try {
      if (window.location.origin && window.location.origin !== "null") {
        params.set("origin", window.location.origin);
      }
    } catch {
      /* ignore */
    }
    return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(videoId)}?${params.toString()}`;
  }

  function ytChatUrl(videoId) {
    const domain = window.location.hostname || "mdtvmas.cl";
    return `https://www.youtube.com/live_chat?v=${videoId}&embed_domain=${domain}`;
  }

  /* ------------------------------------------------------------------
   * Render: ticker + scorebug + hero meta
   * ------------------------------------------------------------------ */
  function renderTicker() {
    const status = $("#ticker-status");
    if (!status) return;
    const text = status.querySelector(".ticker__status-text");
    const dot = status.querySelector(".live-dot");

    if (state.isLive && !state.watchingReplay) {
      text.textContent = "SEÑAL EN VIVO";
      dot.classList.remove("live-dot--standby");
    } else if (state.watchingReplay) {
      text.textContent = "VIENDO REPETICIÓN";
      dot.classList.add("live-dot--standby");
    } else {
      text.textContent = "PRÓXIMA TRANSMISIÓN";
      dot.classList.add("live-dot--standby");
    }
  }

  function waDigits(raw) {
    const fromUrl = String(raw || "").match(/wa\.me\/(\d+)/);
    if (fromUrl) return fromUrl[1];
    return String(raw || "").replace(/\D/g, "") || "56962397613";
  }

  function formatPhoneLabel(raw) {
    const digits = waDigits(raw);
    if (digits.length >= 11 && digits.startsWith("56")) {
      const local = digits.slice(2); // 9XXXXXXXX
      if (local.length === 9 && local.startsWith("9")) {
        return `+56 9 ${local.slice(1, 5)} ${local.slice(5)}`;
      }
      return `+${digits}`;
    }
    return digits ? `+${digits}` : "";
  }

  function sponsorContactHref(sponsor) {
    const wa = String(sponsor?.whatsapp || "").trim();
    if (wa) return wa;
    const phone = waDigits(sponsor?.phone || "");
    return phone ? `https://wa.me/${phone}` : "";
  }

  function sponsorContactLabel(sponsor) {
    // Solo teléfono propio del auspiciador (campo phone). "Espacio disponible" usa otro texto.
    if (sponsor?.tier === "available") return "Anuncia aquí";
    const phone = formatPhoneLabel(sponsor?.phone || "");
    return phone ? `Contacto · ${phone}` : "Contacto";
  }

  function applyChannelLinks() {
    const info = state.config?.channel_info || {};
    const wa = waDigits(info.whatsapp);
    const ytSub =
      info.youtube_subscribe ||
      "https://www.youtube.com/@mdtvmas?sub_confirmation=1";
    const ig = info.instagram || "https://www.instagram.com/md.tvmas/";
    const fb =
      info.facebook ||
      "https://www.facebook.com/profile.php?id=61561203236561";
    const mailC = info.email_contacto || "contacto@mdtvmas.cl";
    const mailV = info.email_ventas || "ventas@mdtvmas.cl";
    const phoneDisplay = `+${wa.replace(/^56/, "56 ").replace(/^56 9/, "56 9 ")}`;

    $$('[data-social="youtube-sub"]').forEach((el) => {
      el.href = ytSub;
    });
    $$('[data-social="instagram"]').forEach((el) => {
      el.href = ig;
    });
    $$('[data-social="facebook"]').forEach((el) => {
      el.href = fb;
    });
    $$('[data-social="whatsapp"]').forEach((el) => {
      el.href = `https://wa.me/${wa}`;
    });

    const comercial = $("#comercial-wa");
    if (comercial) {
      comercial.href = `https://wa.me/${wa}?text=${encodeURIComponent(
        "Hola MDTV+, quiero cotizar publicidad en las transmisiones"
      )}`;
    }

    const phoneEl = $('[data-contact="phone"]');
    if (phoneEl) {
      phoneEl.href = `tel:+${wa}`;
      phoneEl.textContent = phoneDisplay.includes("9")
        ? `+56 9 ${wa.slice(-8).replace(/(\d{4})(\d{4})/, "$1 $2")}`
        : `+${wa}`;
    }
    const mailCEl = $('[data-contact="email-contacto"]');
    if (mailCEl) {
      mailCEl.href = `mailto:${mailC}`;
      mailCEl.textContent = mailC;
    }
    const mailVEl = $('[data-contact="email-ventas"]');
    if (mailVEl) {
      mailVEl.href = `mailto:${mailV}`;
      mailVEl.textContent = mailV;
    }
  }

  function renderScorebug() {
    const ls = state.config.live_stream;
    const home = ls.home_team;
    const away = ls.away_team;

    $("#match-title").textContent = ls.match_title;
    $("#tournament-badge").textContent = ls.tournament;

    const homeCrest = $("#home-crest");
    const awayCrest = $("#away-crest");
    homeCrest.textContent = home.short;
    awayCrest.textContent = away.short;
    homeCrest.style.setProperty("--team-color", home.color);
    awayCrest.style.setProperty("--team-color", away.color);

    $("#home-name").textContent = home.name;
    $("#away-name").textContent = away.name;
    $("#home-score").textContent = state.isLive ? home.score : "–";
    $("#away-score").textContent = state.isLive ? away.score : "–";
    $("#match-stadium").textContent = ls.stadium;

    const badge = $("#match-badge");
    if (state.isLive) {
      badge.textContent = ls.match_status_badge;
      badge.classList.remove("is-upcoming");
    } else {
      badge.textContent = "PRÓXIMO PARTIDO";
      badge.classList.add("is-upcoming");
    }
  }

  /* ------------------------------------------------------------------
   * Player: live iframe OR countdown standby
   * ------------------------------------------------------------------ */
  function clearCountdown() {
    if (state.countdownTimer) {
      clearInterval(state.countdownTimer);
      state.countdownTimer = null;
    }
  }

  function getCountdownParts(targetIso) {
    const target = new Date(targetIso).getTime();
    const now = Date.now();
    let diff = Math.max(0, target - now);

    const days = Math.floor(diff / 86400000);
    diff %= 86400000;
    const hours = Math.floor(diff / 3600000);
    diff %= 3600000;
    const minutes = Math.floor(diff / 60000);
    diff %= 60000;
    const seconds = Math.floor(diff / 1000);

    return { days, hours, minutes, seconds, finished: target - now <= 0 };
  }

  function pad(n) {
    return String(n).padStart(2, "0");
  }

  function updateCountdownValues() {
    const ls = state.config.live_stream;
    const parts = getCountdownParts(ls.next_match_iso);
    const map = {
      days: parts.days,
      hours: parts.hours,
      minutes: parts.minutes,
      seconds: parts.seconds,
    };

    Object.entries(map).forEach(([key, val]) => {
      const el = $(`#cd-${key}`);
      if (!el) return;
      const next = key === "days" ? String(val) : pad(val);
      if (el.textContent !== next) {
        el.textContent = next;
        el.style.animation = "none";
        void el.offsetWidth;
        el.style.animation = "";
      }
    });

    if (parts.finished) {
      clearCountdown();
    }
  }

  function renderStandby() {
    const ls = state.config.live_stream;
    const home = ls.home_team;
    const away = ls.away_team;
    const wrap = $("#player-wrap");

    wrap.innerHTML = `
      <div class="standby" role="status" aria-live="polite">
        <p class="standby__label">
          <span class="live-dot live-dot--standby" aria-hidden="true"></span>
          Señal en Espera · Próxima Transmisión
        </p>
        <div class="standby__teams">
          <span class="standby__crest" style="--team-color:${home.color}">${home.short}</span>
          <span class="standby__vs">VS</span>
          <span class="standby__crest" style="--team-color:${away.color}">${away.short}</span>
        </div>
        <p class="standby__title">${escapeHtml(ls.next_match_title || ls.match_title)}</p>
        <div class="countdown" aria-label="Cuenta regresiva">
          <div class="countdown__unit">
            <span class="countdown__value" id="cd-days">0</span>
            <span class="countdown__label">Días</span>
          </div>
          <div class="countdown__unit">
            <span class="countdown__value" id="cd-hours">00</span>
            <span class="countdown__label">Horas</span>
          </div>
          <div class="countdown__unit">
            <span class="countdown__value" id="cd-minutes">00</span>
            <span class="countdown__label">Min</span>
          </div>
          <div class="countdown__unit">
            <span class="countdown__value" id="cd-seconds">00</span>
            <span class="countdown__label">Seg</span>
          </div>
        </div>
      </div>
    `;

    clearCountdown();
    updateCountdownValues();
    state.countdownTimer = setInterval(updateCountdownValues, 1000);
  }

  function renderLivePlayer(videoId, { autoplay = 0, force = false } = {}) {
    clearCountdown();
    const wrap = $("#player-wrap");
    const sameVideo =
      !force &&
      state.activeYoutubeId === videoId &&
      wrap.querySelector("iframe");

    if (sameVideo) {
      return;
    }

    state.activeYoutubeId = videoId;
    wrap.innerHTML = `
      <iframe
        src="${ytEmbedUrl(videoId, { autoplay })}"
        title="Transmisión MDTV+"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen
        loading="eager"
        referrerpolicy="strict-origin-when-cross-origin"
      ></iframe>
    `;
  }

  function renderPlayer({ forceRemount = false } = {}) {
    const ls = state.config.live_stream;
    const banner = $("#replay-banner");

    if (state.watchingReplay) {
      banner.hidden = false;
      $("#replay-title").textContent = state.replayTitle;
      return;
    }

    banner.hidden = true;

    if (state.isLive) {
      renderLivePlayer(ls.youtube_id, { autoplay: 0, force: forceRemount });
    } else {
      state.activeYoutubeId = null;
      renderStandby();
    }

    updateChatFrame();
  }

  function updateChatFrame() {
    const ls = state.config.live_stream;
    const iframe = $("#yt-chat");
    const placeholder = $("#chat-placeholder");
    const link = $("#chat-yt-link");
    const chatUrl = ytChatUrl(ls.youtube_id);

    if (link) link.href = chatUrl;

    if (state.isLive && !state.watchingReplay) {
      if (iframe) {
        iframe.src = chatUrl;
        iframe.hidden = false;
      }
      if (placeholder) placeholder.hidden = true;
    } else {
      if (iframe) {
        iframe.removeAttribute("src");
        iframe.hidden = true;
      }
      if (placeholder) {
        placeholder.hidden = false;
        const title = placeholder.querySelector(".chat-placeholder__title");
        const text = placeholder.querySelector(".chat-placeholder__text");
        if (title) title.textContent = "Chat disponible en vivo";
        if (text) {
          text.textContent =
            "Cuando arranque la transmisión, el chat de la hinchada se activa aquí. Mientras tanto, síguenos en YouTube.";
        }
      }
    }
  }

  /* ------------------------------------------------------------------
   * Ficha + Sponsors + Upcoming + VOD
   * ------------------------------------------------------------------ */
  function renderFicha() {
    const ls = state.config.live_stream;
    const presenter =
      state.config.sponsors.find((s) => s.tier === "presenter") ||
      state.config.sponsors[0];
    const el = $("#ficha-content");
    if (!el) return;

    el.innerHTML = `
      <div class="ficha__match">
        <dl>
          <div class="ficha__row"><dt>Torneo</dt><dd>${escapeHtml(ls.tournament)}</dd></div>
          <div class="ficha__row"><dt>Estadio</dt><dd>${escapeHtml(ls.stadium)}</dd></div>
          <div class="ficha__row"><dt>Local</dt><dd>${escapeHtml(ls.home_team.name)}</dd></div>
          <div class="ficha__row"><dt>Visita</dt><dd>${escapeHtml(ls.away_team.name)}</dd></div>
          <div class="ficha__row"><dt>Estado</dt><dd>${state.isLive ? escapeHtml(ls.match_status_badge) : "Próximo partido"}</dd></div>
        </dl>
      </div>
      <div class="ficha__sponsor">
        <p class="ficha__sponsor-label">${escapeHtml(presenter.category)}</p>
        <p class="ficha__sponsor-name">${escapeHtml(presenter.name)}</p>
        <p class="ficha__sponsor-cta">${escapeHtml(presenter.cta)}</p>
        ${
          sponsorContactHref(presenter)
            ? `<a class="btn btn--primary btn--sm" href="${escapeAttr(sponsorContactHref(presenter))}" target="_blank" rel="noopener noreferrer">${escapeHtml(sponsorContactLabel(presenter))}</a>`
            : ""
        }
      </div>
    `;
  }

  function renderSponsors() {
    const grid = $("#sponsors-grid");
    if (!grid) return;

    grid.innerHTML = state.config.sponsors
      .map((s) => {
        const avail = s.tier === "available" ? " sponsor-card--available" : "";
        const presenter = s.tier === "presenter" ? " sponsor-card--presenter" : "";
        const hasLogo = Boolean(s.logo_url);
        const contactHref = sponsorContactHref(s);
        const logoBlock = hasLogo
          ? `<div class="sponsor-card__logo-wrap">
               <img class="sponsor-card__logo" src="${escapeAttr(s.logo_url)}" alt="${escapeAttr(s.name)}" loading="lazy" width="240" height="80">
             </div>`
          : `<div class="sponsor-card__mark" aria-hidden="true">${escapeHtml(
              (s.name || "MD").trim().slice(0, 2).toUpperCase()
            )}</div>`;
        const contactBlock = contactHref
          ? `<a class="sponsor-card__link" href="${escapeAttr(contactHref)}" target="_blank" rel="noopener noreferrer">
              ${escapeHtml(sponsorContactLabel(s))} →
            </a>`
          : s.tier === "available"
            ? `<a class="sponsor-card__link" href="#comercial">Cotiza tu espacio →</a>`
            : "";

        return `
          <article class="sponsor-card${avail}${presenter}${hasLogo ? " sponsor-card--has-logo" : ""}">
            ${logoBlock}
            <p class="sponsor-card__tier">${escapeHtml(s.category)}</p>
            <h3 class="sponsor-card__name">${escapeHtml(s.name)}</h3>
            <p class="sponsor-card__cta">${escapeHtml(s.cta)}</p>
            ${contactBlock}
          </article>
        `;
      })
      .join("");
  }

  function formatTicketDate(isoOrDate) {
    const d = new Date(isoOrDate.includes("T") ? isoOrDate : `${isoOrDate}T12:00:00-03:00`);
    return {
      day: String(d.getDate()).padStart(2, "0"),
      month: MONTHS_ES[d.getMonth()],
    };
  }

  function renderUpcoming() {
    const grid = $("#upcoming-grid");
    if (!grid) return;

    grid.innerHTML = state.config.upcoming_matches
      .map((m) => {
        const { day, month } = formatTicketDate(m.iso || m.date);
        return `
          <article class="ticket">
            <div class="ticket__date">
              <span class="ticket__day">${day}</span>
              <span class="ticket__month">${month}</span>
            </div>
            <div class="ticket__body">
              <p class="ticket__time">${escapeHtml(m.time)} hrs · Chile</p>
              <h3 class="ticket__match">${escapeHtml(m.home)} vs ${escapeHtml(m.away)}</h3>
              <p class="ticket__meta">${escapeHtml(m.tournament)}</p>
              <p class="ticket__meta">${escapeHtml(m.stadium)}</p>
            </div>
          </article>
        `;
      })
      .join("");
  }

  function formatVodDate(dateStr) {
    const d = new Date(`${dateStr}T12:00:00-03:00`);
    return d.toLocaleDateString("es-CL", {
      day: "numeric",
      month: "short",
      year: "numeric",
    });
  }

  function renderVod() {
    const grid = $("#vod-grid");
    if (!grid) return;

    grid.innerHTML = state.config.vod_matches
      .map(
        (v, i) => `
        <button type="button" class="vod-card" data-vod-index="${i}" aria-label="Ver repetición: ${escapeAttr(v.title)}">
          <div class="vod-card__thumb">
            <img src="${escapeAttr(v.thumbnail)}" alt="" loading="lazy" width="800" height="450">
            <div class="vod-card__play" aria-hidden="true">
              <span class="vod-card__play-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
              </span>
            </div>
          </div>
          <div class="vod-card__body">
            <p class="vod-card__date">${formatVodDate(v.date)}</p>
            <h3 class="vod-card__title">${escapeHtml(v.title)}</h3>
            <p class="vod-card__tournament">${escapeHtml(v.tournament)}</p>
          </div>
        </button>
      `
      )
      .join("");
  }

  /* ------------------------------------------------------------------
   * Interactions
   * ------------------------------------------------------------------ */
  function toggleTheater() {
    state.theaterMode = !state.theaterMode;
    const grid = $("#broadcast-grid");
    const btn = $("#btn-theater");
    grid.classList.toggle("theater-mode", state.theaterMode);
    if (btn) btn.setAttribute("aria-pressed", String(state.theaterMode));

    if (state.theaterMode) {
      $("#player-stage")?.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  }

  function toggleChat() {
    state.chatHidden = !state.chatHidden;
    const grid = $("#broadcast-grid");
    const btn = $("#btn-toggle-chat");
    const label = $("#chat-toggle-label");
    grid.classList.toggle("chat-hidden", state.chatHidden);
    if (btn) btn.setAttribute("aria-pressed", String(state.chatHidden));
    if (label) label.textContent = state.chatHidden ? "Mostrar Chat" : "Ocultar Chat";
  }

  function shareWhatsApp() {
    const ls = state.config.live_stream;
    const url = state.config.channel_info.website || window.location.href;
    const text = `¡Estoy viendo en vivo ${ls.home_team.name} vs ${ls.away_team.name} por la señal oficial de MDTV+! Míralo gratis aquí: ${url}`;
    const shareUrl = `https://wa.me/?text=${encodeURIComponent(text)}`;
    window.open(shareUrl, "_blank", "noopener,noreferrer");
  }

  function playVod(index) {
    const vod = state.config.vod_matches[index];
    if (!vod || !vod.youtube_id) return;

    state.watchingReplay = true;
    state.replayTitle = vod.title;
    state.isLive = false;
    state.activeYoutubeId = null;

    // force + mute: remonta siempre y permite autoplay en móviles/dominio temporal
    renderLivePlayer(vod.youtube_id, { autoplay: 1, force: true });
    const banner = $("#replay-banner");
    if (banner) banner.hidden = false;
    const titleEl = $("#replay-title");
    if (titleEl) titleEl.textContent = vod.title;
    renderTicker();
    updateChatFrame();

    $("#envivo")?.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function exitReplay() {
    state.watchingReplay = false;
    state.replayTitle = "";
    state.isLive = Boolean(state.config.live_stream.is_live);

    renderTicker();
    renderScorebug();
    renderPlayer({ forceRemount: true });
    renderFicha();
  }

  /* ------------------------------------------------------------------
   * Live sync from Studio (admin.php → config.json) every 15s
   * Updates scorebug / badge / go-live without killing YouTube playback
   * ------------------------------------------------------------------ */
  function shallowTeamEqual(a, b) {
    return (
      a &&
      b &&
      a.name === b.name &&
      a.short === b.short &&
      a.color === b.color &&
      Number(a.score) === Number(b.score)
    );
  }

  function applyRemoteConfig(remote) {
    if (!remote || !remote.live_stream) return;

    const prev = state.config.live_stream;
    const next = remote.live_stream;
    const wasLive = Boolean(prev.is_live);
    const nowLive = Boolean(next.is_live);

    const scoreChanged =
      Number(prev.home_team?.score) !== Number(next.home_team?.score) ||
      Number(prev.away_team?.score) !== Number(next.away_team?.score);

    const badgeChanged = prev.match_status_badge !== next.match_status_badge;
    const metaChanged =
      prev.match_title !== next.match_title ||
      prev.tournament !== next.tournament ||
      prev.stadium !== next.stadium ||
      prev.next_match_iso !== next.next_match_iso ||
      prev.next_match_title !== next.next_match_title ||
      !shallowTeamEqual(prev.home_team, next.home_team) ||
      !shallowTeamEqual(prev.away_team, next.away_team);

    const youtubeChanged = prev.youtube_id !== next.youtube_id;
    const wentLive = !wasLive && nowLive;
    const wentStandby = wasLive && !nowLive;

    const sponsorsChanged =
      JSON.stringify(state.config.sponsors) !== JSON.stringify(remote.sponsors);
    const upcomingChanged =
      JSON.stringify(state.config.upcoming_matches) !==
      JSON.stringify(remote.upcoming_matches);
    const vodChanged =
      JSON.stringify(state.config.vod_matches) !==
      JSON.stringify(remote.vod_matches);

    const channelChanged =
      JSON.stringify(state.config.channel_info) !==
      JSON.stringify(remote.channel_info);

    if (
      !scoreChanged &&
      !badgeChanged &&
      !metaChanged &&
      !youtubeChanged &&
      !wentLive &&
      !wentStandby &&
      !sponsorsChanged &&
      !upcomingChanged &&
      !vodChanged &&
      !channelChanged
    ) {
      return;
    }

    state.config = remote;

    if (!state.watchingReplay) {
      state.isLive = nowLive;
    }

    if (scoreChanged || badgeChanged || metaChanged || wentLive || wentStandby) {
      renderScorebug();
      renderTicker();
      renderFicha();
    }

    if (state.watchingReplay) {
      // Hincha viendo VOD: no cortar el video; solo marcador/ficha ya actualizados
    } else if (wentLive || (nowLive && youtubeChanged)) {
      renderPlayer({ forceRemount: youtubeChanged || wentLive });
    } else if (wentStandby) {
      renderPlayer({ forceRemount: true });
    } else if (!nowLive && metaChanged) {
      // Countdown target / títulos en standby
      renderPlayer();
    }

    if (sponsorsChanged) renderSponsors();
    if (upcomingChanged) renderUpcoming();
    if (vodChanged) renderVod();
    if (channelChanged) applyChannelLinks();
  }

  async function pollRemoteConfig() {
    if (document.visibilityState !== "visible") return;
    try {
      const res = await fetch(`data/config.json?t=${Date.now()}`, {
        cache: "no-store",
      });
      if (!res.ok) return;
      const remote = await res.json();
      applyRemoteConfig(remote);
    } catch {
      // Silencioso: fallos de red no interrumpen la señal
    }
  }

  function startLiveSync() {
    if (state.syncTimer) clearInterval(state.syncTimer);
    state.syncTimer = setInterval(pollRemoteConfig, 15000);

    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") {
        pollRemoteConfig();
      }
    });
  }

  function setupTabs() {
    const tabChat = $("#tab-chat");
    const tabFicha = $("#tab-ficha");
    const panelChat = $("#panel-chat");
    const panelFicha = $("#panel-ficha");

    function activate(which) {
      const chat = which === "chat";
      tabChat.classList.toggle("is-active", chat);
      tabFicha.classList.toggle("is-active", !chat);
      tabChat.setAttribute("aria-selected", String(chat));
      tabFicha.setAttribute("aria-selected", String(!chat));
      panelChat.classList.toggle("is-active", chat);
      panelFicha.classList.toggle("is-active", !chat);
      panelChat.hidden = !chat;
      panelFicha.hidden = chat;
    }

    tabChat?.addEventListener("click", () => activate("chat"));
    tabFicha?.addEventListener("click", () => activate("ficha"));
  }

  function setupNav() {
    const toggle = $("#nav-toggle");
    const mobile = $("#mobile-nav");

    toggle?.addEventListener("click", () => {
      const open = mobile.hasAttribute("hidden");
      if (open) {
        mobile.removeAttribute("hidden");
        toggle.setAttribute("aria-expanded", "true");
      } else {
        mobile.setAttribute("hidden", "");
        toggle.setAttribute("aria-expanded", "false");
      }
    });

    $$(".mobile-nav__link").forEach((link) => {
      link.addEventListener("click", () => {
        mobile.setAttribute("hidden", "");
        toggle?.setAttribute("aria-expanded", "false");
      });
    });
  }

  function bindEvents() {
    $("#btn-theater")?.addEventListener("click", toggleTheater);
    $("#btn-toggle-chat")?.addEventListener("click", toggleChat);
    $("#btn-share-wa")?.addEventListener("click", shareWhatsApp);
    $("#replay-close")?.addEventListener("click", exitReplay);

    $("#vod-grid")?.addEventListener("click", (e) => {
      const card = e.target.closest("[data-vod-index]");
      if (!card) return;
      playVod(Number(card.dataset.vodIndex));
    });

    setupTabs();
    setupNav();
  }

  /* ------------------------------------------------------------------
   * Utils
   * ------------------------------------------------------------------ */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function escapeAttr(str) {
    return escapeHtml(str);
  }

  /* ------------------------------------------------------------------
   * Boot
   * ------------------------------------------------------------------ */
  async function init() {
    const year = $("#year");
    if (year) year.textContent = String(new Date().getFullYear());

    state.config = await loadConfig();
    state.isLive = Boolean(state.config.live_stream.is_live);

    renderTicker();
    applyChannelLinks();
    renderScorebug();
    renderPlayer({ forceRemount: true });
    renderFicha();
    renderSponsors();
    renderUpcoming();
    renderVod();
    bindEvents();
    startLiveSync();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

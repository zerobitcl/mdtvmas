/**
 * MDTV+ STUDIO — Pestaña Noticias
 * contenteditable + toolbar · autosave localStorage · sin dependencias
 */
(() => {
  "use strict";

  const CSRF =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
    "";

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  const toast = (msg, err = false) => {
    if (typeof window.mdtvShowToast === "function") {
      window.mdtvShowToast(msg, err);
    } else {
      console[err ? "error" : "log"](msg);
    }
  };

  const extractYt = (input) => {
    if (typeof window.mdtvExtractYoutubeId === "function") {
      return window.mdtvExtractYoutubeId(input);
    }
    const raw = String(input || "").trim();
    if (/^[a-zA-Z0-9_-]{11}$/.test(raw)) return raw;
    const m = raw.match(
      /(?:youtube\.com\/watch\?[^#]*v=|youtu\.be\/|youtube\.com\/live\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/
    );
    return m ? m[1] : "";
  };

  function esc(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function slugifyClient(title) {
    const map = {
      á: "a", à: "a", ä: "a", â: "a", ã: "a",
      é: "e", è: "e", ë: "e", ê: "e",
      í: "i", ì: "i", ï: "i", î: "i",
      ó: "o", ò: "o", ö: "o", ô: "o", õ: "o",
      ú: "u", ù: "u", ü: "u", û: "u",
      ñ: "n", ç: "c",
    };
    let s = String(title || "").toLowerCase();
    s = s.replace(/[áàäâãéèëêíìïîóòöôõúùüûñç]/g, (ch) => map[ch] || ch);
    s = s.replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").replace(/-+/g, "-");
    if (s.length > 80) s = s.slice(0, 80).replace(/-+$/g, "");
    return s || "noticia";
  }

  function nowLocalValue() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function isoToLocal(iso) {
    if (!iso) return "";
    const m = String(iso).match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
    if (m) return `${m[1]}T${m[2]}`;
    try {
      const d = new Date(iso);
      if (Number.isNaN(d.getTime())) return "";
      const pad = (n) => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch {
      return "";
    }
  }

  function postStatusKind(p) {
    if ((p.status || "") !== "published") return "draft";
    const iso = p.published_at || "";
    if (!iso) return "draft";
    try {
      if (new Date(iso).getTime() > Date.now()) return "scheduled";
    } catch {
      /* ignore */
    }
    return "published";
  }

  function statusBadge(kind) {
    if (kind === "published") {
      return `<span class="news-badge news-badge--pub">Publicada</span>`;
    }
    if (kind === "scheduled") {
      return `<span class="news-badge news-badge--sched">Programada</span>`;
    }
    return `<span class="news-badge news-badge--draft">Borrador</span>`;
  }

  const state = {
    posts: [],
    filter: "all",
    editing: false,
    dirty: false,
    slugLocked: false,
    slugManual: false,
    post: emptyPost(),
    autosaveTimer: null,
  };

  function emptyPost() {
    return {
      id: "",
      slug: "",
      title: "",
      excerpt: "",
      category: "Fútbol",
      cover: { full: "", card: "", og: "", alt: "" },
      body_html: "",
      status: "draft",
      published_at: "",
      published_at_local: nowLocalValue(),
    };
  }

  function lsKey(id) {
    return "mdtv_news_draft_" + (id || "new");
  }

  async function api(action, { method = "GET", body = null, formData = null } = {}) {
    const opts = {
      method,
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", "X-CSRF-Token": CSRF },
    };
    if (formData) {
      opts.body = formData;
    } else if (body !== null) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    const url =
      method === "GET"
        ? `admin.php?action=${encodeURIComponent(action)}${body && body.id ? "&id=" + encodeURIComponent(body.id) : ""}`
        : `admin.php?action=${encodeURIComponent(action)}`;
    const res = await fetch(url, opts);
    let data = {};
    try {
      data = await res.json();
    } catch {
      /* ignore */
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || "Error de red");
    }
    return data;
  }

  /* ---------- List ---------- */

  async function loadList() {
    const data = await api("news_list");
    state.posts = Array.isArray(data.posts) ? data.posts : [];
    renderList();
  }

  function renderList() {
    const list = $("#news-list");
    if (!list) return;

    const filtered = state.posts.filter((p) => {
      const kind = postStatusKind(p);
      if (state.filter === "all") return true;
      return kind === state.filter;
    });

    if (!filtered.length) {
      list.innerHTML = `<p style="color:var(--muted);font-size:.9rem">No hay noticias en este filtro.</p>`;
      return;
    }

    list.innerHTML = filtered
      .map((p) => {
        const kind = postStatusKind(p);
        const thumb = p.cover?.card || p.cover?.og || "";
        const when = p.published_at ? isoToLocal(p.published_at).replace("T", " ") : "";
        return `
        <article class="news-list-card" data-id="${esc(p.id)}">
          ${
            thumb
              ? `<img class="news-list-card__thumb" src="${esc(thumb)}" alt="" width="88" height="66" loading="lazy">`
              : `<div class="news-list-card__thumb is-empty">Sin foto</div>`
          }
          <div>
            <div class="news-list-card__title">${esc(p.title || "Sin título")}</div>
            <div class="news-list-card__meta">${statusBadge(kind)} · ${esc(p.category || "")}${when ? " · " + esc(when) : ""}</div>
            <div class="news-list-card__actions">
              <button type="button" class="btn-ghost" data-edit="${esc(p.id)}" style="min-height:36px;padding:6px 10px;font-size:.75rem">Editar</button>
              ${
                p.slug
                  ? `<button type="button" class="btn-ghost" data-copy="${esc(p.slug)}" style="min-height:36px;padding:6px 10px;font-size:.75rem">Copiar link</button>
                     <button type="button" class="btn-ghost" data-share="${esc(p.slug)}" data-title="${esc(p.title || "")}" style="min-height:36px;padding:6px 10px;font-size:.75rem">Compartir</button>`
                  : ""
              }
            </div>
          </div>
        </article>`;
      })
      .join("");
  }

  /* ---------- Editor ---------- */

  function showList() {
    state.editing = false;
    state.dirty = false;
    $("#news-view-list").hidden = false;
    $("#news-view-editor").hidden = true;
    $("#news-savebar")?.classList.remove("is-visible");
    document.body.classList.remove("has-news-editor");
  }

  function showEditor() {
    state.editing = true;
    $("#news-view-list").hidden = true;
    $("#news-view-editor").hidden = false;
    $("#news-savebar")?.classList.add("is-visible");
    document.body.classList.add("has-news-editor");
    updatePublishButton();
    updatePreviewButtons();
    updateSocialPreview();
  }

  function hydrateEditor(post) {
    state.post = {
      id: post.id || "",
      slug: post.slug || "",
      title: post.title || "",
      excerpt: post.excerpt || "",
      category: post.category || "Fútbol",
      cover: {
        full: post.cover?.full || "",
        card: post.cover?.card || "",
        og: post.cover?.og || "",
        alt: post.cover?.alt || "",
      },
      body_html: post.body_html || "",
      status: post.status || "draft",
      published_at: post.published_at || "",
      published_at_local: post.published_at
        ? isoToLocal(post.published_at)
        : nowLocalValue(),
    };

    state.slugLocked = postStatusKind(post) !== "draft" && !!(post.slug && post.status === "published");
    // Lock slug only if it was ever published
    state.slugLocked = (post.status === "published") && !!post.slug;
    state.slugManual = false;

    $("#news-title").value = state.post.title;
    $("#news-slug").value = state.post.slug;
    $("#news-slug").disabled = state.slugLocked;
    $("#news-excerpt").value = state.post.excerpt;
    $("#news-category").value = state.post.category;
    $("#news-cover-full").value = state.post.cover.full;
    $("#news-cover-card").value = state.post.cover.card;
    $("#news-cover-og").value = state.post.cover.og;
    $("#news-cover-alt").value = state.post.cover.alt;
    $("#news-published-at").value = state.post.published_at_local || nowLocalValue();
    $("#news-editor").innerHTML = state.post.body_html || "";

    const status = state.post.status === "published" ? "published" : "draft";
    $$('input[name="news-status"]').forEach((r) => {
      r.checked = r.value === status;
    });

    setCoverPreview(state.post.cover.og || state.post.cover.card || state.post.cover.full);
    updateCharCounts();
    updateSlugPreview();
    updatePublishButton();
    updatePreviewButtons();
    updateSocialPreview();
    state.dirty = false;
  }

  function setCoverPreview(src) {
    const img = $("#news-cover-preview");
    if (!img) return;
    if (src) {
      img.src = src;
      img.classList.add("is-visible");
    } else {
      img.removeAttribute("src");
      img.classList.remove("is-visible");
    }
  }

  function collectEditor() {
    const status =
      $$('input[name="news-status"]').find((r) => r.checked)?.value || "draft";
    return {
      id: state.post.id || "",
      title: $("#news-title").value.trim(),
      slug: $("#news-slug").value.trim(),
      excerpt: $("#news-excerpt").value.trim(),
      category: $("#news-category").value,
      cover: {
        full: $("#news-cover-full").value.trim(),
        card: $("#news-cover-card").value.trim(),
        og: $("#news-cover-og").value.trim(),
        alt: $("#news-cover-alt").value.trim(),
      },
      body_html: $("#news-editor").innerHTML,
      status,
      published_at_local: $("#news-published-at").value,
    };
  }

  function updateCharCounts() {
    const title = $("#news-title").value;
    const excerpt = $("#news-excerpt").value;
    const tc = $("#news-title-count");
    const ec = $("#news-excerpt-count");
    if (tc) {
      tc.textContent = `${title.length} / 140 · ideal ≤ 70 para WhatsApp`;
      tc.classList.toggle("is-warn", title.length > 70 && title.length <= 140);
      tc.classList.toggle("is-over", title.length > 140);
    }
    if (ec) {
      ec.textContent = `${excerpt.length} / 200`;
      ec.classList.toggle("is-warn", excerpt.length > 160);
      ec.classList.toggle("is-over", excerpt.length > 200);
    }
  }

  function updateSlugPreview() {
    const slug = $("#news-slug").value.trim() || "…";
    const el = $("#news-slug-preview");
    if (el) el.textContent = "mdtvmas.cl/noticias/" + slug;
  }

  function updateSocialPreview() {
    const title = $("#news-title")?.value.trim() || "Título de la noticia";
    const desc = $("#news-excerpt")?.value.trim() || "La bajada aparece aquí…";
    const og = $("#news-cover-og")?.value.trim() || $("#news-cover-card")?.value.trim() || "";
    const t = $("#news-sp-title");
    const d = $("#news-sp-desc");
    const img = $("#news-sp-img");
    if (t) t.textContent = title;
    if (d) d.textContent = desc;
    if (img) {
      if (og) {
        img.src = og;
        img.style.display = "block";
      } else {
        img.removeAttribute("src");
        img.style.display = "none";
      }
    }
  }

  function updatePublishButton() {
    const btn = $("#news-btn-publish");
    if (!btn) return;
    const status =
      $$('input[name="news-status"]').find((r) => r.checked)?.value || "draft";
    const local = $("#news-published-at")?.value || "";
    let label = "Publicar";
    if (status === "published" && local) {
      try {
        if (new Date(local).getTime() > Date.now() + 30_000) {
          label = "Programar";
        }
      } catch {
        /* ignore */
      }
    }
    btn.textContent = label;
  }

  function updatePreviewButtons() {
    const hasId = !!state.post.id;
    const hasSlug = !!($("#news-slug")?.value.trim() || state.post.slug);
    const prev = $("#news-btn-preview");
    const del = $("#news-btn-delete");
    if (prev) {
      prev.hidden = !(hasId && hasSlug);
    }
    if (del) del.hidden = !hasId;
  }

  async function openNew() {
    const draft = emptyPost();
    // Restore local autosave for "new" if present
    try {
      const raw = localStorage.getItem(lsKey("new"));
      if (raw) {
        const saved = JSON.parse(raw);
        if (saved && saved.title) Object.assign(draft, saved);
      }
    } catch {
      /* ignore */
    }
    hydrateEditor(draft);
    showEditor();
  }

  async function openEdit(id) {
    try {
      const data = await api("news_get", { body: { id } });
      let post = data.post;
      try {
        const raw = localStorage.getItem(lsKey(id));
        if (raw) {
          const saved = JSON.parse(raw);
          if (saved && confirm("Hay un borrador local más reciente. ¿Restaurarlo?")) {
            post = { ...post, ...saved, id };
          }
        }
      } catch {
        /* ignore */
      }
      hydrateEditor(post);
      showEditor();
    } catch (err) {
      toast("❌ " + (err.message || "No se pudo cargar"), true);
    }
  }

  function markDirty() {
    state.dirty = true;
  }

  function scheduleAutosave() {
    clearTimeout(state.autosaveTimer);
    state.autosaveTimer = setTimeout(() => {
      try {
        const payload = collectEditor();
        localStorage.setItem(lsKey(payload.id || "new"), JSON.stringify(payload));
      } catch {
        /* quota */
      }
    }, 10_000);
  }

  function clearAutosave(id) {
    try {
      localStorage.removeItem(lsKey(id || "new"));
      localStorage.removeItem(lsKey("new"));
    } catch {
      /* ignore */
    }
  }

  async function save(forceStatus) {
    const payload = collectEditor();
    if (forceStatus) payload.status = forceStatus;

    if (payload.title.length < 5) {
      toast("El título debe tener al menos 5 caracteres", true);
      return;
    }

    const btnPub = $("#news-btn-publish");
    const btnDraft = $("#news-btn-draft");
    if (btnPub) btnPub.disabled = true;
    if (btnDraft) btnDraft.disabled = true;

    try {
      const data = await api("news_save", { method: "POST", body: payload });
      state.post = { ...state.post, ...data.post };
      state.slugLocked = data.post.status === "published";
      $("#news-slug").value = data.post.slug || "";
      $("#news-slug").disabled = state.slugLocked;
      clearAutosave(payload.id);
      clearAutosave(data.post.id);
      state.dirty = false;
      updateSlugPreview();
      updatePreviewButtons();
      toast("✅ " + (data.message || "Guardado"));
      await loadList();
    } catch (err) {
      toast("❌ " + (err.message || "No se pudo guardar"), true);
    } finally {
      if (btnPub) btnPub.disabled = false;
      if (btnDraft) btnDraft.disabled = false;
      updatePublishButton();
    }
  }

  async function uploadCover(file) {
    if (!file) return;
    const btn = $("#news-btn-cover");
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Subiendo…";
    }
    try {
      const fd = new FormData();
      fd.append("action", "news_upload_cover");
      fd.append("csrf_token", CSRF);
      fd.append("id", state.post.id || "");
      fd.append("image", file);
      const data = await api("news_upload_cover", { method: "POST", formData: fd });
      // El ID nace en el upload de portada (aún sin JSON); hay que conservarlo al publicar
      if (data.id) {
        state.post.id = data.id;
      }
      $("#news-cover-full").value = data.cover.full || "";
      $("#news-cover-card").value = data.cover.card || "";
      $("#news-cover-og").value = data.cover.og || "";
      setCoverPreview(data.cover.og || data.cover.card);
      updateSocialPreview();
      updatePreviewButtons();
      markDirty();
      toast("✅ Portada lista");
    } catch (err) {
      toast("❌ " + (err.message || "No se pudo subir"), true);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = "Subir foto de portada";
      }
    }
  }

  async function uploadInline(file) {
    if (!file) return;
    if (!state.post.id) {
      // Create draft shell first to get an id
      const title = $("#news-title").value.trim() || "Borrador sin título";
      try {
        const data = await api("news_save", {
          method: "POST",
          body: {
            ...collectEditor(),
            title: title.length >= 5 ? title : "Borrador sin título xx",
            status: "draft",
          },
        });
        state.post.id = data.post.id;
        state.post.slug = data.post.slug;
        $("#news-slug").value = data.post.slug || "";
      } catch (err) {
        toast("❌ " + (err.message || "Guarda un borrador antes de subir imágenes"), true);
        return;
      }
    }

    try {
      const fd = new FormData();
      fd.append("action", "news_upload_inline");
      fd.append("csrf_token", CSRF);
      fd.append("id", state.post.id);
      fd.append("image", file);
      const data = await api("news_upload_inline", { method: "POST", formData: fd });
      insertHtml(
        `<figure><img src="${esc(data.path)}" alt=""><figcaption></figcaption></figure><p><br></p>`
      );
      markDirty();
      toast("✅ Imagen insertada");
    } catch (err) {
      toast("❌ " + (err.message || "No se pudo subir"), true);
    }
  }

  function insertHtml(html) {
    const editor = $("#news-editor");
    editor.focus();
    try {
      document.execCommand("insertHTML", false, html);
    } catch {
      editor.insertAdjacentHTML("beforeend", html);
    }
  }

  function toolbarAction(cmd) {
    const editor = $("#news-editor");
    editor.focus();
    switch (cmd) {
      case "h2":
        document.execCommand("formatBlock", false, "h2");
        break;
      case "h3":
        document.execCommand("formatBlock", false, "h3");
        break;
      case "bold":
        document.execCommand("bold");
        break;
      case "italic":
        document.execCommand("italic");
        break;
      case "ul":
        document.execCommand("insertUnorderedList");
        break;
      case "ol":
        document.execCommand("insertOrderedList");
        break;
      case "quote":
        document.execCommand("formatBlock", false, "blockquote");
        break;
      case "link": {
        const url = prompt("URL del enlace (https://…)");
        if (!url) return;
        const href = /^https?:\/\//i.test(url) || /^mailto:/i.test(url) ? url : "https://" + url;
        document.execCommand("createLink", false, href);
        break;
      }
      case "image":
        $("#news-inline-file")?.click();
        break;
      case "youtube": {
        const link = prompt("Pega el link de YouTube");
        if (!link) return;
        const id = extractYt(link);
        if (!id) {
          toast("Link de YouTube inválido", true);
          return;
        }
        insertHtml(
          `<figure data-yt="${esc(id)}" contenteditable="false"><img src="https://i.ytimg.com/vi/${esc(id)}/hqdefault.jpg" alt="YouTube"></figure><p><br></p>`
        );
        break;
      }
      default:
        break;
    }
    markDirty();
    scheduleAutosave();
  }

  async function deleteCurrent() {
    if (!state.post.id) return;
    if (!confirm("¿Eliminar esta noticia de forma permanente?")) return;
    try {
      await api("news_delete", { method: "POST", body: { id: state.post.id } });
      clearAutosave(state.post.id);
      toast("✅ Noticia eliminada");
      showList();
      await loadList();
    } catch (err) {
      toast("❌ " + (err.message || "No se pudo eliminar"), true);
    }
  }

  function publicUrl(slug) {
    const origin = window.location.origin || "https://mdtvmas.cl";
    return origin + "/noticias/" + slug;
  }

  async function copyLink(slug) {
    const url = publicUrl(slug);
    try {
      await navigator.clipboard.writeText(url);
      toast("✅ Link copiado");
    } catch {
      prompt("Copia el link:", url);
    }
  }

  async function shareLink(slug, title) {
    const url = publicUrl(slug);
    if (navigator.share) {
      try {
        await navigator.share({ title: title || "MDTV+", url, text: title || "" });
        return;
      } catch {
        /* cancelled */
      }
    }
    copyLink(slug);
  }

  /* ---------- Bind ---------- */

  function bind() {
    const panel = $("#panel-news");
    if (!panel) return;

    $("#news-btn-new")?.addEventListener("click", () => openNew());
    $("#news-btn-back")?.addEventListener("click", () => {
      if (state.dirty && !confirm("Hay cambios sin guardar. ¿Salir igual?")) return;
      showList();
    });

    $("#news-filters")?.addEventListener("click", (e) => {
      const chip = e.target.closest("[data-filter]");
      if (!chip) return;
      state.filter = chip.dataset.filter;
      $$("#news-filters .news-chip").forEach((c) =>
        c.classList.toggle("is-active", c === chip)
      );
      renderList();
    });

    $("#news-list")?.addEventListener("click", (e) => {
      const edit = e.target.closest("[data-edit]");
      if (edit) {
        openEdit(edit.dataset.edit);
        return;
      }
      const copy = e.target.closest("[data-copy]");
      if (copy) {
        copyLink(copy.dataset.copy);
        return;
      }
      const share = e.target.closest("[data-share]");
      if (share) {
        shareLink(share.dataset.share, share.dataset.title || "");
      }
    });

    $("#news-title")?.addEventListener("input", () => {
      updateCharCounts();
      updateSocialPreview();
      if (!state.slugLocked && !state.slugManual) {
        $("#news-slug").value = slugifyClient($("#news-title").value);
        updateSlugPreview();
      }
      markDirty();
      scheduleAutosave();
    });

    $("#news-slug")?.addEventListener("input", () => {
      state.slugManual = true;
      $("#news-slug").value = slugifyClient($("#news-slug").value);
      updateSlugPreview();
      updatePreviewButtons();
      markDirty();
    });

    $("#news-excerpt")?.addEventListener("input", () => {
      updateCharCounts();
      updateSocialPreview();
      markDirty();
      scheduleAutosave();
    });

    $("#news-cover-alt")?.addEventListener("input", () => {
      markDirty();
      scheduleAutosave();
    });

    $("#news-category")?.addEventListener("change", () => {
      markDirty();
      scheduleAutosave();
    });

    $$('input[name="news-status"]').forEach((r) => {
      r.addEventListener("change", () => {
        updatePublishButton();
        markDirty();
      });
    });

    $("#news-published-at")?.addEventListener("change", () => {
      updatePublishButton();
      markDirty();
    });

    $("#news-btn-cover")?.addEventListener("click", () => {
      $("#news-cover-file")?.click();
    });
    $("#news-cover-file")?.addEventListener("change", (e) => {
      const file = e.target.files && e.target.files[0];
      uploadCover(file);
      e.target.value = "";
    });

    $("#news-inline-file")?.addEventListener("change", (e) => {
      const file = e.target.files && e.target.files[0];
      uploadInline(file);
      e.target.value = "";
    });

    $("#news-toolbar")?.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-cmd]");
      if (!btn) return;
      e.preventDefault();
      toolbarAction(btn.dataset.cmd);
    });

    const editor = $("#news-editor");
    editor?.addEventListener("paste", (e) => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData("text/plain") || "";
      const parts = text.replace(/\r\n/g, "\n").split(/\n{2,}/);
      const html = parts
        .map((p) => `<p>${esc(p).replace(/\n/g, "<br>")}</p>`)
        .join("");
      insertHtml(html || "<p></p>");
      markDirty();
      scheduleAutosave();
    });
    editor?.addEventListener("input", () => {
      markDirty();
      scheduleAutosave();
    });

    $("#news-btn-draft")?.addEventListener("click", () => save("draft"));
    $("#news-btn-publish")?.addEventListener("click", () => {
      $$('input[name="news-status"]').forEach((r) => {
        if (r.value === "published") r.checked = true;
      });
      updatePublishButton();
      save("published");
    });

    $("#news-btn-preview")?.addEventListener("click", () => {
      const slug = $("#news-slug").value.trim() || state.post.slug;
      if (!slug) return;
      window.open(publicUrl(slug), "_blank", "noopener");
    });

    $("#news-btn-delete")?.addEventListener("click", () => deleteCurrent());

    window.addEventListener("beforeunload", (e) => {
      if (state.editing && state.dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    window.addEventListener("mdtv:tab", (e) => {
      const tab = e.detail?.tab;
      if (tab === "news") {
        loadList().catch((err) => toast("❌ " + err.message, true));
        if (state.editing) {
          $("#news-savebar")?.classList.add("is-visible");
          document.body.classList.add("has-news-editor");
        }
      } else {
        $("#news-savebar")?.classList.remove("is-visible");
        document.body.classList.remove("has-news-editor");
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();

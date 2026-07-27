"use strict";

const PAGE_SIZE = 25;

const DAYS = [
    { v: 1, l: "24 h" }, { v: 7, l: "7 j" }, { v: 30, l: "30 j" },
    { v: 90, l: "90 j" }, { v: 0, l: "Tout" },
];

const CATS = [
    { id: 2000, l: "Films" }, { id: 5000, l: "Séries" }, { id: 3000, l: "Musique" },
    { id: 4000, l: "Logiciels" }, { id: 7000, l: "Livres" }, { id: 1000, l: "Jeux" },
    { id: 6000, l: "🔞 -18" },
];

const COLUMNS = [
    { key: "title", label: "Release", sortable: true },
    { key: "size", label: "Taille", sortable: true, num: true },
    { key: "seeders", label: "Seed", sortable: true, num: true },
    { key: "publishDate", label: "Âge", sortable: true, num: true },
    { key: "actions", label: "", num: true },
];

/* Tokens techniques d'un nom de release. Sert à les mettre en valeur là où ils
   sont déjà écrits, plutôt qu'à les recopier en pastilles sous chaque ligne. */
const TOKEN_RE = /^(2160p|1080p|720p|480p|4k|uhd|remux|blu-?ray|bdrip|brrip|web-?dl|webrip|web|hdtv|x26[45]|h\.?26[45]|hevc|avc|av1|hdr10\+?|hdr|dovi|dv|atmos|truehd|dts-?hd|dts|ddp?5\.1|e?ac3|flac|aac|multi|multilang|vostfr|truefrench|french|vff|vfq|vfi|subfrench|imax|proper|repack|extended|remastered|10bit)$/i;
const YEAR_RE = /^(19|20)\d{2}$/;

const SVGNS = "http://www.w3.org/2000/svg";
const ICONS = {
    download: "M12 3v12m0 0l-4-4m4 4l4-4M5 21h14",
    magnet: "M6 4v7a6 6 0 0 0 12 0V4M6 4H3m3 0v4m12-4h3m-3 0v4",
    send: "M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z",
    copy: "M9 9h10v10H9zM5 15H4V4h11v1",
};
function svgIcon(d) {
    const svg = document.createElementNS(SVGNS, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    const p = document.createElementNS(SVGNS, "path");
    p.setAttribute("d", d);
    svg.append(p);
    return svg;
}

const QUALITY_ORDER = ["2160p", "1080p", "720p", "480p", "REMUX", "BluRay", "WEB", "HDTV",
    "x265", "x264", "AV1", "HDR", "DV", "Atmos", "DTS", "FLAC", "MULTI", "FR", "VOSTFR"];
const SORTABLE = ["title", "size", "seeders", "leechers", "publishDate"];

const state = {
    query: "", days: 0, trackers: new Set(), cats: new Set(),
    mode: "search", safeMode: true,
    sort: { field: "publishDate", dir: "desc" },
    facets: { minSeeders: 0, freeleech: false, quality: new Set() },
    results: [], total: 0, capped: false, page: 1, maskOn: false, loading: false,
    qbit: false, qbitCategories: [], qbitCategory: "",
};

const $ = (s) => document.querySelector(s);
const form = $("#search-form");
const input = $("#q");
const submitBtn = form.querySelector("button");
const daysBox = $("#days");
const catsBox = $("#categories");
const chipsBox = $("#trackers");
const maskBtn = $("#mask-toggle");
const topBtn = $("#top-btn");
const safeBtn = $("#safe-toggle");
const filtersBtn = $("#filters-btn");
const filtersPanel = $("#filters-panel");
const filtersCount = $("#filters-count");
const filtersReset = $("#filters-reset");
const resultsBox = $("#results");
const facetsBox = $("#facets");
const facetsBody = $("#facets-body");
const statusBox = $("#status");
const historyList = $("#history");
const qbitCatWrap = $("#qbit-cat-wrap");
const qbitCatSel = $("#qbit-cat");
const CSRF = document.querySelector('meta[name="csrf"]')?.content || "";

/* ---------- helpers DOM ---------- */
function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === "class") node.className = v;
        else if (k === "text") node.textContent = v;
        else if (v !== null && v !== undefined) node.setAttribute(k, v);
    }
    for (const c of children) {
        if (c == null) continue;
        node.append(c.nodeType ? c : document.createTextNode(String(c)));
    }
    return node;
}

/* ---------- contrôles (jours / catégories / indexeurs) ---------- */
function setDays(v) {
    state.days = v;
    try { localStorage.setItem("days", String(v)); } catch (e) {}
    renderDays();
    updateFiltersCount();
}

function renderDays() {
    daysBox.replaceChildren(...DAYS.map((d) => {
        const b = el("button", { type: "button", text: d.l });
        if (d.v === state.days) b.classList.add("active");
        b.addEventListener("click", () => {
            if (state.days === d.v) return;
            setDays(d.v); rerunOrSync();
        });
        return b;
    }));
}

function renderCats() {
    catsBox.replaceChildren(...CATS.map((c) => {
        const chip = el("button", { type: "button", class: "chip", text: c.l });
        if (state.cats.has(c.id)) chip.classList.add("active");
        chip.addEventListener("click", () => {
            chip.classList.toggle("active");
            state.cats.has(c.id) ? state.cats.delete(c.id) : state.cats.add(c.id);
            updateFiltersCount();
            rerunOrSync();
        });
        return chip;
    }));
    updateFiltersCount();
}

let lastIndexers = [];
function renderChips(indexers) {
    lastIndexers = indexers;
    if (!indexers.length) {
        chipsBox.replaceChildren(el("span", { class: "muted", text: "Aucun indexeur configuré dans Prowlarr." }));
        return;
    }
    chipsBox.replaceChildren(...indexers.map((ix) => {
        const chip = el("button", { type: "button", class: "chip", "data-id": ix.id },
            el("span", { class: "maskable", text: ix.name }));
        if (state.trackers.has(ix.id)) chip.classList.add("active");
        chip.addEventListener("click", () => {
            chip.classList.toggle("active");
            state.trackers.has(ix.id) ? state.trackers.delete(ix.id) : state.trackers.add(ix.id);
            updateFiltersCount();
            rerunOrSync();
        });
        return chip;
    }));
    updateFiltersCount();
}

/* ---------- panneau Filtres ---------- */
function updateFiltersCount() {
    if (!filtersCount) return;
    const n = state.cats.size + state.trackers.size + (state.days !== 0 ? 1 : 0) + facetCount();
    filtersCount.textContent = n ? ` · ${n}` : "";
    filtersBtn.classList.toggle("has-sel", n > 0);
}

function setFiltersOpen(open) {
    filtersPanel.toggleAttribute("hidden", !open);
    filtersBtn.setAttribute("aria-expanded", String(open));
}
if (filtersBtn) {
    filtersBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        setFiltersOpen(filtersPanel.hasAttribute("hidden"));
    });
    document.addEventListener("click", (e) => {
        if (!filtersPanel.hasAttribute("hidden") && !e.target.closest(".filters-wrap")) setFiltersOpen(false);
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !filtersPanel.hasAttribute("hidden")) setFiltersOpen(false);
    });
}
if (filtersReset) {
    filtersReset.addEventListener("click", () => {
        state.cats.clear();
        state.trackers.clear();
        state.days = 0;
        clearFacets();
        try { localStorage.setItem("days", "0"); } catch (e) {}
        renderDays();
        renderCats();
        renderChips(lastIndexers);
        renderFacets();
        rerunOrSync();
    });
}

function rerunOrSync() {
    state.page = 1;
    if (state.query || state.mode === "top") runSearch(); else syncUrl();
}

/* ---------- masquage ---------- */
function applyMask() {
    document.body.classList.toggle("mask-on", state.maskOn);
    maskBtn.setAttribute("aria-pressed", String(state.maskOn));
}
maskBtn.addEventListener("click", () => {
    state.maskOn = !state.maskOn;
    try { localStorage.setItem("maskTrackers", state.maskOn ? "1" : "0"); } catch (e) {}
    applyMask();
});

/* ---------- safe-mode (-18) ---------- */
function applySafe() {
    if (!safeBtn) return;
    safeBtn.setAttribute("aria-pressed", String(state.safeMode));
    safeBtn.classList.toggle("revealed", !state.safeMode);
    safeBtn.title = state.safeMode
        ? "Contenu -18 masqué — cliquer pour l'afficher"
        : "Contenu -18 affiché — cliquer pour le masquer";
}
if (safeBtn) {
    safeBtn.addEventListener("click", () => {
        state.safeMode = !state.safeMode;
        try { localStorage.setItem("safeMode", state.safeMode ? "1" : "0"); } catch (e) {}
        applySafe();
        // Le filtrage est serveur : on relance la requête pour (dé)masquer le -18.
        state.page = 1;
        rerunOrSync();
    });
}

/* ---------- recherche ---------- */
form.addEventListener("submit", (e) => {
    e.preventDefault();
    state.mode = "search";
    state.query = input.value.trim();
    state.page = 1;
    pushHistory(state.query);
    runSearch();
});

/* ---------- Top (découverte sans recherche) ---------- */
if (topBtn) {
    topBtn.addEventListener("click", () => {
        state.mode = "top";
        state.query = "";
        input.value = "";
        state.sort = { field: "publishDate", dir: "desc" };
        state.page = 1;
        runSearch();
    });
}

// Une recherche lente ne doit jamais écraser le résultat d'une recherche plus
// récente : on annule la requête en vol et on ignore les réponses obsolètes.
let searchSeq = 0;
let searchAbort = null;

async function runSearch() {
    syncUrl();
    const top = state.mode === "top";
    if (!top && !state.query) { state.results = []; renderIdle(); return; }
    setLoading(true);
    clearFacets();
    renderSkeleton();

    const p = new URLSearchParams({ action: "search", days: state.days });
    if (top) p.set("top", "1"); else p.set("q", state.query);
    if (!state.safeMode) p.set("safe", "0");
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));

    const seq = ++searchSeq;
    if (searchAbort) searchAbort.abort();
    searchAbort = new AbortController();

    try {
        const res = await fetch("api.php?" + p.toString(), { signal: searchAbort.signal });
        const data = await res.json();
        if (seq !== searchSeq) return; // réponse dépassée
        if (res.status === 401) { location.href = "login.php"; return; }
        if (data.error) { renderError(data.error); return; }
        state.results = data.results || [];
        state.total = data.total || state.results.length;
        state.capped = !!data.capped;
        renderFacets();
        renderResults();
    } catch (e) {
        if (e.name === "AbortError" || seq !== searchSeq) return;
        renderError("Impossible de contacter le serveur.");
    } finally {
        if (seq === searchSeq) setLoading(false);
    }
}

function setLoading(on) { state.loading = on; submitBtn.disabled = on; }

/* ---------- facettes + tri ---------- */
function facetFiltered() {
    const f = state.facets;
    // Le -18 (safe-mode) est filtré CÔTÉ SERVEUR (api.php) — pas de filtrage
    // cosmétique ici : le contenu adulte n'arrive même pas si safe-mode est actif.
    return state.results.filter((x) => {
        if (f.minSeeders > 0 && x.seeders < f.minSeeders) return false;
        if (f.freeleech && !x.freeleech) return false;
        if (f.quality.size && ![...f.quality].every((q) => (x.badges || []).includes(q))) return false;
        return true;
    });
}
function sortResults(arr) {
    const { field, dir } = state.sort;
    const mul = dir === "asc" ? 1 : -1;
    return [...arr].sort((a, b) => {
        if (field === "title") return a.title.localeCompare(b.title) * mul;
        let av = a[field], bv = b[field];
        if (field === "publishDate") { av = Date.parse(av) || 0; bv = Date.parse(bv) || 0; }
        return (((av > bv) - (av < bv))) * mul;
    });
}
function visibleResults() { return sortResults(facetFiltered()); }

function toggleSort(field) {
    if (state.sort.field === field) state.sort.dir = state.sort.dir === "asc" ? "desc" : "asc";
    else state.sort = { field, dir: field === "title" ? "asc" : "desc" };
    try { localStorage.setItem("sort", JSON.stringify(state.sort)); } catch (e) {}
    state.page = 1;
    renderResults();
}

/* Les facettes vivent dans le panneau Filtres : tout le filtrage au même
   endroit, rien qui encombre la liste. Ce qui est actif remonte au-dessus des
   résultats sous forme de jetons retirables (voir activeFilterChips). */
function renderFacets() {
    if (!state.results.length) { hideFacets(); return; }
    facetsBox.hidden = false;

    const present = new Set();
    state.results.forEach((r) => (r.badges || []).forEach((b) => present.add(b)));
    const hasFree = state.results.some((r) => r.freeleech);
    const maxSeed = Math.max(10, ...state.results.map((r) => r.seeders));

    const slider = el("input", { type: "range", min: "0", max: String(maxSeed),
        value: String(state.facets.minSeeders), class: "facet-range" });
    const seedVal = el("span", { class: "facet-val", text: "≥ " + state.facets.minSeeders });
    slider.addEventListener("input", () => {
        state.facets.minSeeders = parseInt(slider.value, 10);
        seedVal.textContent = "≥ " + slider.value;
        state.page = 1; renderResults(); updateFiltersCount();
    });

    const chips = el("div", { class: "chips" });
    if (hasFree) chips.append(facetChip("Freeleech", () => state.facets.freeleech,
        () => { state.facets.freeleech = !state.facets.freeleech; }));
    QUALITY_ORDER.filter((q) => present.has(q)).forEach((q) => {
        chips.append(facetChip(q, () => state.facets.quality.has(q),
            () => { state.facets.quality.has(q) ? state.facets.quality.delete(q) : state.facets.quality.add(q); }));
    });

    facetsBody.replaceChildren(
        el("label", { class: "facet" }, el("span", { class: "facet-lbl", text: "Seeders" }), slider, seedVal),
        chips,
    );
    updateFiltersCount();
}

function facetChip(label, isActive, toggle) {
    const chip = el("button", { type: "button", text: label,
        class: "facet-chip" + (isActive() ? " active" : "") });
    chip.addEventListener("click", () => {
        toggle();
        chip.classList.toggle("active", isActive());
        state.page = 1;
        renderResults();
        updateFiltersCount();
    });
    return chip;
}

function facetCount() {
    return (state.facets.minSeeders > 0 ? 1 : 0)
        + (state.facets.freeleech ? 1 : 0)
        + state.facets.quality.size;
}

function clearFacets() {
    state.facets = { minSeeders: 0, freeleech: false, quality: new Set() };
}

/** Jetons des facettes actives, retirables d'un clic, au-dessus des résultats. */
function activeFilterChips() {
    if (!facetCount()) return null;
    const wrap = el("span", { class: "active-filters" });
    const drop = (fn) => () => { fn(); state.page = 1; renderFacets(); renderResults(); };

    if (state.facets.minSeeders > 0) {
        const c = el("button", { type: "button", class: "af-chip", text: "≥ " + state.facets.minSeeders + " seed" });
        c.addEventListener("click", drop(() => { state.facets.minSeeders = 0; }));
        wrap.append(c);
    }
    if (state.facets.freeleech) {
        const c = el("button", { type: "button", class: "af-chip", text: "Freeleech" });
        c.addEventListener("click", drop(() => { state.facets.freeleech = false; }));
        wrap.append(c);
    }
    [...state.facets.quality].forEach((q) => {
        const c = el("button", { type: "button", class: "af-chip", text: q });
        c.addEventListener("click", drop(() => state.facets.quality.delete(q)));
        wrap.append(c);
    });
    return wrap;
}

/* ---------- rendu ---------- */
function hideFacets() { facetsBox.hidden = true; facetsBody.replaceChildren(); updateFiltersCount(); }
function renderIdle() {
    hideFacets();
    observeMore(null);
    resultsBox.replaceChildren(el("div", { class: "state" },
        el("span", { class: "emoji", text: "🛰️" }),
        "Lancez une recherche pour interroger vos indexeurs."));
}
function renderSkeleton() {
    hideFacets();
    observeMore(null);
    const rows = Array.from({ length: 6 }, () => el("div", { class: "sk-row" }));
    resultsBox.replaceChildren(el("div", { class: "table-wrap" },
        el("div", { class: "skeleton" }, ...rows)));
}
function renderError(msg) {
    hideFacets();
    observeMore(null);
    resultsBox.replaceChildren(el("div", { class: "state error" },
        el("span", { class: "emoji", text: "⚠️" }), msg));
}

function topBanner() {
    const lbl = (DAYS.find((d) => d.v === state.days) || {}).l || "Tout";
    return el("div", { class: "top-banner" },
        el("span", { class: "tb-fire", text: "🆕" }),
        el("b", { text: "Derniers uploads" }),
        el("span", { class: "muted", text: state.days === 0 ? " · tous trackers" : ` · ${lbl}` }));
}

function renderResults() {
    const top = state.mode === "top";
    const all = visibleResults();
    if (!all.length) {
        const msg = state.results.length
            ? "Aucun résultat avec ces filtres."
            : (top ? "Aucun torrent pour cette période. Essaie « Tout » ou d'autres catégories."
                   : `Aucun résultat pour « ${state.query} ».`);
        resultsBox.replaceChildren(
            ...(top ? [topBanner()] : []),
            el("div", { class: "state" }, el("span", { class: "emoji", text: top ? "🔥" : "🔍" }), msg));
        observeMore(null);
        return;
    }
    const shown = all.slice(0, state.page * PAGE_SIZE);

    // Compte : ce qui est affiché, et sur quel total.
    const left = el("span", {}, el("b", { text: String(all.length) }), ` résultat${all.length > 1 ? "s" : ""}`);
    if (all.length < state.results.length) left.append(el("span", { class: "muted", text: ` sur ${state.results.length}` }));
    else if (state.total > state.results.length) left.append(el("span", { class: "muted", text: ` sur ${state.total}` }));

    const right = el("span", { class: "meta-actions" });
    if (state.days !== 0) {
        const btn = el("button", { type: "button", class: "link-btn", text: "Élargir à toutes les dates" });
        btn.addEventListener("click", () => { setDays(0); state.page = 1; runSearch(); });
        right.append(btn);
    } else if (state.capped) {
        right.append(el("span", { class: "muted", text: "Affine la recherche pour voir le reste" }));
    }
    const meta = el("div", { class: "meta-row" }, left, activeFilterChips(), right);

    const table = el("table", {},
        el("thead", {}, renderHeadRow()),
        el("tbody", {}, ...shown.map((r) => renderRow(r))));

    const parts = top ? [topBanner(), meta, el("div", { class: "table-wrap" }, table)]
                       : [meta, el("div", { class: "table-wrap" }, table)];

    // Le bouton reste (repli clavier et sans IntersectionObserver), mais il
    // s'enclenche tout seul dès qu'il approche du bas : on fait défiler, ça
    // charge.
    let more = null;
    if (shown.length < all.length) {
        more = el("button", { type: "button", class: "load-more",
            text: `Charger plus (${all.length - shown.length} restants)` });
        more.addEventListener("click", loadMore);
        parts.push(more);
    }
    resultsBox.replaceChildren(...parts);
    observeMore(more);
}

function loadMore() {
    state.page++;
    renderResults();
}

/* Défilement infini : on surveille le bouton de fin de liste. */
let moreObserver = null;
function observeMore(btn) {
    if (moreObserver) { moreObserver.disconnect(); moreObserver = null; }
    if (!btn || typeof IntersectionObserver === "undefined") return;
    moreObserver = new IntersectionObserver((entries) => {
        if (!entries.some((e) => e.isIntersecting)) return;
        moreObserver.disconnect();
        moreObserver = null;
        loadMore();
    }, { rootMargin: "400px" });
    moreObserver.observe(btn);
}

function renderHeadRow() {
    const tr = el("tr");
    for (const col of COLUMNS) {
        const th = el("th", { text: col.label, class: col.num ? "num" : (col.cls || "") });
        if (col.sortable) {
            th.classList.add("sortable");
            th.append(el("span", { class: "arrow", text: "▲" }));
            const active = state.sort.field === col.key;
            if (active) th.classList.add("sort-active", state.sort.dir === "desc" ? "sort-desc" : "sort-asc");
            // Tri accessible au clavier (Entrée / Espace) et annoncé aux lecteurs d'écran.
            th.tabIndex = 0;
            th.setAttribute("aria-sort", active ? (state.sort.dir === "asc" ? "ascending" : "descending") : "none");
            th.addEventListener("click", () => toggleSort(col.key));
            th.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggleSort(col.key); }
            });
        }
        tr.append(th);
    }
    return tr;
}

/**
 * Découpe un nom de release en deux parties lisibles :
 *   « The.Matrix.1999 » → titre en clair,
 *   « .REMASTERED.MULTi.2160p.BluRay-REBiRTH » → suite technique en monospace,
 * tokens de qualité en ambre, groupe de release en retrait.
 * Les titres déjà rédigés en clair (avec des espaces) sont laissés tels quels.
 */
function renderRelease(title) {
    const frag = document.createDocumentFragment();

    // Point de bascule : l'année si elle existe, sinon le premier token
    // technique. Avant = ce que l'humain lit, après = ce que la machine décrit.
    let cut = -1;
    const year = title.match(/(?:^|[.\s])((?:19|20)\d{2})(?=[.\s]|$)/);
    if (year) {
        cut = year.index + year[0].length;
    } else {
        const parts = title.split(/([.\s])/);
        let pos = 0;
        for (const piece of parts) {
            if (TOKEN_RE.test(piece)) { cut = pos; break; }
            pos += piece.length;
        }
    }

    const name = cut > 0 ? title.slice(0, cut) : title;
    let tech = cut > 0 ? title.slice(cut) : "";

    frag.append(el("span", { class: "rel-name", text: name.replace(/\./g, " ").trim() }));
    if (tech === "") return frag;

    const techNode = el("span", { class: "rel-tech" });
    // Le titre peut se terminer sans séparateur (« … (2003)MULTi ») : on en pose un.
    if (!/^[.\s\-_]/.test(tech)) techNode.append(document.createTextNode(" "));
    // Groupe de release : ce qui suit le dernier tiret, s'il ne contient plus de point.
    let group = "";
    const dash = tech.lastIndexOf("-");
    if (dash > 0 && !tech.slice(dash + 1).includes(".")) {
        group = tech.slice(dash);
        tech = tech.slice(0, dash);
    }
    tech.split(/([.\-_\s]+)/).forEach((piece) => {
        if (!piece) return;
        if (TOKEN_RE.test(piece)) techNode.append(el("span", { class: "tk", text: piece }));
        else techNode.append(document.createTextNode(piece));
    });
    if (group) techNode.append(el("span", { class: "grp", text: group }));
    frag.append(techNode);
    return frag;
}

function renderRow(r) {
    const tr = el("tr");

    // Liseré de qualité : niveau de résolution lisible d'un coup d'œil vertical.
    const badges = r.badges || [];
    if (badges.includes("2160p")) tr.classList.add("q-uhd");
    else if (badges.includes("1080p")) tr.classList.add("q-hd");

    const titleCell = el("td", { class: "cell-title" });
    const linkable = r.infoUrl && r.infoUrl !== "#";
    const titleNode = linkable
        ? el("a", { class: "rel", href: r.infoUrl, target: "_blank", rel: "noopener noreferrer", title: r.title })
        : el("span", { class: "rel", title: r.title });
    titleNode.append(renderRelease(r.title));
    titleCell.append(titleNode);

    const meta = el("div", { class: "rel-meta" });
    meta.append(el("span", { class: "idx-tag maskable", text: r.indexer }));
    if (r.category) {
        meta.append(el("span", { class: "sep", text: "·" }), el("span", { text: r.category }));
    }
    if (r.freeleech) meta.append(el("span", { class: "badge-fl", text: "FREE" }));
    if (r.adult) meta.append(el("span", { class: "badge-adult", text: "-18" }));
    titleCell.append(meta);
    tr.append(titleCell);

    tr.append(el("td", { class: "num" }, r.sizeHuman));

    const sc = r.seeders >= 20 ? "s-good" : r.seeders >= 5 ? "s-mid" : "s-low";
    const seedCell = el("td", { class: "num" },
        el("span", { class: "seed " + sc }, el("span", { class: "dot" }), String(r.seeders)));
    if (r.leechers) seedCell.append(el("span", { class: "leech", text: " /" + r.leechers }));
    tr.append(seedCell);

    const age = r.daysOld == null ? "—" : (r.daysOld === 0 ? "auj." : r.daysOld + " j");
    const ageCell = el("td", { class: "num" }, age);
    ageCell.title = r.publishDate || "";
    tr.append(ageCell);

    tr.append(el("td", { class: "num" }, renderActions(r)));
    return tr;
}

function torrentHref(dl) {
    return "download_torrent.php?" + new URLSearchParams({ token: dl.token }).toString();
}

function renderActions(r) {
    const wrap = el("div", { class: "actions" });
    let any = false;

    if (r.dl) {
        wrap.append(el("a", { class: "act act-dl", href: torrentHref(r.dl), title: "Télécharger le .torrent" },
            svgIcon(ICONS.download)));
        any = true;
    }
    if (r.magnet) {
        wrap.append(el("a", { class: "act act-magnet", href: r.magnet, title: "Ouvrir le magnet" },
            svgIcon(ICONS.magnet)));
        wrap.append(makeBtn("act act-copy", ICONS.copy, "Copier le magnet", () => copyText(r.magnet)));
        any = true;
    }
    if (state.qbit && r.send) {
        wrap.append(makeBtn("act act-qbit", ICONS.send, "Envoyer à qBittorrent", (btn) => sendToQbit(r, btn)));
        any = true;
    }
    if (!any) wrap.append(el("span", { class: "dl-none", text: "—" }));
    return wrap;
}

function makeBtn(cls, icon, title, onClick) {
    const b = el("button", { type: "button", class: cls, title });
    b.append(svgIcon(icon));
    b.addEventListener("click", () => onClick(b));
    return b;
}

async function copyText(text) {
    try { await navigator.clipboard.writeText(text); toast("Magnet copié"); }
    catch (e) { toast("Copie impossible"); }
}

async function sendToQbit(r, btn) {
    btn.disabled = true;
    btn.classList.add("busy");
    const body = new URLSearchParams();
    body.set("token", r.send);
    if (state.qbitCategory) body.set("category", state.qbitCategory);
    try {
        const res = await fetch("send.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: body.toString(),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) { btn.classList.add("done"); toast("Envoyé à qBittorrent"); }
        else { btn.classList.remove("busy"); btn.disabled = false; toast(data.error || "Échec de l'envoi"); }
    } catch (e) {
        btn.classList.remove("busy"); btn.disabled = false; toast("Échec de l'envoi");
    }
}

/* ---------- toast ---------- */
let toastTimer = null;
function toast(msg) {
    let t = $("#toast");
    if (!t) { t = el("div", { id: "toast", class: "toast" }); document.body.append(t); }
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove("show"), 2500);
}

/* ---------- statut ---------- */
async function loadStatus() {
    try {
        const res = await fetch("api.php?action=status");
        if (res.status === 401) { location.href = "login.php"; return; }
        const s = await res.json();
        state.qbit = !!s.qbit;
        state.qbitCategories = s.qbitCategories || [];
        renderQbitCategories();

        const dot = statusBox.querySelector(".status-dot");
        const txt = statusBox.querySelector(".status-text");
        const oldWarn = statusBox.querySelector(".status-warn");
        if (oldWarn) oldWarn.remove();

        if (s.connected) {
            dot.className = "status-dot ok";
            txt.textContent = `${s.indexers} indexeur${s.indexers > 1 ? "s" : ""}` + (s.qbit ? " · qBit" : "");
            const errs = s.indexerErrors || [];
            if (errs.length) {
                statusBox.append(el("span", { class: "status-warn",
                    title: "Indexeurs en erreur : " + errs.join(", "), text: `⚠ ${errs.length}` }));
            }
        } else {
            dot.className = "status-dot ko";
            txt.textContent = "Prowlarr hors ligne";
        }
    } catch (e) {
        statusBox.querySelector(".status-dot").className = "status-dot ko";
        statusBox.querySelector(".status-text").textContent = "Hors ligne";
    }
}

function renderQbitCategories() {
    if (!qbitCatWrap || !qbitCatSel) return;
    if (!state.qbit || !state.qbitCategories.length) { qbitCatWrap.hidden = true; return; }
    qbitCatWrap.hidden = false;
    qbitCatSel.replaceChildren(
        el("option", { value: "", text: "Sans catégorie" }),
        ...state.qbitCategories.map((c) => el("option", { value: c, text: c }))
    );
    if (state.qbitCategory) qbitCatSel.value = state.qbitCategory;
}

if (qbitCatSel) {
    qbitCatSel.addEventListener("change", () => {
        state.qbitCategory = qbitCatSel.value;
        try { localStorage.setItem("qbitCat", state.qbitCategory); } catch (e) {}
    });
}

/* ---------- historique ---------- */
function getHistory() {
    try { return JSON.parse(localStorage.getItem("history") || "[]"); } catch (e) { return []; }
}
function pushHistory(q) {
    if (!q) return;
    let h = getHistory().filter((x) => x !== q);
    h.unshift(q);
    h = h.slice(0, 8);
    try { localStorage.setItem("history", JSON.stringify(h)); } catch (e) {}
    renderHistory(h);
}
function renderHistory(h) {
    historyList.replaceChildren(...(h || getHistory()).map((q) => el("option", { value: q })));
}

/* ---------- URL partageable ---------- */
function syncUrl() {
    const p = new URLSearchParams();
    if (state.mode === "top") p.set("top", "1");
    else if (state.query) p.set("q", state.query);
    if (state.days !== 0) p.set("days", state.days);
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));
    const qs = p.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
}
function readUrl() {
    const p = new URLSearchParams(location.search);
    if (p.get("top") === "1") state.mode = "top";
    if (p.has("q")) { state.query = p.get("q").trim(); input.value = state.query; }
    if (p.has("days")) { const d = parseInt(p.get("days"), 10); if (DAYS.some((x) => x.v === d)) state.days = d; }
    if (p.has("trackers")) p.get("trackers").split(",").map(Number).filter(Boolean).forEach((id) => state.trackers.add(id));
    if (p.has("cats")) p.get("cats").split(",").map(Number).filter(Boolean).forEach((id) => state.cats.add(id));
}

/* ---------- init ---------- */
async function init() {
    try { state.maskOn = localStorage.getItem("maskTrackers") === "1"; } catch (e) {}
    try { state.safeMode = localStorage.getItem("safeMode") !== "0"; } catch (e) {}
    try { const sd = parseInt(localStorage.getItem("days"), 10); if (DAYS.some((x) => x.v === sd)) state.days = sd; } catch (e) {}
    try { const so = JSON.parse(localStorage.getItem("sort") || "null"); if (so && SORTABLE.includes(so.field) && (so.dir === "asc" || so.dir === "desc")) state.sort = so; } catch (e) {}
    try { state.qbitCategory = localStorage.getItem("qbitCat") || ""; } catch (e) {}
    applyMask();
    applySafe();
    readUrl();
    if (state.mode === "top") state.sort = { field: "publishDate", dir: "desc" };
    renderDays();
    renderCats();
    renderHistory();
    renderIdle();

    loadStatus();
    try {
        const res = await fetch("api.php?action=indexers");
        const data = await res.json();
        renderChips(data.indexers || []);
    } catch (e) { renderChips([]); }

    if (state.mode === "top" || state.query) runSearch();
}
init();

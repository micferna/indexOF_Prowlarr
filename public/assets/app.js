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
    { key: "title", label: "Release", sortable: true, alt: "relevance", altLabel: "Pertinence" },
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
    pause: "M10 4H6v16h4zM18 4h-4v16h4z",
    play: "M6 3l14 9-14 9z",
    trash: "M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14",
    close: "M18 6L6 18M6 6l12 12",
    files: "M3 7h6l2 2h10v10H3zM3 7V5h6l2 2",
    cast: "M2 16a6 6 0 0 1 6 6M2 20a2 2 0 0 1 2 2M21 4H3v3M21 4v16h-9",
    eye: "M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6",
};
/* Une icône par application *arr : téléviseur, pellicule, note, livre. */
const ARR_ICONS = {
    sonarr: "M3 7h18v12H3zM8 3l4 4 4-4",
    radarr: "M4 4h16v16H4zM4 9h16M4 15h16M9 4v16M15 4v16",
    lidarr: "M9 18V5l12-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM21 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z",
    readarr: "M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z",
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
const SORTABLE = ["relevance", "title", "size", "seeders", "leechers", "publishDate"];

/**
 * Score de pertinence d'un titre pour une requête.
 *
 * Trier par date une recherche par mot-clé enterre la meilleure correspondance
 * sous les dernières mises en ligne. On classe donc d'abord par proximité au
 * terme cherché, les seeders départageant les ex æquo.
 */
function relevance(title, query) {
    const t = normalizeName(title);
    const q = normalizeName(query);
    if (!q) return 0;

    let score = 0;
    if (t.includes(q)) score += 100;          // la requête entière est présente
    if (t.startsWith(q)) score += 40;         // et en tête du titre

    // Couverture : combien des mots demandés apparaissent.
    const mots = query.trim().split(/\s+/).map(normalizeName).filter(Boolean);
    if (mots.length) {
        score += 60 * (mots.filter((m) => t.includes(m)).length / mots.length);
    }
    // À correspondance égale, un titre court est plus proche de la demande
    // qu'un pack de trente épisodes qui la contient au passage.
    score -= Math.min(25, t.length / 8);
    return score;
}

/* Tranches de taille. Choisir « entre 4 et 15 Go » est le premier geste quand
   une recherche mélange du REMUX à 70 Go et du WEB à 2 Go. Des tranches se
   cliquent ; un curseur se règle. */
const GO = 1024 ** 3;
const SIZES = [
    { id: "xs", l: "< 1 Go", min: 0, max: GO },
    { id: "s", l: "1 – 5 Go", min: GO, max: 5 * GO },
    { id: "m", l: "5 – 15 Go", min: 5 * GO, max: 15 * GO },
    { id: "l", l: "15 – 40 Go", min: 15 * GO, max: 40 * GO },
    { id: "xl", l: "> 40 Go", min: 40 * GO, max: Infinity },
];

const state = {
    query: "", days: 0, trackers: new Set(), cats: new Set(),
    mode: "search", safeMode: true,
    sort: { field: "relevance", dir: "desc" },
    facets: { minSeeders: 0, freeleech: false, quality: new Set(), sizes: new Set(), exclude: [] },
    results: [], rawResults: [], grouping: true,
    total: 0, capped: false, fetched: 0, loadingMore: false, page: 1, maskOn: false, loading: false,
    qbit: false, qbitCategories: [], qbitCategory: "", arr: {},
    view: "search", health: [], transfersTab: "live", transferFilter: "all", transfers: [], history: [],
    qbitNames: new Set(), store: false, notify: false, saved: [],
    user: "", admin: false, users: [],
    // Fiches des médias : `posters` dit si le serveur peut en fournir
    // (Radarr/Sonarr configurés), `postersOn` si l'utilisateur les veut.
    posters: false, postersOn: true,
    // Bibliothèque : ce qui est téléchargé et prêt à regarder.
    library: false, files: [], filesLoaded: false, streamTtl: 43200,
    // Fichiers masqués : cachés par défaut, révélables à la demande.
    showHidden: false, hiddenCount: 0,
    // Récepteurs Cast vus sur le réseau, et l'adresse que le téléviseur devra
    // joindre pour venir chercher la vidéo.
    castDevices: [], castScannedAt: null, castBase: "", castReachable: true,
    // Conversion à la volée disponible côté serveur (ffmpeg présent).
    transcode: false,
};

/* Fiches déjà connues, par titre de release. `null` = cherché, rien trouvé —
   la distinction évite de redemander indéfiniment ce qui n'existe pas. */
const metaCache = new Map();
const metaPending = new Set();

const $ = (s) => document.querySelector(s);
const form = $("#search-form");
const input = $("#q");
const submitBtn = form.querySelector("button");
const daysBox = $("#days");
const catsBox = $("#categories");
const chipsBox = $("#trackers");
const maskBtn = $("#mask-toggle");
const groupBtn = $("#group-toggle");
const posterBtn = $("#poster-toggle");
const libraryBtn = $("#library-btn");
const metaCard = $("#meta-card");
const topBtn = $("#top-btn");
const transfersBtn = $("#transfers-btn");
const accountBtn = $("#account-btn");
const accountName = $("#account-name");
const transfersCount = $("#transfers-count");
const safeBtn = $("#safe-toggle");
const filtersBtn = $("#filters-btn");
const filtersPanel = $("#filters-panel");
const filtersCount = $("#filters-count");
const filtersReset = $("#filters-reset");
const resultsBox = $("#results");
const facetsBox = $("#facets");
const facetsBody = $("#facets-body");
const savedBox = $("#saved");
const savedList = $("#saved-list");
const savedName = $("#saved-name");
const savedSave = $("#saved-save");
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

/* ---------- regroupement ---------- */
function applyGrouping() {
    if (!groupBtn) return;
    groupBtn.setAttribute("aria-pressed", String(state.grouping));
}
if (groupBtn) {
    groupBtn.addEventListener("click", () => {
        state.grouping = !state.grouping;
        try { localStorage.setItem("grouping", state.grouping ? "1" : "0"); } catch (e) {}
        applyGrouping();
        // Rien à redemander au serveur : on regroupe ce qu'on a déjà.
        state.results = groupResults(state.rawResults);
        state.page = 1;
        renderResults();
    });
}

/* ---------- affiches ---------- */
function applyPosters() {
    if (!posterBtn) return;
    // Le bouton n'apparaît que si le serveur sait fournir des fiches.
    posterBtn.hidden = !state.posters;
    posterBtn.setAttribute("aria-pressed", String(state.postersOn));
    document.body.classList.toggle("posters-off", !state.postersOn);
}
if (posterBtn) {
    posterBtn.addEventListener("click", () => {
        state.postersOn = !state.postersOn;
        try { localStorage.setItem("posters", state.postersOn ? "1" : "0"); } catch (e) {}
        applyPosters();
        hideMetaCard();
        renderResults();
    });
}

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
function parseQuery(raw) {
    const exclude = [];
    const kept = [];
    for (const part of String(raw).trim().split(/\s+/)) {
        if (part.length > 1 && part.startsWith("-")) exclude.push(part.slice(1).toLowerCase());
        else if (part) kept.push(part);
    }
    return { query: kept.join(" "), exclude };
}

/** Requête telle qu'on la réécrit dans l'URL et l'historique, exclusions comprises. */
function fullQuery() {
    return [state.query, ...state.facets.exclude.map((m) => "-" + m)].join(" ").trim();
}

form.addEventListener("submit", (e) => {
    e.preventDefault();
    setView("search");
    state.mode = "search";
    const parsed = parseQuery(input.value);
    state.query = parsed.query;
    state.facets.exclude = parsed.exclude;
    state.page = 1;
    pushHistory(fullQuery());
    runSearch();
});

/* ---------- Top (découverte sans recherche) ---------- */
if (topBtn) {
    topBtn.addEventListener("click", () => {
        setView("search");
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
    selIndex = -1;
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
        state.rawResults = data.results || [];
        state.results = groupResults(state.rawResults);
        state.total = data.total || state.rawResults.length;
        state.capped = !!data.capped;
        state.fetched = state.rawResults.length;
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

/* ---------- regroupement des doublons ----------
   La même release est publiée sur plusieurs trackers : sur une recherche
   courante, jusqu'à 18 % des lignes sont des répétitions. On les réunit en une
   seule entrée, en gardant la meilleure source (le plus de seeders) et en
   conservant les autres sous la main.

   Le rapprochement se fait sur le nom complet normalisé, groupe de release
   compris : deux encodages différents du même film restent deux lignes. Mieux
   vaut un doublon affiché qu'une release masquée à tort. */
function groupKey(title) {
    return title.toLowerCase().replace(/[^a-z0-9]/g, "");
}

function groupResults(list) {
    // `best` désigne la source qui mène l'entrée. L'entrée étant une copie, on
    // ne peut pas la comparer par identité aux éléments de `sources` : sans ce
    // repère, chaque ligne se croirait dupliquée avec elle-même.
    if (!state.grouping) return list.map((r) => ({ ...r, best: r, sources: [r] }));

    const groups = new Map();
    for (const r of list) {
        const key = groupKey(r.title);
        const existing = groups.get(key);
        if (!existing) {
            groups.set(key, { ...r, best: r, sources: [r] });
            continue;
        }
        existing.sources.push(r);
        // La meilleure source mène l'entrée : c'est elle qu'on télécharge.
        if (r.seeders > existing.seeders) {
            groups.set(key, { ...r, best: r, sources: existing.sources });
        }
    }
    return [...groups.values()];
}

/* ---------- facettes + tri ---------- */
function facetFiltered() {
    const f = state.facets;
    // Le -18 (safe-mode) est filtré CÔTÉ SERVEUR (api.php) — pas de filtrage
    // cosmétique ici : le contenu adulte n'arrive même pas si safe-mode est actif.
    return state.results.filter((x) => {
        if (f.minSeeders > 0 && x.seeders < f.minSeeders) return false;
        if (f.freeleech && !x.freeleech) return false;
        if (f.quality.size && ![...f.quality].every((q) => (x.badges || []).includes(q))) return false;
        if (f.sizes.size) {
            const dans = SIZES.some((b) => f.sizes.has(b.id) && x.size >= b.min && x.size < b.max);
            if (!dans) return false;
        }
        if (f.exclude.length) {
            const titre = x.title.toLowerCase();
            if (f.exclude.some((mot) => titre.includes(mot))) return false;
        }
        return true;
    });
}
function sortResults(arr) {
    const { field, dir } = state.sort;
    const mul = dir === "asc" ? 1 : -1;
    if (field === "relevance") {
        // Sans requête (mode Top), la pertinence n'a pas de sens : on retombe
        // sur la date, qui est l'intérêt de ce mode.
        if (!state.query) return sortBy(arr, "publishDate", -1);
        return [...arr].sort((a, b) =>
            ((relevance(b.title, state.query) - relevance(a.title, state.query)) || (b.seeders - a.seeders)) * (dir === "asc" ? -1 : 1));
    }
    return sortBy(arr, field, mul);
}

function sortBy(arr, field, mul) {
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

    // Tranches de taille : on n'affiche que celles qui contiennent quelque chose,
    // avec leur nombre — sinon on clique à l'aveugle.
    const tailles = el("div", { class: "chips" });
    for (const b of SIZES) {
        const n = state.results.filter((r) => r.size >= b.min && r.size < b.max).length;
        if (!n) continue;
        const chip = facetChip(`${b.l} · ${n}`, () => state.facets.sizes.has(b.id),
            () => { state.facets.sizes.has(b.id) ? state.facets.sizes.delete(b.id) : state.facets.sizes.add(b.id); });
        tailles.append(chip);
    }

    const chips = el("div", { class: "chips" });
    if (hasFree) chips.append(facetChip("Freeleech", () => state.facets.freeleech,
        () => { state.facets.freeleech = !state.facets.freeleech; }));
    QUALITY_ORDER.filter((q) => present.has(q)).forEach((q) => {
        chips.append(facetChip(q, () => state.facets.quality.has(q),
            () => { state.facets.quality.has(q) ? state.facets.quality.delete(q) : state.facets.quality.add(q); }));
    });

    const parts = [
        el("label", { class: "facet" }, el("span", { class: "facet-lbl", text: "Seeders" }), slider, seedVal),
    ];
    if (tailles.children.length) parts.push(tailles);
    parts.push(chips);
    facetsBody.replaceChildren(...parts);
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
        + state.facets.quality.size
        + state.facets.sizes.size;
}

function clearFacets() {
    // `exclude` vient de la requête (« matrix -animated »), pas d'un clic :
    // il est reconstruit à chaque recherche, pas remis à zéro ici.
    state.facets = {
        minSeeders: 0, freeleech: false, quality: new Set(),
        sizes: new Set(), exclude: state.facets ? state.facets.exclude : [],
    };
}

/** Jetons des facettes actives, retirables d'un clic, au-dessus des résultats. */
function activeFilterChips() {
    if (!facetCount() && !state.facets.exclude.length) return null;
    const wrap = el("span", { class: "active-filters" });
    const drop = (fn) => () => { fn(); state.page = 1; renderFacets(); renderResults(); };

    // Mots exclus : retirés du champ de recherche quand on les enlève.
    state.facets.exclude.forEach((mot) => {
        const c = el("button", { type: "button", class: "af-chip af-excl", text: "− " + mot });
        c.addEventListener("click", () => {
            input.value = input.value.replace(new RegExp("\\s*-" + mot.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "gi"), "");
            form.requestSubmit();
        });
        wrap.append(c);
    });

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
    [...state.facets.sizes].forEach((id) => {
        const b = SIZES.find((x) => x.id === id);
        if (!b) return;
        const c = el("button", { type: "button", class: "af-chip", text: b.l });
        c.addEventListener("click", drop(() => state.facets.sizes.delete(id)));
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
    else if (state.total > state.rawResults.length) left.append(el("span", { class: "muted", text: ` sur ${state.total}` }));
    const replies = state.rawResults.length - state.results.length;
    if (replies > 0) left.append(el("span", { class: "muted", text: ` · ${replies} doublon${replies > 1 ? "s" : ""} replié${replies > 1 ? "s" : ""}` }));

    const right = el("span", { class: "meta-actions" });
    if (state.days !== 0) {
        const btn = el("button", { type: "button", class: "link-btn", text: "Élargir à toutes les dates" });
        btn.addEventListener("click", () => { setDays(0); state.page = 1; runSearch(); });
        right.append(btn);
    } else if (state.capped) {
        right.append(el("span", { class: "muted", text: `${state.total - state.fetched} encore à charger` }));
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
    if (shown.length >= all.length && state.capped) {
        more = el("button", { type: "button", class: "load-more",
            text: `Charger la suite (${state.total - state.fetched} restants)` });
        more.addEventListener("click", loadMore);
        parts.push(more);
    } else if (shown.length < all.length) {
        more = el("button", { type: "button", class: "load-more",
            text: `Charger plus (${all.length - shown.length} restants)` });
        more.addEventListener("click", loadMore);
        parts.push(more);
    }
    resultsBox.replaceChildren(...parts);
    observeMore(more);
    paintSelection(false);
    hideMetaCard();
    // Les fiches arrivent après coup : la liste s'affiche sans attendre
    // Radarr/Sonarr, les vignettes se posent quand elles sont là.
    hydrateMeta(shown);
}

function loadMore() {
    const affichees = state.page * PAGE_SIZE;
    // Tant qu'il reste des lignes déjà reçues, on les déplie sans rien demander.
    if (affichees < visibleResults().length || !state.capped) {
        state.page++;
        renderResults();
        return;
    }
    fetchMore();
}

/**
 * Étend la liste depuis le serveur. Jusqu'ici on s'arrêtait à RESULT_LIMIT en
 * conseillant d'affiner : le reste des résultats existait mais restait
 * inaccessible.
 */
async function fetchMore() {
    if (state.loadingMore || !state.capped) return;
    state.loadingMore = true;

    const p = new URLSearchParams({ action: "search", days: state.days, offset: state.fetched });
    if (state.mode === "top") p.set("top", "1"); else p.set("q", state.query);
    if (!state.safeMode) p.set("safe", "0");
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));

    try {
        const res = await fetch("api.php?" + p.toString());
        const data = await res.json();
        if (res.status === 401) { location.href = "login.php"; return; }
        if (!data.error && (data.results || []).length) {
            state.rawResults = state.rawResults.concat(data.results);
            state.fetched = state.rawResults.length;
            state.results = groupResults(state.rawResults);
            state.capped = !!data.capped;
            state.page++;
            renderFacets();
            renderResults();
        } else {
            state.capped = false;
            renderResults();
        }
    } catch (e) {
        toast("Impossible de charger la suite");
    } finally {
        state.loadingMore = false;
    }
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
        // La première colonne bascule entre « Release » (tri alphabétique) et
        // « Pertinence » : deux tris pour un même en-tête, alternés au clic.
        const actifAlt = col.alt && state.sort.field === col.alt;
        const th = el("th", { text: actifAlt ? col.altLabel : col.label, class: col.num ? "num" : (col.cls || "") });
        if (col.sortable) {
            th.classList.add("sortable");
            th.append(el("span", { class: "arrow", text: "▲" }));
            const active = state.sort.field === col.key || actifAlt;
            if (active) th.classList.add("sort-active", state.sort.dir === "desc" ? "sort-desc" : "sort-asc");
            // Tri accessible au clavier (Entrée / Espace) et annoncé aux lecteurs d'écran.
            th.tabIndex = 0;
            th.setAttribute("aria-sort", active ? (state.sort.dir === "asc" ? "ascending" : "descending") : "none");
            const cible = () => (col.alt && state.sort.field === col.key ? col.alt : col.key);
            th.addEventListener("click", () => toggleSort(cible()));
            th.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggleSort(cible()); }
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

/** Contenu d'un .torrent, déplié sous la ligne. */
async function showContents(r, btn) {
    const tr = btn.closest("tr");
    const suivant = tr.nextElementSibling;
    if (suivant && suivant.classList.contains("files-row")) { suivant.remove(); return; }

    const cell = el("td", { colspan: String(COLUMNS.length) },
        el("div", { class: "files-load", text: "Lecture du .torrent…" }));
    const row = el("tr", { class: "files-row" }, cell);
    tr.after(row);

    try {
        const res = await fetch("api.php?action=contents&" + new URLSearchParams({ token: r.dl.token }));
        const data = await res.json();
        if (!res.ok || data.error) {
            cell.replaceChildren(el("div", { class: "files-load", text: data.error || "Lecture impossible" }));
            return;
        }
        const liste = el("div", { class: "files-list" });
        for (const f of data.files) {
            liste.append(el("div", { class: "files-line" },
                el("span", { class: "files-path", text: f.path }),
                el("span", { class: "src-num", text: f.sizeHuman })));
        }
        const entete = el("div", { class: "files-head" },
            el("b", { text: `${data.files.length} fichier${data.files.length > 1 ? "s" : ""}` }),
            el("span", { class: "muted", text: ` · ${data.sizeHuman}` }));
        cell.replaceChildren(entete, liste);
    } catch (e) {
        cell.replaceChildren(el("div", { class: "files-load", text: "Lecture impossible" }));
    }
}

/** Déplie (ou replie) la liste des autres trackers d'une release groupée. */
function toggleSources(tr, others, btn) {
    const open = tr.nextElementSibling && tr.nextElementSibling.classList.contains("src-row");
    if (open) {
        tr.nextElementSibling.remove();
        btn.setAttribute("aria-expanded", "false");
        return;
    }
    const list = el("div", { class: "src-list" });
    for (const s of others) {
        const line = el("div", { class: "src-line" },
            el("span", { class: "idx-tag maskable", text: s.indexer }),
            el("span", { class: "src-num", text: s.sizeHuman }),
            el("span", { class: "src-num", text: `${s.seeders} seed` }));
        line.append(renderActions(s));
        list.append(line);
    }
    const row = el("tr", { class: "src-row" }, el("td", { colspan: String(COLUMNS.length) }, list));
    tr.after(row);
    btn.setAttribute("aria-expanded", "true");
}

/* ---------- fiches : vignette dans la ligne, fiche au survol ----------
   Ce que ça remplace : ouvrir la page du tracker — donc s'y connecter — juste
   pour savoir de quel film il s'agit. */

/** Emplacement de la vignette d'une release, rempli dès la fiche connue. */
function posterSlot(r) {
    const box = el("div", { class: "poster" });
    box.dataset.rel = r.title;
    paintPosterSlot(box);
    return box;
}

function paintPosterSlot(box) {
    const titre = box.dataset.rel || "";
    const m = metaCache.get(titre);
    box.replaceChildren();
    box.classList.toggle("poster-blank", !m || !m.poster);

    if (m && m.poster) {
        const img = el("img", {
            src: "poster.php?t=" + encodeURIComponent(m.poster),
            alt: m.title ? `Affiche de ${m.title}` : "", loading: "lazy", decoding: "async",
        });
        // Une affiche introuvable ne doit pas laisser d'icône cassée.
        img.addEventListener("error", () => { img.remove(); box.classList.add("poster-blank"); });
        box.append(img);
    } else if (m) {
        box.append(el("span", { class: "poster-initial", text: (m.title || "?").trim().charAt(0).toUpperCase() }));
    }

    if (!m) return;
    box.tabIndex = 0;
    box.setAttribute("role", "button");
    box.setAttribute("aria-label", `Fiche de ${m.title}`);
    box.title = m.title + (m.year ? ` (${m.year})` : "");
}

/**
 * Complète les lignes affichées par leur fiche.
 *
 * Un seul aller-retour pour tout l'écran, et les titres qui désignent la même
 * œuvre n'y comptent qu'une fois — quarante releases de Matrix, c'est une
 * recherche côté Radarr, pas quarante.
 */
async function hydrateMeta(rows) {
    if (!state.posters || !state.postersOn) return;

    const lot = [];
    const vus = new Set();
    for (const r of rows) {
        // Sans nature connue (musique, logiciels, livres), il n'y a pas de fiche
        // à aller chercher : on n'interroge pas Radarr pour un album.
        if (!r.kind || vus.has(r.title)) continue;
        if (metaCache.has(r.title) || metaPending.has(r.title)) continue;
        vus.add(r.title);
        lot.push({ title: r.title, kind: r.kind, imdbId: r.imdbId || "", tmdbId: r.tmdbId || 0 });
        if (lot.length >= 60) break;
    }
    if (!lot.length) return;

    lot.forEach((i) => metaPending.add(i.title));
    try {
        const res = await fetch("api.php?action=meta", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
            body: JSON.stringify(lot),
        });
        if (res.status === 401) { location.href = "login.php"; return; }
        const data = await res.json();
        if (data.enabled === false) { state.posters = false; applyPosters(); return; }

        for (const [titre, fiche] of Object.entries(data.meta || {})) metaCache.set(titre, fiche);
        // Un titre sans réponse est un titre sans fiche : on l'enregistre pour
        // ne pas le redemander à chaque défilement.
        for (const i of lot) if (!metaCache.has(i.title)) metaCache.set(i.title, null);
        repaintPosters();
    } catch (e) {
        // Une fiche absente n'est pas une panne : la recherche reste utilisable.
    } finally {
        lot.forEach((i) => metaPending.delete(i.title));
    }
}

function repaintPosters() {
    for (const box of resultsBox.querySelectorAll(".poster")) paintPosterSlot(box);
}

/* La fiche est unique et se déplace : une par ligne encombrerait le document
   pour rien, et il n'en est visible qu'une à la fois. */
let metaTimer = null;
let metaAnchor = null;

function hideMetaCard() {
    clearTimeout(metaTimer);
    if (!metaCard) return;
    metaCard.hidden = true;
    metaCard.replaceChildren();
    metaAnchor = null;
}

function showMetaCard(box) {
    const m = metaCache.get(box.dataset.rel || "");
    if (!metaCard || !m) return;
    metaAnchor = box;

    const entete = el("div", { class: "mc-head" },
        el("b", { class: "mc-title", text: m.title }),
        m.year ? el("span", { class: "mc-year", text: String(m.year) }) : null);

    const faits = el("div", { class: "mc-facts" });
    if (m.rating) faits.append(el("span", { class: "mc-rating", text: "★ " + m.rating.toFixed(1) }));
    if (m.runtime) faits.append(el("span", { text: m.runtime + " min" }));
    if (m.kind === "tv") faits.append(el("span", { text: "Série" }));
    for (const g of m.genres || []) faits.append(el("span", { class: "mc-genre", text: g }));

    // Affiche à gauche, texte à droite. Empilés, ils faisaient une fiche plus
    // haute que l'écran, qui recouvrait la ligne dont elle parlait.
    const info = el("div", { class: "mc-info" }, entete);
    if (faits.childElementCount) info.append(faits);

    const haut = el("div", { class: "mc-top" });
    if (m.poster) {
        haut.append(el("img", { class: "mc-poster",
            src: "poster.php?t=" + encodeURIComponent(m.poster), alt: "", decoding: "async" }));
    }
    haut.append(info);

    const parts = [haut];
    if (m.overview) parts.push(el("p", { class: "mc-overview", text: m.overview }));

    metaCard.replaceChildren(...parts);
    metaCard.hidden = false;
    placeMetaCard(box);
}

/** Place la fiche à côté de la vignette, du côté où il y a la place. */
function placeMetaCard(box) {
    const r = box.getBoundingClientRect();
    const w = metaCard.offsetWidth;
    const h = metaCard.offsetHeight;
    const marge = 10;

    // En dessous de 620 px de large, l'écran ne permet aucun « à côté » : la
    // fiche devient un panneau bas, posé par la CSS.
    if (window.innerWidth < 620) {
        metaCard.style.left = "";
        metaCard.style.top = "";
        return;
    }

    let x = r.right + marge;
    if (x + w > window.innerWidth - marge) x = Math.max(marge, r.left - w - marge);
    let y = r.top + r.height / 2 - h / 2;
    y = Math.max(marge, Math.min(y, window.innerHeight - h - marge));

    metaCard.style.left = Math.round(x) + "px";
    metaCard.style.top = Math.round(y) + "px";
}

/* Survol au pointeur, clic au doigt : la même fiche, deux gestes. Le délai
   évite qu'elle clignote quand le curseur ne fait que traverser la liste. */
resultsBox.addEventListener("pointerover", (e) => {
    const box = e.target.closest(".poster");
    if (!box || !metaCache.get(box.dataset.rel || "")) return;
    if (e.pointerType === "touch" || box === metaAnchor) return;
    clearTimeout(metaTimer);
    metaTimer = setTimeout(() => showMetaCard(box), 140);
});
resultsBox.addEventListener("pointerout", (e) => {
    const box = e.target.closest(".poster");
    if (!box) return;
    if (e.relatedTarget && (e.relatedTarget.closest(".poster") === box
        || e.relatedTarget.closest("#meta-card"))) return;
    clearTimeout(metaTimer);
    metaTimer = setTimeout(hideMetaCard, 120);
});
resultsBox.addEventListener("click", (e) => {
    const box = e.target.closest(".poster");
    if (!box || !metaCache.get(box.dataset.rel || "")) return;
    e.preventDefault();
    if (box === metaAnchor && !metaCard.hidden) hideMetaCard(); else showMetaCard(box);
});
resultsBox.addEventListener("keydown", (e) => {
    const box = e.target.closest(".poster");
    if (!box || (e.key !== "Enter" && e.key !== " ")) return;
    e.preventDefault();
    if (box === metaAnchor && !metaCard.hidden) hideMetaCard(); else showMetaCard(box);
});
if (metaCard) {
    metaCard.addEventListener("pointerleave", () => { metaTimer = setTimeout(hideMetaCard, 120); });
    metaCard.addEventListener("pointerenter", () => clearTimeout(metaTimer));
}
// La fiche est posée en coordonnées d'écran : un défilement la laisserait
// flotter loin de sa vignette.
window.addEventListener("scroll", () => { if (metaAnchor) hideMetaCard(); }, { passive: true });
document.addEventListener("click", (e) => {
    if (metaAnchor && !e.target.closest(".poster") && !e.target.closest("#meta-card")) hideMetaCard();
});
document.addEventListener("keydown", (e) => { if (e.key === "Escape" && metaAnchor) hideMetaCard(); });

function renderRow(r) {
    const tr = el("tr");

    // Liseré de qualité : niveau de résolution lisible d'un coup d'œil vertical.
    const badges = r.badges || [];
    if (badges.includes("2160p")) tr.classList.add("q-uhd");
    else if (badges.includes("1080p")) tr.classList.add("q-hd");

    const titleCell = el("td", { class: "cell-title" });
    // Vignette et texte côte à côte dans un conteneur interne : la cellule
    // elle-même reste une cellule de tableau, dont l'élision du titre dépend.
    const ligne = el("div", { class: "cell-title-row" });
    // La vignette n'a de sens que pour un film ou une série : ailleurs, elle ne
    // ferait qu'un trou dans la colonne.
    if (state.posters && state.postersOn && r.kind) ligne.append(posterSlot(r));
    const corps = el("div", { class: "cell-title-body" });
    const linkable = r.infoUrl && r.infoUrl !== "#";
    const titleNode = linkable
        ? el("a", { class: "rel", href: r.infoUrl, target: "_blank", rel: "noopener noreferrer", title: r.title })
        : el("span", { class: "rel", title: r.title });
    titleNode.append(renderRelease(r.title));
    corps.append(titleNode);

    const meta = el("div", { class: "rel-meta" });
    meta.append(el("span", { class: "idx-tag maskable", text: r.indexer }));

    // Autres trackers proposant la même release : repliés, jamais perdus.
    // Sur un tracker privé, choisir sa source a des conséquences de ratio.
    const others = (r.sources || []).filter((s) => s !== r.best);
    if (others.length) {
        const btn = el("button", {
            type: "button", class: "src-btn", text: `+${others.length}`,
            title: `Aussi sur : ${others.map((s) => s.indexer).join(", ")}`,
            "aria-expanded": "false",
        });
        btn.addEventListener("click", () => toggleSources(tr, others, btn));
        meta.append(btn);
    }

    if (r.category) {
        meta.append(el("span", { class: "sep", text: "·" }), el("span", { text: r.category }));
    }
    if (r.freeleech) meta.append(el("span", { class: "badge-fl", text: "FREE" }));
    if (r.adult) meta.append(el("span", { class: "badge-adult", text: "-18" }));
    // Ce que nous avons envoyé est certain (titre enregistré tel quel).
    // Le rapprochement par nom avec qBittorrent n'est qu'un repli : il renomme
    // parfois les torrents.
    if (r.sent) {
        const quand = new Date(r.sent.at * 1000).toLocaleDateString("fr-FR");
        meta.append(el("span", { class: "badge-have",
            title: `Envoyé le ${quand} vers ${r.sent.target === "qbit" ? "qBittorrent" : r.sent.target}`,
            text: "DÉJÀ PRIS" }));
    } else if (state.qbitNames.has(normalizeName(r.title))) {
        meta.append(el("span", { class: "badge-have", title: "Déjà présent dans qBittorrent", text: "DANS QBIT" }));
    }
    corps.append(meta);
    ligne.append(corps);
    titleCell.append(ligne);
    tr.append(titleCell);

    tr.append(el("td", { class: "num" }, r.sizeHuman));

    const sc = r.seeders >= 20 ? "s-good" : r.seeders >= 5 ? "s-mid" : "s-low";
    const seedCell = el("td", { class: "num" },
        el("span", { class: "seed " + sc }, el("span", { class: "dot" }), String(r.seeders)));
    if (r.leechers) seedCell.append(el("span", { class: "leech", text: " /" + r.leechers }));
    tr.append(seedCell);

    // Certains trackers publient une date dans le futur : un âge négatif
    // n'apprend rien, on l'affiche comme du jour même.
    const age = r.daysOld == null ? "—" : (r.daysOld <= 0 ? "auj." : r.daysOld + " j");
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
        wrap.append(el("a", { class: "act act-dl", href: torrentHref(r.dl),
            title: "Télécharger le .torrent", "aria-label": "Télécharger le .torrent" },
            svgIcon(ICONS.download)));
        // Savoir ce qu'il y a dedans avant de le prendre.
        wrap.append(makeBtn("act act-files", ICONS.files, "Voir le contenu du .torrent",
            (btn) => showContents(r, btn)));
        any = true;
    }
    if (r.magnet) {
        wrap.append(el("a", { class: "act act-magnet", href: r.magnet,
            title: "Ouvrir le magnet", "aria-label": "Ouvrir le lien magnet" },
            svgIcon(ICONS.magnet)));
        wrap.append(makeBtn("act act-copy", ICONS.copy, "Copier le magnet", () => copyText(r.magnet)));
        any = true;
    }
    if (state.qbit && r.send) {
        wrap.append(makeBtn("act act-qbit", ICONS.send, "Envoyer à qBittorrent", (btn) => sendTo(r, btn, "qbit")));
        any = true;
    }
    // Applications *arr : elles décident elles-mêmes quoi faire de la release.
    if (r.send) {
        for (const [name, label] of Object.entries(state.arr)) {
            wrap.append(makeBtn("act act-arr act-" + name, ARR_ICONS[name] || ICONS.send,
                `Envoyer à ${label}`, (btn) => sendTo(r, btn, name)));
            any = true;
        }
    }
    if (!any) wrap.append(el("span", { class: "dl-none", text: "—" }));
    return wrap;
}

function makeBtn(cls, icon, title, onClick) {
    // `title` n'est qu'une infobulle : les lecteurs d'écran ne l'annoncent pas
    // de façon fiable. Sans aria-label, ces boutons sont muets.
    const b = el("button", { type: "button", class: cls, title, "aria-label": title });
    b.append(svgIcon(icon));
    b.addEventListener("click", () => onClick(b));
    return b;
}

async function copyText(text, message = "Magnet copié") {
    try { await navigator.clipboard.writeText(text); toast(message); }
    catch (e) { toast("Copie impossible"); }
}

async function sendTo(r, btn, to) {
    btn.disabled = true;
    btn.classList.add("busy");
    const body = new URLSearchParams();
    body.set("token", r.send);
    body.set("to", to);
    // Le titre et l'indexeur servent au rapprochement côté Sonarr/Radarr, et à
    // mémoriser l'envoi pour reconnaître la release plus tard.
    body.set("title", r.title);
    body.set("indexer", r.indexer || "");
    if (to === "qbit") {
        if (state.qbitCategory) body.set("category", state.qbitCategory);
    } else {
        body.set("publishDate", r.publishDate || "");
    }
    try {
        const res = await fetch("send.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: body.toString(),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            btn.classList.add("done");
            toast(data.message || "Envoyé");
            if (to === "qbit") loadTransfers();
        } else {
            btn.classList.remove("busy");
            btn.disabled = false;
            toast(data.error || "Échec de l'envoi");
        }
    } catch (e) {
        btn.classList.remove("busy");
        btn.disabled = false;
        toast("Échec de l'envoi");
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
        state.arr = s.arr || {};
        state.store = !!s.store;
        state.notify = !!s.notify;
        state.posters = !!s.posters;
        state.library = !!s.library;
        state.transcode = !!s.transcode;
        if (libraryBtn) libraryBtn.hidden = !state.library;
        state.user = s.user || "";
        state.admin = !!s.admin;
        applyPosters();
        // Le statut arrive parfois après le premier rendu (recherche restaurée
        // depuis l'URL) : sans ce rappel, les vignettes ne paraîtraient qu'à la
        // recherche suivante.
        if (state.posters && state.postersOn && state.view === "search" && state.results.length) {
            renderResults();
        }
        renderAccountButton();
        if (state.qbit) loadTransfers();
        if (state.store) loadSaved();
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
    else if (state.query) p.set("q", fullQuery());
    if (state.days !== 0) p.set("days", state.days);
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));
    const qs = p.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
}
function readUrl() {
    const p = new URLSearchParams(location.search);
    if (p.get("top") === "1") state.mode = "top";
    if (p.has("q")) {
        const parsed = parseQuery(p.get("q"));
        state.query = parsed.query;
        state.facets.exclude = parsed.exclude;
        input.value = p.get("q").trim();
    }
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
    try { state.grouping = localStorage.getItem("grouping") !== "0"; } catch (e) {}
    try { state.postersOn = localStorage.getItem("posters") !== "0"; } catch (e) {}
    applyMask();
    applySafe();
    applyGrouping();
    applyPosters();
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

/* ==================================================================
   Transferts

   qBittorrent sait déjà tout : progression, ratio, vitesses, état. On ne
   duplique rien, on l'interroge. Le rafraîchissement ne tourne que
   lorsque la vue est ouverte — inutile d'interroger un client en boucle
   pour une page que personne ne regarde.
   ================================================================== */
const TRANSFER_STATES = {
    downloading: "Téléchargement", stalledDL: "En attente de sources",
    metaDL: "Métadonnées", forcedDL: "Téléchargement forcé",
    uploading: "Partage", stalledUP: "Partage (inactif)", forcedUP: "Partage forcé",
    pausedDL: "En pause", pausedUP: "Terminé", stoppedDL: "Arrêté", stoppedUP: "Terminé",
    queuedDL: "En file", queuedUP: "En file", checkingDL: "Vérification",
    checkingUP: "Vérification", checkingResumeData: "Vérification",
    moving: "Déplacement", error: "Erreur", missingFiles: "Fichiers manquants",
    unknown: "Inconnu",
};

/* Les états de qBittorrent sont nombreux ; on ne raisonne que sur quatre
   familles, celles qui décident d'une action. */
const TRANSFER_GROUPS = {
    all: { label: "Tous", match: () => true },
    dl: { label: "En cours", match: (t) => t.progress < 1 && !/^(paused|stopped)/.test(t.state) },
    up: { label: "En partage", match: (t) => t.progress >= 1 && !/^(paused|stopped)/.test(t.state) },
    off: { label: "Arrêtés", match: (t) => /^(paused|stopped)/.test(t.state) },
};

let transfersTimer = null;
let pendingDelete = null;

function normalizeName(s) {
    return String(s).toLowerCase().replace(/[^a-z0-9]/g, "");
}

function formatSize(bytes) {
    if (!bytes) return "0 o";
    const units = ["o", "Ko", "Mo", "Go", "To"];
    let v = bytes, i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v < 10 ? v.toFixed(2) : v.toFixed(1)} ${units[i]}`;
}

function formatSpeed(bytesPerSec) {
    if (!bytesPerSec) return "—";
    const units = ["o/s", "Ko/s", "Mo/s", "Go/s"];
    let v = bytesPerSec, i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v < 10 ? v.toFixed(1) : Math.round(v)} ${units[i]}`;
}

function formatEta(seconds) {
    if (!seconds || seconds >= 8640000) return "—";
    const h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60);
    if (h > 24) return `${Math.floor(h / 24)} j`;
    return h ? `${h} h ${m} min` : `${m} min`;
}

async function loadTransfers() {
    try {
        const res = await fetch("api.php?action=transfers");
        if (res.status === 401) { location.href = "login.php"; return; }
        const data = await res.json();
        state.transfers = data.torrents || [];
        // Sert à marquer les résultats de recherche déjà présents dans le client.
        state.qbitNames = new Set(state.transfers.map((t) => normalizeName(t.name)));
        updateTransfersBadge();
        if (state.view === "transfers") renderTransfers();
    } catch (e) { /* le client peut être momentanément injoignable */ }
}

function updateTransfersBadge() {
    if (!transfersBtn || !transfersCount) return;
    transfersBtn.hidden = !state.qbit;
    const actifs = state.transfers.filter((t) => t.dlspeed > 0 || t.upspeed > 0).length;
    transfersCount.textContent = actifs ? ` · ${actifs}` : "";
    transfersBtn.classList.toggle("has-sel", state.view === "transfers");
}

function setView(view) {
    state.view = view;
    statusBox.classList.toggle("active", view === "health");
    renderAccountButton();
    clearInterval(transfersTimer);
    transfersTimer = null;
    if (view === "transfers") {
        loadTransfers();
        transfersTimer = setInterval(loadTransfers, 3000);
        renderTransfers();
    }
    if (view === "library") {
        renderLibrary();
        // Relu à chaque ouverture : un téléchargement a pu se terminer entre-temps,
        // et un téléviseur a pu être allumé.
        loadCastDevices().then(() => loadLibrary());
    }
    if (view !== "library") { closeCastMenu(); stopAllPlayback(); }
    if (libraryBtn) libraryBtn.classList.toggle("has-sel", view === "library");
    updateTransfersBadge();
}

/* ==================================================================
   Comptes

   APP_PASSWORD reste toujours valable et donne l'accès administrateur :
   c'est ce qui rend impossible de se verrouiller dehors. Les comptes
   nommés s'ajoutent, ils ne remplacent rien.
   ================================================================== */
function renderAccountButton() {
    if (!accountBtn || !accountName) return;
    // Sans base, aucun compte ne peut exister : le bouton n'aurait rien à ouvrir.
    accountBtn.hidden = !(state.admin && state.store);
    accountName.textContent = state.user || "admin";
    accountBtn.classList.toggle("has-sel", state.view === "users");
}

async function loadUsers() {
    try {
        const res = await fetch("api.php?action=users");
        const data = await res.json();
        state.users = data.users || [];
    } catch (e) { state.users = []; }
    if (state.view === "users") renderUsers();
}

function renderUsers() {
    hideFacets();
    observeMore(null);

    const meta = el("div", { class: "meta-row" },
        el("span", {}, el("b", { text: String(state.users.length) }),
            ` compte${state.users.length > 1 ? "s" : ""} nommé${state.users.length > 1 ? "s" : ""}`),
        el("span", { class: "meta-actions" },
            el("span", { class: "muted", text: "Le mot de passe partagé reste l'accès administrateur" })));

    // Création : un nom, un mot de passe. Le serveur refuse en dessous de
    // 12 caractères — inutile de créer un compte plus faible que la porte.
    const nom = el("input", { type: "text", class: "saved-input", maxlength: "32",
        placeholder: "Nom d'utilisateur", "aria-label": "Nom d'utilisateur" });
    const mdp = el("input", { type: "password", class: "saved-input",
        placeholder: "Mot de passe (12 caractères minimum)", "aria-label": "Mot de passe" });
    const creer = el("button", { type: "button", class: "del-choice", text: "Créer le compte" });
    const creation = async () => {
        const { ok, data } = await postForm("users.php",
            { op: "add", name: nom.value.trim(), password: mdp.value });
        toast(ok ? data.message : (data.error || "Échec"));
        if (ok) { nom.value = ""; mdp.value = ""; loadUsers(); }
    };
    creer.addEventListener("click", creation);
    mdp.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); creation(); } });
    const form = el("div", { class: "users-add" }, nom, mdp, creer);
    const sauvegarde = renderBackup();

    if (!state.users.length) {
        resultsBox.replaceChildren(meta, form, el("div", { class: "state" },
            el("span", { class: "emoji", text: "👤" }),
            "Aucun compte nommé. Tout le monde entre avec le mot de passe partagé."),
            sauvegarde);
        return;
    }

    const rows = state.users.map((u) => {
        const tr = el("tr");
        const cell = el("td", { class: "cell-title" });
        cell.append(el("span", { class: "rel" }, el("span", { class: "rel-name", text: u.name })));
        cell.append(el("div", { class: "rel-meta" }, el("span", {
            text: u.last_login
                ? "dernière connexion le " + new Date(u.last_login * 1000).toLocaleDateString("fr-FR")
                : "jamais connecté",
        })));
        tr.append(cell);
        tr.append(el("td", { class: "num" },
            new Date(u.created_at * 1000).toLocaleDateString("fr-FR")));

        // Indexeurs autorisés : la case décochée n'est pas cosmétique, elle
        // empêche réellement ce compte d'interroger le tracker — et donc
        // d'utiliser les identifiants d'un autre.
        const permis = new Set(String(u.indexers || "").split(",").map(Number).filter(Boolean));
        const liste = el("div", { class: "chips user-idx" });
        for (const ix of lastIndexers) {
            const chip = el("button", { type: "button", class: "chip" + (permis.size === 0 || permis.has(ix.id) ? " active" : "") },
                el("span", { class: "maskable", text: ix.name }));
            chip.title = permis.size === 0 ? "Aucune restriction : tous les indexeurs" : "Autoriser / retirer";
            chip.addEventListener("click", async () => {
                const courant = permis.size === 0 ? new Set(lastIndexers.map((i) => i.id)) : new Set(permis);
                courant.has(ix.id) ? courant.delete(ix.id) : courant.add(ix.id);
                // Tout coché revient à « aucune restriction ».
                const tous = courant.size === lastIndexers.length;
                const { ok, data } = await postForm("users.php", {
                    op: "indexers", id: u.id, indexers: tous ? "" : [...courant].join(","),
                });
                toast(ok ? data.message : (data.error || "Échec"));
                loadUsers();
            });
            liste.append(chip);
        }
        cell.append(el("div", { class: "user-idx-label", text: permis.size === 0
            ? "Tous les indexeurs — ce compte utilise vos identifiants"
            : `${permis.size} indexeur(s) autorisé(s)` }));
        cell.append(liste);

        // Catégorie qBittorrent imposée : les téléchargements de ce compte
        // atterrissent dans son propre dossier au lieu du tas commun.
        const cat = el("input", { type: "text", class: "saved-input cat-input", maxlength: "40",
            placeholder: "Catégorie qBittorrent (facultatif)",
            "aria-label": `Catégorie qBittorrent de ${u.name}` });
        cat.value = u.category || "";
        const enregistrer = async () => {
            const { ok, data } = await postForm("users.php",
                { op: "category", id: u.id, category: cat.value });
            toast(ok ? data.message : (data.error || "Échec"));
            loadUsers();
        };
        cat.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); enregistrer(); } });
        cat.addEventListener("blur", () => { if (cat.value !== (u.category || "")) enregistrer(); });
        cell.append(el("div", { class: "user-cat" }, cat));

        const actions = el("div", { class: "actions" });
        const suppr = makeBtn("act act-del", ICONS.trash, "Supprimer ce compte", async (btn) => {
            btn.disabled = true;
            const { ok, data } = await postForm("users.php", { op: "delete", id: u.id });
            toast(ok ? data.message : (data.error || "Échec"));
            loadUsers();
        });
        actions.append(suppr);
        tr.append(el("td", { class: "num" }, actions));
        return tr;
    });

    const table = el("table", {},
        el("thead", {}, el("tr", {},
            el("th", { text: "Compte" }),
            el("th", { class: "num", text: "Créé le" }),
            el("th", { class: "num", text: "" }))),
        el("tbody", {}, ...rows));

    resultsBox.replaceChildren(meta, form, el("div", { class: "table-wrap" }, table), sauvegarde);
}

/**
 * Sauvegarde et restauration de la base.
 *
 * Le fichier contient les empreintes de mots de passe et les jetons de flux :
 * il se traite comme un secret, et la restauration est annoncée pour ce qu'elle
 * est — un remplacement, pas une fusion.
 */
function renderBackup() {
    const telecharger = el("a", { class: "del-choice", href: "backup.php",
        text: "Télécharger la sauvegarde" });
    telecharger.setAttribute("download", "");

    const champ = el("input", { type: "file", class: "saved-input", accept: ".sqlite,.db",
        "aria-label": "Fichier de sauvegarde à restaurer" });
    const restaurer = el("button", { type: "button", class: "del-choice danger",
        text: "Restaurer" });

    // Deux temps, comme les autres suppressions de l'app : une restauration
    // remplace la base, elle ne la fusionne pas.
    let confirmee = false;
    const repos = () => {
        confirmee = false;
        restaurer.textContent = "Restaurer";
        restaurer.classList.remove("confirm");
    };
    champ.addEventListener("change", repos);

    restaurer.addEventListener("click", async () => {
        const fichier = champ.files && champ.files[0];
        if (!fichier) { toast("Choisissez d'abord un fichier."); return; }
        if (!confirmee) {
            confirmee = true;
            restaurer.textContent = "Confirmer le remplacement";
            restaurer.classList.add("confirm");
            return;
        }
        restaurer.disabled = true;
        const corps = new FormData();
        corps.append("fichier", fichier);
        try {
            const res = await fetch("backup.php", {
                method: "POST", headers: { "X-CSRF-Token": CSRF }, body: corps,
            });
            const data = await res.json().catch(() => ({}));
            toast(res.ok && data.ok ? data.message : (data.error || "Restauration refusée"));
            if (res.ok && data.ok) { champ.value = ""; loadUsers(); }
        } catch (e) {
            toast("Restauration impossible.");
        }
        restaurer.disabled = false;
        repos();
    });

    return el("div", { class: "backup" },
        el("div", { class: "backup-title", text: "Sauvegarde" }),
        el("div", { class: "muted",
            text: "Comptes, recherches enregistrées, historique et jetons de flux. Gardez ce fichier autant que votre mot de passe : il les contient." }),
        el("div", { class: "backup-row" }, telecharger, champ, restaurer));
}

if (accountBtn) {
    accountBtn.addEventListener("click", () => {
        if (state.view === "users") { setView("search"); renderResults(); return; }
        setView("users");
        loadUsers();
        renderUsers();
    });
}

/* ---------- santé des indexeurs ---------- */
async function loadHealth() {
    try {
        const res = await fetch("api.php?action=health");
        const data = await res.json();
        state.health = data.indexers || [];
    } catch (e) { state.health = []; }
    if (state.view === "health") renderHealth();
}

function renderHealth() {
    hideFacets();
    observeMore(null);

    if (!state.health.length) {
        resultsBox.replaceChildren(el("div", { class: "state" },
            el("span", { class: "emoji", text: "📡" }),
            "Aucune statistique. Prowlarr les accumule au fil des recherches."));
        return;
    }

    const lents = state.health.filter((i) => i.latency >= 1500).length;
    const meta = el("div", { class: "meta-row" },
        el("span", {}, el("b", { text: String(state.health.length) }), " indexeurs"),
        el("span", { class: "meta-actions" }, el("span", { class: "muted",
            text: lents ? `${lents} au-dessus de 1,5 s — ce sont eux qui font traîner les recherches` : "Tous réactifs" })));

    const head = el("tr", {},
        el("th", { text: "Indexeur" }),
        el("th", { class: "num", text: "Latence" }),
        el("th", { class: "num", text: "Requêtes" }),
        el("th", { class: "num", text: "Échecs" }),
        el("th", { class: "num", text: "Grabs" }));

    const rows = state.health.map((i) => {
        const tr = el("tr");
        const cell = el("td", { class: "cell-title" });
        cell.append(el("span", { class: "rel" }, el("span", { class: "rel-name maskable", text: i.name })));
        if (i.disabled) {
            cell.append(el("div", { class: "rel-meta" },
                el("span", { class: "badge-adult", text: "DÉSACTIVÉ PAR PROWLARR" })));
        }
        tr.append(cell);

        // La latence est la donnée qui explique un délai dépassé : on la colore.
        const lat = i.latency >= 1500 ? "s-low" : i.latency >= 700 ? "s-mid" : "s-good";
        tr.append(el("td", { class: "num" },
            el("span", { class: "seed " + lat, text: i.latency ? i.latency + " ms" : "—" })));
        tr.append(el("td", { class: "num" }, String(i.queries)));
        const ech = el("td", { class: "num" }, String(i.failed));
        if (i.failed > 0) ech.style.color = "var(--warn)";
        tr.append(ech);
        tr.append(el("td", { class: "num" }, String(i.grabs)));
        return tr;
    });

    resultsBox.replaceChildren(meta,
        el("div", { class: "table-wrap" }, el("table", {}, el("thead", {}, head), el("tbody", {}, ...rows))));
}

if (statusBox) {
    statusBox.addEventListener("click", () => {
        if (state.view === "health") { setView("search"); renderResults(); return; }
        setView("health");
        loadHealth();
        renderHealth();
    });
}

if (transfersBtn) {
    transfersBtn.addEventListener("click", () => {
        setView(state.view === "transfers" ? "search" : "transfers");
        if (state.view === "search") renderResults();
    });
}

/* ==================================================================
   Bibliothèque

   Ce qui a été téléchargé, prêt à regarder. Deux façons de lire, parce
   qu'aucune ne suffit seule : le navigateur pour ce qu'il sait décoder
   (MP4/WebM), et un lien à ouvrir dans VLC pour tout le reste — un MKV
   en HEVC avec du DTS ne passera jamais dans un onglet, mais VLC le lit
   sur n'importe quelle télévision.

   Pas de transcodage : ce serait un autre projet, et ffmpeg sur un
   NAS pour convertir 20 Go à la volée n'est pas une bonne idée.
   ================================================================== */
/* Carte en attente de confirmation de suppression (jeton de flux). */
let pendingLibDelete = null;

async function loadLibrary() {
    try {
        const res = await fetch("api.php?action=library" + (state.showHidden ? "&all=1" : ""));
        if (res.status === 401) { location.href = "login.php"; return; }
        const data = await res.json();
        state.files = data.files || [];
        state.hiddenCount = data.hiddenCount || 0;
        state.streamTtl = data.ttl || state.streamTtl;
        state.library = data.enabled !== false;
    } catch (e) {
        state.files = [];
    }
    state.filesLoaded = true;
    if (state.view === "library") renderLibrary();
}

/**
 * `p` demande la conversion si nécessaire, pour la cible indiquée : le serveur
 * décide, sur les codecs réels, entre lecture directe, simple changement de
 * conteneur et réencodage. Sans `p` (téléchargement, VLC), le fichier part tel
 * qu'il est — VLC sait tout lire, le convertir serait du gâchis.
 */
function streamUrl(f, { download = false, profile = null } = {}) {
    const p = { t: f.stream };
    if (download) p.dl = "1";
    if (profile) p.p = profile;
    return "stream.php?" + new URLSearchParams(p).toString();
}

function renderLibrary() {
    hideFacets();
    observeMore(null);
    hideMetaCard();
    // Le re-rendu remplace les cartes : sans arrêt explicite, la vidéo est
    // détachée du document mais sa conversion continue côté serveur.
    stopAllPlayback();

    if (!state.filesLoaded) {
        resultsBox.replaceChildren(el("div", { class: "state" },
            el("span", { class: "emoji", text: "📚" }), "Lecture du dossier de téléchargements…"));
        return;
    }
    if (!state.files.length) {
        resultsBox.replaceChildren(el("div", { class: "state" },
            el("span", { class: "emoji", text: "📚" }),
            "Rien de téléchargé pour l'instant. Ce que qBittorrent termine apparaîtra ici."));
        return;
    }

    const total = state.files.reduce((n, f) => n + f.size, 0);
    const meta = el("div", { class: "meta-row" },
        el("span", {}, el("b", { text: String(state.files.length) }),
            ` fichier${state.files.length > 1 ? "s" : ""}`),
        el("span", { class: "muted", text: formatSize(total) }));
    if (state.hiddenCount || state.showHidden) {
        const b = el("button", { type: "button", class: "link-btn",
            text: state.showHidden
                ? "Masquer les fichiers cachés"
                : `Afficher les ${state.hiddenCount} masqué${state.hiddenCount > 1 ? "s" : ""}` });
        b.addEventListener("click", () => {
            state.showHidden = !state.showHidden;
            pendingLibDelete = null;
            state.filesLoaded = false;
            renderLibrary();
            loadLibrary();
        });
        meta.append(el("span", { class: "meta-actions" }, b));
    }

    const grille = el("div", { class: "lib-grid" }, ...state.files.map(libraryCard));
    resultsBox.replaceChildren(meta, grille);

    // Mêmes fiches que dans la recherche : le nom du dossier de la release suffit
    // à retrouver l'affiche.
    hydrateMeta(state.files.map((f) => ({
        title: f.title, kind: null, imdbId: "", tmdbId: 0,
    })).map((x) => ({ ...x, kind: guessKind(x.title) })));
}

/** Film ou série ? Un marqueur de saison tranche, sinon c'est un film. */
function guessKind(titre) {
    return /(^|[\s._-])(s\d{1,2}(e\d{1,3})?|saison\s*\d|season\s*\d|\d{1,2}x\d{2})([\s._-]|$)/i.test(titre)
        ? "tv" : "movie";
}

function libraryCard(f) {
    const carte = el("article", { class: "lib-card" + (f.hidden ? " lib-hidden" : "") });

    const vignette = el("div", { class: "lib-poster poster" });
    vignette.dataset.rel = f.title;
    paintPosterSlot(vignette);
    carte.append(vignette);

    const corps = el("div", { class: "lib-body" });
    corps.append(el("div", { class: "lib-title", title: f.name }, renderRelease(f.name)));
    corps.append(el("div", { class: "lib-meta" },
        el("span", { text: f.sizeHuman }),
        el("span", { class: "sep", text: "·" }),
        el("span", { class: "lib-ext", text: f.ext.toUpperCase() })));

    const actions = el("div", { class: "lib-actions" });

    // Avec la conversion à la volée, tout est lisible : le serveur choisit entre
    // lecture directe, changement de conteneur et réencodage. Sans elle, seuls
    // les formats que le navigateur décode nativement sont proposés.
    if (f.web || state.transcode) {
        actions.append(makeBtn("act act-play", ICONS.play, f.web
            ? "Lire dans le navigateur"
            : "Lire dans le navigateur (converti à la volée)",
            () => playHere(f, carte)));
    } else {
        actions.append(el("span", { class: "lib-nonweb",
            title: "Ce format ne se lit pas dans un navigateur — passez par VLC",
            text: "VLC requis" }));
    }

    // Envoi vers un téléviseur. Le bouton n'apparaît que si des récepteurs ont
    // été vus : proposer d'envoyer dans le vide n'aide personne.
    if (state.castDevices.length) {
        actions.append(makeBtn("act act-cast", ICONS.cast, "Envoyer vers un téléviseur",
            (btn) => openCastMenu(f, btn)));
    }

    actions.append(makeBtn("act act-copy", ICONS.copy, "Copier le lien de lecture (à ouvrir dans VLC)",
        () => copyText(new URL(streamUrl(f), location.href).href,
            "Lien copié — ouvrez-le dans VLC (Média › Ouvrir un flux réseau)")));
    actions.append(el("a", { class: "act act-dl", href: streamUrl(f, { download: true }),
        title: "Télécharger le fichier", "aria-label": "Télécharger le fichier" },
        svgIcon(ICONS.download)));

    actions.append(libraryRemoveActions(f));
    corps.append(actions);
    carte.append(corps);
    return carte;
}

/**
 * Retirer un fichier — deux gestes très différents, et c'est tout l'enjeu.
 *
 *   « Masquer »  : il disparaît de la liste, RIEN d'autre. Le fichier reste sur
 *                  le disque et continue d'être partagé. Sur un tracker privé,
 *                  désencombrer sa vue ne doit pas coûter son ratio.
 *   « Supprimer »: qBittorrent retire le torrent ET le fichier. Le partage
 *                  s'arrête, forcément.
 *
 * La confirmation se fait en deux temps, sans boîte de dialogue — comme dans la
 * vue Transferts.
 */
function libraryRemoveActions(f) {
    const wrap = el("span", { class: "lib-remove" });

    if (pendingLibDelete === f.stream) {
        const masquer = el("button", { type: "button", class: "del-choice", text: "Masquer",
            title: "Retirer de la liste — le fichier reste partagé" });
        masquer.addEventListener("click", () => libraryHide(f, true, masquer));

        const supprimer = el("button", { type: "button", class: "del-choice danger", text: "+ fichier",
            title: "Supprimer le torrent ET le fichier (le partage s'arrête)" });
        supprimer.addEventListener("click", () => libraryDelete(f, supprimer));

        const annuler = el("button", { type: "button", class: "act", title: "Annuler" });
        annuler.append(svgIcon(ICONS.close));
        annuler.addEventListener("click", () => { pendingLibDelete = null; renderLibrary(); });

        wrap.append(masquer, supprimer, annuler);
        return wrap;
    }

    if (f.hidden) {
        wrap.append(makeBtn("act", ICONS.eye, "Réafficher dans la bibliothèque",
            (btn) => libraryHide(f, false, btn)));
        return wrap;
    }

    wrap.append(makeBtn("act act-del", ICONS.trash, "Retirer de la bibliothèque",
        () => { pendingLibDelete = f.stream; renderLibrary(); }));
    return wrap;
}

async function libraryHide(f, masquer, btn) {
    pendingLibDelete = null;
    btn.disabled = true;
    try {
        const res = await fetch("api.php?action=library-hide", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: new URLSearchParams({ token: f.stream, on: masquer ? "1" : "0" }).toString(),
        });
        const data = await res.json().catch(() => ({}));
        toast(res.ok && data.ok ? data.message : (data.error || "Action impossible"));
    } catch (e) {
        toast("Action impossible");
    }
    state.filesLoaded = false;
    renderLibrary();
    loadLibrary();
}

async function libraryDelete(f, btn) {
    pendingLibDelete = null;
    btn.disabled = true;
    btn.classList.add("busy");
    try {
        const res = await fetch("api.php?action=library-delete", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: new URLSearchParams({ token: f.stream }).toString(),
        });
        const data = await res.json().catch(() => ({}));
        toast(res.ok && data.ok ? data.message : (data.error || "Suppression impossible"));
    } catch (e) {
        toast("Suppression impossible");
    }
    state.filesLoaded = false;
    renderLibrary();
    loadLibrary();
}

/** Lecture dans la page, sous la carte. */
/**
 * Arrête une lecture pour de bon.
 *
 * Retirer l'élément du DOM ne suffit pas : le navigateur peut garder la
 * connexion ouverte, et côté serveur ffmpeg continue alors de convertir un film
 * que plus personne ne regarde. Il faut vider la source et forcer un `load()` —
 * c'est ce qui coupe le flux, et donc le processus.
 */
function stopPlayback(zone) {
    if (!zone) return;
    const video = zone.querySelector("video");
    if (video) {
        video.pause();
        video.removeAttribute("src");
        // Sans ce load(), la requête reste en vol et la conversion continue.
        video.load();
    }
    zone.remove();
}

/** Arrête la lecture en cours, où qu'elle soit. */
function stopAllPlayback() {
    document.querySelectorAll(".lib-player").forEach(stopPlayback);
}

/** Lecture dans la page, sous la carte. */
function playHere(f, carte) {
    const existant = carte.querySelector(".lib-player");
    if (existant) { stopPlayback(existant); return; }

    // Une seule lecture à la fois : deux conversions simultanées pour un seul
    // spectateur, c'est deux cœurs mobilisés pour rien.
    stopAllPlayback();

    const converti = !f.web && state.transcode;
    const video = el("video", { class: "lib-video", controls: "", preload: "metadata",
        playsinline: "", src: streamUrl(f, { profile: "browser" }) });
    const zone = el("div", { class: "lib-player" }, video);

    // Arrêter, et pas seulement mettre en pause : une pause laisse la connexion
    // ouverte et la conversion en cours.
    const barre = el("div", { class: "lib-player-bar" });
    barre.append(el("span", { class: "muted", text: converti ? "Converti à la volée" : "Lecture directe" }));
    const arret = el("button", { type: "button", class: "del-choice", text: "Arrêter",
        title: "Arrêter la lecture et libérer la conversion" });
    arret.addEventListener("click", () => stopPlayback(zone));
    barre.append(arret);
    zone.prepend(barre);

    if (converti) {
        // Un flux converti est produit au fil de l'eau : sa durée n'est pas
        // connue d'avance, donc la barre de progression ne permet pas de se
        // déplacer. Le dire évite de croire à un bug.
        zone.append(el("div", { class: "lib-hint",
            text: "Le démarrage prend quelques secondes, et le déplacement dans "
                + "la vidéo n'est pas possible sur un flux converti." }));
    }

    // Un codec non supporté ne lève pas d'exception : la vidéo reste noire.
    // Autant le dire, et proposer la sortie.
    video.addEventListener("error", () => {
        zone.replaceChildren(el("div", { class: "files-load" },
            "Lecture impossible dans le navigateur. Copiez le lien et ouvrez-le dans VLC."));
    });
    carte.append(zone);
    video.play().catch(() => {});
}

// Échap arrête la lecture : le geste attendu quand une vidéo occupe l'écran.
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && document.querySelector(".lib-player")) stopAllPlayback();
});

/* ---------- envoi vers un téléviseur ----------
   Le Cast ne pousse pas la vidéo : il donne une URL au téléviseur, qui va la
   chercher lui-même. Tout en dépend — d'où l'avertissement quand l'adresse de
   l'app n'est joignable que depuis le serveur. */
async function loadCastDevices() {
    try {
        const res = await fetch("api.php?action=cast-devices");
        if (!res.ok) return;
        const d = await res.json();
        state.castDevices = d.devices || [];
        state.castScannedAt = d.scannedAt || null;
        state.castBase = d.base || "";
        state.castReachable = d.reachable !== false;
    } catch (e) { /* la bibliothèque reste utilisable sans Cast */ }
}

function closeCastMenu() {
    const ouvert = document.querySelector(".cast-menu");
    if (ouvert) ouvert.remove();
}

function openCastMenu(f, btn) {
    const deja = btn.parentElement.querySelector(".cast-menu");
    closeCastMenu();
    if (deja) return;

    const menu = el("div", { class: "cast-menu" });
    menu.append(el("div", { class: "cast-head", text: "Envoyer vers…" }));

    if (!state.castReachable) {
        menu.append(el("div", { class: "cast-warn" },
            `Le téléviseur ne pourra pas joindre l'application à « ${state.castBase} ». `
            + "Renseignez PUBLIC_BASE_URL avec son adresse sur le réseau local."));
    }
    if (!f.web && !state.transcode) {
        // Sans conversion, le récepteur Cast refusera : autant le dire avant.
        menu.append(el("div", { class: "cast-warn" },
            "Ce format passera probablement mal : un récepteur Cast ne lit que "
            + "du H.264/AAC en MP4. VLC sur la télévision reste le chemin sûr."));
    }

    for (const d of state.castDevices) {
        const ligne = el("button", { type: "button", class: "cast-device" },
            el("b", { text: d.name }),
            el("span", { class: "muted", text: d.model || d.host }));
        ligne.addEventListener("click", () => { closeCastMenu(); castTo(f, d, btn); });
        menu.append(ligne);
    }

    btn.parentElement.append(menu);
}

async function castTo(f, device, btn) {
    btn.disabled = true;
    btn.classList.add("busy");
    const body = new URLSearchParams();
    body.set("token", f.stream);
    body.set("host", device.host);
    body.set("port", String(device.port || 8009));
    body.set("title", f.title || f.name);
    const fiche = metaCache.get(f.title);
    if (fiche && fiche.poster) body.set("poster", fiche.poster);

    try {
        const res = await fetch("api.php?action=cast", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: body.toString(),
        });
        const data = await res.json();
        toast(data.error || data.message || "Envoyé.");
    } catch (e) {
        toast("Envoi impossible");
    } finally {
        btn.disabled = false;
        btn.classList.remove("busy");
    }
}

document.addEventListener("click", (e) => {
    if (!e.target.closest(".cast-menu") && !e.target.closest(".act-cast")) closeCastMenu();
});

if (libraryBtn) {
    libraryBtn.addEventListener("click", () => {
        setView(state.view === "library" ? "search" : "library");
        if (state.view === "search") renderResults();
    });
}

async function loadHistory() {
    try {
        const res = await fetch("api.php?action=history");
        const data = await res.json();
        state.history = data.history || [];
    } catch (e) { state.history = []; }
    if (state.view === "transfers") renderTransfers();
}

/** Bascule « en cours » / « historique » de la vue Transferts. */
function transfersTabs() {
    const box = el("div", { class: "segmented" });
    const mk = (id, label) => {
        const b = el("button", { type: "button", text: label });
        if (state.transfersTab === id) b.classList.add("active");
        b.addEventListener("click", () => {
            state.transfersTab = id;
            if (id === "history") loadHistory(); else renderTransfers();
        });
        return b;
    };
    box.append(mk("live", `En cours (${state.transfers.length})`));
    if (state.store) box.append(mk("history", "Historique"));
    return box;
}

function renderSendHistory() {
    const meta = el("div", { class: "meta-row" },
        transfersTabs(),
        el("span", { class: "meta-actions" }));
    if (state.history.length) {
        const purge = el("button", { type: "button", class: "link-btn", text: "Vider l'historique" });
        purge.addEventListener("click", async () => {
            const { ok, data } = await postForm("searches.php", { op: "clear-history" });
            toast(ok ? data.message : (data.error || "Échec"));
            loadHistory();
        });
        meta.lastChild.append(purge);
    }

    if (!state.history.length) {
        resultsBox.replaceChildren(meta, el("div", { class: "state" },
            el("span", { class: "emoji", text: "🗂️" }),
            "Rien d'envoyé pour l'instant. L'historique retient ce que vous prenez, même après suppression du torrent."));
        return;
    }

    const rows = state.history.map((h) => {
        const tr = el("tr");
        const cell = el("td", { class: "cell-title" });
        cell.append(el("span", { class: "rel", title: h.title }, renderRelease(h.title)));
        const meta2 = el("div", { class: "rel-meta" },
            el("span", { class: "idx-tag maskable", text: h.indexer || "—" }),
            el("span", { class: "sep", text: "·" }),
            el("span", { text: h.target === "qbit" ? "qBittorrent" : h.target }));
        if (h.user) {
            meta2.append(el("span", { class: "sep", text: "·" }), el("span", { text: h.user }));
        }
        cell.append(meta2);
        tr.append(cell);
        tr.append(el("td", { class: "num" },
            new Date(h.created_at * 1000).toLocaleDateString("fr-FR", {
                day: "2-digit", month: "2-digit", year: "2-digit",
            })));
        return tr;
    });

    const table = el("table", {},
        el("thead", {}, el("tr", {}, el("th", { text: "Release" }), el("th", { class: "num", text: "Envoyé le" }))),
        el("tbody", {}, ...rows));
    resultsBox.replaceChildren(meta, el("div", { class: "table-wrap" }, table));
}

function filteredTransfers() {
    const g = TRANSFER_GROUPS[state.transferFilter] || TRANSFER_GROUPS.all;
    return state.transfers.filter(g.match);
}

/** Filtres d'état + actions groupées : indispensables passé quelques torrents. */
function transferControls(visibles) {
    const wrap = el("div", { class: "tr-controls" });

    const filtres = el("div", { class: "chips" });
    for (const [id, g] of Object.entries(TRANSFER_GROUPS)) {
        const n = state.transfers.filter(g.match).length;
        if (id !== "all" && !n) continue;
        const chip = el("button", { type: "button", text: `${g.label} · ${n}`,
            class: "facet-chip" + (state.transferFilter === id ? " active" : "") });
        chip.addEventListener("click", () => { state.transferFilter = id; renderTransfers(); });
        filtres.append(chip);
    }
    wrap.append(filtres);

    // Les actions groupées portent sur ce qui est affiché : ce que l'on voit est
    // ce que l'on modifie, sans sélection à maintenir.
    const actions = el("div", { class: "tr-bulk" });
    const hashes = visibles.map((t) => t.hash);
    if (!hashes.length) return wrap;

    const enMarche = visibles.filter((t) => !/^(paused|stopped)/.test(t.state));
    const arretes = visibles.filter((t) => /^(paused|stopped)/.test(t.state));
    const finis = visibles.filter((t) => t.progress >= 1);

    const bulk = (label, titre, op, liste, files) => {
        const b = el("button", { type: "button", class: "del-choice", text: label, title: titre });
        b.addEventListener("click", () => transferAction(liste.map((t) => t.hash).join("|"), op, files, b));
        return b;
    };

    if (enMarche.length) actions.append(bulk(`Arrêter (${enMarche.length})`, "Arrêter les transferts affichés", "stop", enMarche, false));
    if (arretes.length) actions.append(bulk(`Relancer (${arretes.length})`, "Relancer les transferts affichés", "start", arretes, false));
    if (finis.length) {
        const b = el("button", { type: "button", class: "del-choice danger",
            text: `Retirer les terminés (${finis.length})`,
            title: "Retire de qBittorrent les transferts terminés, sans toucher aux fichiers" });
        b.addEventListener("click", () => {
            if (pendingBulkDelete) {
                pendingBulkDelete = false;
                transferAction(finis.map((t) => t.hash).join("|"), "delete", false, b);
                return;
            }
            // Deux temps, comme pour une suppression unitaire.
            pendingBulkDelete = true;
            b.textContent = `Confirmer (${finis.length})`;
            b.classList.add("confirm");
        });
        actions.append(b);
    }
    wrap.append(actions);
    return wrap;
}

let pendingBulkDelete = false;

function renderTransfers() {
    hideFacets();
    observeMore(null);

    if (state.transfersTab === "history") { renderSendHistory(); return; }

    if (!state.transfers.length) {
        resultsBox.replaceChildren(
            el("div", { class: "meta-row" }, transfersTabs()),
            el("div", { class: "state" },
                el("span", { class: "emoji", text: "📥" }),
                "Aucun transfert. Envoyez une release à qBittorrent depuis la recherche."));
        return;
    }

    const visibles = filteredTransfers();
    const total = visibles.reduce((n, t) => n + t.size, 0);
    const dl = visibles.reduce((n, t) => n + t.dlspeed, 0);
    const up = visibles.reduce((n, t) => n + t.upspeed, 0);
    const meta = el("div", { class: "meta-row" },
        transfersTabs(),
        el("span", { class: "muted", text: formatSize(total) }),
        el("span", { class: "meta-actions" },
            el("span", { class: "muted", text: `↓ ${formatSpeed(dl)}  ↑ ${formatSpeed(up)}` })));

    const head = el("tr", {},
        el("th", { text: "Torrent" }),
        el("th", { class: "num", text: "Taille" }),
        el("th", { class: "num", text: "Ratio" }),
        el("th", { class: "num", text: "Vitesse" }),
        el("th", { class: "num", text: "" }));

    const table = el("table", {},
        el("thead", {}, head),
        el("tbody", {}, ...visibles.map(renderTransferRow)));

    resultsBox.replaceChildren(meta, transferControls(visibles), el("div", { class: "table-wrap" }, table));
}

function renderTransferRow(t) {
    const tr = el("tr");
    const pct = Math.round(t.progress * 100);
    const done = pct >= 100;

    const nameCell = el("td", { class: "cell-title" });
    nameCell.append(el("span", { class: "rel", title: t.name },
        el("span", { class: "rel-name", text: t.name })));

    const bar = el("div", { class: "prog" }, el("div", { class: "prog-fill" + (done ? " done" : "") }));
    bar.firstChild.style.width = pct + "%";
    const info = el("div", { class: "rel-meta" },
        el("span", { class: "prog-pct", text: pct + " %" }),
        el("span", { class: "sep", text: "·" }),
        el("span", { text: TRANSFER_STATES[t.state] || t.state }));
    if (t.category) info.append(el("span", { class: "sep", text: "·" }), el("span", { text: t.category }));
    if (!done && t.eta) info.append(el("span", { class: "sep", text: "·" }),
        el("span", { text: "reste " + formatEta(t.eta) }));
    nameCell.append(bar, info);
    tr.append(nameCell);

    tr.append(el("td", { class: "num" }, t.sizeHuman));

    // Le ratio dit si l'on a rendu ce qu'on a pris : c'est ce qui compte sur
    // un tracker privé.
    const rc = t.ratio >= 1 ? "s-good" : t.ratio >= 0.5 ? "s-mid" : "s-low";
    tr.append(el("td", { class: "num" }, el("span", { class: "seed " + rc, text: t.ratio.toFixed(2) })));

    tr.append(el("td", { class: "num" },
        el("span", { class: "spd", text: `↓ ${formatSpeed(t.dlspeed)}` }),
        el("span", { class: "spd up", text: `↑ ${formatSpeed(t.upspeed)}` })));

    tr.append(el("td", { class: "num" }, renderTransferActions(t)));
    return tr;
}

/**
 * Actions d'un transfert. La suppression se fait en deux temps, sans boîte de
 * dialogue : le choix reste dans la page. Il est mémorisé dans `pendingDelete`,
 * sinon le rafraîchissement automatique l'effacerait avant qu'on puisse cliquer.
 */
function renderTransferActions(t) {
    const wrap = el("div", { class: "actions" });

    if (pendingDelete === t.hash) {
        const only = el("button", { type: "button", class: "del-choice", text: "Retirer",
            title: "Retirer de qBittorrent, garder les fichiers" });
        only.addEventListener("click", () => transferAction(t.hash, "delete", false, only));

        const both = el("button", { type: "button", class: "del-choice danger", text: "+ fichiers",
            title: "Supprimer aussi les fichiers téléchargés" });
        both.addEventListener("click", () => transferAction(t.hash, "delete", true, both));

        const cancel = el("button", { type: "button", class: "act", title: "Annuler" });
        cancel.append(svgIcon(ICONS.close));
        cancel.addEventListener("click", () => { pendingDelete = null; renderTransfers(); });

        wrap.append(only, both, cancel);
        return wrap;
    }

    const running = !/^(paused|stopped)/.test(t.state);
    wrap.append(makeBtn("act", running ? ICONS.pause : ICONS.play,
        running ? "Arrêter" : "Relancer",
        (btn) => transferAction(t.hash, running ? "stop" : "start", false, btn)));
    wrap.append(makeBtn("act act-del", ICONS.trash, "Supprimer",
        () => { pendingDelete = t.hash; renderTransfers(); }));
    return wrap;
}

async function transferAction(hash, op, files, btn) {
    pendingDelete = null;
    pendingBulkDelete = false;
    btn.disabled = true;
    btn.classList.add("busy");
    const body = new URLSearchParams({ op, hash });
    if (files) body.set("files", "1");
    try {
        const res = await fetch("transfers.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
            body: body.toString(),
        });
        const data = await res.json().catch(() => ({}));
        toast(res.ok && data.ok ? data.message : (data.error || "Échec de l'action"));
    } catch (e) {
        toast("Échec de l'action");
    }
    loadTransfers();
}

/* ==================================================================
   Recherches enregistrées

   Une recherche, c'est une requête plus des filtres. On la met de côté
   sous un nom, on la rejoue d'un clic. C'est la seule chose que
   l'application mémorise en propre, avec l'historique des envois.
   ================================================================== */
async function loadSaved() {
    try {
        const res = await fetch("api.php?action=searches");
        const data = await res.json();
        state.saved = data.searches || [];
        renderSaved();
    } catch (e) { /* la base peut être indisponible */ }
}

function renderSaved() {
    if (!savedBox || !savedList) return;
    savedBox.hidden = !state.store;
    if (!state.store) return;

    if (!state.saved.length) {
        savedList.replaceChildren(el("span", { class: "muted", text: "Aucune pour l'instant." }));
        return;
    }
    savedList.replaceChildren(...state.saved.map((s) => {
        const chip = el("span", { class: "chip saved-chip" });
        const run = el("button", { type: "button", class: "saved-run", text: s.name,
            title: s.query ? `Rechercher « ${s.query} »` : "Derniers uploads" });
        run.addEventListener("click", () => runSaved(s));

        // Le flux permet à qBittorrent de récupérer les nouveautés tout seul.
        const feed = el("button", { type: "button", class: "saved-del", text: "RSS",
            title: "Copier l'adresse du flux (à coller dans qBittorrent → RSS)" });
        feed.addEventListener("click", () => {
            const url = new URL("rss.php", location.href);
            url.searchParams.set("t", s.token);
            copyText(url.toString(), "Adresse du flux copiée");
        });

        // Cloche : n'apparaît que si un webhook est configuré, sinon elle
        // promettrait quelque chose qui n'arriverait jamais.
        let cloche = null;
        if (state.notify) {
            const actif = Number(s.notify) === 1;
            cloche = el("button", { type: "button", class: "saved-del" + (actif ? " on" : ""),
                text: actif ? "🔔" : "🔕",
                title: actif ? "Prévenir sur Discord — actif" : "Prévenir sur Discord des nouveautés" });
            cloche.addEventListener("click", async () => {
                const { ok, data } = await postForm("searches.php",
                    { op: "notify", id: s.id, on: actif ? "0" : "1" });
                toast(ok ? data.message : (data.error || "Échec"));
                loadSaved();
            });
        }

        const del = el("button", { type: "button", class: "saved-del", text: "✕", title: "Supprimer" });
        del.addEventListener("click", () => deleteSaved(s.id));
        chip.append(run, feed, ...(cloche ? [cloche] : []), del);
        return chip;
    }));
}

/** Rejoue une recherche enregistrée : requête et filtres sont restaurés. */
function runSaved(s) {
    setView("search");
    state.query = s.query || "";
    input.value = state.query;
    state.mode = state.query ? "search" : "top";
    state.days = Number(s.days) || 0;
    state.safeMode = Number(s.safe) !== 0;
    state.cats = new Set(String(s.cats || "").split(",").map(Number).filter(Boolean));
    state.trackers = new Set(String(s.trackers || "").split(",").map(Number).filter(Boolean));
    state.page = 1;
    renderDays();
    renderCats();
    renderChips(lastIndexers);
    applySafe();
    setFiltersOpen(false);
    runSearch();
}

async function postForm(url, fields) {
    const body = new URLSearchParams(fields);
    const res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": CSRF },
        body: body.toString(),
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok && data.ok, data };
}

async function deleteSaved(id) {
    const { ok, data } = await postForm("searches.php", { op: "delete", id });
    toast(ok ? data.message : (data.error || "Échec"));
    loadSaved();
}

if (savedSave && savedName) {
    const save = async () => {
        const name = savedName.value.trim() || state.query || "Derniers uploads";
        const { ok, data } = await postForm("searches.php", {
            op: "save",
            name,
            query: state.query,
            days: state.days,
            cats: [...state.cats].join(","),
            trackers: [...state.trackers].join(","),
            safe: state.safeMode ? "1" : "0",
        });
        toast(ok ? data.message : (data.error || "Échec"));
        if (ok) { savedName.value = ""; loadSaved(); }
    };
    savedSave.addEventListener("click", save);
    savedName.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); save(); } });
}

/* ==================================================================
   Raccourcis clavier

   L'outil sert à parcourir une liste et agir sur une ligne : la souris
   n'est pas le bon instrument pour ça. Les touches suivent la logique
   de vi (j/k) et de la plupart des lecteurs de flux.
   ================================================================== */
const SHORTCUTS = [
    ["/", "Aller au champ de recherche"],
    ["j / k", "Ligne suivante / précédente"],
    ["Entrée", "Ouvrir la page de la release"],
    ["d", "Télécharger le .torrent"],
    ["e", "Envoyer à qBittorrent"],
    ["c", "Copier le magnet"],
    ["t", "Derniers uploads (Top)"],
    ["f", "Ouvrir les filtres"],
    ["g", "Grouper / dégrouper les doublons"],
    ["?", "Afficher cette aide"],
    ["Échap", "Fermer / quitter le champ"],
];

let selIndex = -1;

function selectableRows() {
    return [...document.querySelectorAll("#results tbody tr:not(.src-row)")];
}

/** Réapplique la sélection après un rendu, et la garde dans les bornes. */
function paintSelection(scroll) {
    const rows = selectableRows();
    rows.forEach((r) => r.classList.remove("row-sel"));
    if (selIndex < 0 || !rows.length) return;
    selIndex = Math.min(selIndex, rows.length - 1);
    const row = rows[selIndex];
    row.classList.add("row-sel");
    if (scroll) row.scrollIntoView({ block: "nearest" });
}

function moveSelection(delta) {
    const rows = selectableRows();
    if (!rows.length) return;
    selIndex = selIndex < 0 ? 0 : Math.max(0, Math.min(rows.length - 1, selIndex + delta));
    paintSelection(true);
}

/** Déclenche une action de la ligne sélectionnée (le clic fait déjà le travail). */
function actOnSelection(selector) {
    const row = selectableRows()[selIndex];
    const target = row && row.querySelector(selector);
    if (!target) { toast("Action indisponible sur cette ligne"); return; }
    target.click();
}

function toggleHelp(force) {
    const existing = $("#help-overlay");
    if (existing) { existing.remove(); return; }
    if (force === false) return;

    const rows = SHORTCUTS.map(([key, label]) => el("div", { class: "help-row" },
        el("kbd", { text: key }), el("span", { text: label })));
    const panel = el("div", { class: "help-panel", role: "dialog", "aria-modal": "true",
        "aria-label": "Raccourcis clavier" },
        el("h2", { class: "help-title", text: "Raccourcis" }),
        el("div", { class: "help-grid" }, ...rows),
        el("p", { class: "help-foot", text: "Échap ou ? pour fermer" }));
    const overlay = el("div", { id: "help-overlay", class: "help-overlay" }, panel);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) overlay.remove(); });
    document.body.append(overlay);
    panel.tabIndex = -1;
    panel.focus();
}

document.addEventListener("keydown", (e) => {
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    const inField = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
    if (e.key === "Escape") {
        if ($("#help-overlay")) { toggleHelp(); return; }
        if (inField) document.activeElement.blur();
        return;
    }
    // Dans un champ, on ne détourne rien : l'utilisateur écrit.
    if (inField) return;

    switch (e.key) {
        case "/":       e.preventDefault(); input.focus(); input.select(); break;
        case "j":       e.preventDefault(); moveSelection(1); break;
        case "k":       e.preventDefault(); moveSelection(-1); break;
        case "Enter":   if (selIndex >= 0) { e.preventDefault(); actOnSelection("a.rel"); } break;
        case "d":       if (selIndex >= 0) { e.preventDefault(); actOnSelection(".act-dl"); } break;
        case "e":       if (selIndex >= 0) { e.preventDefault(); actOnSelection(".act-qbit"); } break;
        case "c":       if (selIndex >= 0) { e.preventDefault(); actOnSelection(".act-copy"); } break;
        case "t":       e.preventDefault(); if (topBtn) topBtn.click(); break;
        case "f":       e.preventDefault(); if (filtersBtn) filtersBtn.click(); break;
        case "g":       e.preventDefault(); if (groupBtn) groupBtn.click(); break;
        case "?":       e.preventDefault(); toggleHelp(); break;
        default: break;
    }
});

init();

/* Installable sur téléphone. L'échec n'a aucune conséquence : le service
   worker n'apporte que le cache des assets et l'icône d'installation. */
if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("sw.js").catch(() => {});
}

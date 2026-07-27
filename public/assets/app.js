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
    pause: "M10 4H6v16h4zM18 4h-4v16h4z",
    play: "M6 3l14 9-14 9z",
    trash: "M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14",
    close: "M18 6L6 18M6 6l12 12",
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
const SORTABLE = ["title", "size", "seeders", "leechers", "publishDate"];

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
    sort: { field: "publishDate", dir: "desc" },
    facets: { minSeeders: 0, freeleech: false, quality: new Set(), sizes: new Set(), exclude: [] },
    results: [], rawResults: [], grouping: true,
    total: 0, capped: false, page: 1, maskOn: false, loading: false,
    qbit: false, qbitCategories: [], qbitCategory: "", arr: {},
    view: "search", health: [], transfersTab: "live", transferFilter: "all", transfers: [], history: [],
    qbitNames: new Set(), store: false, saved: [],
};

const $ = (s) => document.querySelector(s);
const form = $("#search-form");
const input = $("#q");
const submitBtn = form.querySelector("button");
const daysBox = $("#days");
const catsBox = $("#categories");
const chipsBox = $("#trackers");
const maskBtn = $("#mask-toggle");
const groupBtn = $("#group-toggle");
const topBtn = $("#top-btn");
const transfersBtn = $("#transfers-btn");
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
    paintSelection(false);
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
    const b = el("button", { type: "button", class: cls, title });
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
    applyMask();
    applySafe();
    applyGrouping();
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
    clearInterval(transfersTimer);
    transfersTimer = null;
    if (view === "transfers") {
        loadTransfers();
        transfersTimer = setInterval(loadTransfers, 3000);
        renderTransfers();
    }
    updateTransfersBadge();
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

        const del = el("button", { type: "button", class: "saved-del", text: "✕", title: "Supprimer" });
        del.addEventListener("click", () => deleteSaved(s.id));
        chip.append(run, feed, del);
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

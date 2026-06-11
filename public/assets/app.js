"use strict";

const DAYS = [
    { v: 1, l: "24 h" },
    { v: 7, l: "7 j" },
    { v: 30, l: "30 j" },
    { v: 90, l: "90 j" },
    { v: 0, l: "Tout" },
];

const COLUMNS = [
    { key: "indexer", label: "Indexeur", sortable: false },
    { key: "title", label: "Titre", sortable: true },
    { key: "size", label: "Taille", sortable: true, align: "right" },
    { key: "seeders", label: "Seeders", sortable: true, align: "right" },
    { key: "publishDate", label: "Âge", sortable: true, align: "right" },
    { key: "action", label: "", sortable: false },
];

// Données de tracé des icônes (constantes) — rendues via createElementNS, sans innerHTML.
const SVGNS = "http://www.w3.org/2000/svg";
const ICONS = {
    download: "M12 3v12m0 0l-4-4m4 4l4-4M5 21h14",
    magnet: "M6 4v7a6 6 0 0 0 12 0V4M6 4H3m3 0v4m12-4h3m-3 0v4",
};
function svgIcon(d) {
    const svg = document.createElementNS(SVGNS, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    const path = document.createElementNS(SVGNS, "path");
    path.setAttribute("d", d);
    svg.append(path);
    return svg;
}

const state = {
    query: "",
    days: 1,
    trackers: new Set(),
    sort: { field: "publishDate", dir: "desc" },
    results: [],
    maskOn: false,
    loading: false,
};

const $ = (sel) => document.querySelector(sel);
const form = $("#search-form");
const input = $("#q");
const submitBtn = form.querySelector("button");
const daysBox = $("#days");
const chipsBox = $("#trackers");
const maskBtn = $("#mask-toggle");
const resultsBox = $("#results");

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

/* ---------- contrôles ---------- */
function renderDays() {
    daysBox.replaceChildren(
        ...DAYS.map((d) => {
            const b = el("button", { type: "button", text: d.l });
            if (d.v === state.days) b.classList.add("active");
            b.addEventListener("click", () => {
                if (state.days === d.v) return;
                state.days = d.v;
                renderDays();
                if (state.query) runSearch();
                else syncUrl();
            });
            return b;
        })
    );
}

function renderChips(indexers) {
    if (!indexers.length) {
        chipsBox.replaceChildren(
            el("span", { class: "muted", text: "Aucun indexeur configuré dans Prowlarr." })
        );
        return;
    }
    chipsBox.replaceChildren(
        ...indexers.map((ix) => {
            const chip = el(
                "button",
                { type: "button", class: "chip", "data-id": ix.id },
                el("span", { class: "maskable", text: ix.name })
            );
            if (state.trackers.has(ix.id)) chip.classList.add("active");
            chip.addEventListener("click", () => {
                chip.classList.toggle("active");
                if (state.trackers.has(ix.id)) state.trackers.delete(ix.id);
                else state.trackers.add(ix.id);
                if (state.query) runSearch();
                else syncUrl();
            });
            return chip;
        })
    );
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

/* ---------- recherche ---------- */
form.addEventListener("submit", (e) => {
    e.preventDefault();
    state.query = input.value.trim();
    runSearch();
});

async function runSearch() {
    syncUrl();
    if (!state.query) {
        state.results = [];
        renderIdle();
        return;
    }
    setLoading(true);
    renderSkeleton();

    const params = new URLSearchParams({ action: "search", q: state.query, days: state.days });
    if (state.trackers.size) params.set("trackers", [...state.trackers].join(","));

    try {
        const res = await fetch("api.php?" + params.toString());
        const data = await res.json();
        if (data.error) {
            renderError(data.error);
            return;
        }
        state.results = data.results || [];
        renderResults();
    } catch (err) {
        renderError("Impossible de contacter le serveur.");
    } finally {
        setLoading(false);
    }
}

function setLoading(on) {
    state.loading = on;
    submitBtn.disabled = on;
}

/* ---------- tri ---------- */
function sortedResults() {
    const { field, dir } = state.sort;
    const mul = dir === "asc" ? 1 : -1;
    return [...state.results].sort((a, b) => {
        let av = a[field], bv = b[field];
        if (field === "publishDate") { av = Date.parse(av) || 0; bv = Date.parse(bv) || 0; }
        if (field === "title") return a.title.localeCompare(b.title) * mul;
        return ((av > bv) - (av < bv)) * mul;
    });
}

function toggleSort(field) {
    if (state.sort.field === field) {
        state.sort.dir = state.sort.dir === "asc" ? "desc" : "asc";
    } else {
        state.sort = { field, dir: field === "title" ? "asc" : "desc" };
    }
    renderResults();
}

/* ---------- rendu ---------- */
function renderIdle() {
    resultsBox.replaceChildren(
        el("div", { class: "state" },
            el("span", { class: "emoji", text: "🛰️" }),
            "Lancez une recherche pour interroger vos indexeurs.")
    );
}

function renderSkeleton() {
    const rows = Array.from({ length: 6 }, () => el("div", { class: "sk-row" }));
    resultsBox.replaceChildren(
        el("div", { class: "table-wrap" },
            el("div", { class: "skeleton" },
                el("div", { class: "spinner" }), ...rows))
    );
}

function renderError(msg) {
    resultsBox.replaceChildren(
        el("div", { class: "state error" },
            el("span", { class: "emoji", text: "⚠️" }), msg)
    );
}

function renderResults() {
    const rows = sortedResults();
    if (!rows.length) {
        resultsBox.replaceChildren(
            el("div", { class: "state" },
                el("span", { class: "emoji", text: "🔍" }),
                `Aucun résultat pour « ${state.query} ».`)
        );
        return;
    }

    const meta = el("div", { class: "meta-row" },
        el("span", {}, el("b", { text: String(rows.length) }), ` résultat${rows.length > 1 ? "s" : ""}`),
        el("span", { class: "muted", text: state.trackers.size ? `${state.trackers.size} indexeur(s) filtré(s)` : "Tous les indexeurs" })
    );

    const table = el("table", {},
        el("thead", {}, renderHeadRow()),
        el("tbody", {}, ...rows.map((r, i) => renderRow(r, i)))
    );

    resultsBox.replaceChildren(meta, el("div", { class: "table-wrap" }, table));
}

function renderHeadRow() {
    const tr = el("tr");
    for (const col of COLUMNS) {
        const th = el("th", { text: col.label });
        if (col.align === "right") th.style.textAlign = "right";
        if (col.sortable) {
            th.classList.add("sortable");
            th.append(el("span", { class: "arrow", text: "▲" }));
            if (state.sort.field === col.key) {
                th.classList.add("sort-active", state.sort.dir === "desc" ? "sort-desc" : "sort-asc");
            }
            th.addEventListener("click", () => toggleSort(col.key));
        }
        tr.append(th);
    }
    return tr;
}

function renderRow(r, i) {
    const tr = el("tr");
    tr.style.animationDelay = Math.min(i * 25, 400) + "ms";

    // Indexeur
    tr.append(el("td", {}, el("span", { class: "idx-tag maskable", text: r.indexer })));

    // Titre
    const titleCell = el("td", { class: "cell-title" });
    if (r.infoUrl && r.infoUrl !== "#") {
        titleCell.append(el("a", { href: r.infoUrl, target: "_blank", rel: "noopener noreferrer", text: r.title }));
    } else {
        titleCell.append(el("span", { text: r.title }));
    }
    tr.append(titleCell);

    // Taille
    const sizeCell = el("td", { class: "num muted" }, r.sizeHuman);
    sizeCell.style.textAlign = "right";
    tr.append(sizeCell);

    // Seeders
    const s = r.seeders;
    const cls = s >= 20 ? "s-good" : s >= 5 ? "s-mid" : "s-low";
    const seedCell = el("td", {}, el("span", { class: "seed " + cls },
        el("span", { class: "dot" }), String(s)));
    seedCell.style.textAlign = "right";
    tr.append(seedCell);

    // Âge
    const age = r.daysOld == null ? "—" : (r.daysOld === 0 ? "auj." : r.daysOld + " j");
    const ageCell = el("td", { class: "num muted", text: age, title: r.publishDate || "" });
    ageCell.style.textAlign = "right";
    tr.append(ageCell);

    // Action
    tr.append(el("td", { style: "text-align:right" }, renderAction(r.action)));
    return tr;
}

function renderAction(action) {
    if (!action || !action.type || !action.href) return el("span", { class: "dl-none", text: "—" });
    if (action.type === "magnet") {
        return el("a", { class: "dl dl-magnet", href: action.href }, svgIcon(ICONS.magnet), "Magnet");
    }
    return el("a", { class: "dl dl-torrent", href: action.href }, svgIcon(ICONS.download), "Torrent");
}

/* ---------- URL / état partageable ---------- */
function syncUrl() {
    const p = new URLSearchParams();
    if (state.query) p.set("q", state.query);
    if (state.days !== 1) p.set("days", state.days);
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    const qs = p.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
}

function readUrl() {
    const p = new URLSearchParams(location.search);
    if (p.has("q")) { state.query = p.get("q").trim(); input.value = state.query; }
    if (p.has("days")) { const d = parseInt(p.get("days"), 10); if (DAYS.some((x) => x.v === d)) state.days = d; }
    if (p.has("trackers")) p.get("trackers").split(",").map(Number).filter(Boolean).forEach((id) => state.trackers.add(id));
}

/* ---------- init ---------- */
async function init() {
    try { state.maskOn = localStorage.getItem("maskTrackers") === "1"; } catch (e) {}
    applyMask();
    readUrl();
    renderDays();
    renderIdle();

    try {
        const res = await fetch("api.php?action=indexers");
        const data = await res.json();
        renderChips(data.indexers || []);
    } catch (e) {
        renderChips([]);
    }

    if (state.query) runSearch();
}

init();

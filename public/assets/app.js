"use strict";

const PAGE_SIZE = 25;

const DAYS = [
    { v: 1, l: "24 h" }, { v: 7, l: "7 j" }, { v: 30, l: "30 j" },
    { v: 90, l: "90 j" }, { v: 0, l: "Tout" },
];

const CATS = [
    { id: 2000, l: "Films" }, { id: 5000, l: "Séries" }, { id: 3000, l: "Musique" },
    { id: 4000, l: "Logiciels" }, { id: 7000, l: "Livres" }, { id: 1000, l: "Jeux" },
];

const COLUMNS = [
    { key: "indexer", label: "Indexeur" },
    { key: "title", label: "Titre", sortable: true },
    { key: "size", label: "Taille", sortable: true, right: true },
    { key: "seeders", label: "Seed", sortable: true, right: true },
    { key: "leechers", label: "Leech", sortable: true, right: true },
    { key: "publishDate", label: "Âge", sortable: true, right: true },
    { key: "actions", label: "" },
];

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

const state = {
    query: "", days: 1, trackers: new Set(), cats: new Set(),
    sort: { field: "publishDate", dir: "desc" },
    results: [], page: 1, maskOn: false, loading: false, qbit: false,
};

const $ = (s) => document.querySelector(s);
const form = $("#search-form");
const input = $("#q");
const submitBtn = form.querySelector("button");
const daysBox = $("#days");
const catsBox = $("#categories");
const chipsBox = $("#trackers");
const maskBtn = $("#mask-toggle");
const resultsBox = $("#results");
const statusBox = $("#status");
const historyList = $("#history");
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
function renderDays() {
    daysBox.replaceChildren(...DAYS.map((d) => {
        const b = el("button", { type: "button", text: d.l });
        if (d.v === state.days) b.classList.add("active");
        b.addEventListener("click", () => {
            if (state.days === d.v) return;
            state.days = d.v; renderDays(); rerunOrSync();
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
            rerunOrSync();
        });
        return chip;
    }));
}

function renderChips(indexers) {
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
            rerunOrSync();
        });
        return chip;
    }));
}

function rerunOrSync() {
    state.page = 1;
    if (state.query) runSearch(); else syncUrl();
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
    state.page = 1;
    pushHistory(state.query);
    runSearch();
});

async function runSearch() {
    syncUrl();
    if (!state.query) { state.results = []; renderIdle(); return; }
    setLoading(true);
    renderSkeleton();

    const p = new URLSearchParams({ action: "search", q: state.query, days: state.days });
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));

    try {
        const res = await fetch("api.php?" + p.toString());
        const data = await res.json();
        if (res.status === 401) { location.href = "login.php"; return; }
        if (data.error) { renderError(data.error); return; }
        state.results = data.results || [];
        state.capped = !!data.capped;
        renderResults();
    } catch (e) {
        renderError("Impossible de contacter le serveur.");
    } finally {
        setLoading(false);
    }
}

function setLoading(on) { state.loading = on; submitBtn.disabled = on; }

/* ---------- tri ---------- */
function sortedResults() {
    const { field, dir } = state.sort;
    const mul = dir === "asc" ? 1 : -1;
    return [...state.results].sort((a, b) => {
        if (field === "title") return a.title.localeCompare(b.title) * mul;
        let av = a[field], bv = b[field];
        if (field === "publishDate") { av = Date.parse(av) || 0; bv = Date.parse(bv) || 0; }
        return (((av > bv) - (av < bv))) * mul;
    });
}
function toggleSort(field) {
    if (state.sort.field === field) state.sort.dir = state.sort.dir === "asc" ? "desc" : "asc";
    else state.sort = { field, dir: field === "title" ? "asc" : "desc" };
    state.page = 1;
    renderResults();
}

/* ---------- rendu ---------- */
function renderIdle() {
    resultsBox.replaceChildren(el("div", { class: "state" },
        el("span", { class: "emoji", text: "🛰️" }),
        "Lancez une recherche pour interroger vos indexeurs."));
}
function renderSkeleton() {
    const rows = Array.from({ length: 6 }, () => el("div", { class: "sk-row" }));
    resultsBox.replaceChildren(el("div", { class: "table-wrap" },
        el("div", { class: "skeleton" }, el("div", { class: "spinner" }), ...rows)));
}
function renderError(msg) {
    resultsBox.replaceChildren(el("div", { class: "state error" },
        el("span", { class: "emoji", text: "⚠️" }), msg));
}

function renderResults() {
    const all = sortedResults();
    if (!all.length) {
        resultsBox.replaceChildren(el("div", { class: "state" },
            el("span", { class: "emoji", text: "🔍" }), `Aucun résultat pour « ${state.query} ».`));
        return;
    }
    const shown = all.slice(0, state.page * PAGE_SIZE);

    const meta = el("div", { class: "meta-row" },
        el("span", {}, el("b", { text: String(all.length) }),
            ` résultat${all.length > 1 ? "s" : ""}`, state.capped ? " (max)" : ""),
        el("span", { class: "muted", text: state.cats.size ? `${state.cats.size} catégorie(s)` : "Toutes catégories" }));

    const table = el("table", {},
        el("thead", {}, renderHeadRow()),
        el("tbody", {}, ...shown.map((r, i) => renderRow(r, i))));

    const parts = [meta, el("div", { class: "table-wrap" }, table)];
    if (shown.length < all.length) {
        const more = el("button", { type: "button", class: "load-more",
            text: `Charger plus (${all.length - shown.length} restants)` });
        more.addEventListener("click", () => { state.page++; renderResults(); });
        parts.push(more);
    }
    resultsBox.replaceChildren(...parts);
}

function renderHeadRow() {
    const tr = el("tr");
    for (const col of COLUMNS) {
        const th = el("th", { text: col.label });
        if (col.right) th.style.textAlign = "right";
        if (col.sortable) {
            th.classList.add("sortable");
            th.append(el("span", { class: "arrow", text: "▲" }));
            if (state.sort.field === col.key)
                th.classList.add("sort-active", state.sort.dir === "desc" ? "sort-desc" : "sort-asc");
            th.addEventListener("click", () => toggleSort(col.key));
        }
        tr.append(th);
    }
    return tr;
}

function renderRow(r, i) {
    const tr = el("tr");
    tr.style.animationDelay = Math.min(i * 20, 300) + "ms";

    tr.append(el("td", {}, el("span", { class: "idx-tag maskable", text: r.indexer })));

    // Titre + badges qualité + freeleech
    const titleCell = el("td", { class: "cell-title" });
    if (r.dl || r.magnet) {
        const titleNode = r.infoUrl && r.infoUrl !== "#"
            ? el("a", { href: r.infoUrl, target: "_blank", rel: "noopener noreferrer", text: r.title })
            : el("span", { text: r.title });
        titleCell.append(titleNode);
    } else {
        titleCell.append(el("span", { text: r.title }));
    }
    const badges = el("div", { class: "badges" });
    if (r.freeleech) badges.append(el("span", { class: "badge badge-fl", text: "FREE" }));
    if (r.category) badges.append(el("span", { class: "badge badge-cat", text: r.category }));
    (r.badges || []).forEach((b) => badges.append(el("span", { class: "badge", text: b })));
    if (badges.children.length) titleCell.append(badges);
    tr.append(titleCell);

    tr.append(rightCell("num muted", r.sizeHuman));

    const sc = r.seeders >= 20 ? "s-good" : r.seeders >= 5 ? "s-mid" : "s-low";
    const seed = el("td", {}, el("span", { class: "seed " + sc }, el("span", { class: "dot" }), String(r.seeders)));
    seed.style.textAlign = "right";
    tr.append(seed);

    tr.append(rightCell("num muted", String(r.leechers)));

    const age = r.daysOld == null ? "—" : (r.daysOld === 0 ? "auj." : r.daysOld + " j");
    const ageCell = rightCell("num muted", age);
    ageCell.title = r.publishDate || "";
    tr.append(ageCell);

    const act = el("td", {}, renderActions(r));
    act.style.textAlign = "right";
    tr.append(act);
    return tr;
}

function rightCell(cls, txt) {
    const td = el("td", { class: cls }, txt);
    td.style.textAlign = "right";
    return td;
}

function torrentHref(dl) {
    return "download_torrent.php?" + new URLSearchParams({ url: dl.url, sig: dl.sig }).toString();
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
    if (state.qbit && (r.dl || r.magnet)) {
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
    if (r.magnet) body.set("url", r.magnet);
    else { body.set("url", r.dl.url); body.set("sig", r.dl.sig); }
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
        const dot = statusBox.querySelector(".status-dot");
        const txt = statusBox.querySelector(".status-text");
        if (s.connected) {
            dot.className = "status-dot ok";
            txt.textContent = `${s.indexers} indexeur${s.indexers > 1 ? "s" : ""}` + (s.qbit ? " · qBit" : "");
        } else {
            dot.className = "status-dot ko";
            txt.textContent = "Prowlarr hors ligne";
        }
    } catch (e) {
        statusBox.querySelector(".status-dot").className = "status-dot ko";
        statusBox.querySelector(".status-text").textContent = "Hors ligne";
    }
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
    if (state.query) p.set("q", state.query);
    if (state.days !== 1) p.set("days", state.days);
    if (state.trackers.size) p.set("trackers", [...state.trackers].join(","));
    if (state.cats.size) p.set("cats", [...state.cats].join(","));
    const qs = p.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
}
function readUrl() {
    const p = new URLSearchParams(location.search);
    if (p.has("q")) { state.query = p.get("q").trim(); input.value = state.query; }
    if (p.has("days")) { const d = parseInt(p.get("days"), 10); if (DAYS.some((x) => x.v === d)) state.days = d; }
    if (p.has("trackers")) p.get("trackers").split(",").map(Number).filter(Boolean).forEach((id) => state.trackers.add(id));
    if (p.has("cats")) p.get("cats").split(",").map(Number).filter(Boolean).forEach((id) => state.cats.add(id));
}

/* ---------- init ---------- */
async function init() {
    try { state.maskOn = localStorage.getItem("maskTrackers") === "1"; } catch (e) {}
    applyMask();
    readUrl();
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

    if (state.query) runSearch();
}
init();

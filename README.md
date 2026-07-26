# indexOF_Prowlarr

Interface web légère (PHP) pour rechercher des releases via l'API [Prowlarr](https://prowlarr.com/), trier les résultats et télécharger les fichiers `.torrent` (ou ouvrir les liens magnet).

![Logo Discord](https://zupimages.net/up/23/26/rumo.png)
[Rejoignez le Discord !](https://discord.gg/rSfTxaW)

<p align="center">
  <img src="https://i.goopics.net/vfdyoc.png" alt="indexOF">
</p>

---

## Démarrage rapide (Docker)

```bash
cp exemple.env .env
# Générez un secret applicatif :
sed -i "s/^APP_SECRET=.*/APP_SECRET=$(openssl rand -hex 32)/" .env

docker compose up -d --build
```

- Application : <http://localhost:8080>
- Prowlarr : <http://localhost:9696>
- qBittorrent : <http://localhost:8081>

> Les trois services n'écoutent que sur la loopback : exposez-les via un reverse-proxy TLS, pas directement.

### Configurer Prowlarr

1. Ouvrez Prowlarr (<http://localhost:9696>), créez le compte admin.
2. Récupérez la clé API dans **Settings → General → Security → API Key**.
3. Reportez-la dans `.env` (`PROWLARR_API_KEY=...`), puis ajoutez des indexeurs.
4. Redémarrez l'app : `docker compose up -d`.

> `PROWLARR_BASE_URL` vaut `http://prowlarr:9696` : les services communiquent par leur nom sur le réseau Docker.

## Structure

```
public/                 # Racine web exposée (seul ce dossier est servi)
  index.php             # Coquille HTML (aucune donnée sensible)
  login.php / logout.php# Authentification (si APP_PASSWORD défini)
  api.php               # API JSON : status + indexeurs + recherche (jetons scellés)
  send.php              # Envoi d'un torrent vers qBittorrent (auth + CSRF + jeton)
  download_torrent.php  # Proxy .torrent sécurisé (jeton chiffré + anti-SSRF)
  assets/
    app.css             # Design (thème sombre, sans dépendance CDN)
    app.js              # Front dynamique (AJAX, tri, catégories, actions, masquage)
src/                    # Hors racine web
  config.php            # Chargement de la configuration (env > .env)
  auth.php              # Session, mot de passe, jeton CSRF
  ProwlarrClient.php    # Client API Prowlarr (cURL, X-Api-Key, cache, timeouts)
  QbittorrentClient.php # Client Web API qBittorrent (ajout par URL/magnet)
  functions.php         # Échappement, URL, jetons scellés, anti-SSRF, formatage
tests/                  # Tests PHPUnit (functions critiques)
.github/workflows/ci.yml# CI : lint + PHPStan + PHPUnit + build + scan Trivy
docker/nginx.conf       # Vhost nginx (reverse-proxy FastCGI vers php-fpm)
Dockerfile              # Multi-stage : php-fpm (app) + nginx (web), base Alpine
docker-compose.yml      # web (nginx) + php (php-fpm) + prowlarr + qbittorrent
```

## Fonctionnalités de l'interface

- **Recherche dynamique** (AJAX) sans rechargement de page, état partageable par URL (`?q=…&days=…&trackers=…`).
- **Tri instantané** côté client (titre, taille, seeders, âge).
- **Filtres** par indexeur (chips), par **catégorie** (Films, Séries, Musique, Logiciels, Livres, Jeux) et par ancienneté (24 h → tout).
- **Résultats enrichis** : seeders/leechers, catégorie, badges de qualité (1080p, x265, WEB, FLAC…), indicateur **freeleech**.
- **Filtres facettes** instantanés (sans recharger) : seeders minimum, freeleech, et qualité (chips dérivés des résultats).
- **Actions par résultat** : télécharger le `.torrent` (proxy signé), ouvrir/copier le magnet, ou **envoyer directement à qBittorrent** (avec choix de la catégorie de destination).
- **Tri mémorisé** et indicateur des **indexeurs en erreur**.
- **Masquage des noms d'indexeurs** : un bouton bascule le floutage des noms (chips + colonne source), persisté localement ; le survol révèle ponctuellement.
- **Historique de recherche** (local), pagination « charger plus », indicateur de **statut** Prowlarr/qBittorrent.

## Configuration (`.env`)

| Variable | Description | Défaut |
|----------|-------------|--------|
| `PROWLARR_API_KEY` | Clé API Prowlarr | — (obligatoire) |
| `PROWLARR_BASE_URL` | URL de Prowlarr, sans slash final | — (obligatoire) |
| `APP_SECRET` | Secret de scellement des liens (≥ 16 car., `openssl rand -hex 32`) | — (obligatoire) |
| `APP_PASSWORD` | Mot de passe d'accès. Vide = démarrage refusé, sauf `AUTH_DISABLED=1` | — (obligatoire) |
| `RESULT_LIMIT` | Nombre max de résultats par recherche | `200` |
| `QBITTORRENT_URL` | URL Web UI qBittorrent. Vide = envoi désactivé | _(vide)_ |
| `QBITTORRENT_USER` / `QBITTORRENT_PASS` | Identifiants qBittorrent | `admin` / _(vide)_ |
| `PROWLARR_TIMEOUT` | Timeout des requêtes API (s) | `15` |
| `CACHE_TTL` | Durée du cache recherches/indexeurs (s) | `120` |
| `CACHE_DIR` | Répertoire de cache | `/tmp/indexof_cache` |

## Envoi vers qBittorrent

Renseignez `QBITTORRENT_URL` (ex. `http://qbittorrent:8081`) pour faire apparaître le bouton d'envoi, ainsi que `QBITTORRENT_USER`/`QBITTORRENT_PASS`.

N'utilisez **pas** le contournement d'authentification par sous-réseau (*Options → Web UI → Bypass authentication for clients in whitelisted IP subnets*) : il ouvre la WebUI — donc le contrôle total du client BitTorrent — à tout ce qui atteint le réseau Docker. La WebUI doit exiger un mot de passe, avec *CSRF protection* et *Host header validation* actives. Le port publié doit être identique au port interne (`WEBUI_PORT`), sinon la validation d'en-tête Host rejette les requêtes.

## Sécurité

- **Authentification obligatoire** : `APP_PASSWORD` (session + CSRF). Sans lui, l'app refuse de démarrer, à moins de poser explicitement `AUTH_DISABLED=1`.
- **Liens de téléchargement scellés** : les URLs Prowlarr (qui contiennent la clé API) ne sont jamais envoyées au navigateur. Le client reçoit un jeton chiffré (AES-256-GCM) à durée de vie limitée, que seul le serveur peut ouvrir.
- **Proxy `.torrent` anti-SSRF** : schémas `http(s)` uniquement, rejet des IP privées/réservées, épinglage de l'IP validée (anti-DNS-rebinding), re-validation à chaque redirection, plafond de taille et timeouts.
- **Anti-brute-force** : `limit_req` nginx sur les POST de login + verrouillage applicatif (10 échecs / 15 min par IP).
- **En-têtes** : CSP stricte (pas d'inline, pas de CDN), `nosniff`, `no-referrer`, `frame-ancestors 'none'`.
- **Conteneurs durcis** : non-root, `cap_drop: ALL`, `no-new-privileges`, système de fichiers en lecture seule, ports liés à la loopback.

En production : terminez le TLS sur un reverse-proxy, décommentez `Strict-Transport-Security` dans `docker/nginx.conf`, et si le proxy pose `X-Forwarded-For`, mettez `TRUST_PROXY=1` **et** configurez le module `realip` de nginx (sinon le rate-limit voit l'IP du proxy).

## Développement

```bash
composer install
composer test    # PHPUnit
composer stan    # PHPStan (niveau 6)
```

La CI GitHub Actions exécute lint + PHPStan + PHPUnit, construit les images et les scanne (Trivy, HIGH/CRITICAL bloquants).

## Sans Docker

Nécessite PHP 8.1+ avec l'extension cURL. Définissez les variables d'environnement (ou créez `.env`), puis servez le dossier `public/` :

```bash
php -S 0.0.0.0:8080 -t public
```

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
  api.php               # API JSON : status + indexeurs + recherche (liens signés)
  send.php              # Envoi d'un torrent vers qBittorrent (auth + CSRF + HMAC)
  download_torrent.php  # Proxy .torrent sécurisé (signature HMAC + anti-SSRF)
  assets/
    app.css             # Design (thème sombre, sans dépendance CDN)
    app.js              # Front dynamique (AJAX, tri, catégories, actions, masquage)
src/                    # Hors racine web
  config.php            # Chargement de la configuration (env > .env)
  auth.php              # Session, mot de passe, jeton CSRF
  ProwlarrClient.php    # Client API Prowlarr (cURL, X-Api-Key, cache, timeouts)
  QbittorrentClient.php # Client Web API qBittorrent (ajout par URL/magnet)
  functions.php         # Échappement, URL, signature, anti-SSRF, qualité, formatage
tests/                  # Tests PHPUnit (functions critiques)
.github/workflows/ci.yml# CI : lint + PHPStan + PHPUnit + build + scan Trivy
docker/nginx.conf       # Vhost nginx (reverse-proxy FastCGI vers php-fpm)
Dockerfile              # Multi-stage : php-fpm (app) + nginx (web), base Alpine
docker-compose.yml      # web (nginx) + php (php-fpm) + prowlarr + qbittorrent
AUDIT.md                # Audit de sécurité et correctifs
```

## Fonctionnalités de l'interface

- **Recherche dynamique** (AJAX) sans rechargement de page, état partageable par URL (`?q=…&days=…&trackers=…`).
- **Tri instantané** côté client (titre, taille, seeders, âge).
- **Filtres** par indexeur (chips), par **catégorie** (Films, Séries, Musique, Logiciels, Livres, Jeux) et par ancienneté (24 h → tout).
- **Résultats enrichis** : seeders/leechers, catégorie, badges de qualité (1080p, x265, WEB, FLAC…), indicateur **freeleech**.
- **Actions par résultat** : télécharger le `.torrent` (proxy signé), ouvrir/copier le magnet, ou **envoyer directement à qBittorrent**.
- **Masquage des noms d'indexeurs** : un bouton bascule le floutage des noms (chips + colonne source), persisté localement ; le survol révèle ponctuellement.
- **Historique de recherche** (local), pagination « charger plus », indicateur de **statut** Prowlarr/qBittorrent.

## Configuration (`.env`)

| Variable | Description | Défaut |
|----------|-------------|--------|
| `PROWLARR_API_KEY` | Clé API Prowlarr | — (obligatoire) |
| `PROWLARR_BASE_URL` | URL de Prowlarr, sans slash final | — (obligatoire) |
| `APP_SECRET` | Secret HMAC pour signer les liens de téléchargement | dérivé (à définir en prod) |
| `APP_PASSWORD` | Mot de passe d'accès. Vide = pas d'authentification | _(vide)_ |
| `RESULT_LIMIT` | Nombre max de résultats par recherche | `100` |
| `QBITTORRENT_URL` | URL Web UI qBittorrent. Vide = envoi désactivé | _(vide)_ |
| `QBITTORRENT_USER` / `QBITTORRENT_PASS` | Identifiants qBittorrent | `admin` / _(vide)_ |
| `PROWLARR_TIMEOUT` | Timeout des requêtes API (s) | `15` |
| `CACHE_TTL` | Durée du cache recherches/indexeurs (s) | `120` |
| `CACHE_DIR` | Répertoire de cache | `/tmp/indexof_cache` |

## Envoi vers qBittorrent

Renseignez `QBITTORRENT_URL` (ex. `http://qbittorrent:8080`) pour faire apparaître le bouton d'envoi. Le client tente l'ajout directement, puis se rabat sur les identifiants si qBittorrent exige l'authentification. Si l'app et qBittorrent sont sur le même réseau, le plus simple est d'autoriser le sous-réseau dans qBittorrent (**Options → Web UI → Bypass authentication for clients in whitelisted IP subnets**) ; sinon, fournissez `QBITTORRENT_USER`/`PASS`.

## Sécurité

Cette version corrige plusieurs vulnérabilités de l'ancien code (SSRF/open proxy, fuite de clé API, XSS). Détails et correctifs : **[AUDIT.md](AUDIT.md)**.

- **Authentification** : définissez `APP_PASSWORD` pour protéger l'accès (session + CSRF). Vide, l'app reste ouverte — à réserver à un réseau de confiance / derrière un reverse-proxy.
- Liens de téléchargement signés (HMAC), proxy anti-SSRF, en-têtes CSP.

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

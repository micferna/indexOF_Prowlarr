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
  api.php               # API JSON : recherche + liste d'indexeurs (liens signés)
  download_torrent.php  # Proxy .torrent sécurisé (signature HMAC + anti-SSRF)
  assets/
    app.css             # Design (thème sombre, sans dépendance CDN)
    app.js              # Front dynamique (AJAX, tri client, masquage indexeurs)
src/                    # Hors racine web
  config.php            # Chargement de la configuration (env > .env)
  ProwlarrClient.php    # Client API Prowlarr (cURL, X-Api-Key, cache, timeouts)
  functions.php         # Échappement, validation d'URL, signature, formatage
docker/nginx.conf       # Vhost nginx (reverse-proxy FastCGI vers php-fpm)
Dockerfile              # Multi-stage : php-fpm (app) + nginx (web), base Alpine
docker-compose.yml      # web (nginx) + php (php-fpm) + prowlarr
AUDIT.md                # Audit de sécurité et correctifs
```

## Fonctionnalités de l'interface

- **Recherche dynamique** (AJAX) sans rechargement de page, état partageable par URL (`?q=…&days=…&trackers=…`).
- **Tri instantané** côté client (titre, taille, seeders, âge).
- **Filtres** par indexeur (chips) et par ancienneté (24 h → tout).
- **Masquage des noms d'indexeurs** : un bouton bascule le floutage des noms (chips + colonne source), persisté localement ; le survol révèle ponctuellement.
- Liens **magnet** directs ou téléchargement `.torrent` via le proxy signé.

## Configuration (`.env`)

| Variable | Description | Défaut |
|----------|-------------|--------|
| `PROWLARR_API_KEY` | Clé API Prowlarr | — (obligatoire) |
| `PROWLARR_BASE_URL` | URL de Prowlarr, sans slash final | — (obligatoire) |
| `APP_SECRET` | Secret HMAC pour signer les liens de téléchargement | dérivé (à définir en prod) |
| `PROWLARR_TIMEOUT` | Timeout des requêtes API (s) | `15` |
| `CACHE_TTL` | Durée du cache recherches/indexeurs (s) | `120` |
| `CACHE_DIR` | Répertoire de cache | `/tmp/indexof_cache` |

## Sécurité

Cette version corrige plusieurs vulnérabilités de l'ancien code (SSRF/open proxy, fuite de clé API, XSS). Détails et correctifs : **[AUDIT.md](AUDIT.md)**.

L'application n'a pas d'authentification intégrée : placez-la derrière un reverse-proxy authentifié ou un VPN, et ne l'exposez pas directement sur Internet.

## Sans Docker

Nécessite PHP 8.1+ avec l'extension cURL. Définissez les variables d'environnement (ou créez `.env`), puis servez le dossier `public/` :

```bash
php -S 0.0.0.0:8080 -t public
```

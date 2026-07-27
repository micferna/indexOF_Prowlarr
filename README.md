<h1 align="center">indexOF</h1>

<p align="center">
  Recherche unifiée sur vos indexeurs <a href="https://prowlarr.com/">Prowlarr</a> :
  une requête, tous les trackers, et le torrent part vers qBittorrent en un clic.
</p>

<p align="center">
  <a href="https://github.com/micferna/indexOF_Prowlarr/actions/workflows/ci.yml"><img src="https://github.com/micferna/indexOF_Prowlarr/actions/workflows/ci.yml/badge.svg" alt="État de la CI"></a>
  <a href="https://discord.gg/4P5teKzGUE"><img src="docs/discord.png" width="18" height="18" alt=""> Rejoignez le Discord</a>
</p>

<p align="center">
  <img src="docs/screenshot.png" alt="Liste de résultats indexOF : nom de release en clair, tokens techniques mis en valeur, taille, seeders et âge">
  <br>
  <sub>Noms de release et d'indexeurs floutés pour la capture.</sub>
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

## L'interface

Un nom de release, c'est `The.Matrix.1999.REMASTERED.MULTi.2160p.BluRay.REMUX.DV.HDR-REBiRTH`.
C'est ce que vous venez lire, donc c'est ce que l'interface met en avant : la partie
lisible passe en clair, les tokens techniques sont mis en valeur là où ils sont déjà
écrits, le groupe de release s'efface. Pas de pastilles recopiées sous chaque ligne.

- **Recherche dynamique** sans rechargement, état partageable par URL (`?q=…&days=…&trackers=…`).
- **Top** : les derniers uploads de tous vos trackers, sans taper de requête.
- **Liseré de qualité** à gauche de chaque ligne : la résolution d'une liste se lit verticalement.
- **Filtres** réunis dans un seul panneau — période, catégories, indexeurs, puis affinage
  (seeders minimum, freeleech, qualité) sur les résultats affichés. Ce qui est actif
  remonte en jetons retirables au-dessus de la liste.
- **Défilement infini** : la suite se charge en descendant.
- **Tri** par titre, taille, seeders ou âge, mémorisé, accessible au clavier.
- **Actions** : télécharger le `.torrent` (proxy chiffré), ouvrir ou copier le magnet,
  envoyer à qBittorrent avec choix de la catégorie.
- **Masquage des noms d'indexeurs** en un bouton, persisté localement ; le survol révèle.
- **Filtre -18** appliqué côté serveur, indicateur des indexeurs en erreur, historique local.

## Configuration (`.env`)

| Variable | Description | Défaut |
|----------|-------------|--------|
| `PROWLARR_API_KEY` | Clé API Prowlarr | — (obligatoire) |
| `PROWLARR_BASE_URL` | URL de Prowlarr, sans slash final | — (obligatoire) |
| `APP_SECRET` | Secret de scellement des liens (≥ 16 car., `openssl rand -hex 32`) | — (obligatoire) |
| `APP_PASSWORD` | Mot de passe d'accès. Vide = démarrage refusé, sauf `AUTH_DISABLED=1` | — (obligatoire) |
| `AUTH_DISABLED` | `1` pour assumer une app sans authentification | `0` |
| `RESULT_LIMIT` | Nombre max de résultats par recherche | `200` |
| `QBITTORRENT_URL` | URL Web UI qBittorrent. Vide = envoi désactivé | _(vide)_ |
| `QBITTORRENT_USER` / `QBITTORRENT_PASS` | Identifiants de la Web UI qBittorrent | `admin` / _(vide)_ |
| `PROWLARR_TIMEOUT` | Délai d'une recherche (s). Les trackers sont interrogés en direct | `45` |
| `QBITTORRENT_TIMEOUT` | Délai des appels qBittorrent (s) | `15` |
| `TRUST_PROXY` | `1` seulement derrière un reverse-proxy qui pose `X-Forwarded-For` | `0` |
| `CACHE_TTL` | Durée du cache recherches/indexeurs (s) | `120` |
| `CACHE_DIR` | Répertoire de cache | `/tmp/indexof_cache` |

## Envoi vers qBittorrent

Renseignez `QBITTORRENT_URL` (ex. `http://qbittorrent:8081`) pour faire apparaître le bouton d'envoi, ainsi que `QBITTORRENT_USER` / `QBITTORRENT_PASS`.

N'utilisez **pas** le contournement d'authentification par sous-réseau (*Options → Web UI → Bypass authentication for clients in whitelisted IP subnets*) : il ouvre la Web UI — donc le contrôle total du client BitTorrent, y compris l'exécution d'un programme externe — à tout ce qui atteint le réseau Docker. La Web UI doit exiger un mot de passe, avec *CSRF protection* et *Host header validation* actives.

> Le port publié doit être identique au port interne (`WEBUI_PORT`) : qBittorrent valide le port de l'en-tête `Host` et rejette tout le reste.

## Sécurité

- **Authentification obligatoire** : `APP_PASSWORD` (session + CSRF). Sans lui, l'app refuse de démarrer, à moins de poser explicitement `AUTH_DISABLED=1`.
- **Liens de téléchargement scellés** : les URLs Prowlarr (qui contiennent la clé API) ne sont jamais envoyées au navigateur. Le client reçoit un jeton chiffré (AES-256-GCM) à durée de vie limitée, que seul le serveur peut ouvrir.
- **Proxy `.torrent` anti-SSRF** : schémas `http(s)` uniquement, rejet des IP privées/réservées, épinglage de l'IP validée (anti-DNS-rebinding), re-validation à chaque redirection, plafond de taille et timeouts.
- **Anti-brute-force** : `limit_req` nginx sur les POST de login + verrouillage applicatif (10 échecs / 15 min par IP).
- **En-têtes** : CSP stricte (pas d'inline, pas de CDN), `nosniff`, `no-referrer`, `frame-ancestors 'none'`.
- **Conteneurs durcis** : non-root, `cap_drop: ALL`, `no-new-privileges`, système de fichiers en lecture seule, ports liés à la loopback.

En production : terminez le TLS sur un reverse-proxy, décommentez `Strict-Transport-Security` dans `docker/nginx.conf`, et si le proxy pose `X-Forwarded-For`, mettez `TRUST_PROXY=1` **et** configurez le module `realip` de nginx (sinon le rate-limit voit l'IP du proxy et un seul attaquant verrouille tout le monde).

## Structure

```
public/                 # Racine web exposée (seul ce dossier est servi)
  index.php             # Coquille HTML (aucune donnée sensible)
  login.php / logout.php# Authentification (si APP_PASSWORD défini)
  api.php               # API JSON : status + indexeurs + recherche (jetons scellés)
  send.php              # Envoi d'un torrent vers qBittorrent (auth + CSRF + jeton)
  download_torrent.php  # Proxy .torrent sécurisé (jeton chiffré + anti-SSRF)
  assets/               # CSS et JS, servis sans dépendance externe
src/                    # Hors racine web
  config.php            # Chargement de la configuration (env > .env)
  auth.php              # Session, mot de passe, jeton CSRF
  ProwlarrClient.php    # Client API Prowlarr (cURL, X-Api-Key, cache, timeouts)
  QbittorrentClient.php # Client Web API qBittorrent (ajout par URL/magnet)
  functions.php         # Échappement, URL, jetons scellés, anti-SSRF, formatage
tests/                  # Tests PHPUnit (fonctions critiques)
docker/nginx.conf       # Vhost nginx (reverse-proxy FastCGI vers php-fpm)
Dockerfile              # Multi-stage : php-fpm (app) + nginx (web), base Alpine
docker-compose.yml      # web (nginx) + php (php-fpm) + prowlarr + qbittorrent
.github/workflows/ci.yml# Intégration continue
.github/dependabot.yml  # Mises à jour composer, images Docker et actions
```

## Développement

```bash
composer install
composer test    # PHPUnit
composer stan    # PHPStan (niveau 7, analysé sur la plage PHP 8.1 → 8.5)
```

À chaque push, sur une pull request et une fois par semaine, la CI enchaîne :
lint PHP et JS, `composer audit` (CVE des dépendances), PHPStan et PHPUnit sur
PHP 8.3 **et** 8.5, construction des images, scan Trivy (CVE des images, secrets
commités, mauvaises configurations) et analyse CodeQL. Le rapport complet
remonte dans l'onglet *Security*.

L'exécution hebdomadaire n'est pas décorative : une CVE publiée après le merge
n'apparaît dans aucun scan déclenché par un commit.

## Sans Docker

Nécessite PHP 8.1+ avec les extensions cURL, JSON et OpenSSL. Définissez les variables d'environnement (ou créez `.env`), puis servez le dossier `public/` :

```bash
php -S 0.0.0.0:8080 -t public
```

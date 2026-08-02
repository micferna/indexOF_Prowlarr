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

S'il manque un réglage, l'app ne démarre pas et affiche à la place un écran qui
les liste tous, avec le remède pour chacun. Elle ne lit que la configuration :
elle n'en écrit jamais depuis le navigateur.

## L'interface

Un nom de release, c'est `The.Matrix.1999.REMASTERED.MULTi.2160p.BluRay.REMUX.DV.HDR-REBiRTH`.
C'est ce que vous venez lire, donc c'est ce que l'interface met en avant : la partie
lisible passe en clair, les tokens techniques sont mis en valeur là où ils sont déjà
écrits, le groupe de release s'efface. Pas de pastilles recopiées sous chaque ligne.

- **Recherche dynamique** sans rechargement, état partageable par URL (`?q=…&days=…&trackers=…`).
- **Tri par pertinence** par défaut sur une recherche : le titre qui contient votre
  requête entière passe devant celui qui n'en a qu'un mot, avant même le nombre de
  seeders. Les autres tris restent à un clic, et le vôtre est mémorisé.
- **Top** : les derniers uploads de tous vos trackers, sans taper de requête.
- **Liseré de qualité** à gauche de chaque ligne : la résolution d'une liste se lit verticalement.
- **Filtres** réunis dans un seul panneau — période, catégories, indexeurs, puis affinage
  (seeders minimum, freeleech, qualité) sur les résultats affichés. Ce qui est actif
  remonte en jetons retirables au-dessus de la liste.
- **Contenu du `.torrent`** avant de le prendre : un fichier ou un pack de quarante
  épisodes ? La liste se déplie sous la ligne, sans rien télécharger dans qBittorrent.
- **Affiche et résumé** de chaque film ou série, directement dans la liste. Une vignette
  à gauche du titre, la fiche complète au survol (au doigt : une tape) — année, note,
  durée, genres, synopsis. C'est ce qui évite d'ouvrir la page du tracker, donc de s'y
  connecter, juste pour savoir de quel film il s'agit.
  Détails : [Affiches et fiches](#affiches-et-fiches).
- **Bibliothèque** : ce qui est téléchargé, en grille d'affiches. Lecture dans le
  navigateur, lien à ouvrir dans VLC, ou envoi sur le téléviseur. Ce que l'appareil
  ne sait pas décoder est **converti à la volée** — et seulement ce qui doit l'être :
  un MKV en H.264/AAC est simplement remis dans un autre conteneur, quasiment gratuit.
  Retirer un fichier distingue **masquer** (il reste partagé) de **supprimer**.
  Détails : [Bibliothèque et lecture](#bibliothèque-et-lecture).
- **Envoi sur le téléviseur** (Cast) : les récepteurs du réseau sont découverts tout
  seuls — MiBox, Chromecast, Android TV. Lecture, déplacement dans le film et durée
  complète fonctionnent, y compris sur un fichier converti.
  Détails : [Envoyer sur le téléviseur](#envoyer-sur-le-téléviseur-cast).
- **Santé des indexeurs** : cliquez sur l'indicateur d'état pour voir la latence, le
  volume et les échecs de chacun. C'est ce qui explique une recherche qui traîne.
- **Filtre par taille** (tranches cliquables) et **exclusion de mots** dans la
  requête : `matrix -animated`.
- **Doublons regroupés** : la même release publiée sur plusieurs trackers tient
  sur une ligne, menée par la source la mieux seedée. Les autres sont repliées
  derrière un « +N » et restent téléchargeables individuellement.
- **Défilement infini** : la suite se charge en descendant.
- **Raccourcis clavier** : `/` recherche, `j`/`k` navigation, `Entrée` ouvrir,
  `d` télécharger, `e` envoyer, `c` copier le magnet, `?` l'aide complète.
- **Tri** par titre, taille, seeders ou âge, mémorisé, accessible au clavier.
- **Actions** : télécharger le `.torrent` (proxy chiffré), ouvrir ou copier le magnet,
  envoyer à qBittorrent avec choix de la catégorie, ou pousser la release vers
  Sonarr / Radarr / Lidarr / Readarr.
- **Recherches enregistrées** : mettez de côté une requête et ses filtres, rejouez-la
  d'un clic depuis le panneau Filtres — et **abonnez qBittorrent à son flux RSS**
  pour qu'il récupère les nouveautés sans que vous ouvriez l'application.
- **« Déjà pris »** : les releases que vous avez déjà envoyées sont marquées dans les
  résultats, avec la date et la destination. Le rapprochement se fait sur le titre
  enregistré au moment de l'envoi, pas sur le nom que le client a pu réécrire.
- **Historique des envois**, consultable et purgeable depuis la vue Transferts : il
  garde la trace même après suppression du torrent.
- **Transferts** : ce que qBittorrent télécharge ou partage, sans quitter l'app —
  progression, ratio, vitesses, état. Arrêt, relance et suppression (avec ou sans
  les fichiers, en deux temps, sans boîte de dialogue). Filtres d'état et actions
  groupées pour quand la liste s'allonge. Rafraîchi toutes les 3 s, uniquement
  quand la vue est ouverte.
- **Utilisable au téléphone** : sous 640 px le tableau devient une liste de cartes —
  titre sur toute la largeur, chiffres et actions en dessous. Aucun défilement
  horizontal, cibles tactiles élargies.
- **Installable sur téléphone** (PWA) : icône, plein écran, cache des seuls fichiers
  statiques — jamais les pages ni l'API, qui dépendent de la session.
- **Masquage des noms d'indexeurs** en un bouton, persisté localement ; le survol révèle.
- **Filtre -18** appliqué côté serveur, indicateur des indexeurs en erreur, historique local.

## Configuration (`.env`)

| Variable | Description | Défaut |
|----------|-------------|--------|
| `PROWLARR_API_KEY` | Clé API Prowlarr | — (obligatoire) |
| `PROWLARR_BASE_URL` | URL de Prowlarr, sans slash final | — (obligatoire) |
| `APP_SECRET` | Secret de scellement des liens (≥ 16 car., `openssl rand -hex 32`) | — (obligatoire) |
| `APP_PASSWORD` | Mot de passe partagé et accès administrateur. Vide = démarrage refusé, sauf `AUTH_DISABLED=1` | — (obligatoire) |
| `AUTH_DISABLED` | `1` pour assumer une app sans authentification | `0` |
| `RESULT_LIMIT` | Nombre max de résultats par recherche | `200` |
| `QBITTORRENT_URL` | URL Web UI qBittorrent. Vide = envoi désactivé | _(vide)_ |
| `QBITTORRENT_USER` / `QBITTORRENT_PASS` | Identifiants de la Web UI qBittorrent | `admin` / _(vide)_ |
| `PROWLARR_TIMEOUT` | Délai d'une recherche (s). Les trackers sont interrogés en direct | `45` |
| `QBITTORRENT_TIMEOUT` | Délai des appels qBittorrent (s) | `15` |
| `SONARR_URL` / `SONARR_API_KEY` | Sonarr. Vide = bouton absent | _(vide)_ |
| `RADARR_URL` / `RADARR_API_KEY` | Radarr | _(vide)_ |
| `LIDARR_URL` / `LIDARR_API_KEY` | Lidarr | _(vide)_ |
| `READARR_URL` / `READARR_API_KEY` | Readarr | _(vide)_ |
| `TRUST_PROXY` | `1` seulement derrière un reverse-proxy qui pose `X-Forwarded-For` | `0` |
| `DISCORD_WEBHOOK` | Webhook d'un salon Discord. Vide = cloche masquée | _(vide)_ |
| `NOTIFY_INTERVAL` | Secondes entre deux vérifications du service de veille | `900` |
| `BIND_ADDR` | Adresse d'écoute. Loopback par défaut ; à changer **uniquement** pour le Cast, qui exige que le téléviseur puisse joindre l'app | `127.0.0.1` |
| `DOWNLOADS_DIR` | Dossier des téléchargements **sur l'hôte**. Pointez-le vers un disque avec de l'espace ; le chemin dans les conteneurs ne change pas | `./data/downloads` |
| `MEDIA_DIR` | Chemin du même dossier **vu par l'application**. Ne changez pas sans raison | `/media` |
| `PUBLIC_BASE_URL` | Adresse de l'app **vue depuis le réseau local**. C'est elle qu'on donne au téléviseur pour le Cast. Vide = déduite de la requête | _(vide)_ |
| `CAST_SCAN_INTERVAL` | Secondes entre deux recherches de récepteurs Cast | `60` |
| `TRANSCODE` | `0` pour désactiver la conversion à la volée (lecture directe seule) | `1` |
| `TRANSCODE_MAX` | Conversions simultanées en flux continu (navigateur). Chacune occupe un worker et un cœur | `2` |
| `TRANSCODE_DIR` | Dossier des segments HLS **sur l'hôte**. Doit appartenir à l'utilisateur du conteneur PHP (`chown 82:82`) | `./data/transcode` |
| `FFMPEG_BIN` / `FFPROBE_BIN` | Chemins des binaires, s'ils ne sont pas dans le `PATH` | `ffmpeg` / `ffprobe` |
| `STREAM_TTL` | Validité d'un lien de lecture (s). Il vaut autorisation à lui seul : VLC n'a pas de session | `43200` (12 h) |
| `DATA_DIR` | Répertoire de la base SQLite (historique, recherches enregistrées) | `/var/lib/indexof` |
| `CACHE_TTL` | Durée du cache recherches/indexeurs (s) | `120` |
| `CACHE_DIR` | Répertoire de cache | `/tmp/indexof_cache` |

## Envoi vers qBittorrent

Renseignez `QBITTORRENT_URL` (ex. `http://qbittorrent:8081`) pour faire apparaître le bouton d'envoi, ainsi que `QBITTORRENT_USER` / `QBITTORRENT_PASS`.

N'utilisez **pas** le contournement d'authentification par sous-réseau (*Options → Web UI → Bypass authentication for clients in whitelisted IP subnets*) : il ouvre la Web UI — donc le contrôle total du client BitTorrent, y compris l'exécution d'un programme externe — à tout ce qui atteint le réseau Docker. La Web UI doit exiger un mot de passe, avec *CSRF protection* et *Host header validation* actives.

> Le port publié doit être identique au port interne (`WEBUI_PORT`) : qBittorrent valide le port de l'en-tête `Host` et rejette tout le reste.

## Flux RSS

Chaque recherche enregistrée expose un flux. Le bouton **RSS** du panneau Filtres
copie son adresse ; collez-la dans qBittorrent (*Vue RSS → Nouvel abonnement*), et
il ira chercher les nouveautés tout seul.

Un lecteur RSS ne peut pas ouvrir de session : l'accès repose sur le jeton secret
présent dans l'URL, propre à chaque recherche. Supprimer la recherche révoque le
flux. Le flux ne contient jamais l'URL Prowlarr réelle : les pièces jointes
pointent vers le proxy de téléchargement, avec un lien scellé.

> **Adresse à utiliser depuis un conteneur.** Le bouton copie l'adresse telle que
> vous voyez l'application (`http://localhost:8080/rss.php?t=…`). Un qBittorrent
> qui tourne dans le même Docker Compose ne joint pas `localhost` : remplacez
> l'hôte par le nom du service, soit `http://web/rss.php?t=…`.

## Notifications Discord

Le flux RSS prévient qBittorrent ; ceci vous prévient, vous. Renseignez
`DISCORD_WEBHOOK` dans `.env`, activez la cloche sur les recherches à surveiller,
puis lancez le service — optionnel, derrière un profil :

```bash
docker compose --profile notify up -d
```

Il rejoue les recherches surveillées toutes les 15 minutes (`NOTIFY_INTERVAL`) et
ne signale que ce qu'il n'a jamais vu pour cette recherche. La première exécution
enregistre l'existant **sans rien envoyer** : sinon elle déverserait des centaines
de lignes d'un coup.

## Sonarr, Radarr et consorts

L'application ne remplace pas les *arr : elle leur passe le relais. Un bouton par
application apparaît sur chaque résultat dès que son URL et sa clé API sont
renseignées. La release est poussée via `release/push` ; l'application la parse,
la rapproche de ce qu'elle suit, applique ses profils de qualité et la confie à
son propre client de téléchargement.

Le retour est affiché tel quel : « acceptée et mise en téléchargement », ou le
motif du refus (« Unknown Series », qualité déjà présente…). Pas de faux succès.

Sonarr et Radarr sont fournis dans le `docker-compose.yml`, derrière un profil —
rien ne démarre sans le demander :

```bash
docker compose --profile arr up -d
```

- Sonarr : <http://localhost:8989> · Radarr : <http://localhost:7878>
- Récupérez la clé API de chacun (*Settings → General*) et reportez-la dans `.env`.
- Dans Prowlarr, ajoutez-les en **Apps** : il y synchronisera vos indexeurs.
- Les trois services partagent `./data/downloads` : c'est ce qui permet aux *arr
  de retrouver les fichiers que qBittorrent a terminés.

## Affiches et fiches

Un nom de release ne dit pas de quel film il s'agit. Jusqu'ici, pour le savoir, il
fallait ouvrir la fiche du tracker — donc s'y connecter, tracker par tracker. La
vignette et le résumé rendent ce détour inutile.

**Aucune configuration.** La fonctionnalité s'allume dès que Radarr ou Sonarr est
renseigné dans `.env` — les mêmes réglages que pour l'envoi. Ce sont eux qui
interrogent TMDB / TheTVDB via le serveur de métadonnées des \*arr : pas de clé API
supplémentaire à obtenir. Sans eux, le bouton n'apparaît pas et rien ne change.

Pourquoi eux et pas Prowlarr : Prowlarr ne renvoie pas d'affiche du tout, et moins
d'un quart de ses résultats portent un identifiant IMDb ou TMDB.

Ce qui est cherché, et comment :

- Le titre est découpé pour en extraire l'œuvre — `The.Matrix.1999.MULTi.2160p…`
  devient « The Matrix », 1999 ; `Breaking.Bad.S01E01…` devient « Breaking Bad »,
  saison 1. Quand l'indexeur donne un identifiant IMDb ou TMDB, il prime : c'est
  exact plutôt que probable.
- **Les accents cassés sont réparés d'abord.** Beaucoup de trackers publient
  leurs titres en UTF-8 déjà relu comme du Latin-1 : « Deuxième » y arrive en
  « DeuxiÃ¨me ». Tant qu'on cherchait avec ces octets-là, aucune base ne
  reconnaissait le titre — c'était la première cause d'affiche manquante.
- Seuls les films et les séries sont concernés. On n'interroge pas Radarr pour un
  album ou une image ISO.
- Les releases qui désignent la même œuvre ne comptent qu'une fois : quarante
  versions de Matrix, c'est une recherche, pas quarante. Le reste est mis en cache
  sur le volume persistant, pendant 30 jours.

**Le premier résultat n'est pas le bon.** Pour « GAME OF THRONES INTEGRAL »,
Sonarr propose « Game of Thrones Talk », puis « The Iron Anniversary », et le
vrai « Game of Thrones » en quatrième position seulement. Les candidats sont donc
classés — correspondance de titre, d'année, ou des deux — au lieu de faire
confiance à l'ordre. Les titres alternatifs comptent : c'est par eux que « Dune :
Deuxième partie » rejoint « Dune: Part Two ». Un candidat plus long que ce qu'on
cherche est en revanche une autre œuvre : « Le Roi Lion » ne doit pas attraper
« Le Roi Lion - Les nouvelles Aventures ».

Quand rien ne correspond, quatre replis sont essayés dans l'ordre — et seulement
pour ce qui n'a pas abouti, ce qui laisse le cas courant à un seul aller-retour :

1. **Le titre entre parenthèses en fin de nom.** `… x265-QTZ (Dune Part Two)`,
   `… -RONIN (D@bbe: The Possession)` : les uploadeurs y mettent le titre
   international d'une release publiée sous son titre local.
2. **Le titre débarrassé des mots qui décrivent le lot** : « Avatar Trilogie »
   devient « Avatar », « Game of Thrones The Complete Series » devient
   « Game of Thrones ».
3. **Le nom amputé de son dernier mot**, puis réduit à ses deux premiers — pour
   « Les Visiteurs Elia Kazan » et consorts. Jamais jusqu'à un tronçon sans mot
   porteur : « Le Roi » ramenait vingt films sans rapport.

Au-delà du premier essai, une fiche n'est retenue que si son année correspond à
celle de la release, et une année connue mais démentie par tous les candidats
vaut refus — c'est un désaccord, pas une hésitation. Une affiche fausse est pire
qu'une case vide.

Mesuré sur vingt recherches réelles (1 200 films et séries) : **1 181 fiches
trouvées, soit 98 %**. Les manquantes sont pour l'essentiel des coffrets
(« Trilogie », « Pentalogie », « Saga INTEGRALE ») — où aucune fiche unique ne
serait juste — et quelques documentaires absents des bases.

**Le navigateur ne contacte jamais TMDB ni TheTVDB.** Les images passent par
`poster.php`, qui va les chercher côté serveur et les garde. Un `<img>` pointant
directement sur un CDN annoncerait à un tiers l'adresse IP de qui regarde, et quoi —
sur cette application-là, c'est précisément ce qu'on ne veut pas. La CSP reste donc
`img-src 'self'`, et une affiche déjà vue n'est retéléchargée par personne.

Le proxy n'accepte que des sources d'images connues (`image.tmdb.org`,
`artworks.thetvdb.com`, `assets.fanart.tv`), même face à un jeton scellé par
l'application : un jeton valide n'est pas un blanc-seing. Le contenu récupéré est
vérifié sur ses octets de tête — si ce n'est pas une image, il n'est ni servi ni
mis en cache.

Le bouton **Affiches** du panneau Filtres coupe l'affichage si vous préférez la
liste dense ; le choix est mémorisé.

## Bibliothèque et lecture

> **Où sont rangés les fichiers.** Par défaut dans `./data/downloads`, c'est-à-dire
> sur la partition du projet — souvent la plus petite. `DOWNLOADS_DIR` déplace ce
> dossier où vous voulez sans rien changer d'autre : le chemin **à l'intérieur**
> des conteneurs reste `/downloads`, donc les torrents en cours, Sonarr et Radarr
> continuent de s'y retrouver. Le dossier doit appartenir à `PUID:PGID`
> (1000:1000 par défaut) et être lisible par tous — `chmod 755` : l'application
> le lit en tant que `www-data` pour la bibliothèque.

Une vue **Bibliothèque** liste ce que le client de téléchargement a effectivement
posé sur le disque, avec les mêmes affiches et résumés que les résultats de
recherche. Le bouton apparaît dès que `./data/downloads` est monté (c'est le cas
par défaut) ; sinon la vue se désactive d'elle-même.

Deux façons de regarder, parce qu'aucune ne suffit seule :

- **Dans le navigateur**, pour ce qu'il sait décoder — MP4, M4V, WebM. Un bouton
  ▶ déplie un lecteur sous la fiche. Le déplacement dans la vidéo fonctionne
  (requêtes Range), y compris sur un fichier de plusieurs gigaoctets.
- **Dans VLC**, pour tout le reste. Un MKV en HEVC avec piste DTS ne passera
  jamais dans un onglet ; l'interface le dit *avant* le clic (« VLC requis »)
  plutôt que d'afficher une vidéo noire. Le bouton « copier » donne un lien à
  ouvrir dans VLC — *Média › Ouvrir un flux réseau* — sur un ordinateur, un
  téléphone ou une box Android TV.

### Retirer un fichier

Deux gestes, volontairement distincts — c'est la différence qui compte sur un
tracker privé :

- **Masquer** : le fichier disparaît de la liste, **rien d'autre**. Il reste sur
  le disque et continue d'être partagé. Désencombrer sa vue ne doit pas coûter
  son ratio. Réversible : « Afficher les N masqués » les ramène.
- **+ fichier** : qBittorrent retire le torrent **et** le fichier. Le partage
  s'arrête, forcément.

La confirmation se fait en deux temps, sans boîte de dialogue.

La suppression passe toujours par qBittorrent, jamais par l'application : le
dossier de médias est monté en lecture seule (délibérément), et effacer un
fichier dans le dos du client laisserait un torrent en erreur et un partage rompu
que personne n'a demandé. Sans torrent correspondant — fichier déplacé, torrent
déjà retiré — seul le masquage est proposé, et le motif est affiché. Deux
torrents portant le même nom font également refuser : un choix arbitraire efface
le mauvais fichier, et c'est irréversible.

### Envoyer sur le téléviseur (Cast)

Un bouton d'envoi apparaît sur chaque fichier dès qu'un récepteur Cast a été vu
sur le réseau — MiBox, Chromecast, Android TV. Il faut démarrer le service de
découverte, qui n'est pas lancé par défaut :

```bash
docker compose --profile cast up -d
```

**Pourquoi un service à part ?** Les appareils Cast s'annoncent par multicast
mDNS, et le multicast ne traverse pas le réseau bridge de Docker : depuis le
conteneur applicatif, on n'entend rien. Ce service est donc le seul en
`network_mode: host`. Il écoute, dépose la liste dans un fichier du volume
partagé, et c'est tout : aucun port ouvert, aucune API, l'application ne lui
parle jamais.

**`PUBLIC_BASE_URL` : le réglage qui décide de tout.** Le Cast ne pousse pas la
vidéo — il envoie une URL, et c'est le téléviseur qui va la chercher. Cette URL
doit donc être valable *depuis le salon*. Par défaut elle est déduite de la
requête, ce qui est juste dès que vous consultez l'app depuis un autre appareil.
Si l'adresse déduite est une boucle locale, l'envoi est **refusé avec le motif**
plutôt que de partir dans le vide et de laisser un écran noir :

```bash
PUBLIC_BASE_URL=http://192.168.1.50:8080
```

**Les formats ne sont plus un obstacle** : le récepteur Cast ne lit que du
H.264/AAC en MP4, mais l'application convertit ce qui doit l'être avant de lui
donner l'URL. Un MKV en HEVC avec piste DTS passe donc, **et reste navigable** —
la durée complète est connue du téléviseur.

Ce qui a demandé trois corrections, toutes mesurées sur un vrai appareil :

1. **Un flux converti au fil de l'eau ne suffit pas.** Sans taille annoncée ni
   requêtes Range, la Mi Box en lit une quinzaine de secondes, referme la
   connexion et reste bloquée (18 Mo puis plus rien, journal nginx à l'appui).
   Le Cast passe donc par **HLS** : segments + liste de lecture.
2. **Le téléviseur ne suit pas les redirections** sur un média : il redemande
   trois fois puis abandonne. C'est l'adresse finale de la liste qui lui est
   transmise, pas un renvoi.
3. **CORS est indispensable.** Un récepteur Cast lit un MP4 progressif via
   `<video src>` — aucun contrôle d'origine — mais le HLS via XHR/MSE. Sans
   `Access-Control-Allow-Origin`, la requête échoue en silence et l'appareil
   redemande la liste en boucle sans jamais charger un segment.

Les segments sont écrits dans `TRANSCODE_DIR` : **comptez la taille du film par
lecture**, effacée au bout de six heures. Placez ce dossier sur un disque avec de
l'espace, et donnez-le à l'utilisateur du conteneur PHP (`chown 82:82`).

Avec `TRANSCODE=0`, on retombe sur la lecture directe seule et l'interface
prévient avant le clic.

L'adresse du téléviseur vient du navigateur : elle est donc restreinte aux plages
RFC 1918 (`10/8`, `172.16/12`, `192.168/16`) et IPv6 unique-local. « Pas
publique » ne suffirait pas — cette catégorie contient `169.254.169.254`, le
point de métadonnées des hébergeurs cloud, et cet endpoint deviendrait un
scanner de ports à la demande.

### Conversion à la volée

« Si l'appareil sait lire, il lit ; sinon on convertit. » La décision se prend sur
les **codecs réels** (via `ffprobe`), jamais sur l'extension — et surtout, **on ne
convertit que ce qu'il faut** :

| Mode | Quand | Coût |
|------|-------|------|
| `direct` | Conteneur et codecs déjà compatibles | Aucun processus, nginx sert le fichier, **déplacement possible** |
| `remux` | Codecs bons, conteneur non (MKV en H.264/AAC) | Les flux sont recopiés tels quels — quasiment gratuit |
| `audio` | Image bonne, son non (AC3, DTS, TrueHD) | Seul l'audio est réencodé |
| `full` | L'image aussi (HEVC, VC-1, XviD) | Le seul cas réellement lourd |

Sur une bibliothèque typique, la majorité des MKV tombe en `remux` ou `audio` : le
transcodage complet reste l'exception. Le nom du codec ne suffit pas — un H.264 en
10 bits (« Hi10P ») ou en 4:2:2 n'est décodé ni par un navigateur ni par un
récepteur Cast, alors que `ffprobe` l'annonce comme du simple « h264 ». Le format
de pixels est donc vérifié aussi.

**Ce que ça ne fait pas : le déplacement dans une vidéo convertie.** Le flux est
produit au fil de l'eau, sa taille n'est pas connue d'avance ; il n'y a donc ni
`Content-Length` ni requêtes Range. La lecture directe, elle, reste navigable.
Résoudre ça demanderait de la segmentation HLS — c'est là que commence le
véritable serveur de médias.

Chaque conversion occupe un worker php-fpm et un cœur pendant toute la lecture :
`TRANSCODE_MAX` (2 par défaut) plafonne les lectures simultanées, au-delà l'app
répond « trop de conversions en cours » plutôt que de figer la machine. Mettez
`TRANSCODE=0` pour tout désactiver : ce qui passe passe, le reste ne passe pas,
mais aucun processeur n'est mobilisé.

Pour une bibliothèque complète avec segmentation, sous-titres incrustés et reprise
de lecture, Jellyfin sur le même dossier `./data/downloads` reste plus complet —
les deux cohabitent sans se gêner.

### Comment c'est servi

**PHP autorise, nginx envoie.** Un film relayé par php-fpm mobiliserait un worker
pendant toute la lecture et obligerait à réimplémenter les requêtes Range.
`stream.php` valide le jeton puis renvoie un `X-Accel-Redirect` vers un
emplacement `internal` — inatteignable depuis l'extérieur. nginx s'occupe du
reste : Range, reprise, ETag, nativement.

**Le lien de lecture vaut autorisation, sans session.** Il le faut : VLC sur une
télévision n'a pas le cookie du navigateur. Le jeton est donc chiffré
(AES-256-GCM), expire au bout de 12 h (`STREAM_TTL`) et ne désigne qu'un chemin
relatif. Traitez-le comme un lien de partage : qui l'a peut lire ce fichier
jusqu'à expiration.

**Le dossier est monté en lecture seule**, dans les deux conteneurs. L'application
liste et donne à lire ; elle n'a aucune raison de pouvoir effacer un film.
`resolve()` repasse par `realpath()` et exige que la cible reste sous la racine :
ni `../`, ni chemin absolu, ni lien symbolique ne sortent de la bibliothèque —
c'est testé.

## Comptes utilisateurs

Facultatifs. Sans compte créé, rien ne change : tout le monde entre avec
`APP_PASSWORD`.

Connecté avec `APP_PASSWORD` (sans nom d'utilisateur), vous êtes
**administrateur** : un bouton apparaît dans la barre pour créer et supprimer
des comptes nommés. Chaque compte a son propre mot de passe (12 caractères
minimum, haché) et l'historique retient qui a envoyé quoi.

> **`APP_PASSWORD` reste toujours valable**, même une fois des comptes créés.
> C'est délibéré : c'est ce qui rend impossible de se verrouiller dehors en
> supprimant un compte ou en oubliant un mot de passe. En contrepartie, la
> sécurité de l'ensemble repose sur la force de ce mot de passe.

Un compte nommé ne peut ni créer ni supprimer de compte : sans cette limite,
n'importe quel utilisateur pourrait s'octroyer un accès ou évincer les autres.

### Cloisonnement par indexeur

**Par défaut, un compte utilise vos indexeurs — donc vos identifiants de
tracker.** Sur un tracker privé, cela signifie que ses téléchargements comptent
sur votre ratio et que ses manquements retombent sur votre compte.

Pour l'éviter, attribuez à chaque personne ses propres indexeurs :

1. dans **Prowlarr**, ajoutez le tracker une fois par personne, avec *ses*
   identifiants (« YggTorrent (alice) », « YggTorrent (bob) ») ;
2. dans la vue **Comptes**, cochez les indexeurs autorisés pour chaque compte.

Le compte ne voit alors que ces indexeurs, ne peut interroger qu'eux, et ses
téléchargements passent par ses propres identifiants. Tout coché revient à
« aucune restriction ». Partager un tracker devient un choix explicite,
indexeur par indexeur.

La restriction s'applique côté serveur sur **tous** les chemins : recherche,
liste des indexeurs, statistiques, flux RSS et veille Discord — y compris ceux
qui s'exécutent sans session, où c'est le propriétaire de la recherche qui
fait autorité. Forcer des identifiants d'indexeur dans l'URL ne contourne rien :
la demande est intersectée avec la liste autorisée, jamais réunie.

Supprimer un compte — ou en effacer la trace en restaurant une sauvegarde —
ferme aussitôt sa session ouverte.

### Une catégorie qBittorrent par compte

Dans la vue **Comptes**, donnez une catégorie à chacun (`alice-dl`, `bob-dl`).
Ses téléchargements y atterrissent quoi qu'il demande : la catégorie du compte
prime sur celle choisie dans le navigateur. Laissez le champ vide pour lui
laisser le choix. Réglez le dossier de destination correspondant dans
qBittorrent (*Catégories → Enregistrer dans*).

## Sauvegarde

Depuis la vue **Comptes**, « Télécharger la sauvegarde » produit un instantané
cohérent de la base : comptes, recherches enregistrées, historique et jetons de
flux. Il est pris par `VACUUM INTO`, donc valable même pendant une écriture —
copier `indexof.sqlite` à chaud ne l'est pas.

**Ce fichier contient les empreintes des mots de passe et les jetons de flux
RSS. Traitez-le comme un mot de passe.**

La restauration **remplace** la base, elle ne la fusionne pas. Le fichier envoyé
est refusé s'il n'est pas une base indexOF, et la base précédente est conservée
à côté (`indexof.sqlite.avant-restauration`) au cas où. Réservé à
l'administrateur.

## Sécurité

- **Authentification obligatoire** : `APP_PASSWORD` (session + CSRF). Sans lui, l'app refuse de démarrer, à moins de poser explicitement `AUTH_DISABLED=1`. Comptes nommés en option, mots de passe hachés, gestion réservée à l'administrateur.
- **Liens de téléchargement scellés** : les URLs Prowlarr (qui contiennent la clé API) ne sont jamais envoyées au navigateur. Le client reçoit un jeton chiffré (AES-256-GCM) à durée de vie limitée, que seul le serveur peut ouvrir.
- **Proxy `.torrent` anti-SSRF** : schémas `http(s)` uniquement, rejet des IP privées/réservées, épinglage de l'IP validée (anti-DNS-rebinding), re-validation à chaque redirection, plafond de taille et timeouts.
- **Envoi Cast borné** : l'adresse du téléviseur vient du navigateur, elle est donc restreinte aux plages RFC 1918 et IPv6 unique-local. « Non publique » laisserait passer `169.254.169.254` (métadonnées cloud) et ferait de l'endpoint un scanner de ports. POST + CSRF, et la cible de lecture vient toujours d'un jeton scellé.
- **Lecture cloisonnée** : le dossier de médias est monté en lecture seule, les chemins repassent par `realpath()` et doivent rester sous la racine (ni `../`, ni chemin absolu, ni lien symbolique sortant). nginx sert les fichiers depuis un emplacement `internal`, inatteignable directement. Le jeton de lecture est chiffré et expire.
- **Proxy d'affiches cloisonné** : liste d'hôtes d'images autorisés vérifiée à l'ouverture du jeton, type réel contrôlé sur les octets de tête, réponse servie inerte (`default-src 'none'; sandbox`). Aucune requête du navigateur vers un service tiers : ni l'adresse IP du visiteur ni ce qu'il regarde ne sortent.
- **Anti-brute-force** : `limit_req` nginx sur les POST de login + verrouillage applicatif (10 échecs / 15 min par IP). Les affiches ont leur propre quota : vingt-cinq images en une page ne consomment pas celui de l'API.
- **En-têtes** : CSP stricte (pas d'inline, pas de CDN), `nosniff`, `no-referrer`, `frame-ancestors 'none'`.
- **Conteneurs durcis** : non-root, `cap_drop: ALL`, `no-new-privileges`, système de fichiers en lecture seule, ports liés à la loopback.
- **Dégradation propre** : si la base n'est pas accessible en écriture, les recherches enregistrées et l'historique disparaissent de l'interface — la recherche, elle, continue de fonctionner.

En production : terminez le TLS sur un reverse-proxy, décommentez `Strict-Transport-Security` dans `docker/nginx.conf`, et si le proxy pose `X-Forwarded-For`, mettez `TRUST_PROXY=1` **et** configurez le module `realip` de nginx (sinon le rate-limit voit l'IP du proxy et un seul attaquant verrouille tout le monde).

## Structure

```
public/                 # Racine web exposée (seul ce dossier est servi)
  index.php             # Coquille HTML (aucune donnée sensible)
  login.php / logout.php# Authentification (si APP_PASSWORD défini)
  api.php               # API JSON : status + indexeurs + recherche (jetons scellés)
  send.php              # Envoi d'un torrent vers qBittorrent (auth + CSRF + jeton)
  download_torrent.php  # Proxy .torrent sécurisé (jeton chiffré + anti-SSRF)
  poster.php            # Proxy d'affiches (hôtes autorisés + cache disque)
  stream.php            # Lecture vidéo : valide le jeton, nginx sert (X-Accel)
  assets/               # CSS et JS, servis sans dépendance externe
src/                    # Hors racine web
  config.php            # Chargement de la configuration (env > .env)
  auth.php              # Session, mot de passe, jeton CSRF
  ProwlarrClient.php    # Client API Prowlarr (cURL, X-Api-Key, cache, timeouts)
  QbittorrentClient.php # Client Web API qBittorrent (ajout par URL/magnet)
  functions.php         # Échappement, URL, jetons scellés, XML, formatage
  Search.php            # Recherche partagée par l'API et les flux RSS
  TorrentFetcher.php    # Récupération distante anti-SSRF (.torrent + affiches)
  Metadata.php          # Découpage des titres + fiches via Radarr/Sonarr
  Library.php           # Scan du dossier de téléchargements (chemins cloisonnés)
  Transcoder.php        # Décision direct/remux/audio/full + commandes ffmpeg
  CastProtocol.php      # Trames du protocole Google Cast (protobuf à la main)
  CastClient.php        # Envoi d'une vidéo vers un récepteur (TLS + CASTV2)
  CastDiscovery.php     # Lecture des annonces mDNS des récepteurs
  Bencode.php           # Lecture du contenu d'un .torrent
  ArrClient.php         # Client Sonarr/Radarr/Lidarr/Readarr (release/push)
  Store.php             # Base SQLite : envois mémorisés, recherches enregistrées
bin/notify.php          # Veille : signale les nouveautés sur Discord
bin/cast-discover.php   # Découverte des récepteurs Cast (réseau hôte)
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

## Contribuer

Les contributions sont bienvenues — lisez [CONTRIBUTING.md](CONTRIBUTING.md)
avant d'ouvrir une pull request, et [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
pour le ton des échanges.

Une faille de sécurité ne se signale pas dans une issue publique :
[SECURITY.md](SECURITY.md) explique la marche à suivre.

## Licence

[MIT](LICENSE).

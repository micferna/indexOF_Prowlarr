# Politique de sécurité

## Signaler une faille

**N'ouvrez pas d'issue publique pour une faille de sécurité.**

Utilisez le signalement privé de GitHub : onglet **Security → Report a
vulnerability** du dépôt. Le rapport n'est visible que des mainteneurs, et une
correction peut être préparée avant toute divulgation.

Si le formulaire n'est pas disponible pour vous, contactez un mainteneur en
message privé sur le [Discord du projet](https://discord.gg/4P5teKzGUE).

Merci d'inclure : la version (commit) testée, les étapes de reproduction, et
l'impact que vous en tirez. Une preuve de concept minimale vaut mieux qu'une
longue description.

Comptez quelques jours pour une première réponse : le projet est maintenu sur
du temps libre, sans engagement de délai.

## Versions suivies

Seul `main` est corrigé. Il n'y a pas de branche de maintenance : mettez à jour
avant de signaler.

## Ce qui nous intéresse

Le modèle de menace du projet est celui d'une application **exposée sur
Internet derrière un reverse-proxy TLS**, avec plusieurs utilisateurs qui
partagent un mot de passe unique. Sont dans le périmètre :

- contournement de l'authentification ou de la protection CSRF ;
- fuite de la clé API Prowlarr, du contenu de `.env` ou d'un jeton scellé ;
- forge d'un jeton de téléchargement, ou SSRF via `download_torrent.php` ou
  `send.php` (les IP privées et réservées doivent rester inatteignables) ;
- XSS, injection d'en-tête, traversée de chemin ;
- échappement d'un conteneur, ou élévation de privilège dans les images.

## Ce qui n'est pas une faille du projet

- **Votre configuration de Prowlarr ou de qBittorrent.** Le contournement
  d'authentification par sous-réseau de la Web UI qBittorrent, par exemple,
  donne le contrôle total du client BitTorrent à tout ce qui atteint le réseau
  Docker — c'est une erreur de configuration, documentée dans le README.
- **Une instance exposée sans TLS, ou avec un `APP_PASSWORD` faible.** L'app
  refuse de démarrer sans mot de passe, mais elle ne peut pas juger le vôtre.
- **`AUTH_DISABLED=1`.** C'est un choix explicite de tourner sans
  authentification.
- **Ce que fait un utilisateur légitime déjà connecté.** L'app n'a qu'un seul
  niveau de privilège.

## Vérifications automatiques

À chaque push, sur les pull requests et une fois par semaine, la CI exécute
`composer audit`, Trivy (CVE des images, secrets commités, mauvaises
configurations) et CodeQL. Les résultats remontent dans l'onglet *Security*.
Une alerte qui y apparaît n'a pas besoin d'être signalée — elle est déjà visible.

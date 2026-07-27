# Contribuer

Merci de passer par ici. Le projet est petit et volontairement sans framework :
du PHP 8.1+, du JavaScript sans dépendance, du CSS écrit à la main. Gardons-le
ainsi.

## Avant de coder

- **Un bug ?** Ouvrez une issue avec les étapes de reproduction. Si c'est une
  faille de sécurité, ne l'ouvrez pas publiquement : voir [SECURITY.md](SECURITY.md).
- **Une fonctionnalité ?** Ouvrez une issue d'abord. Une pull request non
  discutée risque d'être refusée pour une question de périmètre, et c'est du
  temps perdu pour tout le monde.

## Monter l'environnement

```bash
cp exemple.env .env
sed -i "s/^APP_SECRET=.*/APP_SECRET=$(openssl rand -hex 32)/" .env
# renseignez PROWLARR_API_KEY, PROWLARR_BASE_URL et APP_PASSWORD
docker compose up -d --build
```

**Le code est copié dans les images, il n'y a pas de montage.** Éditer un
fichier sur l'hôte ne change rien à l'application qui tourne : reconstruisez.

```bash
docker compose build php web && docker compose up -d php web
```

Les assets sont servis avec `max-age=86400` : après un déploiement, rechargez
une fois en ignorant le cache (Ctrl+Maj+R) ou vérifiez que l'URL versionnée
(`app.css?v=…`) a bien changé.

## Vérifier son travail

Tout doit passer avant de proposer une pull request. Si vous n'avez pas PHP sur
l'hôte, l'image du projet fait le travail :

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint sh indexof-prowlarr-php:latest -c '
  find src public tests -name "*.php" -print0 | xargs -0 -n1 php -l &&
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M &&
  php vendor/bin/phpunit --do-not-cache-result'

for f in public/assets/*.js; do node --check "$f"; done
```

La CI rejoue tout cela sur PHP 8.3 **et** 8.5, puis construit les images et les
scanne. PHPStan est au niveau 7 et analyse sur la plage 8.1 → 8.5 : le code doit
rester compatible 8.1.

## Attentes sur le code

- **Pas de dépendance runtime.** `composer.json` ne déclare que des outils de
  développement, et le front n'utilise aucun CDN — la CSP l'interdit
  (`default-src 'self'`, pas d'inline). Un `<style>` ou un `onclick` dans le
  HTML sera bloqué par le navigateur.
- **Rien n'est écrit dans le DOM en HTML.** Le rendu passe par `textContent` et
  `createElement` (voir le helper `el()`). Une release s'appelle parfois
  `<script>`, et ce n'est pas une hypothèse théorique.
- **Toute URL venant de l'extérieur est validée** (`safe_url`,
  `resolve_to_public_ip`). Le proxy de téléchargement est la surface la plus
  sensible du projet : n'y touchez pas sans lire les commentaires en tête de
  `download_torrent.php`.
- **Ajoutez un test** pour toute fonction de `src/functions.php` que vous créez
  ou modifiez.
- **Commentez le pourquoi, pas le quoi.** Un commentaire qui paraphrase la ligne
  suivante sera retiré ; celui qui explique pourquoi le cas limite existe reste.
- Le code, les commentaires et l'interface sont **en français**.

## Messages de commit

Une ligne de résumé à l'impératif, puis le contexte : ce qui ne marchait pas,
et pourquoi ce correctif. Les diffs se lisent tout seuls, pas les raisons.

```
Corrige le délai des recherches Prowlarr

Le délai était à 15 s. Suffisant en temps normal (1 à 5 s) mais dépassé dès
que Prowlarr retente un indexeur en erreur, ce qui faisait échouer toute la
requête en 502.
```

## Pull requests

Une PR = un sujet. Décrivez ce que vous avez vérifié, et joignez une capture
pour tout changement visible. La CI doit être verte : elle bloque sur les CVE
HIGH/CRITICAL, les secrets commités et les erreurs PHPStan.

# syntax=docker/dockerfile:1

##########################################
# Étape 1 — Application PHP (php-fpm)
##########################################
FROM php:8.3-fpm-alpine AS php

# Applique les correctifs de sécurité des paquets OS (CVE).
RUN apk upgrade --no-cache

# Configuration PHP de production + durcissement.
RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'allow_url_fopen = Off'; \
      echo 'session.use_strict_mode = 1'; \
      echo 'session.use_only_cookies = 1'; \
      echo 'session.cookie_httponly = 1'; \
      echo 'session.cookie_samesite = Lax'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"; \
    # php-fpm efface l'environnement par défaut : on le conserve pour getenv().
    printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-clear-env.conf

WORKDIR /var/www/html
COPY src/ ./src/
COPY public/ ./public/

RUN mkdir -p /tmp/indexof_cache && chown -R www-data:www-data /tmp/indexof_cache

# Tourne sans privilège root (le pool fpm n'a pas besoin de setuid).
USER www-data

##########################################
# Étape 2 — Serveur web (nginx)
##########################################
FROM nginx:1.27-alpine AS web

# Applique les correctifs de sécurité des paquets OS (CVE).
RUN apk upgrade --no-cache

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
# nginx sert les fichiers statiques et vérifie leur existence (try_files).
COPY public/ /var/www/html/public/

# Cible un asset statique (200 sans redirection vers le login) : healthcheck fiable.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -qO- http://127.0.0.1/assets/app.css >/dev/null 2>&1 || exit 1

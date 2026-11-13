# WordPress + WPML demo site with the Supertext for WPML plugin.
# Build context is the REPOSITORY ROOT (Railway: Dockerfile path `demo/Dockerfile`), so
# the image always runs the plugin as committed:
#
#   docker build -f demo/Dockerfile -t wordpress-supertext-demo .
#
# Runtime variables: DATABASE_URL (or MYSQL* parts), DEMO_ADMIN_*, DEMO_EDITOR_*,
# SUPERTEXT_API_KEY, SUPERTEXT_ACCOUNT_EMAIL, SUPERTEXT_ORDER_API_KEY, SUPERTEXT_ENVIRONMENT,
# WPML_ZIP_URL, ACF_ZIP_URL, ACFML_ZIP_URL (optional) — see docs/DEVELOPER.md → Demo site.

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip curl less mariadb-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd mysqli pdo_mysql opcache exif \
    && (a2dismod -f mpm_event mpm_worker || true) && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# WP-CLI drives the whole setup (install, users, plugins, content).
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

WORKDIR /var/www/html
COPY demo/docker/php.ini /usr/local/etc/php/conf.d/wordpress-demo.ini

# WordPress core, fixed at build time. WPML is commercial (not on wordpress.org): it is
# fetched at start only when WPML_ZIP_URL is set.
ARG WP_VERSION=latest
RUN wp core download --allow-root --version="$WP_VERSION" --locale=en_US

# The plugin from this repository (without tests, docs and deploy scripts).
COPY supertext-wpml.php LICENSE README.md wp-content/plugins/supertext-wpml/
COPY includes wp-content/plugins/supertext-wpml/includes
COPY languages wp-content/plugins/supertext-wpml/languages
COPY assets wp-content/plugins/supertext-wpml/assets

COPY demo/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/docker/wp-config-extra.php /usr/local/share/wordpress-demo/wp-config-extra.php
COPY demo/docker/setup.php /usr/local/share/wordpress-demo/setup.php
COPY demo/docker/entrypoint.sh /usr/local/bin/demo-entrypoint
# Demo only: an ACF block "Text" (acf/text), active once ACF PRO is installed (ACF_ZIP_URL).
COPY demo/docker/acf-demo-block.php wp-content/mu-plugins/acf-demo-block.php
# Only $PORT (apache.conf): the default "Listen 80" would clash when several demos share
# a host network.
# Railway's health check: a static file (WordPress would redirect / to the public URL).
RUN echo ok > /var/www/html/healthz \
    && : > /etc/apache2/ports.conf \
    && chmod +x /usr/local/bin/demo-entrypoint \
    && chown -R www-data:www-data /var/www/html

ENV PORT=8080
EXPOSE 8080
CMD ["demo-entrypoint"]

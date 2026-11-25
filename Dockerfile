FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    g++ cmake make ca-certificates \
    && docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

RUN cmake -S . -B build \
    && cmake --build build \
    && chmod +x bin/fincaisse_engine docker/entrypoint.sh

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nOptions -Indexes\nAllowOverride All\nCGIPassAuth On\nFallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-available/fincaisse.conf \
    && a2enconf fincaisse

EXPOSE 80
CMD ["/var/www/html/docker/entrypoint.sh"]

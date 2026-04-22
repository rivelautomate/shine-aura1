FROM php:8.2-apache

# Cachebust: cambiá este número cada vez que quieras forzar rebuild completo.
ARG CACHEBUST=2

# Módulos Apache y extensiones PHP.
# libsqlite3-dev es necesaria para compilar pdo_sqlite (la imagen base no la trae).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && a2enmod rewrite headers \
 && docker-php-ext-install pdo_sqlite \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Config de PHP: uploads de hasta 6 MB.
RUN { \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
      echo 'memory_limit = 128M'; \
      echo 'max_execution_time = 60'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/shineaura.ini

# Silenciar el warning de ServerName.
RUN echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data /var/www/html/uploads/products \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R u+rwX,g+rwX /var/www/html/data /var/www/html/uploads

EXPOSE 80

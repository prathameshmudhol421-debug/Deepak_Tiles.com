FROM php:8.3-apache

# Install PostgreSQL client libs + enable both MySQL and PostgreSQL PDO drivers.
# pdo_pgsql is required to connect to Supabase PostgreSQL.
# pdo_mysql is kept so local XAMPP-style development still works.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Render's reverse proxy terminates TLS — forward X-Forwarded-Proto to PHP.
ENV TRUSTED_PROXY_HEADER="HTTP_X_FORWARDED_PROTO"

# Allow .htaccess overrides + honour X-Forwarded-* headers from Render's proxy.
RUN a2enmod rewrite headers \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf

    
# Apache: parse .htaccess so router.php via index.html works (no-op here, kept for safety).
COPY . /var/www/html/

EXPOSE 80

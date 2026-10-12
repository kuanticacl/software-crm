#!/bin/sh
set -e
cd /var/www/html

echo "▶ CRM INTEGRALEADS · iniciando…"

if [ -z "${APP_KEY}" ]; then
    echo "✖ Falta APP_KEY (defínela en las variables de entorno de Dokploy)."
    exit 1
fi

# Directorios de escritura (el volumen puede llegar vacío en el primer despliegue).
mkdir -p storage/app/public storage/app/dompdf storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Esperar a la base de datos (MariaDB del mismo stack).
echo "⏳ Esperando la base de datos en ${DB_HOST}:${DB_PORT:-3306}…"
tries=0
until php -r '
try {
    new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
} catch (Throwable $e) { exit(1); }
'; do
    tries=$((tries + 1))
    if [ "$tries" -ge 90 ]; then echo "✖ La base de datos no respondió a tiempo."; exit 1; fi
    sleep 2
done
echo "✔ Base de datos lista"

php artisan config:clear >/dev/null 2>&1 || true

# Migraciones y datos iniciales (solo la primera vez: roles, etapas, orígenes, servicios y administrador).
php artisan migrate --force
php artisan crm:install
php artisan crm:landing-kit || true

# Datos de muestra (un cliente + propuestas por origen): activar con SEED_DEMO=true; solo se cargan una vez.
if [ "${SEED_DEMO:-false}" = "true" ]; then
    php artisan crm:seed-demo
fi

# Enlace público de storage y cachés de producción.
[ -L public/storage ] || php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan event:cache || true
php artisan view:cache || true

chown -R www-data:www-data storage bootstrap/cache

echo "✔ Listo. Arrancando servicios (nginx, php-fpm, cola y scheduler)…"
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

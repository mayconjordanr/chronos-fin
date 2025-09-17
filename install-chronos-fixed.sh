#!/bin/bash

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

# Config
APP_DIR="/var/www/chronos-fin"
DOMAIN="chronos.ia.br"
DB_NAME="chronos_fin_main"
DB_USER="chronos_user"
DB_PASS="ChronosFin2024!"

print_status "Instalando MySQL..."
apt install -y mysql-server

print_status "Configurando MySQL..."
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "GRANT CREATE ON *.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

print_status "Instalando Nginx..."
apt install -y nginx

print_status "Configurando Nginx..."
cat > /etc/nginx/sites-available/chronos-fin << 'NGINXEOF'
server {
    listen 80;
    server_name chronos.ia.br app.chronos.ia.br *.chronos.ia.br;

    root /var/www/chronos-fin/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINXEOF

ln -sf /etc/nginx/sites-available/chronos-fin /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

print_status "Instalando Node.js..."
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

print_status "Instalando Composer..."
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

print_status "Clonando CHRONOS Fin..."
if [[ -d "$APP_DIR" ]]; then
    rm -rf "$APP_DIR"
fi

git clone https://github.com/mayconjordanr/chronos-fin.git "$APP_DIR"
cd "$APP_DIR"

chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
chmod -R 775 storage bootstrap/cache

print_status "Configurando aplicação..."
sudo -u www-data composer install --no-dev --optimize-autoloader

if [[ -f package.json ]]; then
    sudo -u www-data npm install
    sudo -u www-data npm run build
fi

if [[ -f .env.chronos ]]; then
    sudo -u www-data cp .env.chronos .env
elif [[ -f .env.local ]]; then
    sudo -u www-data cp .env.local .env
else
    sudo -u www-data cp .env.example .env
fi

sed -i "s|APP_URL=.*|APP_URL=https://app.$DOMAIN|g" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|g" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|g" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|g" .env

sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan config:cache

print_status "Reiniciando serviços..."
systemctl restart nginx
systemctl restart php8.1-fpm
systemctl restart mysql

print_success "=== CHRONOS Fin instalado com sucesso! ==="
echo ""
echo "🌐 Site: http://chronos.ia.br"
echo "💻 App: http://app.chronos.ia.br"
echo "🔐 DB User: $DB_USER"
echo "🔑 DB Pass: $DB_PASS"
echo ""
echo "Acesse http://91.99.23.32 para testar!"
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

print_status "Atualizando sistema..."
apt update && apt upgrade -y

print_status "Instalando PHP 8.2..."
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-xml php8.2-curl php8.2-gd php8.2-mbstring php8.2-zip php8.2-intl php8.2-bcmath php8.2-redis php8.2-imagick php8.2-tokenizer

# Configurar PHP 8.2 como padrão
update-alternatives --install /usr/bin/php php /usr/bin/php8.2 82
update-alternatives --set php /usr/bin/php8.2

print_status "Instalando MySQL..."
apt install -y mysql-server

print_status "Configurando MySQL..."
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || true
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';" || true
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" || true
mysql -e "GRANT CREATE ON *.* TO '$DB_USER'@'localhost';" || true
mysql -e "FLUSH PRIVILEGES;" || true

print_status "Instalando Nginx..."
apt install -y nginx

print_status "Configurando Nginx..."
cat > /etc/nginx/sites-available/chronos-fin << 'NGINXEOF'
server {
    listen 80;
    server_name chronos.ia.br app.chronos.ia.br *.chronos.ia.br 91.99.23.32;

    root /var/www/chronos-fin/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
NGINXEOF

ln -sf /etc/nginx/sites-available/chronos-fin /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

print_status "Testando configuração Nginx..."
nginx -t

print_status "Instalando Node.js..."
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

print_status "Instalando Composer..."
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

print_status "Removendo instalação anterior (se existir)..."
rm -rf "$APP_DIR"

print_status "Clonando CHRONOS Fin..."
git clone https://github.com/mayconjordanr/chronos-fin.git "$APP_DIR"
cd "$APP_DIR"

print_status "Configurando permissões..."
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
chmod -R 775 storage bootstrap/cache

print_status "Configurando aplicação..."
sudo -u www-data composer install --no-dev --optimize-autoloader

if [[ -f package.json ]]; then
    print_status "Instalando dependências JavaScript..."
    sudo -u www-data npm install
    sudo -u www-data npm run build
fi

print_status "Configurando ambiente..."
if [[ -f .env.chronos ]]; then
    sudo -u www-data cp .env.chronos .env
elif [[ -f .env.local ]]; then
    sudo -u www-data cp .env.local .env
else
    sudo -u www-data cp .env.example .env
fi

sed -i "s|APP_URL=.*|APP_URL=http://91.99.23.32|g" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|g" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|g" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|g" .env
sed -i "s|CHRONOS_MAIN_DOMAIN=.*|CHRONOS_MAIN_DOMAIN=$DOMAIN|g" .env
sed -i "s|CHRONOS_APP_DOMAIN=.*|CHRONOS_APP_DOMAIN=app.$DOMAIN|g" .env

print_status "Gerando chave da aplicação..."
sudo -u www-data php artisan key:generate

print_status "Executando migrações..."
sudo -u www-data php artisan migrate --force

print_status "Criando link simbólico para storage..."
sudo -u www-data php artisan storage:link

print_status "Otimizando aplicação..."
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

print_status "Reiniciando serviços..."
systemctl enable nginx php8.2-fpm mysql
systemctl restart nginx
systemctl restart php8.2-fpm
systemctl restart mysql

print_success "=== CHRONOS Fin instalado com sucesso! ==="
echo ""
echo "🌐 Site: http://91.99.23.32"
echo "💻 App: http://app.chronos.ia.br"
echo "🔐 DB User: $DB_USER"
echo "🔑 DB Pass: $DB_PASS"
echo ""
echo "✅ PHP Version: $(php --version | head -1)"
echo "✅ Nginx Status: $(systemctl is-active nginx)"
echo "✅ MySQL Status: $(systemctl is-active mysql)"
echo ""
echo "🚀 Acesse http://91.99.23.32 para testar!"
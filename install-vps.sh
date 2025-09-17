#!/bin/bash

#########################################
# CHRONOS Fin - Instalação Automática VPS
# Compatible with Ubuntu 20.04+ / Debian 10+
#########################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
APP_NAME="CHRONOS Fin"
APP_DIR="/var/www/chronos-fin"
DOMAIN=""
EMAIL=""
DB_NAME="chronos_fin_main"
DB_USER="chronos_user"
DB_PASS=""

# Functions
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "Este script deve ser executado como root (use sudo)"
        exit 1
    fi
}

get_user_input() {
    echo -e "${BLUE}=== CONFIGURAÇÃO DO CHRONOS FIN ===${NC}"
    echo ""

    read -p "Digite seu domínio (ex: chronos.ia.br): " DOMAIN
    if [[ -z "$DOMAIN" ]]; then
        print_error "Domínio é obrigatório!"
        exit 1
    fi

    read -p "Digite seu email para SSL e notificações: " EMAIL
    if [[ -z "$EMAIL" ]]; then
        print_error "Email é obrigatório!"
        exit 1
    fi

    read -s -p "Digite uma senha para o banco de dados MySQL: " DB_PASS
    echo ""
    if [[ -z "$DB_PASS" ]]; then
        print_error "Senha do banco é obrigatória!"
        exit 1
    fi

    echo ""
    print_status "Configuração recebida:"
    echo "  Domínio: $DOMAIN"
    echo "  Email: $EMAIL"
    echo "  Diretório: $APP_DIR"
    echo ""

    read -p "Confirma a instalação? (y/N): " confirm
    if [[ ! $confirm =~ ^[Yy]$ ]]; then
        print_warning "Instalação cancelada."
        exit 0
    fi
}

update_system() {
    print_status "Atualizando sistema..."
    apt update && apt upgrade -y
    apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates
}

install_php() {
    print_status "Instalando PHP 8.1..."
    add-apt-repository ppa:ondrej/php -y
    apt update
    apt install -y php8.1 php8.1-fpm php8.1-mysql php8.1-xml php8.1-curl \
        php8.1-gd php8.1-mbstring php8.1-zip php8.1-intl php8.1-bcmath \
        php8.1-json php8.1-redis php8.1-imagick php8.1-tokenizer
}

install_mysql() {
    print_status "Instalando MySQL..."
    apt install -y mysql-server

    print_status "Configurando MySQL..."
    mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
    mysql -e "GRANT CREATE ON *.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
}

install_nginx() {
    print_status "Instalando Nginx..."
    apt install -y nginx

    print_status "Configurando Nginx para $DOMAIN..."
    cat > /etc/nginx/sites-available/chronos-fin << EOF
server {
    listen 80;
    server_name $DOMAIN app.$DOMAIN *.$DOMAIN;
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name $DOMAIN app.$DOMAIN *.$DOMAIN;

    root $APP_DIR/public;
    index index.php index.html;

    # SSL Configuration (will be configured by Certbot)
    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    # Gzip Compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Rate Limiting para API
    location /api/ {
        limit_req zone=api burst=10 nodelay;
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
}

# Rate Limiting Zones
limit_req_zone \$binary_remote_addr zone=api:10m rate=60r/m;
EOF

    ln -sf /etc/nginx/sites-available/chronos-fin /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
}

install_redis() {
    print_status "Instalando Redis..."
    apt install -y redis-server
    systemctl enable redis-server
    systemctl start redis-server
}

install_nodejs() {
    print_status "Instalando Node.js 18..."
    curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
    apt install -y nodejs
}

install_composer() {
    print_status "Instalando Composer..."
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
}

clone_application() {
    print_status "Clonando aplicação CHRONOS Fin..."

    if [[ -d "$APP_DIR" ]]; then
        print_warning "Diretório $APP_DIR já existe. Removendo..."
        rm -rf "$APP_DIR"
    fi

    # Clone do repositório
    git clone https://github.com/mayconjordanr/chronos-fin.git "$APP_DIR"

    cd "$APP_DIR"

    # Definir permissões
    chown -R www-data:www-data "$APP_DIR"
    chmod -R 755 "$APP_DIR"
    chmod -R 775 "$APP_DIR/storage"
    chmod -R 775 "$APP_DIR/bootstrap/cache"
}

setup_application() {
    print_status "Configurando aplicação..."

    cd "$APP_DIR"

    # Instalar dependências PHP
    sudo -u www-data composer install --no-dev --optimize-autoloader

    # Instalar dependências Node.js
    sudo -u www-data npm install

    # Compilar assets
    sudo -u www-data npm run build

    # Configurar ambiente
    sudo -u www-data cp .env.chronos .env

    # Configurar variáveis de ambiente
    sed -i "s|APP_URL=.*|APP_URL=https://app.$DOMAIN|g" .env
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|g" .env
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|g" .env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|g" .env
    sed -i "s|MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=noreply@$DOMAIN|g" .env
    sed -i "s|CHRONOS_MAIN_DOMAIN=.*|CHRONOS_MAIN_DOMAIN=$DOMAIN|g" .env
    sed -i "s|CHRONOS_APP_DOMAIN=.*|CHRONOS_APP_DOMAIN=app.$DOMAIN|g" .env

    # Gerar chave da aplicação
    sudo -u www-data php artisan key:generate

    # Executar migrações
    sudo -u www-data php artisan migrate --force

    # Criar link simbólico para storage
    sudo -u www-data php artisan storage:link

    # Otimizar aplicação
    sudo -u www-data php artisan config:cache
    sudo -u www-data php artisan route:cache
    sudo -u www-data php artisan view:cache
}

install_ssl() {
    print_status "Instalando certificado SSL..."
    apt install -y certbot python3-certbot-nginx

    # Reiniciar nginx antes do SSL
    systemctl restart nginx

    # Aguardar nginx iniciar
    sleep 5

    # Obter certificado SSL (sem wildcard pois requer DNS challenge)
    certbot --nginx -d "$DOMAIN" -d "app.$DOMAIN" --email "$EMAIL" --agree-tos --non-interactive

    # Configurar renovação automática
    echo "0 12 * * * /usr/bin/certbot renew --quiet" | crontab -
}

setup_supervisor() {
    print_status "Configurando Supervisor para filas..."
    apt install -y supervisor

    cat > /etc/supervisor/conf.d/chronos-fin-worker.conf << EOF
[program:chronos-fin-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/worker.log
stopwaitsecs=3600
EOF

    supervisorctl reread
    supervisorctl update
    supervisorctl start chronos-fin-worker:*
}

setup_firewall() {
    print_status "Configurando firewall..."
    ufw --force enable
    ufw default deny incoming
    ufw default allow outgoing
    ufw allow ssh
    ufw allow 'Nginx Full'
}

restart_services() {
    print_status "Reiniciando serviços..."
    systemctl restart nginx
    systemctl restart php8.1-fpm
    systemctl restart mysql
    systemctl restart redis-server
}

create_backup_script() {
    print_status "Criando script de backup..."

    mkdir -p /backup/chronos-fin

    cat > /usr/local/bin/chronos-backup.sh << 'EOF'
#!/bin/bash
BACKUP_DIR="/backup/chronos-fin"
DATE=$(date +%Y%m%d_%H%M%S)
APP_DIR="/var/www/chronos-fin"

mkdir -p $BACKUP_DIR

# Backup dos arquivos da aplicação
tar -czf $BACKUP_DIR/app_$DATE.tar.gz -C /var/www chronos-fin

# Backup do banco principal
mysqldump -u chronos_user -p$DB_PASS chronos_fin_main > $BACKUP_DIR/main_db_$DATE.sql

# Backup dos bancos de tenants
mysql -u chronos_user -p$DB_PASS -e "SHOW DATABASES LIKE 'chronos_%';" | grep -v Database | while read dbname; do
    if [ "$dbname" != "chronos_fin_main" ]; then
        mysqldump -u chronos_user -p$DB_PASS $dbname > $BACKUP_DIR/${dbname}_$DATE.sql
    fi
done

# Manter apenas últimos 7 dias de backup
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete
find $BACKUP_DIR -name "*.sql" -mtime +7 -delete

echo "Backup concluído: $DATE"
EOF

    chmod +x /usr/local/bin/chronos-backup.sh

    # Agendar backup diário às 2h
    echo "0 2 * * * /usr/local/bin/chronos-backup.sh >> /var/log/chronos-backup.log 2>&1" | crontab -
}

show_completion_info() {
    print_success "=== INSTALAÇÃO CONCLUÍDA COM SUCESSO! ==="
    echo ""
    echo -e "${GREEN}🎉 CHRONOS Fin instalado com sucesso!${NC}"
    echo ""
    echo "📋 INFORMAÇÕES DO SISTEMA:"
    echo "  🌐 Site Principal: https://$DOMAIN"
    echo "  💻 Aplicação: https://app.$DOMAIN"
    echo "  📊 Dashboard: https://app.$DOMAIN/login"
    echo "  📝 Cadastro: https://app.$DOMAIN/register"
    echo ""
    echo "🔧 INFORMAÇÕES TÉCNICAS:"
    echo "  📁 Diretório: $APP_DIR"
    echo "  🗄️  Banco de Dados: $DB_NAME"
    echo "  👤 Usuário DB: $DB_USER"
    echo "  🔐 Logs: $APP_DIR/storage/logs/"
    echo ""
    echo "🔗 ENDPOINTS DA API:"
    echo "  ⚡ Health: https://app.$DOMAIN/api/v1/chronos/public/health"
    echo "  📱 WhatsApp: https://app.$DOMAIN/api/v1/chronos/public/webhook/whatsapp"
    echo ""
    echo "⚙️  COMANDOS ÚTEIS:"
    echo "  📊 Ver logs: tail -f $APP_DIR/storage/logs/laravel.log"
    echo "  🔄 Restart: systemctl restart nginx php8.1-fpm"
    echo "  💾 Backup manual: /usr/local/bin/chronos-backup.sh"
    echo ""
    echo -e "${YELLOW}⚠️  PRÓXIMOS PASSOS:${NC}"
    echo "1. Configure seu DNS para apontar para este servidor"
    echo "2. Acesse https://app.$DOMAIN para criar sua primeira conta"
    echo "3. Configure a integração WhatsApp com a URL do webhook"
    echo "4. Monitore os logs em caso de problemas"
    echo ""
    print_success "CHRONOS Fin está pronto para uso! 🚀"
}

# Main execution
main() {
    clear
    echo -e "${BLUE}"
    echo "  ██████╗██╗  ██╗██████╗  ██████╗ ███╗   ██╗ ██████╗ ███████╗    ███████╗██╗███╗   ██╗"
    echo " ██╔════╝██║  ██║██╔══██╗██╔═══██╗████╗  ██║██╔═══██╗██╔════╝    ██╔════╝██║████╗  ██║"
    echo " ██║     ███████║██████╔╝██║   ██║██╔██╗ ██║██║   ██║███████╗    █████╗  ██║██╔██╗ ██║"
    echo " ██║     ██╔══██║██╔══██╗██║   ██║██║╚██╗██║██║   ██║╚════██║    ██╔══╝  ██║██║╚██╗██║"
    echo " ╚██████╗██║  ██║██║  ██║╚██████╔╝██║ ╚████║╚██████╔╝███████║    ██║     ██║██║ ╚████║"
    echo "  ╚═════╝╚═╝  ╚═╝╚═╝  ╚═╝ ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝ ╚══════╝    ╚═╝     ╚═╝╚═╝  ╚═══╝"
    echo -e "${NC}"
    echo ""
    echo -e "${GREEN}Instalador Automático VPS - v1.0${NC}"
    echo ""

    check_root
    get_user_input

    print_status "Iniciando instalação do CHRONOS Fin..."
    sleep 2

    update_system
    install_php
    install_mysql
    install_nginx
    install_redis
    install_nodejs
    install_composer
    clone_application
    setup_application
    install_ssl
    setup_supervisor
    setup_firewall
    restart_services
    create_backup_script

    show_completion_info
}

# Execute main function
main "$@"
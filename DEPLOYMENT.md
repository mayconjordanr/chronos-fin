# CHRONOS Fin - Guia de Deployment

## Visão Geral

O CHRONOS Fin é um sistema de controle financeiro multi-tenant baseado no Firefly III, com interface futurista e integração com WhatsApp para entrada de dados via assistente pessoal.

## Pré-requisitos

### Servidor
- **SO**: Ubuntu 20.04 LTS ou superior
- **CPU**: Mínimo 2 cores, recomendado 4 cores
- **RAM**: Mínimo 4GB, recomendado 8GB
- **Armazenamento**: Mínimo 50GB SSD
- **Largura de banda**: Conexão estável com internet

### Software
- **PHP**: 8.1 ou superior
- **MySQL**: 8.0 ou superior
- **Nginx**: 1.18 ou superior
- **Node.js**: 18 LTS ou superior
- **Composer**: 2.x
- **Redis**: 6.x (para cache e sessões)

## Instalação Passo a Passo

### 1. Preparação do Servidor

```bash
# Atualizar sistema
sudo apt update && sudo apt upgrade -y

# Instalar dependências básicas
sudo apt install -y curl git unzip software-properties-common

# Instalar PHP 8.1
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.1 php8.1-fpm php8.1-mysql php8.1-xml php8.1-curl \
    php8.1-gd php8.1-mbstring php8.1-zip php8.1-intl php8.1-bcmath \
    php8.1-json php8.1-redis php8.1-imagick

# Instalar MySQL
sudo apt install -y mysql-server

# Instalar Nginx
sudo apt install -y nginx

# Instalar Redis
sudo apt install -y redis-server

# Instalar Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Instalar Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. Configuração do MySQL

```bash
# Configurar MySQL
sudo mysql_secure_installation

# Criar usuário e banco principal
sudo mysql -u root -p
```

```sql
CREATE DATABASE chronos_fin_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chronos_user'@'localhost' IDENTIFIED BY 'sua_senha_segura';
GRANT ALL PRIVILEGES ON chronos_fin_main.* TO 'chronos_user'@'localhost';
GRANT CREATE ON *.* TO 'chronos_user'@'localhost'; -- Para criar bancos de tenants
FLUSH PRIVILEGES;
EXIT;
```

### 3. Deployment da Aplicação

```bash
# Criar diretório da aplicação
sudo mkdir -p /var/www/chronos-fin
cd /var/www/chronos-fin

# Clonar o repositório
sudo git clone [URL_DO_SEU_REPO] .

# Definir permissões
sudo chown -R www-data:www-data /var/www/chronos-fin
sudo chmod -R 755 /var/www/chronos-fin
sudo chmod -R 775 /var/www/chronos-fin/storage
sudo chmod -R 775 /var/www/chronos-fin/bootstrap/cache

# Instalar dependências PHP
sudo -u www-data composer install --no-dev --optimize-autoloader

# Instalar dependências Node.js
sudo -u www-data npm install

# Compilar assets
sudo -u www-data npm run build
```

### 4. Configuração da Aplicação

```bash
# Copiar arquivo de configuração
sudo -u www-data cp .env.example .env
sudo -u www-data nano .env
```

Configurar o arquivo `.env`:

```env
# Aplicação
APP_NAME="CHRONOS Fin"
APP_ENV=production
APP_KEY=base64:SUA_CHAVE_AQUI
APP_DEBUG=false
APP_URL=https://app.chronos.ia.br

# Database Principal
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chronos_fin_main
DB_USERNAME=chronos_user
DB_PASSWORD=sua_senha_segura

# Cache e Sessões
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail (para notificações)
MAIL_MAILER=smtp
MAIL_HOST=seu.smtp.com
MAIL_PORT=587
MAIL_USERNAME=seu_email
MAIL_PASSWORD=sua_senha
MAIL_ENCRYPTION=tls

# Configurações CHRONOS Fin específicas
CHRONOS_WHATSAPP_WEBHOOK_TOKEN=seu_token_seguro
CHRONOS_DEFAULT_TIMEZONE=America/Sao_Paulo
CHRONOS_DEFAULT_CURRENCY=BRL
CHRONOS_TRIAL_DAYS=30

# SSL/TLS
APP_FORCE_HTTPS=true
```

```bash
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
```

### 5. Configuração do Nginx

```bash
sudo nano /etc/nginx/sites-available/chronos-fin
```

```nginx
# Configuração principal - chronos.ia.br
server {
    listen 80;
    server_name chronos.ia.br app.chronos.ia.br *.chronos.ia.br;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name chronos.ia.br app.chronos.ia.br *.chronos.ia.br;

    root /var/www/chronos-fin/public;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate /etc/ssl/certs/chronos.ia.br.crt;
    ssl_certificate_key /etc/ssl/private/chronos.ia.br.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;

    # Gzip Compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
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
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Proteção especial para webhooks CHRONOS
    location /api/v1/chronos/public/ {
        limit_req zone=webhook burst=5 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }
}

# Rate Limiting Zones
http {
    limit_req_zone $binary_remote_addr zone=api:10m rate=60r/m;
    limit_req_zone $binary_remote_addr zone=webhook:10m rate=10r/m;
}
```

```bash
# Ativar site
sudo ln -s /etc/nginx/sites-available/chronos-fin /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6. Configuração SSL com Let's Encrypt

```bash
# Instalar Certbot
sudo apt install -y certbot python3-certbot-nginx

# Obter certificado SSL
sudo certbot --nginx -d chronos.ia.br -d app.chronos.ia.br -d *.chronos.ia.br

# Configurar renovação automática
sudo crontab -e
# Adicionar linha:
0 12 * * * /usr/bin/certbot renew --quiet
```

### 7. Configuração de Processos em Background

```bash
# Configurar supervisor para filas
sudo apt install -y supervisor

sudo nano /etc/supervisor/conf.d/chronos-fin-worker.conf
```

```ini
[program:chronos-fin-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/chronos-fin/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/chronos-fin/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chronos-fin-worker:*
```

### 8. Configuração de Backups

```bash
# Criar script de backup
sudo nano /usr/local/bin/chronos-backup.sh
```

```bash
#!/bin/bash

BACKUP_DIR="/backup/chronos-fin"
DATE=$(date +%Y%m%d_%H%M%S)
APP_DIR="/var/www/chronos-fin"

# Criar diretório de backup
mkdir -p $BACKUP_DIR

# Backup dos arquivos da aplicação
tar -czf $BACKUP_DIR/app_$DATE.tar.gz -C /var/www chronos-fin

# Backup do banco principal
mysqldump -u chronos_user -p chronos_fin_main > $BACKUP_DIR/main_db_$DATE.sql

# Backup dos bancos de tenants
mysql -u chronos_user -p -e "SHOW DATABASES LIKE 'chronos_%';" | grep -v Database | while read dbname; do
    if [ "$dbname" != "chronos_fin_main" ]; then
        mysqldump -u chronos_user -p $dbname > $BACKUP_DIR/${dbname}_$DATE.sql
    fi
done

# Manter apenas últimos 7 dias de backup
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete
find $BACKUP_DIR -name "*.sql" -mtime +7 -delete

echo "Backup concluído: $DATE"
```

```bash
sudo chmod +x /usr/local/bin/chronos-backup.sh

# Agendar backup diário
sudo crontab -e
# Adicionar linha:
0 2 * * * /usr/local/bin/chronos-backup.sh >> /var/log/chronos-backup.log 2>&1
```

## Configuração de Domínios Multi-Tenant

### Wildcard DNS
Configure no seu provedor DNS:
- `A chronos.ia.br` → IP do servidor
- `A app.chronos.ia.br` → IP do servidor
- `A *.chronos.ia.br` → IP do servidor

### Configuração de Tenants

```bash
# Comando para criar novo tenant
sudo -u www-data php artisan chronos:create-tenant \
    --name="Empresa XYZ" \
    --domain="empresa-xyz" \
    --plan="pro" \
    --email="admin@empresa-xyz.com"
```

## Monitoramento e Logs

### Configuração de Logs

```bash
# Rotação de logs
sudo nano /etc/logrotate.d/chronos-fin
```

```
/var/www/chronos-fin/storage/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        /usr/bin/supervisorctl restart chronos-fin-worker:*
    endscript
}
```

### Monitoramento de Performance

```bash
# Instalar htop para monitoramento
sudo apt install -y htop

# Configurar alertas de espaço em disco
echo '
if [ $(df / | tail -1 | awk "{print $5}" | sed "s/%//") -gt 80 ]; then
    echo "Alerta: Disco com mais de 80% de uso" | mail -s "CHRONOS Fin - Alerta de Disco" admin@chronos.ia.br
fi
' | sudo tee /usr/local/bin/check-disk.sh

sudo chmod +x /usr/local/bin/check-disk.sh

# Agendar verificação
sudo crontab -e
# Adicionar linha:
0 */6 * * * /usr/local/bin/check-disk.sh
```

## Segurança

### Firewall

```bash
# Configurar UFW
sudo ufw enable
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 'Nginx Full'
sudo ufw status
```

### Hardening adicional

```bash
# Desabilitar informações do servidor
echo 'server_tokens off;' | sudo tee -a /etc/nginx/nginx.conf

# Configurar fail2ban
sudo apt install -y fail2ban

sudo nano /etc/fail2ban/jail.local
```

```ini
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[nginx-http-auth]
enabled = true

[nginx-noscript]
enabled = true

[nginx-badbots]
enabled = true

[nginx-noproxy]
enabled = true
```

```bash
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

## Integração WhatsApp

### Configuração do Webhook

1. Configure o webhook no seu provedor de WhatsApp Business API
2. URL do webhook: `https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp`
3. Token de verificação: usar valor do `CHRONOS_WHATSAPP_WEBHOOK_TOKEN`

### Teste da Integração

```bash
# Testar endpoint de saúde
curl https://app.chronos.ia.br/api/v1/chronos/public/health

# Testar processamento de mensagem (substituir token)
curl -X POST https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Comprei pão por R$ 5,50 no débito",
    "user_id": 1,
    "phone": "+5511999999999"
  }'
```

## Manutenção

### Comandos Úteis

```bash
# Verificar status dos serviços
sudo systemctl status nginx php8.1-fpm mysql redis-server

# Verificar logs em tempo real
sudo tail -f /var/www/chronos-fin/storage/logs/laravel.log

# Limpar cache
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan config:clear

# Verificar filas
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan queue:retry all

# Atualizar aplicação
cd /var/www/chronos-fin
sudo git pull origin main
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo systemctl reload nginx
```

### Solução de Problemas Comuns

1. **Erro 500**: Verificar logs do Laravel e Nginx
2. **Problema de permissões**: Redefenir permissões do storage e cache
3. **Tenant não encontrado**: Verificar DNS e configuração de domínio
4. **WhatsApp não funciona**: Verificar configuração do webhook e token

## Performance e Otimização

### Otimizações Recomendadas

1. **OpCache PHP**: Ativar e configurar opcache
2. **Redis**: Usar para cache, sessões e filas
3. **CDN**: Configurar CloudFlare ou similar
4. **Compressão**: Ativar gzip/brotli no Nginx
5. **Otimização de imagens**: Usar WebP quando possível

### Escalabilidade

Para crescimento futuro:
- Load balancer com múltiplos servidores
- Banco de dados em cluster
- Separação de serviços (API, Web, Workers)
- Kubernetes para orquestração de containers

## Suporte

Para suporte técnico:
- **Email**: suporte@chronos.ia.br
- **Documentação**: https://docs.chronos.ia.br
- **Status**: https://status.chronos.ia.br
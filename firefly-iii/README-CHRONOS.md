# CHRONOS Fin - Sistema de Controle Financeiro Inteligente

![CHRONOS Fin](https://img.shields.io/badge/CHRONOS-Fin-orange?style=for-the-badge&logo=bolt)

## 🚀 Sobre o CHRONOS Fin

O **CHRONOS Fin** é um sistema de controle financeiro multi-tenant baseado no Firefly III, com interface futurista e integração com WhatsApp para entrada de dados via assistente pessoal.

### ✨ Principais Características

- **🎨 Interface Futurista**: Design moderno com tema laranja (#f54e1a)
- **🌙 Modo Escuro/Claro**: Alternância automática entre temas
- **📱 Totalmente Responsivo**: Funciona perfeitamente em desktop e mobile
- **🏢 Multi-Tenant**: Separação completa de dados por cliente
- **💬 Integração WhatsApp**: Adicione transações via mensagens
- **📊 Dashboard Inteligente**: Métricas em tempo real
- **🔐 Sistema de Planos**: Básico, Pro e Enterprise

## 🌐 Domínios e URLs

### Produção
- **Site Principal**: https://chronos.ia.br
- **Aplicação**: https://app.chronos.ia.br
- **Clientes**: `https://[cliente].chronos.ia.br`

### Endpoints Importantes
```
# Dashboard
GET https://app.chronos.ia.br

# Cadastro
GET https://app.chronos.ia.br/register

# API WhatsApp (público)
POST https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp

# Health Check
GET https://app.chronos.ia.br/api/v1/chronos/public/health

# API Autenticada
POST https://app.chronos.ia.br/api/v1/chronos/whatsapp
GET https://app.chronos.ia.br/api/v1/chronos/summary/{userId}
```

## 📦 Instalação Rápida

### 1. Pré-requisitos
```bash
# Ubuntu 20.04+
sudo apt update && sudo apt upgrade -y

# Instalar dependências
sudo apt install -y php8.1 php8.1-fpm mysql-server nginx redis-server nodejs npm composer
```

### 2. Configurar Projeto
```bash
# Clonar repositório
git clone [URL_DO_REPO] /var/www/chronos-fin
cd /var/www/chronos-fin

# Configurar permissões
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage bootstrap/cache

# Instalar dependências
composer install --no-dev --optimize-autoloader
npm install && npm run build

# Configurar ambiente
cp .env.chronos .env
php artisan key:generate
```

### 3. Configurar Banco de Dados
```sql
CREATE DATABASE chronos_fin_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chronos_user'@'localhost' IDENTIFIED BY 'senha_segura';
GRANT ALL PRIVILEGES ON chronos_fin_main.* TO 'chronos_user'@'localhost';
GRANT CREATE ON *.* TO 'chronos_user'@'localhost';
FLUSH PRIVILEGES;
```

```bash
# Executar migrações
php artisan migrate --force
```

### 4. Configurar Nginx
```nginx
server {
    listen 443 ssl http2;
    server_name chronos.ia.br app.chronos.ia.br *.chronos.ia.br;
    root /var/www/chronos-fin/public;

    # SSL
    ssl_certificate /etc/ssl/certs/chronos.ia.br.crt;
    ssl_certificate_key /etc/ssl/private/chronos.ia.br.key;

    # PHP
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

## 🔧 Configuração Multi-Tenant

### DNS Configuration
Configure os seguintes registros DNS:

```
A     chronos.ia.br        → IP_DO_SERVIDOR
A     app.chronos.ia.br    → IP_DO_SERVIDOR
A     *.chronos.ia.br      → IP_DO_SERVIDOR
```

### Criar Novo Tenant
```bash
# Via command line
php artisan chronos:create-tenant \
    --name="Empresa XYZ" \
    --domain="empresa-xyz" \
    --plan="pro" \
    --email="admin@empresa-xyz.com"

# Via API
curl -X POST https://app.chronos.ia.br/api/v1/chronos/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Empresa XYZ",
    "subdomain": "empresa-xyz",
    "plan": "pro"
  }'
```

## 📱 Integração WhatsApp

### Configuração do Webhook
1. Configure no seu provedor WhatsApp Business API
2. URL: `https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp`
3. Token: Configurar em `CHRONOS_WHATSAPP_WEBHOOK_TOKEN`

### Exemplos de Mensagens
```
"Comprei pão por R$ 5,50 no débito"
→ Cria despesa de R$ 5,50 categoria "Alimentação"

"Recebi 5000 reais de salário"
→ Cria receita de R$ 5.000,00 categoria "Receita"

"Transferi 200 reais para poupança"
→ Cria transferência de R$ 200,00
```

### Testar Integração
```bash
# Health check
curl https://app.chronos.ia.br/api/v1/chronos/public/health

# Simular mensagem WhatsApp
curl -X POST https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Comprei café por R$ 4,50 no débito",
    "user_id": 1,
    "phone": "+5511999999999"
  }'
```

## 💰 Planos e Recursos

### 🆓 Básico (Grátis)
- 1 usuário
- 100 transações/mês
- Integração WhatsApp
- Dashboard básico
- 30 dias trial

### 💎 Pro (R$ 29/mês)
- 5 usuários
- 1.000 transações/mês
- Relatórios avançados
- Categorias customizadas
- API completa
- Gráficos avançados

### 🏢 Enterprise (R$ 99/mês)
- Usuários ilimitados
- Transações ilimitadas
- Suporte prioritário
- Branding customizado
- Webhooks personalizados
- Backup automático

## 🔒 Segurança

### Recursos Implementados
- ✅ SSL/TLS obrigatório
- ✅ Rate limiting nas APIs
- ✅ Isolamento de dados por tenant
- ✅ Autenticação robusta
- ✅ Validação de entrada
- ✅ Headers de segurança
- ✅ Proteção CSRF
- ✅ Sanitização de dados

### Configurações Recomendadas
```bash
# Firewall
sudo ufw enable
sudo ufw allow ssh
sudo ufw allow 'Nginx Full'

# Fail2ban
sudo apt install fail2ban
sudo systemctl enable fail2ban
```

## 📈 Monitoramento

### Logs Importantes
```bash
# Logs da aplicação
tail -f /var/www/chronos-fin/storage/logs/laravel.log

# Logs do Nginx
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# Logs do MySQL
tail -f /var/log/mysql/error.log
```

### Métricas de Performance
- Tempo de resposta < 200ms
- Uptime > 99.9%
- Uso de CPU < 70%
- Uso de RAM < 80%
- Espaço em disco < 80%

## 🔄 Backup e Restore

### Backup Automático
```bash
#!/bin/bash
# Script executado diariamente às 2h

# Backup aplicação
tar -czf /backup/app_$(date +%Y%m%d).tar.gz -C /var/www chronos-fin

# Backup banco principal
mysqldump chronos_fin_main > /backup/main_$(date +%Y%m%d).sql

# Backup tenants
mysql -e "SHOW DATABASES LIKE 'chronos_%';" | while read db; do
    mysqldump $db > /backup/${db}_$(date +%Y%m%d).sql
done
```

### Restore
```bash
# Restaurar aplicação
tar -xzf /backup/app_20241201.tar.gz -C /var/www/

# Restaurar banco
mysql chronos_fin_main < /backup/main_20241201.sql
```

## 🚀 Deploy e Atualizações

### Deploy Inicial
```bash
# 1. Preparar servidor
curl -sSL https://raw.githubusercontent.com/chronos-fin/deploy/main/setup.sh | bash

# 2. Deploy aplicação
git clone [REPO] /var/www/chronos-fin
cd /var/www/chronos-fin
./deploy.sh
```

### Atualizações
```bash
# Deploy de nova versão
cd /var/www/chronos-fin
git pull origin main
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
php artisan config:cache
sudo systemctl reload nginx
```

## 🛠️ Desenvolvimento

### Setup Local
```bash
# Clonar e configurar
git clone [REPO] chronos-fin
cd chronos-fin
cp .env.example .env
composer install
npm install

# Configurar banco local
php artisan migrate
php artisan db:seed

# Servidor de desenvolvimento
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
```

### Contribuição
1. Fork o projeto
2. Crie uma branch feature (`git checkout -b feature/nova-funcionalidade`)
3. Commit suas mudanças (`git commit -am 'Adiciona nova funcionalidade'`)
4. Push para a branch (`git push origin feature/nova-funcionalidade`)
5. Abra um Pull Request

## 📞 Suporte

### Contatos
- **Email**: suporte@chronos.ia.br
- **WhatsApp**: +55 11 99999-9999
- **Site**: https://chronos.ia.br
- **Documentação**: https://docs.chronos.ia.br
- **Status**: https://status.chronos.ia.br

### Links Úteis
- [Documentação Completa](https://docs.chronos.ia.br)
- [API Reference](https://api.chronos.ia.br/docs)
- [Changelog](https://github.com/chronos-fin/releases)
- [Roadmap](https://github.com/chronos-fin/roadmap)

---

**CHRONOS Fin** - Seu assistente financeiro inteligente 🚀

*Transformando o controle financeiro com tecnologia e inovação.*
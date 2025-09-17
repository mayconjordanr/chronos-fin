# 🚀 CHRONOS Fin - Sistema de Controle Financeiro Inteligente

![CHRONOS Fin](https://img.shields.io/badge/CHRONOS-Fin-orange?style=for-the-badge&logo=bolt)
![PHP](https://img.shields.io/badge/PHP-8.1+-blue?style=for-the-badge&logo=php)
![Laravel](https://img.shields.io/badge/Laravel-10+-red?style=for-the-badge&logo=laravel)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-blue?style=for-the-badge&logo=mysql)

> **Sistema de controle financeiro multi-tenant com interface futurista e integração WhatsApp**

## ✨ Características Principais

- 🎨 **Interface Futurista** - Design moderno com tema laranja e modo escuro/claro
- 🏢 **Multi-Tenant** - Separação completa de dados por cliente com subdomínios dinâmicos
- 📱 **Integração WhatsApp** - Adicione transações via mensagens de voz ou texto
- 📊 **Dashboard Inteligente** - Métricas em tempo real e insights financeiros
- 🔐 **Segurança Robusta** - Isolamento de dados, SSL obrigatório, rate limiting
- 💰 **Sistema de Planos** - Básico (gratuito), Pro (R$ 29/mês), Enterprise (R$ 99/mês)
- 🌍 **Totalmente Responsivo** - Funciona perfeitamente em desktop e mobile

## 🏗️ Baseado no Firefly III

O CHRONOS Fin é uma evolução do [Firefly III](https://github.com/firefly-iii/firefly-iii), mantendo toda a robustez do backend original e adicionando:

- Interface redesenhada com tema futurista
- Arquitetura multi-tenant para SaaS
- Integração nativa com WhatsApp
- Sistema de planos e assinaturas
- Dashboard moderno com widgets inteligentes

## 🚀 Instalação Rápida (VPS/Cloud)

### Pré-requisitos
- Ubuntu 20.04+ ou Debian 10+
- Acesso root (sudo)
- Domínio configurado com DNS

### Instalação Automática

```bash
# 1. Conectar na VPS (IP específico: 91.99.23.32)
ssh root@91.99.23.32

# 2. Baixar o instalador
curl -sSL https://raw.githubusercontent.com/mayconjordanr/chronos-fin/main/install-vps.sh -o install-vps.sh

# 3. Dar permissão de execução
chmod +x install-vps.sh

# 4. Executar a instalação
sudo ./install-vps.sh
```

### ⚡ Instalação Express (Um Comando)
```bash
ssh root@91.99.23.32 'curl -sSL https://raw.githubusercontent.com/mayconjordanr/chronos-fin/main/install-vps.sh | bash'
```

O script irá:
- ✅ Instalar todas as dependências (PHP 8.1, MySQL, Nginx, Redis, Node.js)
- ✅ Configurar banco de dados e usuários
- ✅ Configurar Nginx com SSL automático (Let's Encrypt)
- ✅ Instalar e configurar a aplicação
- ✅ Configurar workers e filas em background
- ✅ Configurar firewall e segurança
- ✅ Criar scripts de backup automático

### Instalação Manual

Se preferir instalar manualmente, consulte o [Guia de Deployment](DEPLOYMENT.md).

## 🌐 Configuração de Domínios

### Estrutura Recomendada
```
chronos.ia.br           # Site institucional (opcional)
app.chronos.ia.br       # Aplicação principal
cliente1.chronos.ia.br  # Tenant do cliente 1
cliente2.chronos.ia.br  # Tenant do cliente 2
```

### DNS Configuration
```
A     chronos.ia.br        → IP_DO_SERVIDOR
A     app.chronos.ia.br    → IP_DO_SERVIDOR
A     *.chronos.ia.br      → IP_DO_SERVIDOR
```

## 📱 Integração WhatsApp

### Configuração
1. Configure webhook no provedor WhatsApp Business API
2. URL: `https://app.chronos.ia.br/api/v1/chronos/public/webhook/whatsapp`
3. Configure token em `CHRONOS_WHATSAPP_WEBHOOK_TOKEN`

### Exemplos de Uso
```
💬 "Comprei pão por R$ 5,50 no débito"
   → Cria despesa de R$ 5,50, categoria "Alimentação"

💬 "Recebi 5000 reais de salário"
   → Cria receita de R$ 5.000,00, categoria "Receita"

💬 "Transferi 200 reais para poupança"
   → Cria transferência de R$ 200,00
```

## 🎯 Planos e Recursos

| Recurso | Básico (Grátis) | Pro (R$ 29/mês) | Enterprise (R$ 99/mês) |
|---------|------------------|------------------|------------------------|
| Usuários | 1 | 5 | Ilimitado |
| Transações/mês | 100 | 1.000 | Ilimitado |
| WhatsApp | ✅ | ✅ | ✅ |
| Dashboard | Básico | Avançado | Completo |
| API | ❌ | ✅ | ✅ |
| Relatórios | Básicos | Avançados | Completos |
| Suporte | Comunidade | Email | Prioritário |

## 🔧 Desenvolvimento

### Setup Local

```bash
# Clonar repositório
git clone https://github.com/mayconjordanr/chronos-fin.git
cd chronos-fin

# Instalar dependências
composer install
npm install

# Configurar ambiente
cp .env.example .env
php artisan key:generate

# Configurar banco de dados
php artisan migrate
php artisan db:seed

# Iniciar servidor de desenvolvimento
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
```

### Stack Tecnológica

- **Backend**: PHP 8.1, Laravel 10
- **Frontend**: Alpine.js, Bootstrap 5, Chart.js
- **Database**: MySQL 8.0 (multi-tenant)
- **Cache**: Redis
- **Queue**: Redis/Database
- **Assets**: Vite, SASS
- **Server**: Nginx, PHP-FPM

## 📋 API Documentation

### Endpoints Principais

```bash
# Health Check
GET /api/v1/chronos/public/health

# WhatsApp Webhook
POST /api/v1/chronos/public/webhook/whatsapp

# Resumo Financeiro (autenticado)
GET /api/v1/chronos/summary/{userId}

# Processamento de Mensagem (autenticado)
POST /api/v1/chronos/whatsapp
```

### Teste da API

```bash
# Testar health check
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

## 🔐 Segurança

### Recursos Implementados
- ✅ SSL/TLS obrigatório
- ✅ Rate limiting nas APIs
- ✅ Isolamento completo de dados por tenant
- ✅ Headers de segurança
- ✅ Proteção CSRF
- ✅ Validação robusta de entrada
- ✅ Firewall configurado

### Configurações de Produção
- Sempre usar HTTPS
- Configurar firewall (UFW)
- Implementar fail2ban
- Monitorar logs regularmente
- Backups automáticos diários

## 📊 Monitoramento

### Logs Importantes
```bash
# Logs da aplicação
tail -f /var/www/chronos-fin/storage/logs/laravel.log

# Logs do Nginx
tail -f /var/log/nginx/access.log

# Logs dos workers
tail -f /var/www/chronos-fin/storage/logs/worker.log
```

### Métricas Recomendadas
- Tempo de resposta < 200ms
- Uptime > 99.9%
- CPU < 70%
- RAM < 80%
- Disk < 80%

## 💾 Backup e Restore

### Backup Automático
O sistema cria backups automáticos diários às 2h da manhã:
- Arquivos da aplicação
- Banco de dados principal
- Bancos de dados dos tenants

### Backup Manual
```bash
# Executar backup manual
sudo /usr/local/bin/chronos-backup.sh

# Localização dos backups
ls -la /backup/chronos-fin/
```

## 🚀 Deploy e Updates

### Atualização da Aplicação
```bash
cd /var/www/chronos-fin
git pull origin main
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
php artisan config:cache
sudo systemctl reload nginx
```

### Rollback
```bash
# Em caso de problemas, fazer rollback
git checkout COMMIT_ANTERIOR
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate:rollback
php artisan config:cache
```

## 🤝 Contribuindo

1. Fork o projeto
2. Crie uma branch feature (`git checkout -b feature/nova-funcionalidade`)
3. Commit suas mudanças (`git commit -am 'Adiciona nova funcionalidade'`)
4. Push para a branch (`git push origin feature/nova-funcionalidade`)
5. Abra um Pull Request

### Guidelines
- Seguir PSR-12 para PHP
- Usar Prettier para JavaScript/CSS
- Escrever testes para novas funcionalidades
- Atualizar documentação quando necessário

## 📞 Suporte

### Contatos
- **Email**: suporte@chronos.ia.br
- **Website**: https://chronos.ia.br
- **Documentação**: https://docs.chronos.ia.br
- **Status**: https://status.chronos.ia.br

### Links Úteis
- [Documentação Completa](DEPLOYMENT.md)
- [Guia de Instalação](install-vps.sh)
- [FAQ](docs/FAQ.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)

## 📝 Licença

Este projeto está licenciado sob a licença AGPL v3 - veja o arquivo [LICENSE](LICENSE) para detalhes.

**Baseado no Firefly III** - Copyright (c) James Cole

## 🎯 Roadmap

- [ ] App mobile nativo (iOS/Android)
- [ ] Integração com Open Banking
- [ ] IA para categorização automática
- [ ] Relatórios avançados com BI
- [ ] Integração com outros assistentes (Telegram, Discord)
- [ ] API GraphQL
- [ ] Marketplace de plugins

---

**CHRONOS Fin** - Transformando o controle financeiro com tecnologia e inovação 🚀

*Se este projeto te ajudou, considere dar uma ⭐ no repositório!*
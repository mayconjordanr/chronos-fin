# 🖥️ CHRONOS Fin - Setup Local para Desenvolvimento

## Pré-requisitos

- PHP 8.1+
- MySQL 8.0+
- Node.js 18+
- Composer 2.x
- Git

## Passo a Passo

### 1. Clonar o Repositório

```bash
# Em qualquer pasta do seu PC:
git clone https://github.com/mayconjordanr/chronos-fin.git
cd chronos-fin
```

### 2. Instalar Dependências PHP

```bash
# Instalar pacotes Laravel:
composer install
```

### 3. Instalar Dependências JavaScript

```bash
# Instalar pacotes Node.js:
npm install
```

### 4. Configurar Ambiente

```bash
# Copiar arquivo de configuração:
cp .env.example .env

# Gerar chave da aplicação:
php artisan key:generate
```

### 5. Configurar Banco de Dados

**Editar arquivo `.env`:**
```env
APP_NAME="CHRONOS Fin Local"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chronos_fin_local
DB_USERNAME=root
DB_PASSWORD=

# Cache local (sem Redis)
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

# Desabilitar SSL em desenvolvimento
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

### 6. Criar Banco de Dados

```sql
-- No MySQL:
CREATE DATABASE chronos_fin_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 7. Executar Migrações

```bash
# Criar tabelas:
php artisan migrate

# (Opcional) Adicionar dados de exemplo:
php artisan db:seed
```

### 8. Compilar Assets

```bash
# Compilar CSS/JS para desenvolvimento:
npm run dev

# OU compilar e assistir mudanças:
npm run watch
```

### 9. Iniciar Servidor

```bash
# Iniciar servidor Laravel:
php artisan serve --host=0.0.0.0 --port=8000
```

### 10. Acessar o Sistema

Abra o navegador em: **http://localhost:8000**

## URLs de Desenvolvimento

- **Dashboard**: http://localhost:8000
- **Cadastro**: http://localhost:8000/register
- **Login**: http://localhost:8000/login
- **API Health**: http://localhost:8000/api/v1/chronos/public/health

## Comandos Úteis

### Recompilar Assets
```bash
# Após mudanças no CSS/JS:
npm run dev
```

### Limpar Cache
```bash
# Se algo não funcionar:
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### Acessar Console Laravel
```bash
# Para testes e debugging:
php artisan tinker
```

### Verificar Rotas
```bash
# Ver todas as rotas:
php artisan route:list
```

## Troubleshooting

### Erro de Permissão
```bash
# No Linux/Mac:
sudo chmod -R 775 storage
sudo chmod -R 775 bootstrap/cache
```

### Erro de Database
```bash
# Verificar se MySQL está rodando:
# Windows: Abrir XAMPP/WAMP
# Mac: brew services start mysql
# Linux: sudo systemctl start mysql
```

### Erro de Composer
```bash
# Instalar extensões PHP necessárias:
# Ver documentação específica do seu OS
```

### Assets não carregam
```bash
# Verificar se Node.js está instalado:
node --version
npm --version

# Reinstalar dependências:
rm -rf node_modules package-lock.json
npm install
npm run dev
```

## Modo de Desenvolvimento

### Hot Reload (Recomendado)
```bash
# Terminal 1 - Servidor Laravel:
php artisan serve

# Terminal 2 - Vite (hot reload):
npm run dev
```

### Debug Mode
No arquivo `.env`:
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

## Testando Multi-Tenancy Local

Para testar subdomínios localmente:

### 1. Editar hosts
```bash
# Windows: C:\Windows\System32\drivers\etc\hosts
# Linux/Mac: /etc/hosts

# Adicionar linhas:
127.0.0.1 chronos.local
127.0.0.1 app.chronos.local
127.0.0.1 teste.chronos.local
```

### 2. Configurar .env
```env
APP_URL=http://app.chronos.local:8000
CHRONOS_MAIN_DOMAIN=chronos.local
CHRONOS_APP_DOMAIN=app.chronos.local
```

### 3. Acessar
- http://app.chronos.local:8000
- http://teste.chronos.local:8000

## Próximos Passos

1. **Testar Interface** - Navegar pelo dashboard
2. **Criar Conta** - Registrar primeiro usuário
3. **Testar Funcionalidades** - Adicionar transações
4. **Modificar Código** - Personalizar conforme necessário
5. **Deploy na VPS** - Quando estiver satisfeito

## Suporte

- Verificar logs: `storage/logs/laravel.log`
- Console do navegador para erros JavaScript
- Verificar status do servidor: `php artisan route:list`
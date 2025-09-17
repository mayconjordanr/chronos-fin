#!/bin/bash

#########################################
# CHRONOS Fin - Setup Local Development
# Compatible with Windows/Mac/Linux
#########################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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

check_dependencies() {
    print_status "Verificando dependências..."

    # Check PHP
    if ! command -v php &> /dev/null; then
        print_error "PHP não encontrado! Instale PHP 8.1 ou superior."
        exit 1
    fi

    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    print_success "PHP $PHP_VERSION encontrado"

    # Check Composer
    if ! command -v composer &> /dev/null; then
        print_error "Composer não encontrado! Instale o Composer."
        exit 1
    fi
    print_success "Composer encontrado"

    # Check Node.js
    if ! command -v node &> /dev/null; then
        print_error "Node.js não encontrado! Instale Node.js 18 ou superior."
        exit 1
    fi

    NODE_VERSION=$(node --version)
    print_success "Node.js $NODE_VERSION encontrado"

    # Check MySQL
    if ! command -v mysql &> /dev/null; then
        print_warning "MySQL não encontrado no PATH. Verifique se está instalado."
    else
        print_success "MySQL encontrado"
    fi
}

setup_environment() {
    print_status "Configurando ambiente..."

    # Copy environment file
    if [[ ! -f .env ]]; then
        cp .env.local .env
        print_success "Arquivo .env criado"
    else
        print_warning "Arquivo .env já existe, mantendo configuração atual"
    fi

    # Generate application key
    php artisan key:generate
    print_success "Chave da aplicação gerada"
}

install_dependencies() {
    print_status "Instalando dependências PHP..."
    composer install
    print_success "Dependências PHP instaladas"

    print_status "Instalando dependências JavaScript..."
    npm install
    print_success "Dependências JavaScript instaladas"
}

setup_database() {
    print_status "Configurando banco de dados..."

    DB_NAME="chronos_fin_local"

    # Try to create database
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || {
        print_warning "Não foi possível criar o banco automaticamente"
        print_warning "Crie manualmente: CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        read -p "Pressione Enter quando o banco estiver criado..."
    }

    # Run migrations
    print_status "Executando migrações..."
    php artisan migrate --force
    print_success "Migrações executadas"

    # Optional: Run seeders
    read -p "Deseja adicionar dados de exemplo? (y/N): " add_data
    if [[ $add_data =~ ^[Yy]$ ]]; then
        php artisan db:seed
        print_success "Dados de exemplo adicionados"
    fi
}

build_assets() {
    print_status "Compilando assets..."
    npm run build
    print_success "Assets compilados"
}

show_completion() {
    clear
    print_success "=== CHRONOS Fin - Setup Local Concluído! ==="
    echo ""
    echo -e "${GREEN}🎉 CHRONOS Fin configurado com sucesso!${NC}"
    echo ""
    echo "📋 PRÓXIMOS PASSOS:"
    echo ""
    echo "1. ${YELLOW}Iniciar o servidor:${NC}"
    echo "   php artisan serve"
    echo ""
    echo "2. ${YELLOW}Abrir no navegador:${NC}"
    echo "   http://localhost:8000"
    echo ""
    echo "3. ${YELLOW}Para desenvolvimento com hot-reload:${NC}"
    echo "   Terminal 1: php artisan serve"
    echo "   Terminal 2: npm run dev"
    echo ""
    echo "🔗 URLS DISPONÍVEIS:"
    echo "  🏠 Dashboard: http://localhost:8000"
    echo "  📝 Cadastro: http://localhost:8000/register"
    echo "  🔑 Login: http://localhost:8000/login"
    echo "  ⚡ API Health: http://localhost:8000/api/v1/chronos/public/health"
    echo ""
    echo "🛠️  COMANDOS ÚTEIS:"
    echo "  📦 Recompilar assets: npm run dev"
    echo "  🧹 Limpar cache: php artisan cache:clear"
    echo "  🔍 Ver rotas: php artisan route:list"
    echo "  🐛 Console Laravel: php artisan tinker"
    echo ""
    echo -e "${BLUE}📖 Documentação completa em: LOCAL-SETUP.md${NC}"
    echo ""

    read -p "Deseja iniciar o servidor agora? (Y/n): " start_server
    if [[ ! $start_server =~ ^[Nn]$ ]]; then
        print_status "Iniciando servidor..."
        php artisan serve --host=0.0.0.0 --port=8000
    fi
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
    echo -e "${GREEN}Setup Local - v1.0${NC}"
    echo ""

    check_dependencies
    setup_environment
    install_dependencies
    setup_database
    build_assets
    show_completion
}

# Execute main function
main "$@"
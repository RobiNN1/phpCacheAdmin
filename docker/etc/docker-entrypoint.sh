#!/bin/sh
set -euo pipefail

# ANSI Color Codes
NC='\033[0m'       # Default Color
GRN='\033[32;1m'   # Green (Success)
RED='\033[31;1m'   # Red (Error)

# Run custom script before the main docker process gets started
for f in /docker-entrypoint.init.d/*; do
    case "$f" in
        *.sh) # this should match the set of files we check for below
            echo "⚙ Executing entrypoint.init file: ${f}"
            . $f
            break
            ;;
    esac
done

printf "\n${GRN}--->${NC} 🚀  Starting phpCacheAdmin container..."
printf "\n${GRN}--->${NC} Docker image build date: ${GRN}${BUILD_DATE:-Undefined}${NC}"

printf "\n\nCaddy version: "
caddy version

printf "\nPHP-FPM version:\n"
php-fpm --version
printf "\n"

# Start PHP-FPM in the background
php-fpm --daemonize --pid /tmp/php-fpm.pid

# Start Caddy in the foreground
caddy run --adapter caddyfile --config /etc/caddy/Caddyfile
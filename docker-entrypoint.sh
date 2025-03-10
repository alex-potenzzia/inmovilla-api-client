#!/bin/bash
set -e

# Obtener el puerto de Render o usar 8080 por defecto
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
    sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf
fi

# Iniciar Apache en primer plano
apache2-foreground

# Étape 1 : image Composer pour récupérer composer
FROM composer:2 AS composer_stage

# Étape 2 : image PHP CLI pour exécuter l'appli
FROM php:8.2-cli

# Installation des dépendances système nécessaires (zip, gd, etc.)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libxml2-dev \
    && docker-php-ext-install zip gd \
    && rm -rf /var/lib/apt/lists/*

# Copier composer depuis l'étape précédente
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# Répertoire de travail dans le conteneur
WORKDIR /var/www/html

# Copier composer.json (+ lock si présent) et installer les dépendances PHP
COPY composer.json composer.lock* ./

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress || true

# Copier le reste du projet
COPY . .

# S'assurer que le dossier de sortie existe
RUN mkdir -p storage/output

# Exposer le port du serveur PHP intégré
EXPOSE 8000

# Commande par défaut : serveur PHP interne, avec public/ comme racine
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

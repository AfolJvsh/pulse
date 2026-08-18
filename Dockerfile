FROM node:22-bookworm-slim AS frontend
WORKDIR /src
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./
ARG VITE_REVERB_APP_KEY=pulse-local-key
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}
RUN npm run build

FROM dunglas/frankenphp:php8.4-bookworm
RUN install-php-extensions pdo_pgsql redis pcntl intl mbstring zip bcmath
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY --from=frontend /src/public/build /app/public/build
EXPOSE 8000
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]

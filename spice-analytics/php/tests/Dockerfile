FROM php@sha256:0d6ec82fde85307fd9c672527017fccf9bac55f254756f035e542f8c566b63c9
RUN --mount=type=secret,id=host_ca \
    if [ -s /run/secrets/host_ca ]; then cat /run/secrets/host_ca >> /etc/ssl/certs/ca-certificates.crt; fi \
    && sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev \
    && docker-php-ext-install -j2 bcmath pdo_mysql mbstring intl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app

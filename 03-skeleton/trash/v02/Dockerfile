# 베이스: PHP 8.2 + Apache
FROM php:8.2-apache

# --- 시스템 패키지 & PHP 확장 ---
# imap 확장 빌드에 필요한 dev 패키지(배포판에 따라 libc-client 패키지명이 다름)
# - Debian bullseye: libc-client2007e-dev
# - Debian bookworm: uw-imap-dev
# 둘 중 하나만 성공해도 계속 진행하도록 '|| true'
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      git unzip ca-certificates \
      libzip-dev zlib1g-dev libicu-dev libonig-dev \
      libkrb5-dev libssl-dev \
      libc-client2007e-dev || true; \
    apt-get install -y --no-install-recommends uw-imap-dev || true; \
    rm -rf /var/lib/apt/lists/*

# imap 확장 컴파일 (케르베로스/SSL 사용)
# uw-imap-dev가 있을 때는 /usr/include/c-client 경로가 제공됩니다.
RUN set -eux; \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl; \
    docker-php-ext-install -j"$(nproc)" imap; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli intl zip; \
    php -m | grep -E 'imap|pdo|mysqli|intl|zip'

# Apache 모듈 활성화
RUN a2enmod rewrite headers ssl

# 보안 헤더 기본 세팅(필요시 조정)
RUN printf '%s\n' \
  'Header always set X-Content-Type-Options "nosniff"' \
  'Header always set X-Frame-Options "SAMEORIGIN"' \
  'Header always set Referrer-Policy "no-referrer-when-downgrade"' \
  'Header always set X-XSS-Protection "1; mode=block"' \
  'Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"' \
  > /etc/apache2/conf-available/security-headers.conf \
  && a2enconf security-headers

# 앱 복사 (프로젝트 루트에 있는 모든 파일을 컨테이너로)
WORKDIR /var/www/html
COPY . /var/www/html

# 웹에서 직접 접근하는 저장 폴더들 만들기 (컨테이너 내부 기본 생성)
RUN set -eux; \
  mkdir -p /var/www/html/소송 /var/www/html/계약 /var/www/html/거버넌스 /var/www/html/보관; \
  mkdir -p /var/www/data; \
  touch /var/www/html/audit_log.csv; \
  chown -R www-data:www-data /var/www/html /var/www/data

# Apache 가상호스트 적용
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# 업로드/포스트 사이즈(필요시 조정)
RUN { \
      echo 'upload_max_filesize=25M'; \
      echo 'post_max_size=25M'; \
      echo 'memory_limit=512M'; \
      echo 'max_execution_time=60'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# 헬스체크(인덱스 응답 확인)
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://localhost/ || exit 1

EXPOSE 80
CMD ["apache2-foreground"]

# syntax=docker/dockerfile:1.6
# Kode Framework — 生产级多阶段构建（12-factor）
# 1) 依赖阶段：composer install --no-dev --optimize-autoloader
# 2) 运行阶段：php:8.3-cli + 非 root 用户 + opcache 预热 + 健康探针

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# 仅当存在 composer.lock 时复用，否则退化为 update 语义由 CI 保证提交 lock
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist \
    || composer update --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

FROM php:8.3-cli
# 系统依赖：ext 所需（pcntl/posix 已内置，swoole 按需外挂）
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libicu-dev \
    && docker-php-ext-install zip opcache \
    && rm -rf /var/lib/apt/lists/*

# opcache 生产配置
COPY <<'INI' /usr/local/etc/php/conf.d/opcache.ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.preload_user=app
INI

WORKDIR /app
# 运行时用户（非 root）
RUN useradd -m -u 1000 app
COPY --from=vendor /app/vendor ./vendor
COPY . .
# 修正 .env 权限与 storage 目录归属（kode init 已做 chmod 0600，镜像内二次收紧）
RUN mkdir -p storage/logs storage/cache storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R app:app storage \
    && chmod -R 755 storage \
    && if [ -f .env ]; then chmod 0600 .env; fi

USER app
EXPOSE 9527
HEALTHCHECK --interval=10s --timeout=2s --retries=3 --start-period=10s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:9527/health/live") ? 0 : 1);'

ENTRYPOINT ["php", "bin/kode", "serve"]
# 默认参数可被 k8s args 覆盖
CMD ["--host", "0.0.0.0", "--port", "9527"]

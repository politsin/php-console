# php-console


## Ubuntu WSL
```sh
apt install php \
            php-cli \
            php-dev \
            php-zip \
            php-pear \
            php8.1-gmp \
            -y --no-install-recommends

pecl install -y dio
# OR 
pecl install -y channel://pecl.php.net/dio-0.2.1

echo 'extension=dio.so' > /etc/php/8.1/mods-available/dio.ini
ln -s /etc/php/8.1/mods-available/dio.ini /etc/php/8.1/cli/conf.d/20-dio.ini

#Composer:::
wget https://getcomposer.org/installer -q -O composer-setup.php && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    chmod +x /usr/local/bin/composer

php --version
export COMPOSER_ALLOW_SUPERUSER=1
composer --version

#PHPCS:::
mkdir -p /var/lib/composer && \
      cd /var/lib/composer && \
      wget https://raw.githubusercontent.com/politsin/snipets/master/patch/composer.json && \
      composer install -o && \
      sed -i 's/snap/var\/lib\/composer\/vendor/g' /etc/environment && \
      /var/lib/composer/vendor/bin/phpcs -i && \
      /var/lib/composer/vendor/bin/phpcs --config-set colors 1 && \
      /var/lib/composer/vendor/bin/phpcs --config-set default_standard Drupal && \
      /var/lib/composer/vendor/bin/phpcs --config-show
```

## Конфигурация (.env)
Файл `.env` в корне (загружается автоматически):

```
# GNSS / Ublox
GNSS_PORT=/dev/ttyACM0
GNSS_BAUD=9600

# Redis (in-memory)
REDIS_DSN=redis://127.0.0.1:6379
GNSS_REDIS_TTL=15

# InfluxDB v2 (optional)
INFLUX_URL=
INFLUX_TOKEN=
INFLUX_ORG=
INFLUX_BUCKET=

# Telegram (optional)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=

# Webhook (optional)
WEBHOOK_URL=

# Spoofing thresholds
SPOOF_JUMP_M=100
SPOOF_DRIFT_R_M=50
SPOOF_SV_JACCARD=0.3
SPOOF_MIN_ALERT_INTERVAL=60

# Project specific
HOST=ozon.biz-panel.com
KEY=19074-ozon
```

## Запуск GNSS
```sh
php /opt/php-console/console.php gnss:listen \
  --port=${GNSS_PORT:-/dev/ttyACM0} \
  --baud=${GNSS_BAUD:-9600}
```

Выводит сводку GGA/RMC, статистику SNR из GSV, дрейф (радиус/среднее/максимум), публикует состояние в Redis с TTL, опционально отправляет метрики в Influx, уведомления в Telegram и JSON на Webhook.

## Influx + Grafana (кратко)
1. Создайте в InfluxDB bucket (например, `gnss`) и токен.
2. Заполните `INFLUX_URL`, `INFLUX_TOKEN`, `INFLUX_ORG`, `INFLUX_BUCKET`.
3. В Grafana добавьте источник InfluxDB (Flux/HTTP v2), постройте графики по `measurement="gnss"`.

Поля: `lat`, `lon`, `alt`, `fix`, `sv`, `hdop`, `snr_min`, `snr_avg`, `snr_max`, `drift_r`, `drift_avg`, `drift_max`. Теги: `host`, `port`.

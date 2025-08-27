# GNSS — краткий запуск

## 1) Зависимости
- PHP 8.4+, Composer, расширение dio (по README в корне).

## 2) Конфигурация (.env)
- Порт/скорость (по умолчанию):
  - `GNSS_PORT=/dev/ttyACM0`
  - `GNSS_BAUD=9600`
- Redis (в Docker): `REDIS_HOST/REDIS_PORT/REDIS_PASS` или `REDIS_DSN`.
- Influx v2: `INFLUX_URL` + `INFLUX_ORG` + `INFLUX_BUCKET` + `INFLUX_TOKEN`.
- Telegram (опционально): `TELEGRAM_BOT_TOKEN`/`TELEGRAM_TOKEN`/`TG_KEY` + `TELEGRAM_CHAT_ID`/`TG_CHAT`.
- Webhook (опционально): `WEBHOOK_URL` (суффикс `/gnss` добавится автоматически, `/gps` срежется).

## 3) Запуск (фореграунд)
```sh
php /opt/php-console/console.php gnss:listen
```

## 4) Запуск (фон) и логи
```sh
mkdir -p /opt/php-console/logs
nohup php /opt/php-console/console.php gnss:listen >> /opt/php-console/logs/gnss.log 2>&1 &
tail -f /opt/php-console/logs/gnss.log
```

Остановить:
```sh
pkill -f "php /opt/php-console/console.php gnss:listen"
```

## 5) Что пишется
- Redis:
  - `gnss:state:latest` — актуальное состояние.
  - `gnss:state:YYYY:MM:DD:HH:MM` — история поминутно (TTL настраивается).
  - `gnss:satellites:latest` и `gnss:satellites:YYYY:MM:DD:HH:MM` — видимые спутники (каждые 15 мин).
- Influx: `measurement=gnss` (lat/lon/alt/fix/sv/hdop/snr_min/avg/max/drift_*), раз в 15 мин добавляются `snr_sat_<id>`.
- Webhook: JSON со `state`, `satellites`, временные метки `ts` и `state.event_ts`/`event_iso`.

## 6) Тесты
- Telegram тест:
```sh
php /opt/php-console/console.php gnss:listen --test-telegram
```
- Influx health/запись — см. команды в README.md (корень).



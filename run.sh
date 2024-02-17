#!/bin/bash
if [ ! -f vendor/autoload.php ]; then
  export COMPOSER_ALLOW_SUPERUSER=1
  composer install -o
fi

php ./console.php

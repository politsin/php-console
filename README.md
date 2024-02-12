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

#PHPCS:::
mkdir /var/lib/composer && \
      cd /var/lib/composer && \
      wget https://raw.githubusercontent.com/politsin/snipets/master/patch/composer.json && \
      composer install -o && \
      sed -i 's/snap/var\/lib\/composer\/vendor/g' /etc/environment && \
      /var/lib/composer/vendor/bin/phpcs -i && \
      /var/lib/composer/vendor/bin/phpcs --config-set colors 1 && \
      /var/lib/composer/vendor/bin/phpcs --config-set default_standard Drupal && \
      /var/lib/composer/vendor/bin/phpcs --config-show
```

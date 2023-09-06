# php-console


## Ubuntu WSL
```sh
apt install php \
            php-cli \
            php-dev \
            php-zip \
            php-pear \

pecl install dio
# OR 
pecl install channel://pecl.php.net/dio-0.2.1

echo 'extension=dio.so' > /etc/php/8.1/mods-available/dio.ini
ln -s /etc/php/8.1/mods-available/dio.ini /etc/php/8.1/cli/conf.d/20-dio.ini

#Composer:::
wget https://getcomposer.org/installer -q -O composer-setup.php && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    chmod +x /usr/local/bin/composer
#Composer-FIX:::
git clone https://github.com/composer/composer.git --branch 2.6.0  ~/composer-build && \
    composer install  -o -d ~/composer-build && \
    wget https://raw.githubusercontent.com/politsin/snipets/master/patch/composer.patch -q -O ~/composer-build/composer.patch  && \
    cd ~/composer-build && patch -p1 < composer.patch && \
    php -d phar.readonly=0 bin/compile && \
    rm /usr/local/bin/composer && \
    php composer.phar install && \
    php composer.phar update && \
    mv ~/composer-build/composer.phar /usr/local/bin/composer && \
    rm -rf ~/composer-build  && \
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

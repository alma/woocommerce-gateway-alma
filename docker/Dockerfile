FROM wordpress:latest

# install composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

# install xdebug
RUN pecl install xdebug

# install WordPress CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp

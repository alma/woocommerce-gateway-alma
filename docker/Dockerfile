FROM wordpress:latest

# install composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

# install xdebug
RUN pecl install xdebug

# install wp-cli
RUN cd /usr/local/bin && curl https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp
RUN echo "alias wp='wp --allow-root'" >> /root/.bashrc

# install vim & less
RUN apt-get update && apt-get install -y vim less

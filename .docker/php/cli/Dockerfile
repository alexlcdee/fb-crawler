FROM phpdockerio/php71-cli:latest

# Install selected extensions and other stuff
RUN apt-get update \
    && apt-get -y --no-install-recommends install \
        php7.1-curl php7.1-mysql php7.1-sqlite3 php7.1-gd \
        php7.1-geoip php7.1-intl php7.1-mbstring php7.1-xdebug \
        php7.1-bcmath \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*


WORKDIR "/application"
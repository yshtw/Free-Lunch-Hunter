FROM php:8.4-cli-bookworm
RUN docker-php-ext-install -j2 pdo_mysql
WORKDIR /var/www
COPY app/ app/
COPY database/ database/
COPY scripts/ scripts/
COPY final_project/ final_project/
RUN chmod -R a+rX app database scripts final_project \
    && mkdir -p final_project/uploads \
    && chown www-data:www-data final_project/uploads
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s --start-period=10s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/api.php?resource=health") === false ? 1 : 0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/final_project"]

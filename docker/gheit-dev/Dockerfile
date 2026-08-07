FROM openemr/openemr:8.0.0

COPY ../.. /var/www/localhost/htdocs/openemr

RUN cd /var/www/localhost/htdocs/openemr && \
    apk update && \
    apk add --no-cache git && \
    git config --global url."https://github.com/".insteadOf "git@github.com:" && \
    composer require google/cloud-pubsub aws/aws-sdk-php && \
    composer require auth0/auth0-php --ignore-platform-req=ext-bcmath

RUN chown -R apache:apache /var/www/localhost/htdocs/openemr/.env


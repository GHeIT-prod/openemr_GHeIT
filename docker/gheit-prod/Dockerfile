FROM openemr/openemr:7.0.4

WORKDIR /var/www/localhost/htdocs/openemr

COPY . .

RUN apk update && \
    apk add --no-cache git && \
    git config --global url."https://github.com/".insteadOf "git@github.com:" && \
    composer require google/cloud-pubsub aws/aws-sdk-php && \
    composer require auth0/auth0-php --ignore-platform-req=ext-bcmath

RUN if [ -f .env ]; then chown apache:apache .env; fi

FROM openemr/openemr:7.0.4

ENV COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/localhost/htdocs/openemr

COPY --chown=apache:apache . .
COPY docker/gheit-prod/openemr-start.sh /usr/local/bin/openemr-start.sh

RUN apk add --no-cache git && \
    git config --global url."https://github.com/".insteadOf "git@github.com:" && \
    composer require --no-interaction --no-progress google/cloud-pubsub aws/aws-sdk-php && \
    composer require --no-interaction --no-progress auth0/auth0-php --ignore-platform-req=ext-bcmath && \
    composer clear-cache

RUN chown -R apache:apache /var/www/localhost/htdocs/openemr && \
    chmod 755 /usr/local/bin/openemr-start.sh && \
    chmod -R u+rwX,go+rX /var/www/localhost/htdocs/openemr && \
    if [ -f .env ]; then chown apache:apache .env; fi

ENTRYPOINT ["/usr/local/bin/openemr-start.sh"]

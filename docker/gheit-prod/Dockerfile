FROM openemr/openemr:8.0.0

WORKDIR /var/www/localhost/htdocs/openemr

# Copy Apache configuration
COPY docker/gheit-prod/config/openemr.conf /etc/apache2/conf.d/openemr.conf
RUN chown root:root /etc/apache2/conf.d/openemr.conf

# Copy the entire application from the build context
COPY . .

RUN apk add --no-cache git

RUN git config --global url."https://github.com/".insteadOf "git@github.com:"

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer install
RUN composer require google/cloud-pubsub aws/aws-sdk-php --with-all-dependencies
RUN composer require auth0/auth0-php --ignore-platform-req=ext-bcmath --with-all-dependencies

RUN npm install

RUN npm install -g gulp

RUN npm run build

RUN chown -R apache:root /var/www/localhost/htdocs/openemr
#!/bin/sh
# Windows Docker Desktop: fix bind-mount permissions for Apache.
chmod -R a+rX /var/www/localhost/htdocs/openemr/interface \
    /var/www/localhost/htdocs/openemr/library \
    /var/www/localhost/htdocs/openemr/src \
    /var/www/localhost/htdocs/openemr/public \
    /var/www/localhost/htdocs/openemr/sites 2>/dev/null || true

# Bind-mounted code: skip flex git-clone of upstream OpenEMR.
rm -f /var/www/localhost/htdocs/auto_configure.php

exec ./openemr.sh "$@"

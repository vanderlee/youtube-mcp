#!/bin/bash

## Plesk additional deployment actions:
# chmod u+x ./deploy.sh
# ./deploy.sh

# Pre
MYLOG="deploy.log"

echo "Deploy start" > $MYLOG
date --iso-8601='seconds' >> $MYLOG

# Deploy
#
# Maintenance mode

## Composer
echo "Composer install" >> $MYLOG
/opt/plesk/php/8.5/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --prefer-dist --optimize-autoloader &>> deploy.log || exit 1

# Post

## Cleanup
#rm -rf tests/
#rm -rf docs/
#rm .gitignore
#rm .env.*
#rm phpunit
#rm phpunit.xml
#rm README.md
#rm deploy.sh

echo "Deploy end." >> $MYLOG
date --iso-8601='seconds' >> $MYLOG

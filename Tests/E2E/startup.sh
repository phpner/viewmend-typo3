#!/bin/sh

set -eu

cd /var/www/html
mkdir -p config public/fileadmin var
chown -R www-data:www-data config public/fileadmin var

if [ ! -f public/.htaccess ]; then
    cp vendor/typo3/cms-install/Resources/Private/FolderStructureTemplateFiles/root-htaccess public/.htaccess
fi

if [ ! -f config/system/settings.php ]; then
    gosu www-data vendor/bin/typo3 setup --force --no-interaction --server-type=apache
fi

gosu www-data vendor/bin/typo3 extension:setup
exec apache2-foreground

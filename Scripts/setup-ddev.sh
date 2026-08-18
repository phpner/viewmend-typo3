#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
site_root="$project_root/.ddev/site"

if [ ! -f "$site_root/composer.json" ]; then
    composer create-project --no-interaction --prefer-dist typo3/cms-base-distribution:^14.3 "$site_root"
    composer --working-dir="$site_root" config repositories.viewmend path ../..
    composer --working-dir="$site_root" require --no-interaction viewmend/typo3-site-tracker:@dev
fi

cd "$project_root"
ddev start

if [ ! -f "$site_root/config/system/settings.php" ]; then
    ddev typo3 setup \
        --no-interaction \
        --server-type=other \
        --driver=mysqli \
        --host=db \
        --port=3306 \
        --dbname=db \
        --username=db \
        --password=db \
        --admin-username=admin \
        --admin-user-password='ViewMend-DDEV-Local-1!' \
        --admin-email=local-admin@example.invalid \
        --project-name='ViewMend TYPO3 DDEV' \
        --create-site="https://viewmend-typo3.ddev.site/"
fi

ddev typo3 extension:setup
ddev describe

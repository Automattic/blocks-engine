#!/usr/bin/env bash
set -euo pipefail

# Disposable Docker runtime; no host WordPress files or database are used.
root="$(realpath "$(dirname "$0")/../..")"
prefix="be2514_${RANDOM}_$$"
network="${prefix}_network"
volume="${prefix}_wordpress"
cleanup() {
    docker rm -f "${prefix}_cli" "${prefix}_wordpress" "${prefix}_db" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
docker run -d --name "${prefix}_db" --network "$network" -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=wordpress mysql:8.4 --innodb-use-native-aio=0 >/dev/null
docker run -d --name "${prefix}_wordpress" --network "$network" -p 127.0.0.1::80 -e WORDPRESS_DB_HOST="${prefix}_db" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=test -e WORDPRESS_DB_NAME=wordpress -v "$volume:/var/www/html" -v "$root:/engine:ro" wordpress:7.0.4-php8.3-apache >/dev/null
port="$(docker port "${prefix}_wordpress" 80/tcp)"
url="http://$port"
wp() {
    timeout 60 docker run --rm --name "${prefix}_cli" --network "$network" --user 33:33 -e WORDPRESS_DB_HOST="${prefix}_db" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=test -e WORDPRESS_DB_NAME=wordpress -v "$volume:/var/www/html" -v "$root:/engine:ro" wordpress:cli-php8.3 --path=/var/www/html "$@"
}
for attempt in $(seq 1 60); do
    if [ "$(docker inspect "${prefix}_db" --format '{{.State.Status}}')" = exited ]; then docker logs "${prefix}_db"; exit 1; fi
    if docker exec "${prefix}_db" mysqladmin ping -h127.0.0.1 -ptest --silent >/dev/null 2>&1 && docker exec "${prefix}_wordpress" test -f /var/www/html/wp-config.php; then break; fi
    if [ "$attempt" = 60 ]; then docker logs "${prefix}_db"; docker logs "${prefix}_wordpress"; exit 1; fi
    sleep 2
done
wp core install --url="$url" --title='Stylesheet activation proof' --admin_user=proof --admin_password=proof-test-password --admin_email=proof@example.test --skip-email
runtime_status=0
wp eval 'require "/engine/tests/integration/stylesheet-activation.php";' || runtime_status=$?
STYLESHEET_TEST_URL="$url" node "$root/tests/integration/stylesheet-activation-browser.mjs"
wp eval 'require "/engine/tests/integration/navigation-inventory.php";'
NAVIGATION_TEST_URL="$url" node "$root/tests/integration/navigation-inventory-browser.mjs"
wp eval 'putenv("NAVIGATION_OPENER_TEST=1"); require "/engine/tests/integration/navigation-inventory.php";'
NAVIGATION_OPENER_TEST=1 NAVIGATION_TEST_URL="$url" node "$root/tests/integration/navigation-inventory-browser.mjs"
wp eval 'require "/engine/tests/integration/navigation-anchor-subject.php";'
NAVIGATION_TEST_URL="$url" node "$root/tests/integration/navigation-anchor-subject-browser.mjs"
exit "$runtime_status"

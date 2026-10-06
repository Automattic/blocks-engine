#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
core_tar="${WORDPRESS_71_CORE_TAR:-/home/chubes/Developer/nick-direct-recovery-20261005/evidence/wordpress71-core.tar.gz}"
expected_sha="a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b"
evidence="${THEME_ACCEPTANCE_EVIDENCE_DIR:?Set THEME_ACCEPTANCE_EVIDENCE_DIR outside the repository.}"
for command in docker curl php node sha256sum; do command -v "$command" >/dev/null || { printf 'Missing %s\n' "$command" >&2; exit 2; }; done
test -d "$root/vendor" || { printf 'Run composer install in php-transformer first.\n' >&2; exit 2; }
test -d "$root/tools/visual-parity/node_modules" || { printf 'Run npm ci in tools/visual-parity first.\n' >&2; exit 2; }
test -f "$core_tar" || { printf 'Missing WordPress 7.1 core tar: %s\n' "$core_tar" >&2; exit 2; }
actual_sha="$(sha256sum "$core_tar" | cut -d ' ' -f 1)"
test "$actual_sha" = "$expected_sha" || { printf 'WordPress core SHA mismatch: %s\n' "$actual_sha" >&2; exit 2; }
mkdir -p "$evidence"
exec > >(tee -a "$evidence/runner.log") 2>&1

THEME_ACCEPTANCE_EVIDENCE_DIR="$evidence" php "$root/tests/build-theme-toggle-acceptance.php"
project="be_theme_${RANDOM}_$$"
network="${project}_network"
volume="${project}_wordpress"
port="${THEME_ACCEPTANCE_PORT:-$(( 19000 + RANDOM % 1000 ))}"
wp_container="${project}_wordpress"
db_container="${project}_db"
db_name=wordpress db_user=wordpress db_password=wordpress
wordpress_image="${THEME_ACCEPTANCE_WORDPRESS_IMAGE:-wordpress:php8.3-apache}"
cli_image="${THEME_ACCEPTANCE_CLI_IMAGE:-wordpress:cli-php8.3}"
cleanup() {
    docker logs "$wp_container" > "$evidence/wordpress.log" 2>&1 || true
    docker logs "$db_container" > "$evidence/mysql.log" 2>&1 || true
    docker rm --force "$wp_container" "$db_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
run() { timeout "${THEME_ACCEPTANCE_COMMAND_TIMEOUT:-240}" "$@"; }

run docker network create "$network" >/dev/null
run docker volume create "$volume" >/dev/null
run docker run --detach --name "$db_container" --network "$network" --network-alias mysql -e MYSQL_DATABASE="$db_name" -e MYSQL_USER="$db_user" -e MYSQL_PASSWORD="$db_password" -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
for attempt in $(seq 1 60); do
    if docker exec "$db_container" mysqladmin ping -h 127.0.0.1 -u"$db_user" -p"$db_password" >/dev/null 2>&1; then break; fi
    sleep 2
done
docker exec "$db_container" mysqladmin ping -h 127.0.0.1 -u"$db_user" -p"$db_password" >/dev/null
run docker run --detach --name "$wp_container" --network "$network" --publish "127.0.0.1:${port}:80" \
    -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" \
    -v "$volume:/var/www/html" -v "$core_tar:/tmp/wordpress71-core.tar.gz:ro" \
    -v "$evidence/theme-toggle-companion:/var/www/html/wp-content/plugins/theme-toggle-acceptance:ro" "$wordpress_image" >/dev/null
for attempt in $(seq 1 60); do
    if curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi
    sleep 2
done
curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null
docker exec "$wp_container" tar -xzf /tmp/wordpress71-core.tar.gz --exclude=wp-content --exclude=wp-config.php -C /var/www/html --no-same-owner 2>/dev/null
docker restart "$wp_container" >/dev/null
for attempt in $(seq 1 60); do
    if curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi
    sleep 2
done
curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null
wp=(docker run --rm --network "$network" --user 33:33 -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "$volume:/var/www/html" -v "$evidence/theme-toggle-companion:/var/www/html/wp-content/plugins/theme-toggle-acceptance:ro" "$cli_image" wp --allow-root)
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Theme selection acceptance' --admin_user=themeadmin --admin_password='theme-password' --admin_email=theme@example.test --skip-email
"${wp[@]}" core version | tee "$evidence/wordpress-version.txt"
"${wp[@]}" plugin activate theme-toggle-acceptance
content="$(node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).content)' "$evidence/source-and-page.json")"
post_id="$("${wp[@]}" post create --post_type=page --post_status=publish --post_title='Theme selection acceptance' --post_content="$content" --porcelain)"
printf '%s\n' "$post_id" > "$evidence/wordpress-post-id.txt"
attribute_content="$(node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).content)' "$evidence/root-attribute-page.json")"
attribute_post_id="$("${wp[@]}" post create --post_type=page --post_status=publish --post_title='Theme data attribute acceptance' --post_content="$attribute_content" --porcelain)"
printf '%s\n' "$attribute_post_id" > "$evidence/wordpress-attribute-post-id.txt"
THEME_ACCEPTANCE_WP_URL="http://127.0.0.1:${port}" THEME_ACCEPTANCE_POST_ID="$post_id" THEME_ACCEPTANCE_ATTRIBUTE_POST_ID="$attribute_post_id" THEME_ACCEPTANCE_USER=themeadmin THEME_ACCEPTANCE_PASSWORD=theme-password THEME_ACCEPTANCE_EVIDENCE_DIR="$evidence" \
attribute_content="$(node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).content)' "$evidence/root-attribute-page.json")"
attribute_post_id="$("${wp[@]}" post create --post_type=page --post_status=publish --post_title='Theme data attribute acceptance' --post_content="$attribute_content" --porcelain)"
printf '%s\n' "$attribute_post_id" > "$evidence/wordpress-attribute-post-id.txt"
THEME_ACCEPTANCE_WP_URL="http://127.0.0.1:${port}" THEME_ACCEPTANCE_POST_ID="$post_id" THEME_ACCEPTANCE_ATTRIBUTE_POST_ID="$attribute_post_id" THEME_ACCEPTANCE_USER=themeadmin THEME_ACCEPTANCE_PASSWORD=theme-password THEME_ACCEPTANCE_EVIDENCE_DIR="$evidence" \
    run node "$root/tests/editor-theme-toggle-acceptance.mjs" | tee "$evidence/browser-result.txt"
printf 'Evidence retained at %s\n' "$evidence"

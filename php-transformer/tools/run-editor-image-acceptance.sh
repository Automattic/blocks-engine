#!/usr/bin/env bash
set -euo pipefail

# This runner owns a disposable Docker project and never addresses an existing site.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for command in docker curl php timeout sha256sum tar; do command -v "$command" >/dev/null || { printf 'Missing %s\n' "$command" >&2; exit 2; }; done
test -d "$root/vendor" || { printf 'Run composer install in php-transformer first.\n' >&2; exit 2; }
test -d "$root/tools/visual-parity/node_modules" || { printf 'Run npm install in php-transformer/tools/visual-parity first.\n' >&2; exit 2; }
evidence="${BE_EDITOR_EVIDENCE_DIR:?Set BE_EDITOR_EVIDENCE_DIR outside this repository.}"
mkdir -p "$evidence"
exec > >(tee -a "$evidence/runner.log") 2>&1

work="$(mktemp -d "${TMPDIR:-/tmp}/blocks-engine-editor-image.XXXXXX")"
project="be_editor_image_${RANDOM}_$$"
port="${BE_EDITOR_PORT:-$(( 18000 + RANDOM % 1000 ))}"
network="${project}_network"
volume="${project}_wordpress"
wordpress_image="${BE_EDITOR_WORDPRESS_IMAGE:-wordpress:7.0.4-php8.2-apache}"
cli_image="${BE_EDITOR_CLI_IMAGE:-wordpress:cli-php8.2}"
wordpress_core_archive="${BE_EDITOR_WORDPRESS_CORE_ARCHIVE:-}"
core_archive_mount=()
if [[ -n "$wordpress_core_archive" ]]; then
	wordpress_core_sha="a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b"
	actual_core_sha="$(sha256sum "$wordpress_core_archive" | cut -d ' ' -f 1)"
	test "$actual_core_sha" = "$wordpress_core_sha" || { printf 'WordPress core archive checksum mismatch: %s\n' "$actual_core_sha" >&2; exit 2; }
	wordpress_image="${BE_EDITOR_WORDPRESS_IMAGE:-wordpress:beta-php8.3-apache}"
	cli_image="${BE_EDITOR_CLI_IMAGE:-wordpress:cli-php8.3}"
	core_archive_mount=(-v "${wordpress_core_archive}:/tmp/wordpress71-core.tar.gz:ro")
fi
browser_image="${BE_EDITOR_BROWSER_IMAGE:-mcr.microsoft.com/playwright:v1.61.1-noble}"
db_host=mysql db_name=wordpress db_user=wordpress db_password=wordpress
cleanup() { docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true; docker logs "${project}_db" > "$evidence/mysql.log" 2>&1 || true; docker rm --force "${project}_wordpress" "${project}_db" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; docker volume rm "$volume" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
run() { timeout "${BE_EDITOR_COMMAND_TIMEOUT:-240}" "$@"; }
wait_for() { local label="$1" command="$2" attempt; for attempt in $(seq 1 60); do if eval "$command"; then return 0; fi; sleep 2; done; printf 'Timed out waiting for %s after 120 seconds.\n' "$label" >&2; return 1; }

run docker network create "$network" >/dev/null
run docker volume create "$volume" >/dev/null
run docker run --detach --name "${project}_db" --network "$network" --network-alias "$db_host" -e MYSQL_DATABASE="$db_name" -e MYSQL_USER="$db_user" -e MYSQL_PASSWORD="$db_password" -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
wait_for 'MySQL' "run docker exec ${project}_db mysqladmin ping -u${db_user} -p${db_password}"
run docker run --detach --name "${project}_wordpress" --network "$network" --publish "127.0.0.1:${port}:80" -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/blocks-engine-php-transformer:ro" "${core_archive_mount[@]}" "$wordpress_image" >/dev/null
wp=(run docker run --rm --network "$network" --user 33:33 -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/blocks-engine-php-transformer:ro" -v "${work}:/work" "${core_archive_mount[@]}" "$cli_image" wp --allow-root)
wait_for 'WordPress files' "curl --silent --fail http://127.0.0.1:${port}/wp-login.php >/dev/null"
if [[ -n "$wordpress_core_archive" ]]; then
	run docker exec "${project}_wordpress" sh -c 'tar --overwrite -xzf /tmp/wordpress71-core.tar.gz -C /var/www/html 2>/dev/null && chown -R www-data:www-data /var/www/html/wp-admin /var/www/html/wp-includes'
	run docker exec "${project}_wordpress" php -r 'require "/var/www/html/wp-includes/version.php"; printf("core=%s archive_sha256=%s\n", $wp_version, hash_file("sha256", "/tmp/wordpress71-core.tar.gz")); if ("7.1" !== $wp_version) exit(1);' | tee "$evidence/core-overlay-preflight.txt"
fi
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Blocks Engine editor acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" plugin activate blocks-engine-php-transformer
"${wp[@]}" core version | tee "$evidence/wordpress-version.txt"

# Other block acceptance fixtures share this disposable runtime lifecycle.
if [[ -n "${BE_EDITOR_ACCEPTANCE_BUILDER:-}" ]]; then
	"${wp[@]}" eval-file "wp-content/plugins/blocks-engine-php-transformer/${BE_EDITOR_ACCEPTANCE_BUILDER}" | tee "$evidence/source-and-page.json"
	post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_int($x["post_id"]??null)||$x["post_id"]<1) exit(1); echo $x["post_id"];' "$evidence/source-and-page.json")"
	BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/${BE_EDITOR_ACCEPTANCE_BROWSER:?Set the corresponding browser acceptance script.}" | tee "$evidence/browser.json"
	exit 0
fi

# WordPress crop support needs a raster editor. Create two visibly distinct local PNGs through its GD extension.
"${wp[@]}" eval 'if (!function_exists("imagecreatetruecolor")) { throw new RuntimeException("WordPress image editor prerequisite missing: GD is unavailable."); } foreach ([["first", 210, 70, 70], ["second", 35, 100, 210]] as $spec) { [$name, $r, $g, $b] = $spec; $image=imagecreatetruecolor(960,720); imagefill($image,0,0,imagecolorallocate($image,$r,$g,$b)); imagefilledrectangle($image,160,120,800,600,imagecolorallocate($image,255-$r,255-$g,255-$b)); $file=wp_upload_dir()["path"]."/be-editor-".$name.".png"; imagepng($image,$file); imagedestroy($image); $id=wp_insert_attachment(["post_mime_type"=>"image/png","post_title"=>"BE editor ".$name,"post_status"=>"inherit"],$file); require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file)); echo $id."\n"; }' > "$work/attachment-ids.txt"
first_id="$(php -r '$a=preg_split("/\\s+/",trim(file_get_contents($argv[1]))); if(2!==count($a)||preg_match("/\\D/",implode("",$a))) exit(1); echo $a[0];' "$work/attachment-ids.txt")"
second_id="$(php -r '$a=preg_split("/\\s+/",trim(file_get_contents($argv[1]))); if(2!==count($a)||preg_match("/\\D/",implode("",$a))) exit(1); echo $a[1];' "$work/attachment-ids.txt")"
"${wp[@]}" eval-file wp-content/plugins/blocks-engine-php-transformer/tools/editor-image-acceptance-build-page.php "$first_id" "$second_id" | tee "$evidence/source-and-page.json"
post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["post_id"]??null)||$x["post_id"]<1) exit(1); echo $x["post_id"];' "$evidence/source-and-page.json")"
if command -v node >/dev/null; then
	compact_post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["compact_row_post_id"]??null)||$x["compact_row_post_id"]<1) exit(1); echo $x["compact_row_post_id"];' "$evidence/source-and-page.json")"
	BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_COMPACT_POST_ID="$compact_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/compact-row-editor-acceptance.mjs" | tee "$evidence/compact-row-browser.json"
	if [[ "${BE_EDITOR_SKIP_IMAGE:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/editor-image-acceptance.mjs" | tee "$evidence/browser.json"
	fi
else
	compact_post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["compact_row_post_id"]??null)||$x["compact_row_post_id"]<1) exit(1); echo $x["compact_row_post_id"];' "$evidence/source-and-page.json")"
	BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_COMPACT_POST_ID="$compact_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR=/evidence run docker run --rm --network host -v "${root}:/repo:ro" -v "${evidence}:/evidence" -e BE_EDITOR_WP_URL -e BE_EDITOR_COMPACT_POST_ID -e BE_EDITOR_USER -e BE_EDITOR_PASSWORD -e BE_EDITOR_EVIDENCE_DIR "$browser_image" node /repo/tests/compact-row-editor-acceptance.mjs | tee "$evidence/compact-row-browser.json"
	# A browser container keeps the runner usable on Docker-only Linux hosts.
	if [[ "${BE_EDITOR_SKIP_IMAGE:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR=/evidence run docker run --rm --network host -v "${root}:/repo:ro" -v "${evidence}:/evidence" -e BE_EDITOR_WP_URL -e BE_EDITOR_POST_ID -e BE_EDITOR_USER -e BE_EDITOR_PASSWORD -e BE_EDITOR_EVIDENCE_DIR "$browser_image" node /repo/tests/editor-image-acceptance.mjs | tee "$evidence/browser.json"
	fi
fi
printf 'Evidence retained at %s\n' "$evidence"

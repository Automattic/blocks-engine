#!/usr/bin/env bash
set -euo pipefail

# This runner owns a disposable Docker project and never addresses an existing site.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_root="${BE_EDITOR_PLUGIN_SOURCE:-$root}"
for command in docker curl php timeout sha256sum tar git; do command -v "$command" >/dev/null || { printf 'Missing %s\n' "$command" >&2; exit 2; }; done
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
responsive_mounts=()
if [[ -n "${BE_EDITOR_SSI_SOURCE:-}" ]]; then
	responsive_mounts=(-v "${BE_EDITOR_SSI_SOURCE}:/ssi:ro" -v "${BE_EDITOR_BASELINE_SOURCE:?Set baseline php-transformer path}:/baseline:ro")
fi
if [[ -n "$wordpress_core_archive" ]]; then
	wordpress_core_sha="a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b"
	actual_core_sha="$(sha256sum "$wordpress_core_archive" | cut -d ' ' -f 1)"
	test "$actual_core_sha" = "$wordpress_core_sha" || { printf 'WordPress core archive checksum mismatch: %s\n' "$actual_core_sha" >&2; exit 2; }
	wordpress_image="${BE_EDITOR_WORDPRESS_IMAGE:-wordpress:beta-php8.3-apache}"
	cli_image="${BE_EDITOR_CLI_IMAGE:-wordpress:cli-php8.3}"
	core_archive_mount=(-v "${wordpress_core_archive}:/tmp/wordpress71-core.tar.gz:ro")
fi

source_archive="${BE_EDITOR_SOURCE_ARCHIVE:-}"
ssi_plugin_dir="${BE_EDITOR_SSI_PLUGIN_DIR:-}"
source_archive_mount=()
ssi_plugin_mount=()
if [[ -n "$source_archive" ]]; then
	test -r "$source_archive" || { printf 'Captured source archive is unreadable: %s\n' "$source_archive" >&2; exit 2; }
	test -n "$wordpress_core_archive" || { printf 'Exact-source acceptance requires the verified WordPress 7.1 core archive.\n' >&2; exit 2; }
	test -n "$ssi_plugin_dir" && test -r "$ssi_plugin_dir/static-site-importer.php" && test -r "$ssi_plugin_dir/vendor/autoload.php" || { printf 'Set BE_EDITOR_SSI_PLUGIN_DIR to the clean, Composer-installed SSI companion-materializer checkout.\n' >&2; exit 2; }
	ssi_sha="$(git -C "$ssi_plugin_dir" rev-parse HEAD)"
	ssi_expected_sha="c46706f62cc2160afaee4d3faab551500a01d325"
	test "$ssi_sha" = "$ssi_expected_sha" || { printf 'SSI materializer must match accepted workflow revision %s; got %s\n' "$ssi_expected_sha" "$ssi_sha" >&2; exit 2; }
	source_archive_mount=(-v "${source_archive}:/tmp/blocks-engine-captured-source.json:ro")
	ssi_plugin_mount=(-v "${ssi_plugin_dir}:/var/www/html/wp-content/plugins/static-site-importer:ro")
fi

browser_image="${BE_EDITOR_BROWSER_IMAGE:-mcr.microsoft.com/playwright:v1.61.1-noble}"
db_host=mysql db_name=wordpress db_user=wordpress db_password=wordpress
cleanup() { docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true; docker logs "${project}_db" > "$evidence/mysql.log" 2>&1 || true; docker rm --force "${project}_wordpress" "${project}_db" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; docker volume rm "$volume" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
run() { timeout "${BE_EDITOR_COMMAND_TIMEOUT:-240}" "$@"; }
wait_for() { local label="$1" command="$2" attempt; for attempt in $(seq 1 60); do if eval "$command"; then return 0; fi; sleep 2; done; printf 'Timed out waiting for %s after 120 seconds.\n' "$label" >&2; return 1; }

run docker network create "$network" >/dev/null
run docker volume create "$volume" >/dev/null
run docker run --detach --name "${project}_db" --network "$network" --network-alias "$db_host" -e MYSQL_DATABASE="$db_name" -e MYSQL_USER="$db_user" -e MYSQL_PASSWORD="$db_password" -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 --innodb-use-native-aio=0 >/dev/null
wait_for 'MySQL' "run docker exec ${project}_db mysqladmin ping -u${db_user} -p${db_password}"
run docker run --detach --name "${project}_wordpress" --network "$network" --publish "127.0.0.1:${port}:80" -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${plugin_root}:/var/www/html/wp-content/plugins/blocks-engine-php-transformer:ro" "${core_archive_mount[@]}" "${ssi_plugin_mount[@]}" "$wordpress_image" >/dev/null
wp=(run docker run --rm --network "$network" --user 33:33 -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${plugin_root}:/var/www/html/wp-content/plugins/blocks-engine-php-transformer:ro" -v "${root}/tools:/var/www/html/wp-content/plugins/blocks-engine-php-transformer/tools:ro" -v "${work}:/work" "${core_archive_mount[@]}" "${source_archive_mount[@]}" "${ssi_plugin_mount[@]}" "${responsive_mounts[@]}" "$cli_image" wp --allow-root)

if [[ -n "$wordpress_core_archive" ]]; then
	wait_for 'WordPress base files' "docker exec ${project}_wordpress test -f /var/www/html/wp-includes/version.php"
	# Use the checksum-pinned core source without spoofing WP_VERSION.
	run docker exec "${project}_wordpress" sh -c 'tar --overwrite -xzf /tmp/wordpress71-core.tar.gz -C /var/www/html 2>/dev/null && chown -R www-data:www-data /var/www/html/wp-admin /var/www/html/wp-includes'
	run docker exec "${project}_wordpress" php -r 'require "/var/www/html/wp-includes/version.php"; require "/var/www/html/wp-includes/icons.php"; printf("core=%s default_icon_callback=%s icons_sha256=%s wp_settings_sha256=%s\\n", $wp_version, function_exists("_wp_register_default_icon_collections") ? "loaded" : "missing", hash_file("sha256", "/var/www/html/wp-includes/icons.php"), hash_file("sha256", "/var/www/html/wp-settings.php")); if ("7.1" !== $wp_version) exit(1);' | tee "$evidence/core-overlay-preflight.txt"
fi
wait_for 'WordPress login' "curl --silent --fail http://127.0.0.1:${port}/wp-login.php >/dev/null"
wait_for 'WordPress core files' "docker exec ${project}_wordpress test -f /var/www/html/wp-includes/version.php"
"${wp[@]}" core version | tee "$evidence/wordpress-version.txt"
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Blocks Engine editor acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" plugin activate blocks-engine-php-transformer

# Other block acceptance fixtures share this disposable runtime lifecycle.
if [[ -n "${BE_EDITOR_ACCEPTANCE_BUILDER:-}" ]]; then
	builder_args=()
	if [[ -n "${BE_EDITOR_ACCEPTANCE_INPUT:-}" ]]; then builder_args+=("${BE_EDITOR_ACCEPTANCE_INPUT}"); fi
	"${wp[@]}" eval-file "wp-content/plugins/blocks-engine-php-transformer/${BE_EDITOR_ACCEPTANCE_BUILDER}" "${builder_args[@]}" | tee "$evidence/source-and-page.json"
	post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_int($x["post_id"]??null)||$x["post_id"]<1) exit(1); echo $x["post_id"];' "$evidence/source-and-page.json")"
	BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/${BE_EDITOR_ACCEPTANCE_BROWSER:?Set the corresponding browser acceptance script.}" | tee "$evidence/browser.json"
	exit 0
fi

# WordPress crop support needs a raster editor. Create two visibly distinct local PNGs through its GD extension.
"${wp[@]}" eval 'if (!function_exists("imagecreatetruecolor")) { throw new RuntimeException("WordPress image editor prerequisite missing: GD is unavailable."); } foreach ([["first", 210, 70, 70], ["second", 35, 100, 210]] as $spec) { [$name, $r, $g, $b] = $spec; $image=imagecreatetruecolor(960,720); imagefill($image,0,0,imagecolorallocate($image,$r,$g,$b)); imagefilledrectangle($image,160,120,800,600,imagecolorallocate($image,255-$r,255-$g,255-$b)); $file=wp_upload_dir()["path"]."/be-editor-".$name.".png"; imagepng($image,$file); imagedestroy($image); $id=wp_insert_attachment(["post_mime_type"=>"image/png","post_title"=>"BE editor ".$name,"post_status"=>"inherit"],$file); require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file)); echo $id."\n"; }' > "$work/attachment-ids.txt"
first_id="$(php -r '$a=preg_split("/\\s+/",trim(file_get_contents($argv[1]))); if(2!==count($a)||preg_match("/\\D/",implode("",$a))) exit(1); echo $a[0];' "$work/attachment-ids.txt")"
second_id="$(php -r '$a=preg_split("/\\s+/",trim(file_get_contents($argv[1]))); if(2!==count($a)||preg_match("/\\D/",implode("",$a))) exit(1); echo $a[1];' "$work/attachment-ids.txt")"
"${wp[@]}" eval-file wp-content/plugins/blocks-engine-php-transformer/tools/editor-image-acceptance-build-page.php "$first_id" "$second_id" | tee "$evidence/source-and-page.json"
post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["image"]["post_id"]??null)||$x["image"]["post_id"]<1) exit(1); echo $x["image"]["post_id"];' "$evidence/source-and-page.json")"
compact_post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["compact_row_post_id"]??null)||$x["compact_row_post_id"]<1) exit(1); echo $x["compact_row_post_id"];' "$evidence/source-and-page.json")"
synthetic_home_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["listing"]["post_id"]??null)||$x["listing"]["post_id"]<1) exit(1); echo $x["listing"]["post_id"];' "$evidence/source-and-page.json")"
listing_post_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); if(!is_array($x)||!is_int($x["listing"]["neutral_post_id"]??null)||$x["listing"]["neutral_post_id"]<1) exit(1); echo $x["listing"]["neutral_post_id"];' "$evidence/source-and-page.json")"
if [[ -n "$source_archive" ]]; then
	"${wp[@]}" eval-file wp-content/plugins/blocks-engine-php-transformer/tools/editor-image-acceptance-import-captured-source.php /tmp/blocks-engine-captured-source.json "$synthetic_home_id" | tee "$evidence/captured-source-wordpress.json"
	"${wp[@]}" plugin activate static-site-importer
	printf 'SSI companion materializer revision: %s\n' "$ssi_sha" | tee "$evidence/ssi-companion-materializer.txt"
	"${wp[@]}" eval-file wp-content/plugins/blocks-engine-php-transformer/tools/editor-image-acceptance-materialize-companion.php /var/www/html/wp-content/uploads/blocks-engine-captured-source-companion.json | tee "$evidence/captured-source-companion-materialization.json"
fi

if [[ -n "${BE_EDITOR_SSI_SOURCE:-}" ]]; then
	"${wp[@]}" eval-file wp-content/plugins/blocks-engine-php-transformer/tools/responsive-image-acceptance-build-page.php "$second_id" | tee "$evidence/responsive-source-and-pages.json"
	BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/responsive-image-acceptance.mjs" | tee "$evidence/responsive-browser.json"
	initial_theme="$(php -r '$x=json_decode(file_get_contents($argv[1]),true); echo $x["initial_theme"];' "$evidence/responsive-source-and-pages.json")"
	"${wp[@]}" theme activate "$initial_theme"
fi
if command -v node >/dev/null; then
	if [[ "${BE_EDITOR_SKIP_COMPACT_ROW:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_COMPACT_POST_ID="$compact_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/compact-row-editor-acceptance.mjs" | tee "$evidence/compact-row-browser.json"
	fi
	if [[ "${BE_EDITOR_SKIP_IMAGE:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_LISTING_POST_ID="$listing_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/editor-image-acceptance.mjs" | tee "$evidence/browser.json"
	fi
else
	# A browser container keeps the runner usable on Docker-only Linux hosts.
	if [[ "${BE_EDITOR_SKIP_COMPACT_ROW:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_COMPACT_POST_ID="$compact_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR=/evidence run docker run --rm --network host -v "${root}:/repo:ro" -v "${evidence}:/evidence" -e BE_EDITOR_WP_URL -e BE_EDITOR_COMPACT_POST_ID -e BE_EDITOR_USER -e BE_EDITOR_PASSWORD -e BE_EDITOR_EVIDENCE_DIR "$browser_image" node /repo/tests/compact-row-editor-acceptance.mjs | tee "$evidence/compact-row-browser.json"
	fi
	if [[ "${BE_EDITOR_SKIP_IMAGE:-0}" != 1 ]]; then
		BE_EDITOR_WP_URL="http://127.0.0.1:${port}" BE_EDITOR_POST_ID="$post_id" BE_EDITOR_LISTING_POST_ID="$listing_post_id" BE_EDITOR_USER=admin BE_EDITOR_PASSWORD=password BE_EDITOR_EVIDENCE_DIR=/evidence run docker run --rm --network host -v "${root}:/repo:ro" -v "${evidence}:/evidence" -e BE_EDITOR_WP_URL -e BE_EDITOR_POST_ID -e BE_EDITOR_LISTING_POST_ID -e BE_EDITOR_USER -e BE_EDITOR_PASSWORD -e BE_EDITOR_EVIDENCE_DIR "$browser_image" node /repo/tests/editor-image-acceptance.mjs | tee "$evidence/browser.json"
	fi
fi
printf 'Evidence retained at %s\n' "$evidence"

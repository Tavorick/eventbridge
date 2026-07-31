#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-7.0.2}"

if [[ ! "$WP_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
	echo "Unsupported WordPress version: $WP_VERSION" >&2
	exit 1
fi
if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "Unsafe test database name: $DB_NAME" >&2
	exit 1
fi

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress-${WP_VERSION}}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-${WP_VERSION}}"

if [[ ! -f "${WP_CORE_DIR}/wp-settings.php" ]]; then
	rm -rf "${WP_CORE_DIR}"
	mkdir -p "${WP_CORE_DIR}"
	curl --fail --location --retry 3 --proto '=https' "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "/tmp/wordpress-${WP_VERSION}.tar.gz"
	tar -xzf "/tmp/wordpress-${WP_VERSION}.tar.gz" --strip-components=1 -C "${WP_CORE_DIR}"
fi

if [[ ! -f "${WP_TESTS_DIR}/includes/functions.php" ]]; then
	rm -rf "${WP_TESTS_DIR}"
	svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit" "${WP_TESTS_DIR}"
fi

curl --fail --silent --show-error --proto '=https' \
	"https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php" \
	-o "${WP_TESTS_DIR}/wp-tests-config-sample.php"

cp "${WP_TESTS_DIR}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "${WP_TESTS_DIR}/wp-tests-config.php"

mysqladmin --host="${DB_HOST}" --user="${DB_USER}" --password="${DB_PASS}" --force drop "${DB_NAME}" >/dev/null 2>&1 || true
mysqladmin --host="${DB_HOST}" --user="${DB_USER}" --password="${DB_PASS}" create "${DB_NAME}"

printf '%s\n' "${WP_TESTS_DIR}"

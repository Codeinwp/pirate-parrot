#!/usr/bin/env bash
# Installs WordPress core and the core test suite for phpunit.
# usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# svn-free take on the classic wp-cli scaffold script: core comes from
# wordpress.org, the test suite from the wordpress-develop GitHub tarball.

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | php -r 'echo json_decode(stream_get_contents(STDIN))->offers[0]->version;')
fi
echo "Using WordPress $WP_VERSION"

install_core() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar -xz -C "$WP_CORE_DIR" --strip-components=1
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR/includes" ]; then
		return
	fi
	local workdir
	workdir=$(mktemp -d)
	curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.tar.gz" | tar -xz -C "$workdir" --strip-components=1
	mkdir -p "$WP_TESTS_DIR"
	cp -r "$workdir/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	cp -r "$workdir/tests/phpunit/data" "$WP_TESTS_DIR/data"

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		cp "$workdir/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
		rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
	fi
	rm -rf "$workdir"
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	local HOST=${DB_HOST%%:*}
	local PORT=${DB_HOST#*:}
	local EXTRA="--host=$HOST --protocol=tcp"
	if [ "$PORT" != "$DB_HOST" ]; then
		EXTRA="$EXTRA --port=$PORT"
	fi
	# tolerate an already-existing database so re-runs work
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" $EXTRA || true
}

install_core
install_test_suite
install_db

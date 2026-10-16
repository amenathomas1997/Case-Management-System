#!/bin/bash
# Drop and recreate one test database on the CI MariaDB service, so each database
# suite starts from an empty schema. CI only: it talks to the service container
# named in CMS_TEST_HOST and nothing else.
#   bash .github/ci/fresh-db.sh cms_audit_test
set -euo pipefail
name=${1:?database name}
case "$name" in cms_audit_test|cms_installer_e2e) ;; *) echo "refusing to recreate '$name'" >&2; exit 2 ;; esac
php -r '
$p = new PDO("mysql:host=" . $argv[1] . ";charset=utf8mb4", $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->exec("DROP DATABASE IF EXISTS `" . $argv[4] . "`");
$p->exec("CREATE DATABASE `" . $argv[4] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
echo "Fresh empty database " . $argv[4] . "\n";
' "${CMS_TEST_HOST:-127.0.0.1}" "${CMS_TEST_USER:-root}" "${CMS_TEST_PASSWORD:-}" "$name"

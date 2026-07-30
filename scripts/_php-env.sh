#!/usr/bin/env bash
# Shared resolver for the project's pinned PHP. Sourced by the wrappers.
#
# Homebrew's composer and wp-cli formulae depend on the unversioned `php`
# formula, which tracks the newest PHP release. That puts a newer PHP on PATH
# than this project targets, so tooling run without these wrappers does not
# match CI or production. See docs/operations/local-development.md.

set -euo pipefail

readonly EC_PHP_VERSION="8.3"

_ec_brew_prefix() { command -v brew >/dev/null 2>&1 && brew --prefix || echo "/opt/homebrew"; }

EC_PHP_BIN="$(_ec_brew_prefix)/opt/php@${EC_PHP_VERSION}/bin/php"

if [[ ! -x "${EC_PHP_BIN}" ]]; then
	printf 'error: PHP %s not found at %s\n' "${EC_PHP_VERSION}" "${EC_PHP_BIN}" >&2
	printf 'Run ./scripts/bootstrap-local.sh to install it.\n' >&2
	exit 1
fi

export EC_PHP_BIN EC_PHP_VERSION

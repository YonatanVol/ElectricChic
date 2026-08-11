#!/usr/bin/env bash
# Shared resolver for the project's pinned PHP. Sourced by the wrappers.
#
# Homebrew's composer and wp-cli formulae depend on the unversioned `php`
# formula, which tracks the newest PHP release. That puts a newer PHP on PATH
# than this project targets, so tooling run without these wrappers does not
# match CI or production. See docs/operations/local-development.md.
#
# Resolution searches known locations for the binary rather than asking
# `brew --prefix`. This machine has TWO Homebrew installations — /usr/local
# (Intel) and /opt/homebrew (Apple Silicon) — so `brew --prefix` returns
# whichever brew happens to be first on PATH, which is not necessarily the one
# holding php@8.3. Trusting it produced a "PHP 8.3 not found" failure whose
# outcome depended on shell environment rather than on what was installed.

set -euo pipefail

readonly EC_PHP_VERSION="8.3"

_ec_find_php() {
	local candidates=()

	# Whatever prefix the current brew reports, tried first.
	if command -v brew >/dev/null 2>&1; then
		local brew_prefix
		brew_prefix="$(brew --prefix 2>/dev/null || true)"
		[[ -n "${brew_prefix}" ]] && candidates+=( "${brew_prefix}/opt/php@${EC_PHP_VERSION}/bin/php" )
	fi

	# Then every location a Homebrew PHP is known to live.
	candidates+=(
		"/opt/homebrew/opt/php@${EC_PHP_VERSION}/bin/php"
		"/usr/local/opt/php@${EC_PHP_VERSION}/bin/php"
		"/home/linuxbrew/.linuxbrew/opt/php@${EC_PHP_VERSION}/bin/php"
	)

	local candidate
	for candidate in "${candidates[@]}"; do
		if [[ -x "${candidate}" ]]; then
			printf '%s' "${candidate}"
			return 0
		fi
	done

	# Last resort: a php already on PATH, but only if it is the right version.
	# Silently accepting the wrong one is the failure mode this whole file exists
	# to prevent.
	if command -v php >/dev/null 2>&1; then
		local on_path_version
		on_path_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
		if [[ "${on_path_version}" == "${EC_PHP_VERSION}" ]]; then
			command -v php
			return 0
		fi
	fi

	return 1
}

if ! EC_PHP_BIN="$(_ec_find_php)"; then
	printf 'error: PHP %s not found.\n' "${EC_PHP_VERSION}" >&2
	printf '  Searched every Homebrew prefix and PATH.\n' >&2
	printf '  Install it with: brew install php@%s\n' "${EC_PHP_VERSION}" >&2
	printf '  Or run: ./scripts/bootstrap-local.sh\n' >&2
	exit 1
fi

export EC_PHP_BIN EC_PHP_VERSION

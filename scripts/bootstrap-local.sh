#!/usr/bin/env bash
#
# bootstrap-local.sh — set up the ElectricChic local development environment.
#
# Idempotent: safe to run repeatedly. Installs only what is missing, then
# verifies the result. Run it after cloning, and again whenever you suspect
# your environment has drifted.
#
#   ./scripts/bootstrap-local.sh          install and verify
#   ./scripts/bootstrap-local.sh --check  verify only, install nothing
#
# See docs/operations/local-development.md for the reasoning behind the
# version pinning and the wrapper scripts.

set -euo pipefail

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------

# The PHP version this project develops against. CI additionally tests 8.2,
# which is the supported floor. Keep this in step with the CI matrix and with
# the production host's PHP version.
readonly PHP_VERSION="8.3"
readonly PHP_FORMULA="php@${PHP_VERSION}"

readonly REQUIRED_FORMULAE=("${PHP_FORMULA}" "composer" "wp-cli")
readonly REQUIRED_CASKS=("local")

CHECK_ONLY=0
[[ "${1:-}" == "--check" ]] && CHECK_ONLY=1

# -----------------------------------------------------------------------------
# Output helpers
# -----------------------------------------------------------------------------

if [[ -t 1 ]]; then
	readonly C_RESET=$'\033[0m' C_BOLD=$'\033[1m' C_DIM=$'\033[2m'
	readonly C_GREEN=$'\033[32m' C_YELLOW=$'\033[33m' C_RED=$'\033[31m' C_BLUE=$'\033[34m'
else
	readonly C_RESET='' C_BOLD='' C_DIM='' C_GREEN='' C_YELLOW='' C_RED='' C_BLUE=''
fi

step() { printf '\n%s==>%s %s%s%s\n' "${C_BLUE}" "${C_RESET}" "${C_BOLD}" "$1" "${C_RESET}"; }
ok()   { printf '  %s✓%s %s\n' "${C_GREEN}" "${C_RESET}" "$1"; }
warn() { printf '  %s!%s %s\n' "${C_YELLOW}" "${C_RESET}" "$1"; }
fail() { printf '  %s✗%s %s\n' "${C_RED}" "${C_RESET}" "$1" >&2; }
note() { printf '    %s%s%s\n' "${C_DIM}" "$1" "${C_RESET}"; }

die() { fail "$1"; exit 1; }

ERRORS=0
record_error() { ERRORS=$((ERRORS + 1)); fail "$1"; }

# -----------------------------------------------------------------------------
# Preconditions
# -----------------------------------------------------------------------------

step "Checking preconditions"

[[ "$(uname -s)" == "Darwin" ]] \
	|| die "This script targets macOS. On another OS, install ${PHP_FORMULA}, Composer, WP-CLI and a local WordPress stack by hand, then re-run with --check."
ok "macOS $(sw_vers -productVersion) ($(uname -m))"

command -v brew >/dev/null 2>&1 \
	|| die "Homebrew is not installed. Install it from https://brew.sh and re-run."
ok "Homebrew $(brew --version | head -1 | awk '{print $2}')"

readonly BREW_PREFIX="$(brew --prefix)"

# Disk space: Local plus a WordPress site wants roughly 2–3 GB.
AVAIL_GB="$(df -g / | tail -1 | awk '{print $4}')"
if (( AVAIL_GB < 5 )); then
	warn "Only ${AVAIL_GB} GB free. Local plus a WordPress site needs roughly 2–3 GB."
	note "Consider freeing space before creating a site in Local."
else
	ok "${AVAIL_GB} GB disk available"
fi

# -----------------------------------------------------------------------------
# Install
# -----------------------------------------------------------------------------

install_formula() {
	local formula="$1"
	if brew list --formula --versions "${formula}" >/dev/null 2>&1; then
		ok "${formula} present"
		return
	fi
	if (( CHECK_ONLY )); then
		record_error "${formula} missing (run without --check to install)"
		return
	fi
	step "Installing ${formula}"
	brew install "${formula}"
	ok "${formula} installed"
}

install_cask() {
	local cask="$1"
	if brew list --cask --versions "${cask}" >/dev/null 2>&1; then
		ok "${cask} present"
		return
	fi
	if (( CHECK_ONLY )); then
		record_error "${cask} missing (run without --check to install)"
		return
	fi
	step "Installing ${cask} (large download)"
	brew install --cask "${cask}"
	ok "${cask} installed"
}

step "Command-line tooling"
for formula in "${REQUIRED_FORMULAE[@]}"; do
	install_formula "${formula}"
done

step "Local WordPress environment"
for cask in "${REQUIRED_CASKS[@]}"; do
	install_cask "${cask}"
done

# -----------------------------------------------------------------------------
# Verify
# -----------------------------------------------------------------------------

step "Verifying toolchain"

readonly PHP_BIN="${BREW_PREFIX}/opt/${PHP_FORMULA}/bin/php"

if [[ -x "${PHP_BIN}" ]]; then
	ok "PHP $("${PHP_BIN}" -r 'echo PHP_VERSION;') at ${PHP_BIN}"
else
	record_error "${PHP_FORMULA} binary not found at ${PHP_BIN}"
fi

if [[ -x "${BREW_PREFIX}/bin/composer" ]]; then
	ok "$("${PHP_BIN}" "${BREW_PREFIX}/bin/composer" --version --no-ansi 2>/dev/null | head -1)"
else
	record_error "composer not found"
fi

if [[ -x "${BREW_PREFIX}/bin/wp" ]]; then
	ok "$("${PHP_BIN}" "${BREW_PREFIX}/bin/wp" --version 2>/dev/null | head -1)"
else
	record_error "wp-cli not found"
fi

if [[ -d "/Applications/Local.app" ]]; then
	ok "Local.app installed"
else
	record_error "Local.app not found in /Applications"
fi

# -----------------------------------------------------------------------------
# PHP version drift — the trap this project has already hit once
# -----------------------------------------------------------------------------

step "Checking for PHP version drift"

# Homebrew's `composer` and `wp-cli` formulae depend on the unversioned `php`
# formula, which tracks the newest release. So installing them silently puts a
# NEWER PHP on your PATH than the one this project targets. Tooling run against
# it produces results that do not match CI or production.
#
# The fix is the scripts/ wrappers, not a change to your shell profile — a
# project should not reconfigure a developer's machine.

if command -v php >/dev/null 2>&1; then
	DEFAULT_PHP="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"
	if [[ "${DEFAULT_PHP}" == "${PHP_VERSION}."* ]]; then
		ok "Default php on PATH is ${DEFAULT_PHP} — matches the project target"
	else
		warn "Default php on PATH is ${DEFAULT_PHP}, project targets ${PHP_VERSION}.x"
		note "This is expected — Homebrew's composer and wp-cli pull in the newest PHP."
		note "Use the project wrappers so tooling runs on ${PHP_VERSION}:"
		note "  ./scripts/php  ./scripts/composer  ./scripts/wp"
		note "Do not 'fix' this by putting ${PHP_FORMULA} first on your global PATH;"
		note "that changes behaviour for every other project on this machine."
	fi
else
	warn "No php on PATH at all. The wrappers in scripts/ will still work."
fi

# -----------------------------------------------------------------------------
# Result
# -----------------------------------------------------------------------------

if (( ERRORS > 0 )); then
	printf '\n%s✗ %d problem(s) found.%s\n' "${C_RED}" "${ERRORS}" "${C_RESET}" >&2
	exit 1
fi

printf '\n%s✓ Local environment ready.%s\n' "${C_GREEN}" "${C_RESET}"

cat <<EOF

Run project tooling through the wrappers so it uses PHP ${PHP_VERSION}:

  ./scripts/php --version
  ./scripts/composer install
  ./scripts/wp --info

Next: Issue #08 installs WordPress and WooCommerce into a Local site.
Open Local.app, create a site, then follow docs/operations/local-development.md.
EOF

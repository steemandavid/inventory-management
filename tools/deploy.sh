#!/usr/bin/env bash
# Deploy web/ to https://www.steeman.be/inventory/ over FTPS, then run the security checks
# of FSD §9.6. FTP credentials come from ~/.netrc (machine ftp.steeman.be).
#
#   tools/deploy.sh                 upload app files + run checks
#   tools/deploy.sh --check         only run the checks
#   tools/deploy.sh --config FILE   also upload FILE as config.php (first deploy, or secret change)
#   tools/deploy.sh --setup         also upload setup.php + setup-seed.json (first deploy only)
#
# Never uploaded unless asked: config.php, setup.php, setup-seed.json. lftp mirror hangs on
# this hosting, so files go one by one with curl.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"

FTP="ftp://ftp.steeman.be/inventory"
SITE="https://www.steeman.be/inventory"
CURL_FTP=(curl -s --netrc --ftp-ssl --insecure --ftp-create-dirs --connect-timeout 20 --retry 2)

check_only=0; config=""; setup=0
while [ $# -gt 0 ]; do
  case "$1" in
    --check) check_only=1 ;;
    --config) config="$(realpath "$2")"; shift ;;
    --setup) setup=1 ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
  shift
done
cd "$here/../web"

upload() {  # local path relative to web/, remote path relative to /inventory/
  local code
  code=$("${CURL_FTP[@]}" -T "$1" "$FTP/$2" -w '%{http_code}')
  if [ "$code" != "226" ]; then echo "UPLOAD FAILED ($code): $2" >&2; exit 1; fi
  echo "  up  $2"
}

if [ $check_only -eq 0 ]; then
  echo "== uploading"
  # .htaccess files first, so protection is in place before anything it protects arrives.
  for f in $(find . -name .htaccess | sort); do upload "$f" "${f#./}"; done
  while IFS= read -r f; do
    upload "$f" "${f#./}"
  done < <(find . -type f ! -name .htaccess ! -name config.php ! -name setup.php ! -name setup-seed.json \
             ! -path './_backups/*' | sort)
  if [ -n "$config" ]; then upload "$config" config.php; fi
  if [ $setup -eq 1 ]; then
    upload setup.php setup.php
    [ -f setup-seed.json ] && upload setup-seed.json setup-seed.json
  fi
fi

echo "== checks against $SITE"
fail=0
ok()  { echo "  ok    $1"; }
bad() { echo "  FAIL  $1"; fail=1; }
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT

# 1. plain HTTP must redirect to HTTPS
loc=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://www.steeman.be/inventory/")
case "$loc" in 301\ https://*|302\ https://*) ok "http redirects to https" ;; *) bad "http not redirected: $loc" ;; esac

# 2. public page works, sends the security headers, leaks no source
code=$(curl -s -D "$tmp/h" -o "$tmp/b" -w '%{http_code}' "$SITE/")
[ "$code" = 200 ] && grep -q 'Inventory management' "$tmp/b" && ok "public page 200" || bad "public page: $code"
for h in content-security-policy strict-transport-security x-robots-tag x-content-type-options x-frame-options; do
  grep -qi "^$h:" "$tmp/h" && ok "header $h" || bad "missing header $h"
done
grep -qi '^set-cookie:' "$tmp/h" && bad "public page sets a cookie" || ok "public page sets no cookie"

# 3. nothing sensitive is served
for p in config.php config.example.php setup-seed.json lib/ lib/bootstrap.php lib/auth.php sql/schema.sql \
         _backups/ .htaccess admin/.htaccess; do
  code=$(curl -s -o "$tmp/b" -w '%{http_code}' "$SITE/$p")
  if [ "$code" != 403 ] && [ "$code" != 404 ]; then bad "$p served ($code)"; else ok "$p blocked ($code)"; fi
done
for p in "" admin/login.php api/search.php config.php lib/bootstrap.php; do
  curl -s "$SITE/$p" | grep -qE '<\?php|db_password|pepper' && bad "source/secret visible in /$p" || true
done
ok "no PHP source or secrets in responses"

# 4. setup.php must be gone after the first setup
code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/setup.php")
[ "$code" = 404 ] && ok "setup.php not reachable" || echo "  WARN  setup.php answers $code: delete it once setup is done"

# 5. admin needs login and is never cached; search not cached by the edge
code=$(curl -s -D "$tmp/h" -o /dev/null -w '%{http_code}' "$SITE/admin/")
[ "$code" = 303 ] && grep -qi '^cache-control:.*no-store' "$tmp/h" && ok "admin redirects to login, no-store" \
  || bad "admin: $code"
curl -s -D "$tmp/h" -o /dev/null "$SITE/api/search.php?q=probe$RANDOM"
grep -qi '^x-cache-status: *HIT' "$tmp/h" && bad "search answered from edge cache" || ok "search not edge-cached"

echo
[ $fail -eq 0 ] && echo "ALL CHECKS PASSED" || { echo "CHECKS FAILED"; exit 1; }

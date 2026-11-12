#!/bin/sh
# Turn docs/server/nginx-subjectsplus.conf into a plain-HTTP site for CI / local testing:
#   tests/ci/nginx-ci-conf.sh ROOT PHP_FPM_SOCKET [PORT] > /etc/nginx/sites-enabled/subjectsplus
# (drops the HTTP→HTTPS redirect server, TLS lines and HSTS; everything else — the protection rules — is kept)
set -e
root=${1:?web root}; sock=${2:?php-fpm socket}; port=${3:-80}
conf="$(dirname "$0")/../../docs/server/nginx-subjectsplus.conf"
awk -v root="$root" -v sock="$sock" -v port="$port" '
  BEGIN { skip = 0; n = 0 }
  /^server \{/ { n++; if (n == 1) { skip = 1; next } }
  skip == 1 { if ($0 ~ /^\}/) { skip = 0 } ; next }
  /listen 443 ssl;/        { print "    listen " port " default_server;"; next }
  /listen \[::\]:443 ssl;/ { next }
  /http2 on;/              { next }
  /ssl_certificate|ssl_protocols/ { next }
  /Strict-Transport-Security/     { next }
  /server_name /           { print "    server_name _;"; next }
  { sub(/root  \/var\/www\/subjectsplus;/, "root  " root ";"); gsub(/unix:\/run\/php\/php8\.[0-9]-fpm\.sock/, "unix:" sock); print }
' "$conf"

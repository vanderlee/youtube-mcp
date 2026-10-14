#!/bin/sh
set -eu

response=$(SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000)
printf '%s\n' "$response" | grep -q '^pong'

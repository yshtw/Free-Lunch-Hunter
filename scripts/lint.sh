#!/usr/bin/env sh
set -eu
find app final_project scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check final_project/assets/app.js

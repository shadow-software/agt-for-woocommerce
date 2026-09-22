#!/usr/bin/env bash
# Strip OpenAPI-generator / SDK dev artifacts from vendor/ before wp.org upload.
# WordPress.org rejects plugins containing git_push.sh and similar files.
set -euo pipefail

root="${1:?staging root directory}"

if [ ! -d "${root}/vendor" ]; then
  exit 0
fi

find "${root}/vendor" -type f \( \
  -name 'git_push.sh' \
  -o -name '.travis.yml' \
  -o -name 'phpunit.xml.dist' \
  -o -name '.php-cs-fixer.dist.php' \
  -o -name '.openapi-generator-ignore' \
  \) -delete

find "${root}/vendor" -type d \( -name test -o -name tests -o -name '.github' \) -exec rm -rf {} + 2>/dev/null || true

if find "${root}" -name 'git_push.sh' | grep -q .; then
  echo "git_push.sh still present after prune" >&2
  find "${root}" -name 'git_push.sh' >&2
  exit 1
fi

#!/bin/bash
# Автодеплой romanovbyuro.ru: забирает main с GitHub и выкладывает в public_html (запускается cron'ом Beget раз в минуту)
set -e
SITE=$HOME/romanovbyuro.ru
R=$SITE/repo
[ -d "$R/.git" ] || git clone -q https://github.com/tessera321/romanov-buro.git "$R"
cd "$R"
git fetch -q origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ] || [ ! -f "$SITE/public_html/index.html" ]; then
  git reset -q --hard origin/main
  rsync -a --delete --exclude .git --exclude .github --exclude deploy ./ "$SITE/public_html/"
  echo "$(date '+%F %T') $(git rev-parse --short HEAD)" >> "$HOME/deploy-romanov.log"
fi

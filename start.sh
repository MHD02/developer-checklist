#!/usr/bin/env bash
cd "$(dirname "$0")"
echo "Local Checklist Studio running at:"
echo "http://127.0.0.1:8088"
echo ""
echo "Press CTRL+C to stop."
php -S 127.0.0.1:8088 -t public
# chmod +x start.sh
# ./start.sh
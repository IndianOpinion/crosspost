#!/bin/sh
# Run the crosspost queue from a system cron instead of WP-Cron.
# Example crontab line (every two minutes):
#   */2 * * * * /path/to/system-cron.sh /var/www/example
set -eu
cd "${1:?usage: system-cron.sh /path/to/wordpress}"
exec wp crosspost run --quiet

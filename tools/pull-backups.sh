#!/usr/bin/env bash
# Nightly on john-ai (cron 23:30, before the 00:00 Borg run): copy the inventory backups
# from the Pi into /home/john/backups/inventory/ (in the Borg System repo, FSD §10.2).
# No --delete: john-ai keeps every set it ever received, even if the Pi's SD card dies.
# The key only allows read-only rsync of /var/lib/inventory-backups on the Pi (rrsync -ro).
set -euo pipefail
DEST=/home/john/backups/inventory
mkdir -p "$DEST"
rsync -a --exclude='.*.tmp' -e "ssh -i $HOME/.ssh/inventory_backup_ed25519 -o BatchMode=yes -o ConnectTimeout=20" \
  john@192.168.1.20: "$DEST/"
date '+%Y-%m-%d %H:%M:%S pulled OK' > "$DEST/.last-pull"

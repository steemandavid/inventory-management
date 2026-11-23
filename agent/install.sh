#!/usr/bin/env bash
# Install or update the print agent on the Pi. Run from john-ai:
#   agent/install.sh [user@host]          (default john@192.168.1.20)
# The token is set separately: create it in the web admin (Settings > Print agent) and run
#   ssh john@192.168.1.20 'sudo install -m 640 -o root -g invagent /dev/stdin /etc/inventory-agent/token && sudo systemctl restart inventory-agent' <<< TOKEN
set -euo pipefail
HOST="${1:-john@192.168.1.20}"
cd "$(dirname "$0")"
ssh "$HOST" 'mkdir -p ~/inventory-agent-src'
scp -q print_agent.py label_render.py systemd/inventory-agent.service "$HOST":inventory-agent-src/
ssh "$HOST" 'set -e
  id invagent >/dev/null 2>&1 || sudo useradd --system --no-create-home --shell /usr/sbin/nologin invagent
  sudo install -d -m 755 /opt/inventory-agent
  sudo install -m 644 ~/inventory-agent-src/print_agent.py ~/inventory-agent-src/label_render.py /opt/inventory-agent/
  sudo install -d -m 750 -o root -g invagent /etc/inventory-agent
  [ -f /etc/inventory-agent/agent.conf ] || printf "API_URL=https://www.steeman.be/inventory/api/agent.php\nPRINTER=LabelWriter-450\nMEDIA=w36h144\nPOLL_SECONDS=5\n" | sudo install -m 640 -o root -g invagent /dev/stdin /etc/inventory-agent/agent.conf
  sudo install -m 644 ~/inventory-agent-src/inventory-agent.service /etc/systemd/system/
  sudo systemctl daemon-reload
  sudo systemctl enable inventory-agent >/dev/null 2>&1
  if [ -f /etc/inventory-agent/token ]; then sudo systemctl restart inventory-agent; echo "agent (re)started"; else echo "no token yet: agent not started"; fi'

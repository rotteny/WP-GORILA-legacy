#!/usr/bin/env bash
# Smoke test da conexao adb.
set -euo pipefail

echo ">> adb version"
adb version

echo ">> adb devices"
adb devices

echo ">> boot_completed em cada device"
for id in $(adb devices | awk 'NR>1 && $2=="device" {print $1}'); do
  echo "  $id: $(adb -s "$id" shell getprop sys.boot_completed)"
done

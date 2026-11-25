#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

echo "=== FINCAISSE — Démarrage local ==="

# 1. Compilation du moteur de calcul C++20 si absent ou modifié
if [[ ! -x "$ROOT_DIR/bin/fincaisse_engine" ]] || [[ "$ROOT_DIR/cpp/fincaisse_engine.cpp" -nt "$ROOT_DIR/bin/fincaisse_engine" ]]; then
  echo "[1/3] Compilation du moteur financier C++20..."
  cmake -S . -B build >/dev/null
  cmake --build build >/dev/null
  echo "      Moteur C++20 prêt : bin/fincaisse_engine"
else
  echo "[1/3] Moteur C++20 déjà compilé."
fi

# 2. Démarrage de MySQL MAMP si non actif
if [[ -x "/Applications/MAMP/bin/startMysql.sh" ]]; then
  if ! pgrep -f "/Applications/MAMP/Library/bin/mysqld" >/dev/null 2>&1; then
    echo "[2/3] Démarrage de MySQL (MAMP port 8889)..."
    /Applications/MAMP/bin/startMysql.sh >/dev/null 2>&1 &
    for _ in $(seq 1 20); do
      if /Applications/MAMP/Library/bin/mysql -u root -proot --socket=/Applications/MAMP/tmp/mysql/mysql.sock -e "SELECT 1" >/dev/null 2>&1; then
        break
      fi
      sleep 1
    done
  else
    echo "[2/3] MySQL MAMP déjà actif."
  fi
fi

# 3. Sélection de PHP 8.2 (MAMP prioritaire car pdo_mysql inclus)
PHP_BIN="php"
if [[ -x "/Applications/MAMP/bin/php/php8.2.0/bin/php" ]]; then
  PHP_BIN="/Applications/MAMP/bin/php/php8.2.0/bin/php"
fi

PORT="${PORT:-8080}"
echo "[3/3] Serveur FINCAISSE lancé sur http://127.0.0.1:${PORT}"
echo "      Appuyez sur Ctrl+C pour arrêter."
exec "$PHP_BIN" -S "127.0.0.1:${PORT}" -t "$ROOT_DIR/public" "$ROOT_DIR/public/index.php"

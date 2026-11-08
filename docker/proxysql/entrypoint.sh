#!/bin/bash
# Erzeugt /etc/proxysql.cnf aus einem Template und startet ProxySQL.
# Jeder Platzhalter @NAME@ im Template wird durch die Umgebungsvariable NAME ersetzt; fehlt eine, bricht der Start ab.
# Template: PROXYSQL_CNF_TEMPLATE (Standard: das Template des Test-Setups)
set -euo pipefail

template=${PROXYSQL_CNF_TEMPLATE:-/docker/proxysql.cnf.tpl}

# Sonderzeichen für die sed-Ersetzung maskieren
esc() { printf '%s' "$1" | sed -e 's/[\\|&]/\\&/g'; }

args=()
for var in $(grep -o '@[A-Z][A-Z0-9_]*@' "$template" | tr -d @ | sort -u); do
    if [ -z "${!var:-}" ]; then
        echo "entrypoint: Umgebungsvariable $var fehlt (gebraucht in $template)" >&2
        exit 1
    fi
    args+=(-e "s|@$var@|$(esc "${!var}")|g")
done
sed "${args[@]}" "$template" > /etc/proxysql.cnf

exec proxysql -f --idle-threads -D /var/lib/proxysql

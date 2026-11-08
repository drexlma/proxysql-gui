#!/bin/bash
# Erzeugt gleichmäßige Last über ProxySQL (Port 6033), damit Diagramme,
# Query-Digests und Cache-Statistiken in der GUI Daten zeigen.

OPTS=(-h proxysql -P 6033 -u app -p"${APP_PASSWORD}" --skip-ssl shop)

until mariadb "${OPTS[@]}" -e 'SELECT 1' >/dev/null 2>&1; do sleep 2; done
echo "loadgen: verbunden mit ProxySQL"

while true; do
  p=$((RANDOM % 200 + 1))
  c=$((RANDOM % 50 + 1))
  mariadb "${OPTS[@]}" >/dev/null <<SQL
SELECT * FROM products WHERE id = $p;
SELECT category, COUNT(*), AVG(price) FROM products GROUP BY category;
SELECT o.id, p.name, o.qty FROM orders o JOIN products p ON p.id = o.product_id WHERE o.customer_id = $c ORDER BY o.created_at DESC LIMIT 10;
SELECT city, COUNT(*) FROM customers GROUP BY city;
UPDATE products SET views = views + 1 WHERE id = $p;
INSERT INTO orders (customer_id, product_id, qty) VALUES ($c, $p, $((RANDOM % 5 + 1)));
SQL
  sleep 0.3
done

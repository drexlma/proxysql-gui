-- Demo-Datenbank für das Beispiel-Setup
USE shop;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(50) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  views INT NOT NULL DEFAULT 0,
  KEY (category)
);

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  city VARCHAR(60) NOT NULL
);

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (customer_id, created_at)
);

-- seq_1_to_N kommt von der Sequence-Engine (in MariaDB standardmäßig aktiv)
INSERT INTO products (name, category, price)
  SELECT CONCAT('Produkt ', seq),
         ELT(1 + seq % 5, 'Bücher', 'Elektronik', 'Garten', 'Küche', 'Spielzeug'),
         ROUND(5 + RAND() * 200, 2)
  FROM seq_1_to_200;

INSERT INTO customers (name, city)
  SELECT CONCAT('Kunde ', seq), ELT(1 + seq % 4, 'München', 'Berlin', 'Hamburg', 'Köln')
  FROM seq_1_to_50;

INSERT INTO orders (customer_id, product_id, qty, created_at)
  SELECT 1 + seq % 50, 1 + (seq * 7) % 200, 1 + seq % 5, NOW() - INTERVAL seq HOUR
  FROM seq_1_to_1000;

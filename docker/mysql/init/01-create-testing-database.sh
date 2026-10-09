#!/bin/sh
# Runs once when the MySQL volume is first created: adds the database Pest uses.
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS testing;
GRANT ALL PRIVILEGES ON testing.* TO '$MYSQL_USER'@'%';
SQL

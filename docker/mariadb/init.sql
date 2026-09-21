-- Runs once, on first initialisation of the database volume (docker-entrypoint-initdb.d).
-- Doctrine's test environment appends "_test" to the database name (config/packages/doctrine.yaml),
-- and the image only grants the `app` user rights on the `app` database.
CREATE DATABASE IF NOT EXISTS `app_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `app_test`.* TO 'app'@'%';
FLUSH PRIVILEGES;

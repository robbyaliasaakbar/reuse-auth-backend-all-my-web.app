# PHP 8.3 + SQLite driver. Tanpa PHP native di laptop, image ini yang jadi runtime.
FROM php:8.3-cli

# pdo_sqlite = driver database (butuh libsqlite3-dev buat ngompile).
# sqlite3 = tools kecil buat intip auth.db dari terminal.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev sqlite3 \
 && docker-php-ext-install pdo pdo_sqlite \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
EXPOSE 7002

# Server bawaan PHP (cukup buat belajar lokal, BUKAN buat production).
CMD ["php", "-S", "0.0.0.0:7002", "-t", "public", "public/index.php"]

# 1. Asos sifatida rasmiy PHP va Apache rasmini olamiz
FROM php:8.2-apache

# 2. Tizimni yangilash va SQLite ishlashi uchun kerakli kutubxonalarni o'rnatish
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# 3. Apache mod_rewrite ni yoqish (kelajakda kerak bo'lishi mumkin)
RUN a2enmod rewrite

# 4. Apache server nomini sozlash (xatolik bermasligi uchun)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# 5. Loyiha fayllarini konteyner ichiga ko'chirish
COPY . /var/www/html/

# 6. Ruxsatlarni to'g'irlash
# Bu JUDA MUHIM: PHP fayl yarata olishi uchun (scambase.db) www-data ga ruxsat beramiz
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 7. Render kutadigan portni ochish (Apache odatda 80 da ishlaydi)
EXPOSE 80

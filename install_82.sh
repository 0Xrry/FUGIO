#!/bin/bash
pip3 install -r requirements.txt --use-pep517

cd Lib/rabbitmq_php7
composer install
cd ../..

cd Lib/PHP-Parser7
composer install
cd ../..

cd Lib/evalhook8
phpize
./configure
make && make install
cd ../..

apt install -y php8.2-uopz

cd Lib/pcntl84
phpize
./configure
make && make install
cd ../..

pip3 install pika
pip3 install requests
pip3 install python-dateutil


grep -qF -- "extension=evalhook.so" /etc/php/8.2/apache2/php.ini || echo "extension=evalhook.so" >> /etc/php/8.2/apache2/php.ini
grep -qF -- "extension=uopz.so" /etc/php/8.2/apache2/php.ini || echo "extension=uopz.so" >> /etc/php/8.2/apache2/php.ini
grep -qF -- "extension=uopz.so" /etc/php/8.2/cli/php.ini || echo "extension=uopz.so" >> /etc/php/8.2/cli/php.ini
grep -qF -- "extension=pcntl.so" /etc/php/8.2/apache2/php.ini || echo "extension=pcntl.so" >> /etc/php/8.2/apache2/php.ini
sed -i "s/^disable_functions/; disable_functions/g" /etc/php/8.2/apache2/php.ini
sed -i "s/;phar.readonly = On/phar.readonly = Off/g" /etc/php/8.2/cli/php.ini

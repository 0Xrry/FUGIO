#!/bin/bash
pip3 install -r requirements.txt

cd Lib/rabbitmq_php8
composer install
cd ../..

cd Lib/PHP-Parser8
composer install
cd ../..

cd Lib/evalhook8
phpize
./configure
make && make install
cd ../..

cd Lib/uopz8
phpize
./configure
make && make install
cd ../..

cd Lib/pcntl84
phpize
./configure
make && make install
cd ../..

grep -qF -- "extension=evalhook.so" /etc/php/8.4/apache2/php.ini || echo "extension=evalhook.so" >> /etc/php/8.4/apache2/php.ini
grep -qF -- "extension=uopz.so" /etc/php/8.4/apache2/php.ini || echo "extension=uopz.so" >> /etc/php/8.4/apache2/php.ini
grep -qF -- "extension=uopz.so" /etc/php/8.4/cli/php.ini || echo "extension=uopz.so" >> /etc/php/8.4/cli/php.ini
grep -qF -- "extension=pcntl.so" /etc/php/8.4/apache2/php.ini || echo "extension=pcntl.so" >> /etc/php/8.4/apache2/php.ini
sed -i "s/^disable_functions/; disable_functions/g" /etc/php/8.4/apache2/php.ini
sed -i "s/;phar.readonly = On/phar.readonly = Off/g" /etc/php/8.4/cli/php.ini

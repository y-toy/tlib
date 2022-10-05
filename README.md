# tlib
my php library

Don't use this lib. It's not tested (not reliable).

# you need composer to use this library.
## composer install
```
mkdir ~/temp
cd ~/temp
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
rm composer-setup.php

mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer
```
# run composer
```
composer install
```

# in php programs
```
include 'vendor/autoload.php'
```

#!/bin/bash
# Runs once, when the sandbox Dolibarr container first installs itself.
su www-data -s /bin/sh -c "php /var/www/html/custom/onboarding/sandbox/setup.php"

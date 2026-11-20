<?php

// Loaded by auto_prepend_file (deploy/php/php.ini) in every PHP process of the php and worker
// containers - php-fpm, the worker, `docker compose exec` commands and composer - so files
// created in temp/ and log/ stay writable for the shared development group.
umask(0002);

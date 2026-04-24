<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo 'OPcache cleared OK';
} else {
    echo 'OPcache not active';
}

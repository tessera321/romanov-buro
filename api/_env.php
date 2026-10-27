<?php
header('Content-Type: text/plain');
foreach (['HTTPS','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_SSL','HTTP_X_FORWARDED_PORT','SERVER_PORT','REQUEST_SCHEME','HTTP_HOST'] as $k) echo $k, '=', $_SERVER[$k] ?? '-', "\n";

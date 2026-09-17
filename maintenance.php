<?php
// Optional daily CLI job; never executable over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/lib/bootstrap.php';
storage_cleanup();
echo "NIAG: gallring klar.\n";

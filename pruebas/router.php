<?php
$root = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/api/(.*)$#', $uri, $m)) { $_GET['r'] = $m[1]; require "$root/api/index.php"; return; }
if (preg_match('#^/r/([A-Za-z0-9_-]{22})/?$#', $uri, $m)) { $_GET['id'] = $m[1]; require "$root/api/r.php"; return; }
if (preg_match('#^/r/([A-Za-z0-9_-]{22})/(calendario\.ics|imagen\.jpg|material/[A-Za-z0-9_-]{16})$#', $uri, $m)) { $_GET['id'] = $m[1]; $_GET['x'] = $m[2]; require "$root/api/r.php"; return; }
if (str_starts_with($uri, '/api/_lib') ) { http_response_code(403); return; }
if ($uri !== '/' && is_file($root . $uri)) return false;
readfile("$root/index.html");

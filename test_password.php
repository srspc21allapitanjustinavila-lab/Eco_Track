<?php

require 'config.php';

$c = getDBConnection();
$c->exec("USE ecotrack_db");

$row = $c->query("SELECT password_hash FROM users WHERE username = 'Justin'")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "USER_NOT_FOUND\n";
    exit;
}

$password = 'admin123';

echo password_verify($password, $row['password_hash'])
    ? "PASSWORD_MATCH=YES\n"
    : "PASSWORD_MATCH=NO\n";
<?php

/* ---------------------------------------------------------------
 | EXAMPLE FILE - sirf structure/reference ke liye.
 | Ismein KOI real credential nahi hai.
 |
 | Apna `config/db.php` file is file se copy bana kar banayein:
 |     copy config/db.example.php config/db.php
 |
 | `config/db.php` .gitignore mein hai, isliye wo kabhi
 | repository mein push nahi hoga.
 | ------------------------------------------------------------- */

$conn = new mysqli(
    "localhost",
    "your_db_username",
    "your_db_password",
    "your_db_name"
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
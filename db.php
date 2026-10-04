<?php

// Database configuration
$servername = "localhost";        
$username   = "DB USER NAME ";     
$password   = "DB PASSWORD";    
$dbname     = "DB NAME"; 

// Telegram Bot configuration


$botToken    = "TELEGRAM BOT TOKEN"; 


// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

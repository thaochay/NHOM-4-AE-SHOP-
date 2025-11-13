<?php


$DB_HOST = "127.0.0.1";  
$DB_NAME = "dsq2shop";   
$DB_USER = "root";       
$DB_PASS = "";            
$DB_CHAR = "utf8mb4";


$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, 
    PDO::ATTR_EMULATE_PREPARES => false,             
];

try {
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHAR";

    $conn = new PDO($dsn, $DB_USER, $DB_PASS, $options);

} catch (PDOException $e) {

    die("❌ Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
}
?>

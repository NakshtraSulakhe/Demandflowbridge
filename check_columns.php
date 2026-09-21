<?php
require_once 'config/database.php';
$conn = getDbConnection();
$result = $conn->query('DESCRIBE leads');
if($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . PHP_EOL;
    }
}

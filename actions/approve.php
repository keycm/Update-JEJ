<?php
include '../config.php';

$id = $_GET['id'];

$conn->query("UPDATE lots SET status='RESERVED' WHERE id='$id'");

header("Location: ../dashboard.php?status=RESERVED");
?>
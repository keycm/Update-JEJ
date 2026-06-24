<?php
include '../config.php';

$id = $_GET['id'];

$conn->query("
    UPDATE lots SET
        status='AVAILABLE',
        fullname=NULL,
        contact=NULL,
        email=NULL,
        address=NULL
    WHERE id='$id'
");

header("Location: ../dashboard.php?status=AVAILABLE");
exit();
?>
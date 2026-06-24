<?php
include '../config.php';

$id = $_POST['id'];
$buyer = $_POST['buyer'];
$contact = $_POST['contact'];

$conn->query("UPDATE lots SET
status='FOR APPROVAL',
buyer_name='$buyer',
buyer_contact='$contact'
WHERE id='$id'");

header("Location: ../dashboard.php?status=FOR APPROVAL");
?>
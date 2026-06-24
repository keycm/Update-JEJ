<?php
include 'config.php';

/* destroy session */
session_unset();
session_destroy();

/* redirect to login */
header("Location: index.php");
exit();
?>
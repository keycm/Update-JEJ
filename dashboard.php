<?php
include 'config.php';

if(!isset($_SESSION['user'])){
    header("Location:index.php");
}

$status = $_GET['status'] ?? 'AVAILABLE';

$result = $conn->query("SELECT * FROM lots WHERE status='$status'");
?>

<!DOCTYPE html>
<html>
<head>
<title>Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="nav">
<h3>Lots Reservation Dashboard</h3>
<a href="logout.php">Logout</a>
</div>

<div class="tabs">
<a href="?status=AVAILABLE">AVAILABLE</a>
<a href="?status=FOR APPROVAL">FOR APPROVAL</a>
<a href="?status=RESERVED">RESERVED</a>
</div>

<br>

<table>
<tr>
<th>Lot</th>
<th>Block</th>
<th>Area</th>
<th>Price</th>
<th>Status</th>
<th>Action</th>
</tr>

<?php while($row=$result->fetch_assoc()): ?>
<tr>
<td><?= $row['lot_no']?></td>
<td><?= $row['block_no']?></td>
<td><?= $row['area']?></td>
<td><?= number_format($row['price'])?></td>
<td>
<span class="badge 
<?= $row['status']=='AVAILABLE'?'av':($row['status']=='FOR APPROVAL'?'fa':'rs')?>">

<?= $row['status']?>
</span>
</td>

<td>

<?php if($row['status']=="AVAILABLE"): ?>
<form action="actions/reserve.php" method="POST">
<input type="hidden" name="id" value="<?= $row['id']?>">
<input name="buyer" placeholder="Buyer name">
<input name="contact" placeholder="Contact">
<button>Reserve</button>
</form>
<?php endif; ?>

<?php if($row['status']=="FOR APPROVAL"): ?>
<a href="actions/approve.php?id=<?= $row['id']?>">Approve</a>
<?php endif; ?>

</td>
</tr>
<?php endwhile; ?>

</table>

</body>
</html>
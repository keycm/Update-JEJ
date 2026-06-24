<?php
require 'config.php';
checkAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Calendar Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="d-flex justify-content-between mb-3">
        <h2>Calendar Tracker (Transactions)</h2>
        <a href="dashboard_financial.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    <div class="card p-3 shadow-sm">
        <div id="calendar"></div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            events: [
                <?php
                // Fetch transactions to plot on the calendar
                $ev = $conn->query("SELECT * FROM transactions");
                while($row = $ev->fetch_assoc()){
                    $color = ($row['type'] == 'INCOME') ? 'green' : 'red';
                    echo "{ title: '{$row['type']}: ₱{$row['amount']}', start: '{$row['transaction_date']}', color: '$color' },";
                }
                ?>
            ]
        });
        calendar.render();
    });
</script>
</body>
</html>
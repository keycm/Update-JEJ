<?php
// print_check_voucher.php
include 'config.php';

// Security Check
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER'])){
    die("Access Denied");
}

if(!isset($_GET['cv']) || empty($_GET['cv'])){
    die("Invalid Request. Check Voucher Number is missing.");
}

$cv_number = $_GET['cv'];

// Query the actual 'transactions' table using the 'or_number'
$stmt = $conn->prepare("SELECT * FROM transactions WHERE or_number = ? AND is_check = 1");
$stmt->bind_param("s", $cv_number);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if(!$data) {
    die("Check Voucher not found in the database.");
}

// Custom function to convert number to words (No PHP extensions required)
function numberToWords($num) {
    $ones = array(
        0 => "Zero", 1 => "One", 2 => "Two", 3 => "Three", 4 => "Four", 5 => "Five", 6 => "Six", 7 => "Seven", 8 => "Eight", 9 => "Nine",
        10 => "Ten", 11 => "Eleven", 12 => "Twelve", 13 => "Thirteen", 14 => "Fourteen", 15 => "Fifteen", 16 => "Sixteen", 17 => "Seventeen", 18 => "Eighteen", 19 => "Nineteen"
    );
    $tens = array(
        0 => "Zero", 1 => "Ten", 2 => "Twenty", 3 => "Thirty", 4 => "Forty", 5 => "Fifty", 6 => "Sixty", 7 => "Seventy", 8 => "Eighty", 9 => "Ninety"
    );
    $hundreds = array("Hundred", "Thousand", "Million", "Billion", "Trillion");

    $num = number_format((float)$num, 2, ".", "");
    $num_arr = explode(".", $num);
    $wholenum = $num_arr[0];
    $decnum = $num_arr[1];
    
    if($wholenum == 0) {
        $rettxt = "Zero";
    } else {
        $whole_arr = array_reverse(explode(",", number_format($wholenum)));
        ksort($whole_arr);
        
        $rettxt = "";
        foreach($whole_arr as $key => $i){
            if($i < 20){
                $rettxt = ($i > 0 ? $ones[intval($i)] . " " : "") . ($key > 0 && $i > 0 ? $hundreds[$key] . " " : "") . $rettxt;
            } elseif($i < 100){
                $rettxt = $tens[substr($i, 0, 1)] . " " . ($ones[substr($i, 1, 1)] != "Zero" ? $ones[substr($i, 1, 1)] . " " : "") . ($key > 0 ? $hundreds[$key] . " " : "") . $rettxt;
            } else {
                $rettxt = $ones[substr($i, 0, 1)] . " " . $hundreds[0] . " " . 
                          ($tens[substr($i, 1, 1)] != "Zero" ? $tens[substr($i, 1, 1)] . " " : "") . 
                          ($ones[substr($i, 2, 1)] != "Zero" ? $ones[substr($i, 2, 1)] . " " : "") . 
                          ($key > 0 ? $hundreds[$key] . " " : "") . $rettxt;
            }
        }
    }
    
    $rettxt = trim($rettxt) . " Pesos";
    
    // Handle Cents
    if($decnum > 0){
        $rettxt .= " and " . $decnum . "/100";
    }
    
    return $rettxt . " Only";
}

$amount = isset($data['amount']) ? (float)$data['amount'] : 0.00;
$amount_words = numberToWords($amount);

function checkVoucherField($description, $label, $fallback = '') {
    $description = (string)$description;
    if(preg_match('/(?:^|\|)\s*' . preg_quote($label, '/') . '\s*:\s*([^|]+)/i', $description, $m)){
        return trim($m[1]);
    }
    return $fallback;
}

function checkVoucherPurpose($description, $fallback = 'Payment for reimbursement of surveying expenses.') {
    $description = (string)$description;
    $parts = array_map('trim', explode('|', $description));
    $purposeParts = [];

    foreach($parts as $part){
        if($part === '') continue;
        if(preg_match('/^(prepared by|checked by|approved by|released to)\s*:/i', $part)){
            continue;
        }
        if(preg_match('/^purpose\s*:\s*(.+)$/i', $part, $m)){
            $part = trim($m[1]);
        }
        if($part !== ''){
            $purposeParts[] = $part;
        }
    }

    $purpose = trim(implode("\n", $purposeParts));
    return $purpose !== '' ? $purpose : $fallback;
}

$raw_description = $data['description'] ?? '';
$voucher_purpose = checkVoucherPurpose($raw_description);
$prepared_by = checkVoucherField($raw_description, 'Prepared by', $_SESSION['fullname'] ?? 'Admin');
$checked_by = checkVoucherField($raw_description, 'Checked by', $data['checked_by'] ?? '');
$approved_by = checkVoucherField($raw_description, 'Approved by', $data['approved_by'] ?? '');
$released_to = checkVoucherField($raw_description, 'Released to', $data['payee'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Voucher <?= htmlspecialchars($data['or_number']) ?> - JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        *{ box-sizing:border-box; }

        body{
            font-family:'Inter', sans-serif;
            background:#f4f6f8;
            color:#0f172a;
            margin:0;
            padding:24px 16px;
            display:flex;
            justify-content:center;
        }

        .btn-print{
            position:fixed;
            right:30px;
            bottom:30px;
            border:0;
            border-radius:999px;
            background:#2e7d32;
            color:#fff;
            padding:13px 24px;
            font-size:14px;
            font-weight:800;
            cursor:pointer;
            box-shadow:0 10px 25px rgba(46,125,50,.25);
            z-index:999;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .voucher-container{
            width:100%;
            max-width:900px;
            background:#fff;
            border-top:7px solid #2e7d32;
            padding:24px 44px 18px;
            box-shadow:0 12px 35px rgba(15,23,42,.08);
            position:relative;
            overflow:hidden;
        }

        .voucher-content{
            position:relative;
            z-index:2;
        }

        .watermark{
            position:absolute;
            top:52%;
            left:50%;
            transform:translate(-50%, -50%) rotate(-28deg);
            font-size:42px;
            font-weight:800;
            color:#0f172a;
            opacity:.045;
            white-space:nowrap;
            pointer-events:none;
            z-index:1;
        }

        .header{
            display:grid;
            grid-template-columns:80px 1fr 130px;
            align-items:center;
            gap:14px;
            border-bottom:2px solid #2e7d32;
            padding-bottom:12px;
            margin-bottom:12px;
        }

        .company-logo{
            width:70px;
            height:70px;
            object-fit:contain;
            display:block;
        }

        .company-title{
            text-align:center;
        }

        .company-title h1{
            margin:0;
            font-size:25px;
            line-height:1.15;
            font-weight:800;
            color:#075619;
            text-transform:uppercase;
            letter-spacing:.8px;
        }

        .company-title p{
            margin:5px 0 0;
            font-size:12px;
            color:#475569;
        }

        .voucher-status{
            justify-self:end;
            align-self:start;
            border:1px solid #bbf7d0;
            background:#f0fdf4;
            color:#15803d;
            border-radius:999px;
            padding:7px 12px;
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            white-space:nowrap;
        }

        .voucher-title{
            text-align:center;
            font-size:20px;
            font-weight:800;
            color:#1e293b;
            letter-spacing:2.5px;
            text-transform:uppercase;
            border:1px solid #dbe3ec;
            background:#f8fafc;
            padding:7px;
            margin:12px 0 14px;
        }

        .top-details{
            display:grid;
            grid-template-columns:2fr 1fr;
            gap:28px;
            margin-bottom:12px;
        }

        .detail-row{
            display:flex;
            align-items:flex-end;
            gap:8px;
            margin-bottom:7px;
            font-size:12px;
        }

        .detail-row .label{
            width:115px;
            color:#334155;
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            flex-shrink:0;
        }

        .detail-row .value{
            flex:1;
            min-height:18px;
            border-bottom:1px solid #cbd5e1;
            padding:0 4px 2px;
            font-weight:700;
            color:#0f172a;
        }

        .right-col .detail-row .label{ width:62px; }

        .amount-box{
            border:2px solid #2e7d32;
            background:#f0fdf4;
            border-radius:8px;
            padding:9px 12px;
            margin-top:10px;
            text-align:center;
        }

        .amount-label{
            color:#166534;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:3px;
        }

        .amount-box .currency{
            font-size:15px;
            font-weight:800;
            color:#166534;
        }

        .amount-box .total-number{
            font-size:26px;
            font-weight:800;
            color:#166534;
        }

        .accounting-strip{
            border:1px solid #dbe3ec;
            background:#f8fafc;
            padding:8px 10px;
            margin:8px 0 10px;
            font-size:11px;
            color:#334155;
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:8px;
        }

        .accounting-strip strong{ color:#0f172a; }

        table{
            width:100%;
            border-collapse:collapse;
            margin-bottom:12px;
            border:1px solid #cbd5e1;
        }

        th{
            background:#f1f5f9;
            color:#334155;
            text-align:left;
            padding:8px 12px;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            border-bottom:2px solid #cbd5e1;
            border-right:1px solid #cbd5e1;
        }

        th:last-child, td:last-child{ border-right:none; }

        td{
            padding:9px 12px;
            font-size:12px;
            border-bottom:1px solid #e2e8f0;
            border-right:1px solid #e2e8f0;
            vertical-align:top;
        }

        .particulars-cell{ height:78px; }

        .particulars-title{
            font-weight:800;
            margin-bottom:4px;
        }

        .particulars-text{
            color:#334155;
            font-size:11px;
            line-height:1.35;
        }

        .text-right{ text-align:right; }

        .amount-words-container{
            display:flex;
            align-items:center;
            gap:12px;
            background:#f8fafc;
            border-left:4px solid #2e7d32;
            padding:10px 16px;
            margin-bottom:16px;
        }

        .amount-words-container .label{
            font-size:11px;
            color:#334155;
            font-weight:800;
            text-transform:uppercase;
            white-space:nowrap;
        }

        .amount-words-container .value{
            font-size:14px;
            font-weight:800;
            text-transform:uppercase;
            color:#0f172a;
        }

        .signatories{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
            margin-top:22px;
        }

        .sig-box{
            text-align:center;
            min-width:0;
        }

        .sig-line{
            border-bottom:1px solid #64748b;
            padding:0 4px 5px;
            min-height:24px;
            font-size:12px;
            font-weight:800;
            color:#0f172a;
            display:flex;
            align-items:flex-end;
            justify-content:center;
            line-height:1.15;
        }

        .sig-title{
            font-size:10px;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.5px;
            font-weight:800;
        }

        .sig-sub{
            margin-top:4px;
            font-size:9px;
            color:#64748b;
            line-height:1.25;
        }

        .audit-footer{
            margin-top:14px;
            border-top:1px dashed #cbd5e1;
            padding-top:7px;
            text-align:center;
            color:#64748b;
            font-size:9px;
        }

        @media print{
            @page{
                size:A4 portrait;
                margin:5mm;
            }

            html, body{
                width:210mm;
                margin:0;
                padding:0;
                background:#fff;
                overflow:hidden;
            }

            .btn-print{ display:none !important; }

            .voucher-container{
                width:200mm;
                max-width:200mm;
                height:138mm;
                margin:0 auto;
                padding:6mm 9mm 5mm;
                box-shadow:none !important;
                border-top:4px solid #2e7d32;
                page-break-inside:avoid;
                page-break-after:avoid;
                overflow:hidden;
            }

            .header{
                grid-template-columns:20mm 1fr 32mm;
                gap:3mm;
                padding-bottom:2.5mm;
                margin-bottom:2.5mm;
            }

            .company-logo{
                width:17mm;
                height:17mm;
            }

            .company-title h1{ font-size:15pt; }
            .company-title p{
                font-size:7.2pt;
                margin-top:1mm;
            }

            .voucher-status{
                font-size:6.8pt;
                padding:1.5mm 2.5mm;
            }

            .voucher-title{
                font-size:12pt;
                padding:1.5mm;
                margin:2.5mm 0;
            }

            .top-details{
                gap:7mm;
                margin-bottom:2mm;
            }

            .detail-row{
                font-size:7.4pt;
                margin-bottom:1.2mm;
                gap:1.5mm;
            }

            .detail-row .label{
                width:24mm;
                font-size:6.8pt;
            }

            .right-col .detail-row .label{ width:15mm; }

            .amount-box{
                padding:1.8mm 2mm;
                margin-top:1.5mm !important;
            }

            .amount-label{
                font-size:6.5pt;
                margin-bottom:.5mm;
            }

            .amount-box .currency{ font-size:9pt; }
            .amount-box .total-number{ font-size:18pt; }

            .accounting-strip{
                padding:1.8mm 2mm;
                margin:1.8mm 0 2mm;
                font-size:6.7pt;
                gap:2mm;
            }

            table{ margin-bottom:2.5mm; }

            th{
                font-size:7pt;
                padding:1.6mm 2mm;
            }

            td{
                font-size:7pt;
                padding:1.6mm 2mm;
            }

            .particulars-cell{ height:22mm; }

            .particulars-text{
                font-size:6.5pt;
                line-height:1.25;
            }

            .amount-words-container{
                padding:2mm 3mm;
                margin-bottom:4mm;
            }

            .amount-words-container .label{ font-size:6.8pt; }
            .amount-words-container .value{ font-size:9pt; }

            .signatories{
                gap:4mm;
                margin-top:7mm;
            }

            .sig-line{
                font-size:7pt;
                min-height:5mm;
                padding:0 1mm 1mm;
            }

            .sig-title{ font-size:6.4pt; }

            .sig-sub{
                font-size:6pt;
                margin-top:1mm;
            }

            .audit-footer{
                margin-top:4mm;
                padding-top:1.5mm;
                font-size:6pt;
            }

            .watermark{ font-size:26pt; }
        }
    </style>
</head>

<body>
    <button class="btn-print" onclick="window.print()">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
            <polyline points="6 9 6 2 18 2 18 9"></polyline>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
            <rect x="6" y="14" width="12" height="8"></rect>
        </svg>
        Print Voucher
    </button>

    <div class="voucher-container">
        <div class="watermark">JEJ TOP PRIORITY CORPORATION</div>

        <div class="voucher-content">
            <div class="header">
                <img src="assets/logo1.png" class="company-logo" alt="JEJ Top Priority Corporation Logo">

                <div class="company-title">
                    <h1>JEJ Top Priority Corporation</h1>
                    <p>San Francisco, Nueva Ecija, Philippines | Tel: (045) 123-4567</p>
                </div>

                <div class="voucher-status">STATUS: RELEASED</div>
            </div>

            <div class="voucher-title">Check Voucher</div>

            <div class="top-details">
                <div class="left-col">
                    <div class="detail-row">
                        <span class="label">Payee:</span>
                        <span class="value"><?= htmlspecialchars($data['payee'] ?? 'N/A') ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Address:</span>
                        <span class="value"><?= htmlspecialchars($data['payee_address'] ?? $data['address'] ?? 'San Francisco, Nueva Ecija') ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Bank Name:</span>
                        <span class="value"><?= htmlspecialchars($data['bank_name'] ?? 'N/A') ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Check No:</span>
                        <span class="value" style="font-family:monospace;"><?= htmlspecialchars($data['check_number'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <div class="right-col">
                    <div class="detail-row">
                        <span class="label">Date:</span>
                        <span class="value text-right"><?= date('F d, Y', strtotime($data['transaction_date'])) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">CV No:</span>
                        <span class="value text-right" style="color:#dc2626;font-weight:800;"><?= htmlspecialchars($data['or_number']) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Ref No:</span>
                        <span class="value text-right"><?= htmlspecialchars($data['reference_no'] ?? $data['or_number']) ?></span>
                    </div>

                    <div class="amount-box">
                        <div class="amount-label">Total Disbursement</div>
                        <span class="currency">₱</span>
                        <span class="total-number"><?= number_format($amount, 2) ?></span>
                    </div>
                </div>
            </div>

            <div class="accounting-strip">
                <div><strong>Account Code:</strong> <?= htmlspecialchars($data['account_code'] ?? '5-01-02-010') ?></div>
                <div><strong>Fund Source:</strong> <?= htmlspecialchars($data['fund_source'] ?? 'General Fund') ?></div>
                <div><strong>Department:</strong> <?= htmlspecialchars($data['department'] ?? 'Realty Operations') ?></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="75%">Particulars / Description</th>
                        <th class="text-right" width="25%">Amount</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td class="particulars-cell">
                            <div class="particulars-title">Payment Disbursement</div>

                            <div class="particulars-text">
                                <?= nl2br(htmlspecialchars($voucher_purpose)) ?>
                            </div>
                        </td>

                        <td class="text-right" style="font-weight:700;">
                            ₱<?= number_format($amount, 2) ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="text-right" style="font-weight:800;text-transform:uppercase;background:#f8fafc;">
                            Total Amount
                        </td>

                        <td class="text-right" style="font-weight:800;font-size:16px;color:#1b5e20;background:#f8fafc;">
                            ₱<?= number_format($amount, 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="amount-words-container">
                <span class="label">The sum of:</span>
                <span class="value"><?= htmlspecialchars($amount_words) ?></span>
            </div>

            <div class="signatories">
                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($prepared_by) ?></div>
                    <div class="sig-title">Prepared By</div>
                </div>

                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($checked_by) ?></div>
                    <div class="sig-title">Checked By</div>
                </div>

                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($approved_by) ?></div>
                    <div class="sig-title">Approved By</div>
                </div>

                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($released_to) ?></div>
                    <div class="sig-title">Received By</div>
                    <div class="sig-sub">
                        Signature Over Printed Name<br>
                        Date: _______________
                    </div>
                </div>
            </div>

            <div class="audit-footer">
                Generated by: <?= htmlspecialchars($_SESSION['fullname'] ?? 'System User') ?>
                |
                Generated on: <?= date('F d, Y h:i A') ?>
                |
                System: JEJ Top Priority Corporation ERP
            </div>
        </div>
    </div>
</body>
</html>

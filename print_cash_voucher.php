<?php
// print_cash_voucher.php
include 'config.php';

// Security Check
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER'])){
    die("Access Denied");
}

$cvo_number = trim($_GET['cvo'] ?? $_GET['cv'] ?? '');
if($cvo_number === ''){
    die("Invalid Request. Cash Voucher Number is missing.");
}

$stmt = $conn->prepare("SELECT 
        t.*, 
        c.name AS category_name,
        c.group_name AS category_group,
        p.name AS project_name
    FROM transactions t
    LEFT JOIN accounting_categories c ON t.category_id = c.id
    LEFT JOIN projects p ON t.project_id = p.id
    WHERE t.or_number = ?
      AND t.type = 'EXPENSE'
      AND (t.is_check = 0 OR t.or_number LIKE 'CVO-%' OR t.description LIKE '%Voucher Type: Cash Voucher Out%')
    LIMIT 1");

if(!$stmt){
    die("Unable to load Cash Voucher. Please check the transactions table structure.");
}

$stmt->bind_param("s", $cvo_number);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if(!$data) {
    die("Cash Voucher not found in the database.");
}

function numberToWordsCashVoucher($num) {
    $ones = [
        0 => "Zero", 1 => "One", 2 => "Two", 3 => "Three", 4 => "Four", 5 => "Five", 6 => "Six", 7 => "Seven", 8 => "Eight", 9 => "Nine",
        10 => "Ten", 11 => "Eleven", 12 => "Twelve", 13 => "Thirteen", 14 => "Fourteen", 15 => "Fifteen", 16 => "Sixteen", 17 => "Seventeen", 18 => "Eighteen", 19 => "Nineteen"
    ];
    $tens = [2 => "Twenty", 3 => "Thirty", 4 => "Forty", 5 => "Fifty", 6 => "Sixty", 7 => "Seventy", 8 => "Eighty", 9 => "Ninety"];
    $scales = ["", "Thousand", "Million", "Billion", "Trillion"];

    $num = number_format((float)$num, 2, ".", "");
    [$whole, $cents] = explode('.', $num);
    $whole = (int)$whole;

    if($whole === 0){
        $words = "Zero";
    } else {
        $chunks = [];
        while($whole > 0){
            $chunks[] = $whole % 1000;
            $whole = intdiv($whole, 1000);
        }

        $parts = [];
        foreach($chunks as $scaleIndex => $chunk){
            if($chunk === 0) continue;

            $chunkWords = [];
            $hundreds = intdiv($chunk, 100);
            $remainder = $chunk % 100;

            if($hundreds > 0){
                $chunkWords[] = $ones[$hundreds] . " Hundred";
            }
            if($remainder > 0){
                if($remainder < 20){
                    $chunkWords[] = $ones[$remainder];
                } else {
                    $ten = intdiv($remainder, 10);
                    $one = $remainder % 10;
                    $chunkWords[] = $tens[$ten] . ($one > 0 ? " " . $ones[$one] : "");
                }
            }

            $scale = $scales[$scaleIndex] ?? '';
            $parts[] = trim(implode(' ', $chunkWords) . ' ' . $scale);
        }

        $words = implode(' ', array_reverse($parts));
    }

    $words .= " Pesos";
    if((int)$cents > 0){
        $words .= " and " . $cents . "/100";
    }

    return strtoupper(trim($words) . " Only");
}

function cashVoucherField($description, $label, $fallback = '') {
    $description = (string)$description;
    $label = preg_quote($label, '/');
    if(preg_match('/(?:^|\|)\s*' . $label . '\s*:\s*([^|]+)/i', $description, $m)){
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return $fallback;
}

function cashVoucherParticulars($description) {
    $description = (string)$description;
    $parts = array_map('trim', explode('|', $description));
    $keep = [];

    foreach($parts as $part){
        if($part === '') continue;
        if(preg_match('/^(Voucher Type|Purpose|Prepared by|Checked by|Approved by|Released to)\s*:/i', $part)) continue;
        $keep[] = $part;
    }

    return trim(implode(' | ', $keep));
}

$amount = isset($data['amount']) ? (float)$data['amount'] : 0.00;
$amount_words = numberToWordsCashVoucher($amount);
$description = $data['description'] ?? '';
$cash_purpose = cashVoucherField($description, 'Purpose', 'Cash Disbursement');
$prepared_by = cashVoucherField($description, 'Prepared by', $_SESSION['fullname'] ?? 'Super Admin');
$checked_by = cashVoucherField($description, 'Checked by', '');
$approved_by = cashVoucherField($description, 'Approved by', '');
$released_to = cashVoucherField($description, 'Released to', $data['payee'] ?? '');
$particulars = cashVoucherParticulars($description);
if($particulars === ''){
    $particulars = $cash_purpose;
}
$cash_voucher_detail = $cash_purpose;
if(trim($particulars) !== '' && strcasecmp(trim($particulars), trim($cash_purpose)) !== 0){
    $cash_voucher_detail .= "\n" . trim($particulars);
}
$project_name = $data['project_name'] ?? 'General Operations';
$category_name = trim(($data['category_group'] ?? 'Expense') . ' - ' . ($data['category_name'] ?? 'Cash Disbursement'));
$cash_mode = strtoupper($data['bank_name'] ?? '') === 'CASH' || empty($data['bank_name']) ? 'Cash' : $data['bank_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Voucher <?= htmlspecialchars($data['or_number']) ?> - JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        *{ box-sizing:border-box; }

        body{
            font-family:'Inter', Arial, sans-serif;
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
            background:#f59e0b;
            color:#fff;
            padding:13px 24px;
            font-size:14px;
            font-weight:800;
            cursor:pointer;
            box-shadow:0 10px 25px rgba(245,158,11,.25);
            z-index:999;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .voucher-container{
            width:100%;
            max-width:900px;
            background:#fff;
            border-top:7px solid #f59e0b;
            padding:24px 44px 18px;
            box-shadow:0 12px 35px rgba(15,23,42,.08);
            position:relative;
            overflow:hidden;
        }

        .voucher-content{ position:relative; z-index:2; }

        .watermark{
            position:absolute;
            top:49%;
            left:50%;
            transform:translate(-50%, -50%) rotate(-25deg);
            font-size:44px;
            font-weight:900;
            color:rgba(245,158,11,.07);
            letter-spacing:2px;
            white-space:nowrap;
            pointer-events:none;
            z-index:1;
        }

        .header{
            display:grid;
            grid-template-columns:90px 1fr 140px;
            align-items:center;
            gap:16px;
            padding-bottom:16px;
            border-bottom:3px solid #f59e0b;
        }

        .company-logo{
            width:72px;
            height:72px;
            object-fit:contain;
        }

        .company-title{ text-align:center; }
        .company-title h1{
            margin:0;
            color:#0f6b25;
            font-size:28px;
            line-height:1.1;
            text-transform:uppercase;
            letter-spacing:.5px;
            font-weight:900;
        }
        .company-title p{
            margin:8px 0 0;
            color:#475569;
            font-size:13px;
            font-weight:500;
        }

        .voucher-status{
            justify-self:end;
            align-self:start;
            border:1px solid #fed7aa;
            background:#fff7ed;
            color:#b45309;
            padding:8px 13px;
            border-radius:999px;
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
            text-transform:uppercase;
        }

        .voucher-title{
            margin:14px 0 16px;
            padding:9px;
            text-align:center;
            border:1px solid #cbd5e1;
            background:#f8fafc;
            font-size:25px;
            font-weight:900;
            letter-spacing:8px;
            text-transform:uppercase;
        }

        .top-details{
            display:grid;
            grid-template-columns:1.25fr .75fr;
            gap:28px;
            margin-bottom:14px;
        }

        .detail-row{
            display:grid;
            grid-template-columns:130px 1fr;
            gap:10px;
            align-items:end;
            margin-bottom:8px;
            font-size:13px;
        }

        .detail-row .label{
            color:#0f172a;
            text-transform:uppercase;
            font-size:12px;
            font-weight:900;
        }

        .detail-row .value{
            min-height:22px;
            border-bottom:1px solid #cbd5e1;
            padding:0 5px 4px;
            color:#0f172a;
            font-weight:700;
        }

        .right-col .detail-row{ grid-template-columns:85px 1fr; }
        .text-right{ text-align:right; }

        .amount-box{
            margin-top:10px;
            padding:12px;
            border:2px solid #f59e0b;
            border-radius:8px;
            background:#fff7ed;
            text-align:center;
        }
        .amount-label{
            text-transform:uppercase;
            color:#92400e;
            font-size:11px;
            letter-spacing:.8px;
            font-weight:900;
            margin-bottom:5px;
        }
        .amount-box .currency{
            color:#0f6b25;
            font-size:15px;
            font-weight:900;
            margin-right:5px;
        }
        .amount-box .total-number{
            color:#0f6b25;
            font-size:27px;
            font-weight:900;
        }

        .accounting-strip{
            display:grid;
            grid-template-columns:1fr 1fr 1fr;
            gap:12px;
            background:#f8fafc;
            border:1px solid #cbd5e1;
            padding:9px 12px;
            margin:12px 0 12px;
            font-size:12px;
            color:#334155;
        }
        .accounting-strip strong{ color:#0f172a; }

        table{
            width:100%;
            border-collapse:collapse;
            margin-bottom:12px;
        }
        th{
            background:#f1f5f9;
            color:#0f172a;
            border:1px solid #cbd5e1;
            padding:10px 12px;
            text-align:left;
            text-transform:uppercase;
            font-size:12px;
            font-weight:900;
        }
        td{
            border:1px solid #cbd5e1;
            padding:12px;
            vertical-align:top;
            font-size:13px;
        }
        .particulars-cell{ height:118px; }
        .particulars-title{
            font-weight:900;
            margin-bottom:8px;
            color:#0f172a;
        }
        .particulars-text{
            color:#334155;
            font-size:12px;
            line-height:1.45;
        }

        .amount-words-container{
            display:flex;
            gap:14px;
            align-items:center;
            border-left:4px solid #f59e0b;
            background:#fff7ed;
            padding:13px 15px;
            margin-bottom:24px;
        }
        .amount-words-container .label{
            text-transform:uppercase;
            font-size:12px;
            color:#0f172a;
            font-weight:900;
        }
        .amount-words-container .value{
            color:#0f172a;
            font-size:15px;
            font-weight:900;
        }

        .signatories{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
            margin-top:28px;
        }
        .sig-box{ text-align:center; min-height:58px; }
        .sig-line{
            border-bottom:1px solid #334155;
            min-height:24px;
            padding:0 4px 5px;
            font-size:12px;
            font-weight:800;
            display:flex;
            align-items:flex-end;
            justify-content:center;
            line-height:1.15;
        }
        .sig-title{
            color:#64748b;
            text-transform:uppercase;
            font-size:10px;
            font-weight:900;
            letter-spacing:.5px;
        }
        .sig-sub{
            color:#64748b;
            font-size:9px;
            line-height:1.35;
            margin-top:6px;
        }

        .audit-footer{
            margin-top:22px;
            padding-top:8px;
            border-top:1px dashed #cbd5e1;
            text-align:center;
            color:#64748b;
            font-size:10px;
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
                border-top:4px solid #f59e0b;
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
                letter-spacing:4pt;
                padding:1.5mm;
                margin:2.5mm 0;
            }

            .top-details{
                gap:7mm;
                margin-bottom:2mm;
            }

            .detail-row{
                grid-template-columns:24mm 1fr;
                font-size:7.4pt;
                margin-bottom:1.2mm;
                gap:1.5mm;
            }

            .detail-row .label{
                font-size:6.8pt;
            }

            .detail-row .value{
                min-height:4mm;
                padding:0 1mm .8mm;
            }

            .right-col .detail-row{ grid-template-columns:15mm 1fr; }

            .amount-box{
                padding:1.8mm 2mm;
                margin-top:1.5mm !important;
                border-radius:2mm;
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

            .particulars-title{
                margin-bottom:1mm;
            }

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

            .sig-box{ min-height:11mm; }

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
        Print Cash Voucher
    </button>

    <div class="voucher-container">
        <div class="watermark">CASH VOUCHER OUT</div>

        <div class="voucher-content">
            <div class="header">
                <img src="assets/logo1.png" class="company-logo" alt="JEJ Top Priority Corporation Logo">

                <div class="company-title">
                    <h1>JEJ Top Priority Corporation</h1>
                    <p>Purok San Francisco, Brgy. Langla, Jaen, Nueva Ecija, Philippines | Tel: 0975 134 6179</p>
                </div>

                <div class="voucher-status">Status: Released</div>
            </div>

            <div class="voucher-title">Cash Voucher</div>

            <div class="top-details">
                <div class="left-col">
                    <div class="detail-row">
                        <span class="label">Payee:</span>
                        <span class="value"><?= htmlspecialchars($data['payee'] ?? 'N/A') ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Cash Purpose:</span>
                        <span class="value"><?= htmlspecialchars($cash_purpose) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Cash Mode:</span>
                        <span class="value"><?= htmlspecialchars($cash_mode) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Released To:</span>
                        <span class="value"><?= htmlspecialchars($released_to ?: ($data['payee'] ?? 'N/A')) ?></span>
                    </div>
                </div>

                <div class="right-col">
                    <div class="detail-row">
                        <span class="label">Date:</span>
                        <span class="value text-right"><?= date('F d, Y', strtotime($data['transaction_date'])) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">CVO No:</span>
                        <span class="value text-right" style="color:#d97706;font-weight:900;"><?= htmlspecialchars($data['or_number']) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Ref No:</span>
                        <span class="value text-right"><?= htmlspecialchars($data['or_number']) ?></span>
                    </div>

                    <div class="amount-box">
                        <div class="amount-label">Total Cash Released</div>
                        <span class="currency">₱</span>
                        <span class="total-number"><?= number_format($amount, 2) ?></span>
                    </div>
                </div>
            </div>

            <div class="accounting-strip">
                <div><strong>Account Category:</strong> <?= htmlspecialchars($category_name) ?></div>
                <div><strong>Project:</strong> <?= htmlspecialchars($project_name) ?></div>
                <div><strong>Fund Source:</strong> Cash on Hand</div>
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
                            <div class="particulars-title">Cash Disbursement</div>

                            <div class="particulars-text">
                                <?= nl2br(htmlspecialchars($cash_voucher_detail)) ?>
                            </div>
                        </td>

                        <td class="text-right" style="font-weight:800;">
                            ₱<?= number_format($amount, 2) ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="text-right" style="font-weight:900;text-transform:uppercase;background:#f8fafc;">
                            Total Amount
                        </td>

                        <td class="text-right" style="font-weight:900;font-size:16px;color:#0f6b25;background:#f8fafc;">
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
                    <div class="sig-line"><?= htmlspecialchars($prepared_by ?: ($_SESSION['fullname'] ?? 'Admin')) ?></div>
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
                    <div class="sig-line"><?= htmlspecialchars($released_to ?: ($data['payee'] ?? '')) ?></div>
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
                System: JEJ Top Priority Corporation
            </div>
        </div>
    </div>
</body>
</html>

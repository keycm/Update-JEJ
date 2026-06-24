<?php if(!empty($row['contract_file'])): ?>
    <a href="<?= htmlspecialchars(jej_file_url('contracts', $row['contract_file'])) ?>" download class="btn btn-success">
        <i class="fa-solid fa-download"></i> Download Signed Contract
    </a>
<?php else: ?>
    <span class="badge bg-secondary">Signed contract not yet uploaded</span>
<?php endif; ?>

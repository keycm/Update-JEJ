<?php
// get_lot.php
include 'config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $conn->prepare("SELECT * FROM lots WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$lot = $stmt->get_result()->fetch_assoc();

if (!$lot) {
    echo "<p style='text-align:center; color:#E53E3E; font-weight:700;'>Lot not found in the database.</p>";
    exit;
}
?>
<form id="lotForm" onsubmit="saveLot(event)">
    <input type="hidden" name="id" value="<?= $lot['id'] ?>">

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase;">Block No.</label>
            <input type="text" name="block_no" value="<?= htmlspecialchars($lot['block_no']) ?>" required style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 6px;">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase;">Lot No.</label>
            <input type="text" name="lot_no" value="<?= htmlspecialchars($lot['lot_no']) ?>" required style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 6px;">
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase;">Area (sqm)</label>
            <input type="number" step="0.01" name="area" value="<?= htmlspecialchars($lot['area']) ?>" style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 6px;">
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase;">Status</label>
            <select name="status" style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 6px;">
                <option value="AVAILABLE" <?= $lot['status'] === 'AVAILABLE' ? 'selected' : '' ?>>AVAILABLE</option>
                <option value="RESERVED" <?= $lot['status'] === 'RESERVED' ? 'selected' : '' ?>>RESERVED</option>
                <option value="SOLD" <?= $lot['status'] === 'SOLD' ? 'selected' : '' ?>>SOLD</option>
            </select>
        </div>
        
        <div class="form-group" style="grid-column: span 2; margin-bottom: 0;">
            <label style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase;">
                <span>Polygon Points (Map Coordinates)</span>
                <button type="button" onclick="startDrawing()" style="background: #F6AD55; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-draw-polygon"></i> Pin on Map
                </button>
            </label>
            <textarea name="points" id="polygonPoints" rows="2" style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 6px;" placeholder="x1,y1 x2,y2 x3,y3 x4,y4"><?= htmlspecialchars($lot['coordinates'] ?? '') ?></textarea>
        </div>
    </div>

    <button type="submit" style="background: var(--primary); color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 20px;">
        <i class="fa-solid fa-save"></i> Save Lot Changes
    </button>
</form>
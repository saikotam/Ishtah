<?php
// pharmacy_billing_ajax.php - Handles AJAX updates for bill list (quantity/discount)
require_once 'includes/db.php';
session_start();

// Helper: Render bill list and summary (copied from main file, but as a function)
function render_bill_list_and_summary($bill_list, $item_discounts, $discount_type, $discount_value, $visit_id) {
    ob_start();
    ?>
    <h5>Bill List</h5>
    <div class="table-responsive mb-2">
        <table class="table table-bordered">
            <thead><tr><th>Medicine</th><th>HSN Code</th><th>Batch</th><th>Expiry</th><th>Unit Price</th><th>GST %</th><th>Qty</th><th>Item Discount</th><th>Remove</th></tr></thead>
            <tbody>
                <?php
                $live_subtotal = 0;
                $live_total_item_discount = 0;
                $live_items = [];
                foreach ($bill_list as $item) {
                    $price = $item['unit_price'] * $item['quantity'];
                    $item_discount_type = isset($item_discounts[$item['medicine_id']]['type']) ? $item_discounts[$item['medicine_id']]['type'] : 'rupees';
                    $item_discount_value = isset($item_discounts[$item['medicine_id']]['value']) ? $item_discounts[$item['medicine_id']]['value'] : 0;
                    $item_discount = 0;
                    if ($item_discount_type === 'percent') {
                        $item_discount = $price * $item_discount_value / 100;
                    } else {
                        $item_discount = $item_discount_value;
                    }
                    $discounted_price = max(0, $price - $item_discount);
                    $live_subtotal += $price;
                    $live_total_item_discount += $item_discount;
                    $live_items[] = [
                        'price' => $discounted_price,
                        'gst_percent' => $item['gst_percent'],
                        'medicine_id' => $item['medicine_id'],
                        'quantity' => $item['quantity'],
                        'item_discount' => $item_discount
                    ];
                }
                $live_discount_amount = 0;
                if ($discount_type === 'percent' && $discount_value > 0) {
                    $live_discount_amount = ($live_subtotal - $live_total_item_discount) * $discount_value / 100;
                } elseif ($discount_type === 'rupees' && $discount_value > 0) {
                    $live_discount_amount = $discount_value;
                }
                $live_discounted_total = max(0, $live_subtotal - $live_total_item_discount - $live_discount_amount);
                $live_gst_total = 0;
                foreach ($live_items as &$item) {
                    $proportion = ($live_subtotal - $live_total_item_discount) > 0 ? $item['price'] / ($live_subtotal - $live_total_item_discount) : 0;
                    $item_total_discount = $live_discount_amount * $proportion;
                    $net_price = max(0, $item['price'] - $item_total_discount);
                    $item['gst'] = $net_price * $item['gst_percent'] / (100 + $item['gst_percent']);
                    $live_gst_total += $item['gst'];
                }
                unset($item);
                ?>
                <?php foreach ($bill_list as $item): ?>
                <?php
                    $item_discount_type = isset($item_discounts[$item['medicine_id']]['type']) ? $item_discounts[$item['medicine_id']]['type'] : 'rupees';
                    $item_discount_value = isset($item_discounts[$item['medicine_id']]['value']) ? $item_discounts[$item['medicine_id']]['value'] : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><?= number_format($item['unit_price'],2) ?></td>
                    <td><?= $item['gst_percent'] ?>%</td>
                    <td>
                        <form method="post" class="d-flex align-items-center auto-submit-form" style="gap:4px;">
                            <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                            <input type="number" name="new_quantity" class="form-control form-control-sm auto-submit-input" min="1" max="<?= $item['available'] ?>" value="<?= $item['quantity'] ?>" style="width:70px;display:inline-block;">
                            <input type="hidden" name="update_quantity" value="<?= $item['medicine_id'] ?>">
                        </form>
                    </td>
                    <td>
                        <form method="post" class="d-inline auto-submit-form">
                            <input type="hidden" name="item_id" value="<?= $item['medicine_id'] ?>">
                            <select name="item_discount_type" class="form-select form-select-sm d-inline auto-submit-input" style="width:90px;display:inline-block;">
                                <option value="rupees" <?= $item_discount_type==='rupees'?'selected':'' ?>>₹</option>
                                <option value="percent" <?= $item_discount_type==='percent'?'selected':'' ?>>%</option>
                            </select>
                            <input type="number" name="item_discount_value" class="form-control form-control-sm d-inline auto-submit-input" style="width:80px;display:inline-block;" min="0" step="0.01" value="<?= htmlspecialchars($item_discount_value) ?>">
                            <input type="hidden" name="set_item_discount" value="1">
                        </form>
                    </td>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                            <button type="submit" name="remove_medicine" value="<?= $item['medicine_id'] ?>" class="btn btn-danger btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bill_list)): ?>
                <tr><td colspan="9" class="text-center">No medicines in bill.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Live Bill Summary -->
    <div class="table-responsive mb-3">
        <table class="table table-bordered w-auto ms-auto">
            <tr><th>Subtotal (Base Price)</th><td>₹<?= number_format(array_sum(array_map(function($item){ return $item['price'] / (1 + $item['gst_percent']/100); }, $live_items)),2) ?></td></tr>
            <tr><th>Total GST</th><td>₹<?= number_format(array_sum(array_map(function($item){ return $item['price'] - ($item['price'] / (1 + $item['gst_percent']/100)); }, $live_items)),2) ?></td></tr>
            <tr><th>Grand Total</th><td>₹<?= number_format($live_discounted_total,2) ?></td></tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

// Handle updates
if (isset($_POST['update_quantity'])) {
    $update_id = intval($_POST['update_quantity']);
    $new_qty = intval($_POST['new_quantity']);
    $bill_list = &$_SESSION['pharmacy_bill_list'];
    foreach ($bill_list as $idx => $item) {
        if ($item['medicine_id'] == $update_id) {
            $max_qty = isset($item['available']) ? intval($item['available']) : 1;
            if ($new_qty >= 1 && $new_qty <= $max_qty) {
                $bill_list[$idx]['quantity'] = $new_qty;
            }
            break;
        }
    }
    $_SESSION['pharmacy_bill_list'] = array_values($bill_list);
}
if (isset($_POST['set_item_discount'])) {
    $item_id = intval($_POST['item_id']);
    $item_discount_type = $_POST['item_discount_type'] ?? 'rupees';
    $item_discount_value = floatval($_POST['item_discount_value'] ?? 0);
    if (!isset($_SESSION['pharmacy_item_discounts'])) {
        $_SESSION['pharmacy_item_discounts'] = [];
    }
    $_SESSION['pharmacy_item_discounts'][$item_id] = ['type' => $item_discount_type, 'value' => $item_discount_value];
}

$bill_list = isset($_SESSION['pharmacy_bill_list']) ? $_SESSION['pharmacy_bill_list'] : [];
$item_discounts = isset($_SESSION['pharmacy_item_discounts']) ? $_SESSION['pharmacy_item_discounts'] : [];
$discount_type = isset($_SESSION['pharmacy_discount_type']) ? $_SESSION['pharmacy_discount_type'] : '';
$discount_value = isset($_SESSION['pharmacy_discount_value']) ? $_SESSION['pharmacy_discount_value'] : '';
$visit_id = isset($_POST['visit_id']) ? intval($_POST['visit_id']) : 0;

// Output updated bill list and summary
header('Content-Type: text/html; charset=UTF-8');
echo render_bill_list_and_summary($bill_list, $item_discounts, $discount_type, $discount_value, $visit_id); 
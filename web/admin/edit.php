<?php
declare(strict_types=1);
/* Add, edit or duplicate a component (FSD §7.2), with a live label preview. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require_once dirname(__DIR__) . '/lib/print.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
$id = (int) ($_GET['id'] ?? 0);
$copy = (int) ($_GET['copy'] ?? 0);
$errors = [];

if ($id > 0) {
    $existing = get_item($id);
    if ($existing === null) {
        http_response_code(404);
        exit('Item not found.');
    }
    $item = array_intersect_key($existing, ITEM_FIELDS);
} elseif ($copy > 0 && ($src = get_item($copy))) {
    $item = array_intersect_key($src, ITEM_FIELDS);
} else {
    $s = settings();
    $item = array_fill_keys(array_keys(ITEM_FIELDS), '');
    $item['box'] = $s['last_box'];
    $item['bin'] = $s['last_bin'];
}

if (is_post()) {
    $item = clean_item($_POST);
    $errors = validate_item($item);
    if (!$errors) {
        if ($id > 0) {
            update_item($id, $item);
            flash_set('Saved: ' . $item['box'] . '/' . $item['bin'] . ' ' . $item['label1']);
        } else {
            $id = create_item($item);
            flash_set('Added: ' . $item['box'] . '/' . $item['bin'] . ' ' . $item['label1']);
        }
        $next = $_POST['next'] ?? '';
        if ($next === 'print_another') {
            [$n, $err] = queue_labels([$id]);
            flash_set(($err ?? queued_message($n)) . ' Saved ' . $item['box'] . '/' . $item['bin'] . ' ' . $item['label1'] . '.');
            redirect('admin/edit.php');
        }
        if ($next === 'another') {
            redirect('admin/edit.php');
        }
        redirect('admin/?q=' . rawurlencode($item['box'] . '/' . $item['bin']));
    }
}

$s = settings();
page_start($id > 0 ? 'Edit' : 'Add', true, ['preview.js']);
flash_show();
echo '<h1>', $id > 0 ? 'Edit component' : ($copy > 0 ? 'Duplicate component' : 'Add component'), '</h1>';
if ($errors) {
    echo '<p class="error">Please fix: ', h(implode(' ', $errors)), '</p>';
}
echo '<form method="post" class="edit" autocomplete="off">', csrf_field();
echo '<div class="row2">';
foreach (['box', 'bin'] as $f) {
    [$label, $max] = ITEM_FIELDS[$f];
    echo '<label><span>', $label, ' <span class="req">*</span></span><input name="', $f, '" value="', h($item[$f]), '" maxlength="', $max,
        '" required', $f === 'box' ? ' autofocus' : '', ' data-preview="', $f, '"', isset($errors[$f]) ? ' class="bad"' : '', '></label>';
}
echo '</div>';
foreach (['label1', 'label2', 'label3', 'keywords'] as $f) {
    [$label, $max, $req] = ITEM_FIELDS[$f];
    echo '<label><span>', $label, $req ? ' <span class="req">*</span>' : '', '</span><input name="', $f, '" value="', h($item[$f]),
        '" maxlength="', $max, '"', $req ? ' required' : '', str_starts_with($f, 'label') ? ' data-preview="' . $f . '"' : '',
        isset($errors[$f]) ? ' class="bad"' : '', '></label>';
}
echo '<label>Comments<textarea name="comments" rows="3" maxlength="2000">', h($item['comments']), '</textarea></label>';

$layout = [
    'font_box' => $s['font_box'], 'font_bin' => $s['font_bin'], 'font_label1' => $s['font_label1'],
    'font_label2' => $s['font_label2'], 'font_label3' => $s['font_label3'],
    'margin_left_mm' => $s['margin_left_mm'], 'margin_right_mm' => $s['margin_right_mm'],
    'margin_top_mm' => $s['margin_top_mm'], 'margin_bottom_mm' => $s['margin_bottom_mm'],
    'boxbin_side' => $s['boxbin_side'],
];
echo '<figure class="preview"><div id="label-preview" data-layout="', h(json_encode($layout)), '"></div>',
    '<figcaption id="preview-note">Label preview (actual size on a typical phone ≈ 2×). The printed label is rendered by the print agent.</figcaption></figure>';
echo '<div class="buttons"><button type="submit" class="primary">Save</button>';
if ($id === 0) {
    echo '<button type="submit" name="next" value="another">Save and add another</button>',
        '<button type="submit" name="next" value="print_another">Save, print label, add another</button>';
}
echo '<a href="', h(url('admin/')), '">Cancel</a></div></form>';
page_end(true);

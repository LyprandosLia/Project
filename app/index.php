<?php
require __DIR__ . '/db.php';

$site_user = getenv('SITE_USERNAME');
$site_pass = getenv('SITE_PASSWORD');

$given_user = $_SERVER['PHP_AUTH_USER'] ?? '';
$given_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!hash_equals($site_user, $given_user) || !hash_equals($site_pass, $given_pass)) {
    header('WWW-Authenticate: Basic realm="Items"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    add_item($_POST['name'], $_POST['note'] ?? '');
    header('Location: /');
    exit;
}

$items = get_items();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Items Menu</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #1a1a1a; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; }
    .card h3 { margin: 0 0 4px 0; }
    .card p { margin: 0; color: #444; }
    form.add { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 16px; }
    input, textarea { width: 100%; padding: 8px; margin-bottom: 8px; box-sizing: border-box; }
    button { background: #2563eb; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; }
  </style>
</head>
<body>
  <h1>Items - Changed by Lee - Test 4</h1>

  <?php if (empty($items)): ?>
    <p>No items yet.</p>
  <?php endif; ?>

  <?php foreach ($items as $item): ?>
    <div class="card">
      <h3><?= htmlspecialchars($item['name']) ?></h3>
      <?php if (!empty($item['note'])): ?>
        <p><?= htmlspecialchars($item['note']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <form class="add" method="post" action="/">
    <input type="text" name="name" placeholder="Item name" required>
    <textarea name="note" placeholder="Note (optional)"></textarea>
    <button type="submit">Add item here!!</button>
  </form>
</body>
</html>

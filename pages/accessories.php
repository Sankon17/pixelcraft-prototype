<?php
// Accessories now live on the All Products page. Keep old links/bookmarks working.
$cat = $_GET['cat'] ?? $_GET['category'] ?? '';
header('Location: products.php' . ($cat !== '' ? '?cat=' . urlencode($cat) : ''), true, 301);
exit;

<?php
require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/db_connection.php';

function isTaggingReady(PDO $pdo): bool {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tags', 'product_tags')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $ready = ((int)($result['total'] ?? 0) === 2);

    return $ready;
}

function slugifyTag($value) {
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', '-', $value);
    $value = preg_replace('/[^a-z0-9\-]/', '', $value);
    $value = preg_replace('/-+/', '-', $value);
    return trim($value, '-');
}

function normalizeTagsInput(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $parts = explode(',', $raw);
    if (count($parts) > 10) {
        throw new InvalidArgumentException('A product can have at most 10 tags.');
    }

    $normalized = [];
    $seen = [];

    foreach ($parts as $part) {
        $tag = preg_replace('/\s+/', ' ', trim($part));
        if ($tag === '') {
            continue;
        }

        if (strlen($tag) > 30) {
            throw new InvalidArgumentException('Each tag must be 30 characters or less.');
        }

        if (!preg_match('/^[A-Za-z0-9\-\s]+$/', $tag)) {
            throw new InvalidArgumentException('Tags may only contain letters, numbers, spaces, and hyphens.');
        }

        $slug = slugifyTag($tag);
        if ($slug === '') {
            continue;
        }

        if (!isset($seen[$slug])) {
            $seen[$slug] = true;
            $normalized[] = [
                'name' => $tag,
                'slug' => $slug
            ];
        }
    }

    if (count($normalized) > 10) {
        throw new InvalidArgumentException('A product can have at most 10 unique tags.');
    }

    return $normalized;
}

function upsertTags(PDO $pdo, array $tagNames): array {
    if (!isTaggingReady($pdo)) {
        return [];
    }

    if (empty($tagNames)) {
        return [];
    }

    $selectStmt = $pdo->prepare('SELECT id FROM tags WHERE slug = ? LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');

    $tagIds = [];
    foreach ($tagNames as $tag) {
        $selectStmt->execute([$tag['slug']]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $tagIds[] = (int)$existing['id'];
            continue;
        }

        $insertStmt->execute([$tag['name'], $tag['slug']]);
        $tagIds[] = (int)$pdo->lastInsertId();
    }

    return $tagIds;
}

function syncProductTags(PDO $pdo, int $productId, array $tagIds): bool {
    if (!isTaggingReady($pdo)) {
        return true;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM product_tags WHERE product_id = ?');
    if (!$deleteStmt->execute([$productId])) {
        return false;
    }

    if (empty($tagIds)) {
        return true;
    }

    $insertStmt = $pdo->prepare('INSERT INTO product_tags (product_id, tag_id) VALUES (?, ?)');
    foreach ($tagIds as $tagId) {
        if (!$insertStmt->execute([$productId, $tagId])) {
            return false;
        }
    }

    return true;
}

function getProductTags(PDO $pdo, int $productId): array {
    if (!isTaggingReady($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT t.id, t.name, t.slug FROM tags t INNER JOIN product_tags pt ON pt.tag_id = t.id WHERE pt.product_id = ? ORDER BY t.name');
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllTags(PDO $pdo): array {
    if (!isTaggingReady($pdo)) {
        return [];
    }

    $stmt = $pdo->query('SELECT id, name, slug FROM tags ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExistingTagIds(PDO $pdo, array $tagIds): array {
    if (!isTaggingReady($pdo) || empty($tagIds)) {
        return [];
    }

    $normalizedIds = [];
    foreach ($tagIds as $tagId) {
        $tagId = (int)$tagId;
        if ($tagId > 0) {
            $normalizedIds[] = $tagId;
        }
    }

    $normalizedIds = array_values(array_unique($normalizedIds));
    if (empty($normalizedIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE id IN ($placeholders)");
    $stmt->execute($normalizedIds);

    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

function resolveTagIdsForSave(PDO $pdo, string $rawTags, array $selectedTagIds = []): array {
    if (!isTaggingReady($pdo)) {
        return [];
    }

    $normalizedTags = normalizeTagsInput($rawTags);
    $upsertedIds = upsertTags($pdo, $normalizedTags);
    $existingSelectedIds = getExistingTagIds($pdo, $selectedTagIds);

    return array_values(array_unique(array_merge($upsertedIds, $existingSelectedIds)));
}

function renderTagChips(array $tags): string {
    if (empty($tags)) {
        return '';
    }

    $visibleTags = array_slice($tags, 0, 6);
    $hiddenCount = count($tags) - count($visibleTags);
    $html = '<div class="tag-chip-list">';

    foreach ($visibleTags as $tag) {
        $safeTag = htmlspecialchars($tag['name'], ENT_QUOTES);
        $html .= '<span class="tag-chip">' . $safeTag . '</span>';
    }

    if ($hiddenCount > 0) {
        $html .= '<span class="tag-chip tag-chip-more">+' . $hiddenCount . '</span>';
    }

    $html .= '</div>';
    return $html;
}

// Get products with statistics
function getProducts($pdo, $search = '', $status = '', $tag = '') {
    $taggingReady = isTaggingReady($pdo);

    $sql = "SELECT p.*,";

    if ($taggingReady) {
        $sql .= " GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') AS tags_csv,";
    } else {
        $sql .= " NULL AS tags_csv,";
    }

    $sql .= "
            (SELECT COUNT(*) FROM scrape_log WHERE product_id = p.id AND status = 'success') as scrape_count,
            (SELECT timestamp FROM scrape_log WHERE product_id = p.id ORDER BY timestamp DESC LIMIT 1) as last_scrape
            FROM products p";

    if ($taggingReady) {
        $sql .= " LEFT JOIN product_tags pt ON pt.product_id = p.id
                  LEFT JOIN tags t ON t.id = pt.tag_id";
    }

    $sql .= " WHERE 1=1";
    
    $params = [];
    
    if ($search) {
        $sql .= " AND (p.product_name LIKE ? OR p.url LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($status) {
        $sql .= " AND p.scrape_status = ?";
        $params[] = $status;
    }

    if ($tag && $taggingReady) {
        $sql .= " AND EXISTS (
            SELECT 1
            FROM product_tags ptf
            INNER JOIN tags tf ON tf.id = ptf.tag_id
            WHERE ptf.product_id = p.id
              AND (tf.slug = ? OR tf.name = ?)
        )";
        $params[] = slugifyTag($tag);
        $params[] = $tag;
    }
    
    $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProduct($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function saveProduct($pdo, $product) {
    try {
        $rawTags = (string)($product['tags'] ?? '');
        $selectedTagIds = is_array($product['selected_tag_ids'] ?? null) ? $product['selected_tag_ids'] : [];

        if (!isTaggingReady($pdo) && (trim($rawTags) !== '' || !empty($selectedTagIds))) {
            $_SESSION['message'] = 'Tagging tables are not ready. Run php local/apply_tagging_migration.php first.';
            $_SESSION['message_type'] = 'error';
            return false;
        }

        $pdo->beginTransaction();
        $tagIds = resolveTagIdsForSave($pdo, $rawTags, $selectedTagIds);

        if (isset($product['id']) && $product['id']) {
            // Update existing product
            $stmt = $pdo->prepare("UPDATE products SET url = ?, product_name = ?, price = ?, stock = ?, updated_at = ? WHERE id = ?");
            $updated = $stmt->execute([
                $product['url'],
                $product['product_name'],
                $product['price'],
                $product['stock'],
                date('Y-m-d H:i:s'),
                $product['id']
            ]);

            if (!$updated) {
                $pdo->rollBack();
                return false;
            }

            if (!syncProductTags($pdo, (int)$product['id'], $tagIds)) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();
            return true;
        } else {
            // Create new product with validation
            if (empty($product['url'])) {
                $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("INSERT INTO products (url, scrape_status, created_at, updated_at) VALUES (?, 'active', ?, ?)");
            $now = date('Y-m-d H:i:s');
            $created = $stmt->execute([$product['url'], $now, $now]);
            if (!$created) {
                $pdo->rollBack();
                return false;
            }

            $productId = (int)$pdo->lastInsertId();
            if (!syncProductTags($pdo, $productId, $tagIds)) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();
            return true;
        }

    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = 'error';
        return false;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error saving product: " . $e->getMessage());
        return false;
    }
}

function bulkSyncTags($pdo, $productIds, $rawTags, $selectedTagIds = []) {
    if (empty($productIds)) {
        return false;
    }

    if (!isTaggingReady($pdo)) {
        $_SESSION['message'] = 'Tagging tables are not ready. Run php local/apply_tagging_migration.php first.';
        $_SESSION['message_type'] = 'error';
        return false;
    }

    try {
        $pdo->beginTransaction();
        $tagIds = resolveTagIdsForSave($pdo, (string)$rawTags, is_array($selectedTagIds) ? $selectedTagIds : []);

        foreach ($productIds as $productId) {
            if (!syncProductTags($pdo, (int)$productId, $tagIds)) {
                $pdo->rollBack();
                return false;
            }
        }

        $pdo->commit();
        return true;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = 'error';
        return false;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error bulk syncing product tags: ' . $e->getMessage());
        return false;
    }
}

function deleteProduct($pdo, $id) {
    try {
        // Delete scraping logs first (foreign key)
        $stmt = $pdo->prepare("DELETE FROM scrape_log WHERE product_id = ?");
        $stmt->execute([$id]);
        
        // Delete product
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Error deleting product: " . $e->getMessage());
        return false;
    }
}

function updateProductStatus($pdo, $id, $status) {
    $stmt = $pdo->prepare("UPDATE products SET scrape_status = ? WHERE id = ?");
    return $stmt->execute([$status, $id]);
}

function bulkUpdateStatus($pdo, $ids, $status) {
    if (empty($ids)) return false;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE products SET scrape_status = ? WHERE id IN ($placeholders)");
    return $stmt->execute(array_merge([$status], $ids));
}

function getStatistics($pdo) {
    $stats = [
        'total' => 0,
        'active' => 0,
        'halted' => 0,
        'total_scrapes' => 0
    ];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM products WHERE scrape_status = 'active'");
    $stats['active'] = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as halted FROM products WHERE scrape_status = 'halt'");
    $stats['halted'] = $stmt->fetch(PDO::FETCH_ASSOC)['halted'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_scrapes FROM scrape_log WHERE status = 'success'");
    $stats['total_scrapes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_scrapes'];
    
    return $stats;
}

// Handle AJAX requests for better UX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    echo json_encode(getStatistics($pdo));
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = false;
    $message = '';
    
    if (isset($_POST['delete'])) {
        $success = deleteProduct($pdo, $_POST['id']);
        $message = $success ? 'Product deleted successfully!' : 'Failed to delete product.';
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $success ? 'success' : 'error';
        
    } elseif (isset($_POST['create'])) {
        $success = saveProduct($pdo, [
            'url' => $_POST['url'],
            'tags' => $_POST['tags'] ?? ''
        ]);
        $message = $success
            ? 'Product created successfully! It will be scraped on next run.'
            : (($_SESSION['message_type'] ?? '') === 'error' && !empty($_SESSION['message'])
                ? $_SESSION['message']
                : 'Failed to create product.');
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $success ? 'success' : 'error';
        
    } elseif (isset($_POST['update_status'])) {
        $success = updateProductStatus($pdo, $_POST['id'], $_POST['status']);
        $statusLabel = $_POST['status'] === 'halt' ? 'halted' : 'activated';
        $message = $success ? "Product $statusLabel successfully!" : 'Failed to update product status.';
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $success ? 'success' : 'error';
        
    } elseif (isset($_POST['bulk_action']) && isset($_POST['selected_ids'])) {
        $ids = $_POST['selected_ids'];
        $action = $_POST['bulk_action'];
        
        if ($action === 'delete') {
            foreach ($ids as $id) {
                deleteProduct($pdo, $id);
            }
            $message = count($ids) . ' product(s) deleted successfully!';
        } elseif (in_array($action, ['active', 'halt'])) {
            bulkUpdateStatus($pdo, $ids, $action);
            $message = count($ids) . ' product(s) status updated!';
        } elseif ($action === 'set_tags') {
            $success = bulkSyncTags(
                $pdo,
                $ids,
                $_POST['bulk_tags'] ?? ''
            );
            $message = $success
                ? count($ids) . ' product(s) tags updated!'
                : (($_SESSION['message_type'] ?? '') === 'error' && !empty($_SESSION['message'])
                    ? $_SESSION['message']
                    : 'Failed to update tags in bulk.');
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $success ? 'success' : 'error';
            header('Location: '.$_SERVER['PHP_SELF']);
            exit;
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = 'success';
        
    } else {
        $success = saveProduct($pdo, [
            'id' => $_POST['id'] ?? null,
            'url' => $_POST['url'],
            'product_name' => $_POST['product_name'],
            'price' => $_POST['price'],
            'stock' => $_POST['stock'],
            'tags' => $_POST['tags'] ?? ''
        ]);
        $message = $success ? 
            (isset($_POST['id']) ? 'Product updated successfully!' : 'Product created successfully!') :
            ((($_SESSION['message_type'] ?? '') === 'error' && !empty($_SESSION['message']))
                ? $_SESSION['message']
                : 'Failed to save product.');
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $success ? 'success' : 'error';
    }
    
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$tagFilter = $_GET['tag'] ?? '';

$products = getProducts($pdo, $search, $statusFilter, $tagFilter);
$statistics = getStatistics($pdo);
$availableTags = getAllTags($pdo);
$availableTagNames = array_values(array_filter(array_map(static function ($tag) {
    return isset($tag['name']) ? (string)$tag['name'] : '';
}, $availableTags), static function ($name) {
    return $name !== '';
}));
$editingProduct = null;
$editingTags = [];

if (isset($_GET['edit'])) {
    $editingProduct = getProduct($pdo, $_GET['edit']);
    if ($editingProduct) {
        $editingTags = getProductTags($pdo, (int)$editingProduct['id']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #0f766e;
            --color-primary-hover: #115e59;
            --color-success: #15803d;
            --color-success-hover: #166534;
            --color-danger: #b91c1c;
            --color-danger-hover: #991b1b;
            --color-warning: #b45309;
            --color-warning-hover: #92400e;
            --color-info: #0369a1;
            --color-info-hover: #075985;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(circle at 15% 20%, rgba(15, 118, 110, 0.24) 0, rgba(15, 118, 110, 0) 40%),
                radial-gradient(circle at 85% 10%, rgba(217, 119, 6, 0.2) 0, rgba(217, 119, 6, 0) 35%),
                linear-gradient(140deg, #ecfeff 0%, #f8fafc 52%, #fffbeb 100%);
            min-height: 100vh;
            padding: 2rem;
            color: var(--gray-800);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            font-family: 'Space Grotesk', sans-serif;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.95rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary { background: rgba(79, 70, 229, 0.1); }
        .stat-icon.success { background: rgba(16, 185, 129, 0.1); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.1); }
        .stat-icon.info { background: rgba(59, 130, 246, 0.1); }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* Toolbar */
        .toolbar {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Search Bar */
        .search-wrapper {
            position: relative;
            min-width: 300px;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        /* Filter Select */
        .filter-select {
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .tag-filter {
            min-width: 200px;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background: var(--color-primary);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--color-primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--color-success);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: var(--color-success-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background: var(--color-danger);
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            background: var(--color-danger-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning {
            background: var(--color-warning);
            color: white;
        }

        .btn-warning:hover:not(:disabled) {
            background: var(--color-warning-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover:not(:disabled) {
            background: var(--gray-300);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-icon {
            padding: 0.5rem;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
        }

        thead th {
            padding: 1rem 1.5rem;
            text-align: left;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        thead th.checkbox-col {
            width: 40px;
            padding: 1rem 0.75rem;
        }

        tbody tr {
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.15s ease;
        }

        tbody tr:hover {
            background: var(--gray-50);
        }

        tbody tr.selected {
            background: rgba(15, 118, 110, 0.08);
        }

        tbody td {
            padding: 1.25rem 1.5rem;
            font-size: 0.95rem;
            color: var(--gray-700);
        }

        tbody td.checkbox-col {
            padding: 1rem 0.75rem;
        }

        .product-name {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .product-url {
            font-size: 0.875rem;
            color: var(--gray-500);
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            gap: 0.375rem;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--color-success);
        }

        .badge-halt {
            background: rgba(239, 68, 68, 0.1);
            color: var(--color-danger);
        }

        .badge-resume {
            background: rgba(245, 158, 11, 0.1);
            color: var(--color-warning);
        }

        .tag-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.6rem;
        }

        .tag-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            background: rgba(15, 118, 110, 0.13);
            color: var(--color-primary-hover);
            border: 1px solid rgba(15, 118, 110, 0.2);
        }

        .tag-chip-more {
            background: rgba(75, 85, 99, 0.15);
            color: var(--gray-700);
            border-color: rgba(75, 85, 99, 0.2);
        }

        .badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow);
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--color-success);
            border-left: 4px solid var(--color-success);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--color-danger);
            border-left: 4px solid var(--color-danger);
        }

        .message-icon {
            font-size: 1.5rem;
        }

        /* Form Styles */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .input-help {
            margin-top: 0.4rem;
        }

        .tag-select-multi {
            min-height: 140px;
            padding: 0.6rem;
        }

        .tag-picker {
            position: relative;
        }

        .tag-picker-control {
            min-height: 48px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            background: #fff;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.45rem;
            padding: 0.45rem 0.6rem;
            cursor: text;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .tag-picker-control:focus-within {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .tag-picker-selected {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-items: center;
        }

        .tag-picker-token {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.55rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            background: rgba(15, 118, 110, 0.13);
            color: var(--color-primary-hover);
            border: 1px solid rgba(15, 118, 110, 0.2);
        }

        .tag-picker-token button {
            border: none;
            background: transparent;
            cursor: pointer;
            color: inherit;
            font-size: 0.9rem;
            line-height: 1;
            padding: 0;
        }

        .tag-picker-input {
            border: none;
            outline: none;
            min-width: 180px;
            flex: 1;
            font-size: 0.95rem;
            padding: 0.25rem 0.1rem;
        }

        .tag-picker-dropdown {
            position: absolute;
            top: calc(100% + 0.35rem);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            max-height: 220px;
            overflow-y: auto;
            z-index: 20;
        }

        .tag-picker-option {
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
            cursor: pointer;
            color: var(--gray-700);
        }

        .tag-picker-option:hover,
        .tag-picker-option.active {
            background: rgba(15, 118, 110, 0.1);
            color: var(--color-primary-hover);
        }

        .tag-picker-empty {
            padding: 0.6rem 0.8rem;
            color: var(--gray-500);
            font-size: 0.88rem;
        }

        .bulk-tags-wrap {
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        /* Checkbox */
        .checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--color-primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-left, .toolbar-right {
                width: 100%;
            }

            .search-wrapper {
                min-width: 100%;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* Utility Classes */
        .text-muted {
            color: var(--gray-500);
            font-size: 0.875rem;
        }

        .mt-2 { margin-top: 0.5rem; }
        .mb-2 { margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 class="page-title">🛍️ Product Management Dashboard</h1>
                    <p class="page-subtitle">Manage your product scraping queue and monitor performance</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="index.php" class="btn btn-primary">
                        📊 View Price Dashboard
                    </a>
                    <a href="logout.php" class="btn btn-secondary">
                        Log out
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="message <?= $_SESSION['message_type'] ?? 'success' ?>">
                <span class="message-icon"><?= ($_SESSION['message_type'] ?? 'success') === 'success' ? '✓' : '⚠' ?></span>
                <span><?= $_SESSION['message'] ?></span>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']) ?>
        <?php endif ?>

        <!-- Statistics -->
        <?php if (!isset($_GET['create']) && !isset($_GET['edit'])): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon primary">📦</div>
                    <div>
                        <div class="stat-label">Total Products</div>
                        <div class="stat-value"><?= $statistics['total'] ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon success">✓</div>
                    <div>
                        <div class="stat-label">Active Scraping</div>
                        <div class="stat-value"><?= $statistics['active'] ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">⏸</div>
                    <div>
                        <div class="stat-label">Halted Products</div>
                        <div class="stat-value"><?= $statistics['halted'] ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon info">📊</div>
                    <div>
                        <div class="stat-label">Total Scrapes</div>
                        <div class="stat-value"><?= number_format($statistics['total_scrapes']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif ?>

        <!-- Create Form -->
        <?php if(isset($_GET['create'])): ?>
            <div class="form-card">
                <h2 class="form-title">➕ Add New Product</h2>
                <form method="POST">
                    <input type="hidden" name="create" value="1">
                    <div class="form-group">
                        <label class="form-label">Product URL *</label>
                        <input type="url" name="url" class="form-control" 
                               placeholder="https://www.tokopedia.com/..." required>
                        <p class="text-muted mt-2">Enter the full product page URL to start scraping</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="create-tag-picker">Tags</label>
                        <div class="tag-picker" data-initial-tags="">
                            <div class="tag-picker-control" id="create-tag-picker">
                                <div class="tag-picker-selected"></div>
                                <input type="text" class="tag-picker-input" placeholder="Click to select tags or type and press Enter">
                            </div>
                            <div class="tag-picker-dropdown" hidden></div>
                            <input type="hidden" name="tags" class="tag-picker-hidden" value="">
                        </div>
                        <p class="text-muted input-help">Choose from suggestions or create new tags. Press Enter or comma to add.</p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="create" class="btn btn-success">
                            ✓ Create Product
                        </button>
                        <a href="manage.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

        <!-- Edit Form -->
        <?php elseif(isset($_GET['edit']) && $editingProduct): ?>
            <div class="form-card">
                <h2 class="form-title">✏️ Edit Product</h2>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $editingProduct['id'] ?>">

                    <div class="form-group">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="product_name" class="form-control" 
                               value="<?= htmlspecialchars($editingProduct['product_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Price (Rp) *</label>
                        <input type="number" step="0.01" name="price" class="form-control" 
                               value="<?= $editingProduct['price'] ?? '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Stock Quantity *</label>
                        <input type="number" name="stock" class="form-control" 
                               value="<?= $editingProduct['stock'] ?? 0 ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Product URL *</label>
                        <input type="url" name="url" class="form-control" 
                               value="<?= htmlspecialchars($editingProduct['url'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit-tag-picker">Tags</label>
                        <div class="tag-picker" data-initial-tags="<?= htmlspecialchars(implode(', ', array_column($editingTags, 'name')), ENT_QUOTES) ?>">
                            <div class="tag-picker-control" id="edit-tag-picker">
                                <div class="tag-picker-selected"></div>
                                <input type="text" class="tag-picker-input" placeholder="Click to select tags or type and press Enter">
                            </div>
                            <div class="tag-picker-dropdown" hidden></div>
                            <input type="hidden" name="tags" class="tag-picker-hidden" value="<?= htmlspecialchars(implode(', ', array_column($editingTags, 'name')), ENT_QUOTES) ?>">
                        </div>
                        <p class="text-muted input-help">Choose from suggestions or create new tags. Press Enter or comma to add.</p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-success">
                            ✓ Update Product
                        </button>
                        <a href="manage.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

        <!-- Product List -->
        <?php else: ?>
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; flex: 1;">
                        <div class="search-wrapper">
                            <span class="search-icon">🔍</span>
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search products..." 
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="halt" <?= $statusFilter === 'halt' ? 'selected' : '' ?>>Halted</option>
                            <option value="resume" <?= $statusFilter === 'resume' ? 'selected' : '' ?>>Resume</option>
                        </select>

                        <select name="tag" class="filter-select tag-filter" onchange="this.form.submit()" aria-label="Filter by tag">
                            <option value="">All Tags</option>
                            <?php foreach ($availableTags as $tagOption): ?>
                                <option value="<?= htmlspecialchars($tagOption['slug'], ENT_QUOTES) ?>" <?= $tagFilter === $tagOption['slug'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tagOption['name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach ?>
                        </select>

                        <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                        <?php if ($search || $statusFilter || $tagFilter): ?>
                            <a href="manage.php" class="btn btn-secondary btn-sm">Clear</a>
                        <?php endif ?>
                    </form>
                </div>

                <div class="toolbar-right">
                    <button id="bulkActionBtn" class="btn btn-secondary btn-sm" disabled onclick="showBulkActions()">
                        Bulk Actions
                    </button>
                    <a href="?create" class="btn btn-success">
                        ➕ Add Product
                    </a>
                </div>
            </div>

            <!-- Table -->
            <div class="table-container">
                <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3 class="empty-state-title">No products found</h3>
                        <p>Start by adding your first product to scrape</p>
                        <a href="?create" class="btn btn-primary" style="margin-top: 1rem;">Add Your First Product</a>
                    </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th class="checkbox-col">
                                    <input type="checkbox" class="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Product Info</th>
                                <th>Status</th>
                                <th>Scrapes</th>
                                <th>Last Scrape</th>
                                <th>Added</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <?php
                                $productTagList = [];
                                if (!empty($product['tags_csv'])) {
                                    foreach (explode(',', $product['tags_csv']) as $tagName) {
                                        $cleanTag = trim($tagName);
                                        if ($cleanTag !== '') {
                                            $productTagList[] = ['name' => $cleanTag];
                                        }
                                    }
                                }
                            ?>
                            <tr id="row-<?= $product['id'] ?>">
                                <td class="checkbox-col">
                                    <input type="checkbox" class="checkbox row-checkbox" 
                                           value="<?= $product['id'] ?>" 
                                           onchange="updateBulkButton()">
                                </td>
                                <td>
                                    <div class="product-name">
                                        <?= htmlspecialchars($product['product_name'] ?: 'Pending scrape...') ?>
                                    </div>
                                    <div class="product-url" title="<?= htmlspecialchars($product['url']) ?>">
                                        <?= htmlspecialchars($product['url']) ?>
                                    </div>
                                    <?= renderTagChips($productTagList) ?>
                                    <?php if ($product['price']): ?>
                                        <div class="text-muted mt-2">
                                            💰 Rp <?= number_format($product['price'], 0, ',', '.') ?> 
                                            | 📦 Stock: <?= $product['stock'] ?? 0 ?>
                                        </div>
                                    <?php endif ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $product['scrape_status'] ?>">
                                        <?= ucfirst($product['scrape_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= $product['scrape_count'] ?></strong> times
                                </td>
                                <td>
                                    <?= $product['last_scrape'] ? date('M d, Y H:i', strtotime($product['last_scrape'])) : '<span class="text-muted">Never</span>' ?>
                                </td>
                                <td>
                                    <?= date('M d, Y', strtotime($product['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="action-buttons" style="justify-content: center;">
                                        <a href="?edit=<?= $product['id'] ?>" class="btn btn-primary btn-sm" title="Edit">
                                            ✏️
                                        </a>
                                        
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            
                                            <?php if ($product['scrape_status'] === 'active'): ?>
                                                <input type="hidden" name="status" value="halt">
                                                <button type="submit" class="btn btn-warning btn-sm" 
                                                        title="Pause scraping"
                                                        onclick="return confirm('Pause scraping for this product?')">
                                                    ⏸
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="status" value="active">
                                                <button type="submit" class="btn btn-success btn-sm" 
                                                        title="Resume scraping"
                                                        onclick="return confirm('Resume scraping for this product?')">
                                                    ▶️
                                                </button>
                                            <?php endif ?>
                                            
                                            <button type="submit" name="delete" class="btn btn-danger btn-sm" 
                                                    title="Delete product"
                                                    onclick="return confirm('⚠️ Delete this product?\n\nThis will also delete all scraping history.')">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <?php endif ?>
            </div>
        <?php endif ?>
    </div>

    <!-- Bulk Actions Modal (Hidden by default) -->
    <div id="bulkModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 12px; max-width: 400px; box-shadow: var(--shadow-lg);">
            <h3 style="margin-bottom: 1rem; color: var(--gray-900);">Bulk Actions</h3>
            <p style="color: var(--gray-600); margin-bottom: 1.5rem;" id="bulkCount"></p>
            
            <form method="POST">
                <input type="hidden" name="bulk_action" id="bulkActionInput">
                <div id="selectedIdsContainer"></div>

                <div class="bulk-tags-wrap">
                    <label class="form-label" for="bulk-tag-picker" style="margin-bottom: 0;">Bulk Tags</label>
                    <div class="tag-picker" data-initial-tags="">
                        <div class="tag-picker-control" id="bulk-tag-picker">
                            <div class="tag-picker-selected"></div>
                            <input type="text" class="tag-picker-input" placeholder="Click to select tags or type and press Enter">
                        </div>
                        <div class="tag-picker-dropdown" hidden></div>
                        <input type="hidden" name="bulk_tags" id="bulk-tags" class="tag-picker-hidden" value="">
                    </div>
                    <p class="text-muted" style="margin: 0;">Bulk tag action replaces selected products tags with chosen tags.</p>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <button type="button" class="btn btn-primary" onclick="submitBulkAction('set_tags')">
                        🏷️ Update Tags for Selected
                    </button>
                    <button type="button" class="btn btn-success" onclick="submitBulkAction('active')">
                        ▶️ Activate All Selected
                    </button>
                    <button type="button" class="btn btn-warning" onclick="submitBulkAction('halt')">
                        ⏸ Halt All Selected
                    </button>
                    <button type="button" class="btn btn-danger" onclick="submitBulkAction('delete')">
                        🗑️ Delete All Selected
                    </button>
                </div>
                
                <button type="button" class="btn btn-secondary" onclick="closeBulkModal()" style="width: 100%;">
                    Cancel
                </button>
            </form>
        </div>
    </div>

    <script>
        const TAG_DEBUG = true;

        function tagLog(...args) {
            if (TAG_DEBUG) {
                console.log('[TagPicker]', ...args);
            }
        }

        window.addEventListener('error', (event) => {
            if (TAG_DEBUG) {
                console.error('[TagPicker][WindowError]', event.message, event.filename, event.lineno);
            }
        });

        const availableTagSuggestions = (() => {
            const raw = <?= json_encode($availableTagNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>;
            const normalized = Array.isArray(raw) ? raw.filter(Boolean).map(String) : [];
            tagLog('Loaded suggestions', { count: normalized.length, values: normalized });
            return normalized;
        })();

        function normalizeTagValue(tag) {
            return tag.trim().replace(/\s+/g, ' ');
        }

        function isTagValueValid(tag) {
            return /^[A-Za-z0-9\-\s]+$/.test(tag) && tag.length <= 30;
        }

        function initTagPickers() {
            const pickers = document.querySelectorAll('.tag-picker');
            tagLog('initTagPickers called', { pickerCount: pickers.length });

            pickers.forEach((picker, pickerIndex) => {
                const control = picker.querySelector('.tag-picker-control');
                const selectedWrap = picker.querySelector('.tag-picker-selected');
                const input = picker.querySelector('.tag-picker-input');
                const dropdown = picker.querySelector('.tag-picker-dropdown');
                const hiddenInput = picker.querySelector('.tag-picker-hidden');

                if (!control || !selectedWrap || !input || !dropdown || !hiddenInput) {
                    tagLog('picker missing required node(s)', {
                        pickerIndex,
                        hasControl: !!control,
                        hasSelectedWrap: !!selectedWrap,
                        hasInput: !!input,
                        hasDropdown: !!dropdown,
                        hasHiddenInput: !!hiddenInput
                    });
                    return;
                }

                const initialRaw = picker.getAttribute('data-initial-tags') || hiddenInput.value || '';
                tagLog('picker init', { pickerIndex, initialRaw });

                let selectedTags = initialRaw
                    .split(',')
                    .map(normalizeTagValue)
                    .filter(Boolean)
                    .filter((value, index, arr) => arr.findIndex(v => v.toLowerCase() === value.toLowerCase()) === index)
                    .slice(0, 10);

                function syncHiddenInput() {
                    hiddenInput.value = selectedTags.join(', ');
                    tagLog('syncHiddenInput', { pickerIndex, hiddenValue: hiddenInput.value });
                }

                function renderTokens() {
                    selectedWrap.innerHTML = '';
                    selectedTags.forEach((tag) => {
                        const token = document.createElement('span');
                        token.className = 'tag-picker-token';
                        token.innerHTML = `${tag}<button type="button" aria-label="Remove ${tag}">&times;</button>`;
                        token.querySelector('button').addEventListener('click', () => {
                            selectedTags = selectedTags.filter((value) => value.toLowerCase() !== tag.toLowerCase());
                            syncHiddenInput();
                            renderTokens();
                            renderDropdown(input.value);
                        });
                        selectedWrap.appendChild(token);
                    });
                }

                function closeDropdown() {
                    dropdown.hidden = true;
                    dropdown.innerHTML = '';
                    tagLog('closeDropdown', { pickerIndex });
                }

                function renderDropdown(query = '') {
                    const q = normalizeTagValue(query).toLowerCase();
                    const suggestions = (availableTagSuggestions || [])
                        .filter((tag) => !selectedTags.some((selected) => selected.toLowerCase() === tag.toLowerCase()))
                        .filter((tag) => q === '' || tag.toLowerCase().includes(q));

                    const normalizedQuery = normalizeTagValue(query);
                    const canAddTyped = normalizedQuery !== ''
                        && isTagValueValid(normalizedQuery)
                        && !selectedTags.some((selected) => selected.toLowerCase() === normalizedQuery.toLowerCase());

                    tagLog('renderDropdown', {
                        pickerIndex,
                        query,
                        normalizedQuery,
                        selectedCount: selectedTags.length,
                        suggestionsCount: suggestions.length,
                        canAddTyped
                    });

                    dropdown.innerHTML = '';

                    if (canAddTyped) {
                        const addOption = document.createElement('div');
                        addOption.className = 'tag-picker-option active';
                        addOption.textContent = `Add "${normalizedQuery}"`;
                        addOption.addEventListener('mousedown', (e) => {
                            e.preventDefault();
                            addTag(normalizedQuery);
                        });
                        dropdown.appendChild(addOption);
                    }

                    if (!suggestions.length && !canAddTyped) {
                        dropdown.innerHTML = '<div class="tag-picker-empty">No matching tags</div>';
                        dropdown.hidden = false;
                        return;
                    }

                    suggestions.slice(0, 12).forEach((tag, index) => {
                        const option = document.createElement('div');
                        option.className = 'tag-picker-option' + (index === 0 && !canAddTyped ? ' active' : '');
                        option.textContent = tag;
                        option.setAttribute('role', 'option');
                        option.addEventListener('mousedown', (e) => {
                            e.preventDefault();
                            addTag(tag);
                        });
                        dropdown.appendChild(option);
                    });
                    dropdown.hidden = false;
                }

                function addTag(rawTag) {
                    const tag = normalizeTagValue(rawTag);
                    tagLog('addTag called', { pickerIndex, rawTag, normalized: tag });
                    if (!tag) {
                        tagLog('addTag skipped: empty');
                        return;
                    }

                    if (selectedTags.some((value) => value.toLowerCase() === tag.toLowerCase())) {
                        tagLog('addTag skipped: duplicate', { tag });
                        input.value = '';
                        renderDropdown('');
                        return;
                    }

                    if (!isTagValueValid(tag)) {
                        tagLog('addTag invalid format', { tag });
                        alert('Tag must contain only letters, numbers, spaces, or hyphens and be 30 characters or less.');
                        return;
                    }

                    if (selectedTags.length >= 10) {
                        tagLog('addTag blocked: max tags reached');
                        alert('You can only set up to 10 tags per product.');
                        return;
                    }

                    selectedTags.push(tag);
                    tagLog('addTag success', { pickerIndex, tag, selectedTags });
                    syncHiddenInput();
                    renderTokens();
                    input.value = '';
                    renderDropdown('');
                }

                control.addEventListener('click', () => {
                    tagLog('control click', { pickerIndex });
                    input.focus();
                    renderDropdown(input.value);
                });

                control.addEventListener('pointerdown', () => {
                    tagLog('control pointerdown', { pickerIndex });
                    renderDropdown(input.value);
                });

                input.addEventListener('focus', () => {
                    tagLog('input focus', { pickerIndex });
                    renderDropdown(input.value);
                });
                input.addEventListener('input', () => {
                    tagLog('input change', { pickerIndex, value: input.value });
                    renderDropdown(input.value);
                });

                input.addEventListener('keydown', (e) => {
                    tagLog('keydown', { pickerIndex, key: e.key, value: input.value });
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        addTag(input.value);
                        return;
                    }

                    if (e.key === 'Backspace' && input.value === '' && selectedTags.length > 0) {
                        selectedTags.pop();
                        syncHiddenInput();
                        renderTokens();
                        renderDropdown(input.value);
                    }
                });

                document.addEventListener('click', (e) => {
                    if (!picker.contains(e.target)) {
                        closeDropdown();
                    }
                });

                syncHiddenInput();
                renderTokens();
            });
        }

        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    cb.closest('tr').classList.add('selected');
                } else {
                    cb.closest('tr').classList.remove('selected');
                }
            });
            updateBulkButton();
        }

        function updateBulkButton() {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            const bulkBtn = document.getElementById('bulkActionBtn');
            const selectAllCheckbox = document.getElementById('selectAll');

            if (!bulkBtn || !selectAllCheckbox) {
                tagLog('updateBulkButton skipped (list controls not present)');
                return;
            }
            
            bulkBtn.disabled = checkboxes.length === 0;
            bulkBtn.textContent = checkboxes.length > 0 ? `Bulk Actions (${checkboxes.length})` : 'Bulk Actions';
            
            // Update row highlighting
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                if (cb.checked) {
                    cb.closest('tr').classList.add('selected');
                } else {
                    cb.closest('tr').classList.remove('selected');
                }
            });
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.row-checkbox');
            selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
            selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
        }

        function showBulkActions() {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            if (checkboxes.length === 0) return;
            
            document.getElementById('bulkCount').textContent = `${checkboxes.length} product(s) selected`;
            
            // Add hidden inputs for selected IDs
            const container = document.getElementById('selectedIdsContainer');
            container.innerHTML = '';
            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });
            
            document.getElementById('bulkModal').style.display = 'flex';
        }

        function closeBulkModal() {
            document.getElementById('bulkModal').style.display = 'none';
        }

        function submitBulkAction(action) {
            let confirmMsg = '';
            if (action === 'delete') {
                confirmMsg = '⚠️ Delete all selected products?\n\nThis will also delete all scraping history for these products.';
            } else if (action === 'active') {
                confirmMsg = 'Activate scraping for all selected products?';
            } else if (action === 'halt') {
                confirmMsg = 'Pause scraping for all selected products?';
            } else if (action === 'set_tags') {
                const hasTypedTags = (document.getElementById('bulk-tags')?.value || '').trim() !== '';

                if (!hasTypedTags) {
                    alert('Choose at least one tag before applying bulk tag update.');
                    return;
                }

                confirmMsg = 'Update tags for all selected products?\n\nThis replaces their existing tags with the selected values.';
            }
            
            if (confirm(confirmMsg)) {
                document.getElementById('bulkActionInput').value = action;
                document.getElementById('bulkModal').querySelector('form').submit();
            }
        }

        // Close modal when clicking outside
        document.getElementById('bulkModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeBulkModal();
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            tagLog('DOMContentLoaded');
            if (document.getElementById('bulkActionBtn')) {
                updateBulkButton();
            } else {
                tagLog('bulk controls not found on this page mode');
            }
            initTagPickers();
        });
    </script>
</body>
</html>
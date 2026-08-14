<?php
require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/push_config.php';

$debug = env('APP_DEBUG', '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

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

function parseTagsCsv($tagsCsv) {
    if (!$tagsCsv) {
        return [];
    }

    $tags = [];
    foreach (explode(',', $tagsCsv) as $tag) {
        $cleanTag = trim($tag);
        if ($cleanTag !== '') {
            $tags[] = [
                'name' => $cleanTag,
                'slug' => slugifyTag($cleanTag)
            ];
        }
    }

    return $tags;
}

function getAllTags($pdo) {
    if (!isTaggingReady($pdo)) {
        return [];
    }

    $stmt = $pdo->query('SELECT id, name, slug FROM tags ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildTagFilterUrl($tagSlug, $search, $sortBy, $sortOrder) {
    $params = [
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder
    ];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($tagSlug !== '') {
        $params['tag'] = $tagSlug;
    }

    return 'index.php?' . http_build_query($params);
}

function buildFilterClearUrl($search, $sortBy, $sortOrder, $tagFilter, $removeKey) {
    $params = [
        'search' => $search,
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
        'tag' => $tagFilter
    ];

    unset($params[$removeKey]);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return empty($params) ? 'index.php' : ('index.php?' . http_build_query($params));
}

// Get products with enhanced data
function getProductsWithStats($pdo, $search = '', $sortBy = 'name', $sortOrder = 'asc', $tag = '') {
    $taggingReady = isTaggingReady($pdo);

    $sql = "SELECT 
                p.id, 
                p.product_name, 
                p.price, 
                p.stock, 
                p.url, 
                p.scrape_status,
                p.created_at,
                " . ($taggingReady ? "GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',')" : "NULL") . " AS tags_csv,
                (SELECT timestamp FROM scrape_log 
                 WHERE product_id = p.id AND status = 'success' 
                 ORDER BY timestamp DESC LIMIT 1) as last_scrape,
                (SELECT COUNT(*) FROM scrape_log 
                 WHERE product_id = p.id AND status = 'success') as total_scrapes,
                (SELECT price FROM scrape_log 
                 WHERE product_id = p.id AND status = 'success' 
                 ORDER BY timestamp DESC LIMIT 1, 1) as previous_price
                FROM products p";

            if ($taggingReady) {
            $sql .= "
                LEFT JOIN product_tags pt ON pt.product_id = p.id
                LEFT JOIN tags t ON t.id = pt.tag_id";
            }

            $sql .= "
            WHERE p.product_name IS NOT NULL";

    $params = [];
    
    if ($search) {
        $sql .= " AND (p.product_name LIKE :search OR p.url LIKE :search)";
        $params['search'] = "%$search%";
    }

    if ($tag && $taggingReady) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM product_tags ptf
            INNER JOIN tags tf ON tf.id = ptf.tag_id
            WHERE ptf.product_id = p.id
              AND (tf.slug = :tag_slug OR tf.name = :tag_name)
        )";
        $params['tag_slug'] = slugifyTag($tag);
        $params['tag_name'] = $tag;
    }

    $sql .= " GROUP BY p.id";
    
    // Sorting
    switch ($sortBy) {
        case 'price':
            $sql .= " ORDER BY p.price " . ($sortOrder === 'desc' ? 'DESC' : 'ASC');
            break;
        case 'stock':
            $sql .= " ORDER BY p.stock " . ($sortOrder === 'desc' ? 'DESC' : 'ASC');
            break;
        case 'updated':
            $sql .= " ORDER BY last_scrape " . ($sortOrder === 'desc' ? 'DESC' : 'ASC');
            break;
        default:
            $sql .= " ORDER BY p.product_name " . ($sortOrder === 'desc' ? 'DESC' : 'ASC');
    }
    
    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPriceStatistics($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT 
        MAX(price) as max_price, 
        MIN(price) as min_price, 
        AVG(price) as avg_price,
        COUNT(*) as data_points,
        MIN(timestamp) as first_scrape,
        MAX(timestamp) as last_scrape
        FROM scrape_log 
        WHERE product_id = ?
        AND status = 'success'
        AND price IS NOT NULL
        AND price > 0");
    
    $stmt->execute([$product_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: [
        'max_price' => null,
        'min_price' => null,
        'avg_price' => null,
        'data_points' => 0,
        'first_scrape' => null,
        'last_scrape' => null
    ];
}

function getDashboardStats($pdo) {
    $stats = [];
    
    // Total products
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE product_name IS NOT NULL");
    $stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Active tracking
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM products WHERE scrape_status = 'active'");
    $stats['active_tracking'] = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    // Low stock alerts
    $stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM products WHERE stock > 0 AND stock < 5");
    $stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['low_stock'];
    
    // Out of stock
    $stmt = $pdo->query("SELECT COUNT(*) as out_stock FROM products WHERE stock = 0");
    $stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['out_stock'];
    
    return $stats;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_price_stats' && isset($_GET['product_id'])) {
        echo json_encode(getPriceStatistics($pdo, (int)$_GET['product_id']));
        exit;
    }
    
    if ($_GET['action'] === 'get_dashboard_stats') {
        echo json_encode(getDashboardStats($pdo));
        exit;
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'name';
$sortOrder = $_GET['sort_order'] ?? 'asc';
$tagFilter = $_GET['tag'] ?? '';

$products = getProductsWithStats($pdo, $search, $sortBy, $sortOrder, $tagFilter);
$dashboardStats = getDashboardStats($pdo);
$availableTags = getAllTags($pdo);

$activeFilters = [];
if ($search !== '') {
    $activeFilters[] = [
        'label' => 'Search: ' . $search,
        'clear_url' => buildFilterClearUrl($search, $sortBy, $sortOrder, $tagFilter, 'search')
    ];
}
if ($sortBy !== 'name' || $sortOrder !== 'asc') {
    $activeFilters[] = [
        'label' => 'Sort: ' . ucfirst($sortBy) . ' (' . strtoupper($sortOrder) . ')',
        'clear_url' => buildTagFilterUrl($tagFilter, $search, 'name', 'asc')
    ];
}
if ($tagFilter !== '') {
    $activeFilters[] = [
        'label' => 'Tag: ' . $tagFilter,
        'clear_url' => buildFilterClearUrl($search, $sortBy, $sortOrder, $tagFilter, 'tag')
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Sentinel - Price Monitoring Dashboard</title>
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
            --color-info: #0369a1;
            --primary: var(--color-primary);
            --primary-hover: var(--color-primary-hover);
            --success: var(--color-success);
            --success-hover: var(--color-success-hover);
            --danger: var(--color-danger);
            --danger-hover: var(--color-danger-hover);
            --warning: var(--color-warning);
            --info: var(--color-info);
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
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 2px 4px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(circle at 8% 18%, rgba(15, 118, 110, 0.26) 0, rgba(15, 118, 110, 0) 38%),
                radial-gradient(circle at 84% 12%, rgba(180, 83, 9, 0.22) 0, rgba(180, 83, 9, 0) 34%),
                linear-gradient(145deg, #ecfeff 0%, #f8fafc 50%, #fffbeb 100%);
            min-height: 100vh;
            padding: 2rem;
            color: var(--gray-800);
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            background: linear-gradient(130deg, #0f766e 0%, #0e7490 40%, #b45309 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        /* Dashboard Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--success));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: var(--gray-50);
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1;
        }

        /* Toolbar */
        .toolbar {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .toolbar-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .toolbar-left {
            display: flex;
            gap: 1rem;
            flex: 1;
            flex-wrap: wrap;
        }

        .search-wrapper {
            position: relative;
            min-width: 300px;
            flex: 1;
            max-width: 400px;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        .sort-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .sort-select {
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
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

        .sort-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
        }

        .filter-pills {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.7rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            background: rgba(15, 118, 110, 0.12);
            color: #0f766e;
            border: 1px solid rgba(15, 118, 110, 0.24);
        }

        .filter-pill-clear {
            text-decoration: none;
            color: inherit;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.1);
        }

        .tag-select {
            min-width: 220px;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
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

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: var(--success-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: var(--danger-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: var(--warning-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .page-actions {
            display: flex;
            gap: 0.625rem;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .page-actions .btn {
            flex: 0 0 auto;
        }

        .toolbar-form {
            display: contents;
        }

        /* Products Grid */
        .products-container {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .products-header {
            background: var(--gray-50);
            padding: 1rem 1.5rem;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.95rem;
        }

        .products-grid {
            display: grid;
            gap: 1px;
            background: var(--gray-200);
        }

        .product-card {
            background: white;
            padding: 1.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: center;
        }

        .product-card:hover {
            background: var(--gray-50);
        }

        .product-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .product-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .product-url {
            font-size: 0.875rem;
            color: var(--gray-500);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 600px;
        }

        .product-meta {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .tag-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            margin-top: 0.2rem;
        }

        .tag-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.26rem 0.62rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
            background: rgba(14, 116, 144, 0.12);
            color: #0e7490;
            border: 1px solid rgba(14, 116, 144, 0.22);
        }

        .tag-chip:hover {
            background: rgba(14, 116, 144, 0.22);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .meta-icon {
            font-size: 1.125rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--gray-900);
        }

        .price-tag {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .price-change {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            margin-left: 0.5rem;
        }

        .price-up {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .price-down {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .price-same {
            background: var(--gray-100);
            color: var(--gray-500);
        }

        .stock-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            gap: 0.375rem;
        }

        .stock-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        .in-stock {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .low-stock {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .out-stock {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .product-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: flex-end;
        }

        .view-chart-btn {
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-chart-btn:hover {
            background: var(--primary-hover);
            transform: translateX(-4px);
        }

        button:focus-visible,
        a:focus-visible,
        input:focus-visible,
        select:focus-visible {
            outline: 3px solid rgba(15, 118, 110, 0.35);
            outline-offset: 2px;
        }

        .external-link {
            color: var(--gray-400);
            transition: color 0.2s ease;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .external-link:hover {
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .empty-text {
            font-size: 1rem;
            margin-bottom: 2rem;
        }

        /* Modal Styles */
        .product-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            backdrop-filter: blur(4px);
        }

        .product-modal.show {
            display: flex;
        }

        .product-modal-content {
            background: white;
            border-radius: 16px;
            max-width: 1200px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 2rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        .modal-subtitle {
            font-size: 1rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
        }

        .close-modal {
            cursor: pointer;
            color: var(--gray-400);
            transition: color 0.2s ease;
            background: var(--gray-100);
            border-radius: 8px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            color: var(--gray-700);
            background: var(--gray-200);
        }

        .modal-body {
            padding: 2rem;
        }

        /* Price Statistics */
        .price-statistics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: var(--gray-50);
            padding: 1.25rem;
            border-radius: 12px;
            border: 2px solid var(--gray-200);
        }

        .stat-box-label {
            font-size: 0.8125rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .stat-box-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* Chart Controls */
        .chart-controls {
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .toggle-buttons {
            display: flex;
            gap: 0.5rem;
            background: white;
            padding: 0.375rem;
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }

        .toggle-btn {
            flex: 1;
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--gray-600);
        }

        .toggle-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .date-range-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .date-range-btn {
            padding: 0.625rem 1.25rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }

        .date-range-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .date-range-btn:hover:not(.active) {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Chart Container */
        .chart-wrapper {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            border: 2px solid var(--gray-200);
        }

        .chart-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        #priceChart {
            max-height: 400px;
            height: 400px !important;
            width: 100% !important;
        }

        .chart-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chart-btn {
            padding: 0.5rem 1rem;
            background: var(--gray-100);
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .chart-btn:hover {
            background: var(--gray-200);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.5rem;
            }

            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .toolbar-row {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-left {
                width: 100%;
            }

            .sort-controls {
                width: 100%;
                justify-content: space-between;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .page-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .page-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .toolbar-form {
                display: flex;
                flex-direction: column;
                width: 100%;
                gap: 0.75rem;
            }

            .sort-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .sort-select,
            .btn,
            .btn-secondary {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .product-card {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .product-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .product-actions {
                width: 100%;
                align-items: stretch;
            }

            .view-chart-btn {
                width: 100%;
                justify-content: center;
            }

            .product-url,
            .search-wrapper {
                max-width: 100%;
                min-width: 100%;
            }
        }

        @media (max-width: 640px) {
            .toolbar-row {
                gap: 1.5rem;
            }

            .chart-toolbar,
            .chart-actions,
            .toggle-buttons,
            .date-range-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .chart-actions {
                width: 100%;
            }

            .chart-btn,
            .date-range-btn {
                width: 100%;
                justify-content: center;
            }

            .price-statistics {
                grid-template-columns: 1fr;
            }

            .product-modal {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">📊 Price Sentinel Dashboard</h1>
                <p class="page-subtitle">Real-time product price and stock monitoring</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="subscribeBtn" class="btn btn-primary" onclick="toggleSubscription()">
                    🔔 Enable Notifications
                </button>
                <a href="manage.php" class="btn btn-success">
                    🛍️ Manage Products
                </a>
                <a href="logout.php" class="btn btn-secondary">
                    Log out
                </a>
            </div>
        </div>

        <!-- Dashboard Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Total Products</div>
                        <div class="stat-value"><?= $dashboardStats['total_products'] ?></div>
                    </div>
                    <div class="stat-icon">📦</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Active Tracking</div>
                        <div class="stat-value"><?= $dashboardStats['active_tracking'] ?></div>
                    </div>
                    <div class="stat-icon">✓</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Low Stock Alerts</div>
                        <div class="stat-value"><?= $dashboardStats['low_stock'] ?></div>
                    </div>
                    <div class="stat-icon">⚠</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Out of Stock</div>
                        <div class="stat-value"><?= $dashboardStats['out_of_stock'] ?></div>
                    </div>
                    <div class="stat-icon">❌</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-row">
                <div class="toolbar-left">
                    <form method="get" style="display: contents;">
                        <div class="search-wrapper">
                            <span class="search-icon">🔍</span>
                            <input 
                                type="text" 
                                name="search" 
                                class="search-input"
                                placeholder="Search products..." 
                                value="<?= htmlspecialchars($search) ?>">
                        </div>
                        
                        <div class="sort-controls">
                            <select name="sort_by" class="sort-select" onchange="this.form.submit()">
                                <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Name</option>
                                <option value="price" <?= $sortBy === 'price' ? 'selected' : '' ?>>Sort by Price</option>
                                <option value="stock" <?= $sortBy === 'stock' ? 'selected' : '' ?>>Sort by Stock</option>
                                <option value="updated" <?= $sortBy === 'updated' ? 'selected' : '' ?>>Sort by Updated</option>
                            </select>

                            <select name="tag" class="sort-select tag-select" onchange="this.form.submit()" aria-label="Filter products by tag">
                                <option value="">All Tags</option>
                                <?php foreach ($availableTags as $tagOption): ?>
                                    <option value="<?= htmlspecialchars($tagOption['slug'], ENT_QUOTES) ?>" <?= $tagFilter === $tagOption['slug'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tagOption['name'], ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                            
                            <button type="submit" name="sort_order" value="<?= $sortOrder === 'asc' ? 'desc' : 'asc' ?>" 
                                    class="btn btn-secondary" title="Toggle sort order">
                                <?= $sortOrder === 'asc' ? '⬆️' : '⬇️' ?>
                            </button>
                        </div>

                        <button type="submit" class="btn btn-primary">Search</button>
                        
                        <?php if ($search || $sortBy !== 'name' || $sortOrder !== 'asc' || $tagFilter !== ''): ?>
                            <a href="index.php" class="btn btn-secondary">Clear</a>
                        <?php endif ?>
                    </form>
                </div>
            </div>
            <?php if (!empty($activeFilters)): ?>
                <div class="filter-pills" aria-label="Active filters">
                    <?php foreach ($activeFilters as $filter): ?>
                        <span class="filter-pill">
                            <?= htmlspecialchars($filter['label'], ENT_QUOTES) ?>
                            <a class="filter-pill-clear" href="<?= htmlspecialchars($filter['clear_url'], ENT_QUOTES) ?>" aria-label="Clear filter">&times;</a>
                        </span>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>

        <!-- Products Container -->
        <div class="products-container">
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <h3 class="empty-title">No products found</h3>
                    <p class="empty-text">
                        <?= $search ? 'Try adjusting your search terms' : 'Start by adding products to track their prices' ?>
                    </p>
                    <a href="manage.php?create" class="btn btn-success">Add Your First Product</a>
                </div>
            <?php else: ?>
                <div class="products-header">
                    Showing <?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?>
                </div>
                <div class="products-grid">
                    <?php foreach ($products as $product): 
                        // Calculate price change
                        $priceChange = null;
                        $priceChangePercent = 0;
                        if ($product['previous_price'] && $product['price']) {
                            $priceChange = $product['price'] - $product['previous_price'];
                            $priceChangePercent = ($priceChange / $product['previous_price']) * 100;
                        }
                        
                        // Determine stock badge
                        $stock_class = $product['stock'] == 0 ? "out-stock" : 
                                      ($product['stock'] < 5 ? "low-stock" : "in-stock");
                        $stock_text = $product['stock'] == 0 ? "Out of Stock" : 
                                     ($product['stock'] < 5 ? "Low Stock" : "In Stock");
                    ?>
                    <div class="product-card" data-product-id="<?= $product['id'] ?>" data-product-name="<?= htmlspecialchars($product['product_name']) ?>">
                        <?php $productTags = parseTagsCsv($product['tags_csv'] ?? ''); ?>
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($product['product_name']) ?></div>
                            <div class="product-url" title="<?= htmlspecialchars($product['url']) ?>">
                                <?= htmlspecialchars($product['url']) ?>
                            </div>
                            <?php if (!empty($productTags)): ?>
                                <div class="tag-chip-list">
                                    <?php foreach ($productTags as $tag): ?>
                                        <a class="tag-chip" href="<?= htmlspecialchars(buildTagFilterUrl($tag['slug'], $search, $sortBy, $sortOrder), ENT_QUOTES) ?>">
                                            #<?= htmlspecialchars($tag['name'], ENT_QUOTES) ?>
                                        </a>
                                    <?php endforeach ?>
                                </div>
                            <?php endif ?>
                            <div class="product-meta">
                                <div class="meta-item">
                                    <span class="meta-icon">💰</span>
                                    <span class="price-tag">Rp<?= number_format($product['price'], 0, ',', '.') ?></span>
                                    <?php if ($priceChange !== null): ?>
                                        <span class="price-change <?= $priceChange > 0 ? 'price-up' : ($priceChange < 0 ? 'price-down' : 'price-same') ?>">
                                            <?= $priceChange > 0 ? '↑' : ($priceChange < 0 ? '↓' : '→') ?>
                                            <?= abs($priceChangePercent) < 0.01 ? '0%' : number_format(abs($priceChangePercent), 1) . '%' ?>
                                        </span>
                                    <?php endif ?>
                                </div>
                                <div class="meta-item">
                                    <span class="stock-badge <?= $stock_class ?>">
                                        <?= $stock_text ?> <?= $product['stock'] > 0 ? "({$product['stock']})" : '' ?>
                                    </span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-icon">📊</span>
                                    <span class="meta-value"><?= $product['total_scrapes'] ?></span> scrapes
                                </div>
                                <?php if ($product['last_scrape']): ?>
                                <div class="meta-item">
                                    <span class="meta-icon">🕐</span>
                                    Updated <?= date('M j, Y H:i', strtotime($product['last_scrape'])) ?>
                                </div>
                                <?php endif ?>
                            </div>
                        </div>
                        <div class="product-actions">
                            <button class="view-chart-btn" onclick="openModal(<?= $product['id'] ?>, '<?= htmlspecialchars($product['product_name'], ENT_QUOTES) ?>')">
                                📈 View Chart
                            </button>
                            <a href="<?= htmlspecialchars($product['url']) ?>" target="_blank" rel="noopener noreferrer" class="external-link" title="Open product page">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42L17.59 5H14V3z"/>
                                    <path d="M5 5h9v2H7v10h10v-7h2v9H5V5z"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>

    <!-- Price History Modal -->
    <div class="product-modal" id="priceModal">
        <div class="product-modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">
                        <span id="contentType">Price</span> History
                    </h2>
                    <p class="modal-subtitle" id="modal-product-name"></p>
                </div>
                <span class="close-modal" onclick="closeModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                    </svg>
                </span>
            </div>
            
            <div class="modal-body">
                <!-- Price Statistics -->
                <div class="price-statistics" id="priceStatistics">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- Chart Controls -->
                <div class="chart-controls">
                    <div class="toggle-buttons">
                        <button class="toggle-btn active" data-type="price" onclick="switchChartType('price')">
                            💰 Price History
                        </button>
                        <button class="toggle-btn" data-type="stock" onclick="switchChartType('stock')">
                            📦 Stock History
                        </button>
                    </div>
                    
                    <div class="date-range-controls">
                        <button class="date-range-btn active" data-range="all" onclick="setDateRange('all')">All Time</button>
                        <button class="date-range-btn" data-range="7" onclick="setDateRange(7)">7 Days</button>
                        <button class="date-range-btn" data-range="30" onclick="setDateRange(30)">30 Days</button>
                        <button class="date-range-btn" data-range="90" onclick="setDateRange(90)">90 Days</button>
                    </div>
                </div>

                <!-- Chart Container -->
                <div class="chart-wrapper">
                    <div class="chart-toolbar">
                        <div class="chart-actions">
                            <button class="chart-btn" id="resetZoom" onclick="resetChartZoom()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                                </svg>
                                Reset Zoom
                            </button>
                            <button class="chart-btn" id="downloadChart" onclick="downloadChart()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                    <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                                </svg>
                                Download
                            </button>
                        </div>
                        <div style="color: var(--gray-500); font-size: 0.875rem;">
                            💡 Scroll to zoom, drag to pan
                        </div>
                    </div>
                    <div style="position: relative; height: 400px; width: 100%;">
                        <canvas id="priceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        let chartInstance = null;
        let currentProductId = null;
        let currentProductName = null;
        let currentType = 'price';
        let currentDateRange = 'all';

        // Open modal and load chart
        function openModal(productId, productName) {
            currentProductId = productId;
            currentProductName = productName;
            
            document.getElementById('modal-product-name').textContent = productName;
            document.getElementById('priceModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            loadPriceStatistics(productId);
            loadChart(productId, 'price', 'all');
        }

        // Close modal
        function closeModal() {
            document.getElementById('priceModal').classList.remove('show');
            document.body.style.overflow = '';
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
            }
        }

        // Close modal when clicking outside
        document.getElementById('priceModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('priceModal').classList.contains('show')) {
                closeModal();
            }
        });

        // Load price statistics
        function loadPriceStatistics(productId) {
            fetch(`index.php?action=get_price_stats&product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    const statsHtml = `
                        <div class="stat-box">
                            <div class="stat-box-label">Highest Price</div>
                            <div class="stat-box-value">Rp${data.max_price ? Number(data.max_price).toLocaleString('id-ID') : 'N/A'}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-label">Lowest Price</div>
                            <div class="stat-box-value">Rp${data.min_price ? Number(data.min_price).toLocaleString('id-ID') : 'N/A'}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-label">Average Price</div>
                            <div class="stat-box-value">Rp${data.avg_price ? Number(data.avg_price).toLocaleString('id-ID') : 'N/A'}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-label">Data Points</div>
                            <div class="stat-box-value">${data.data_points || 0}</div>
                        </div>
                    `;
                    document.getElementById('priceStatistics').innerHTML = statsHtml;
                })
                .catch(error => {
                    console.error('Error loading statistics:', error);
                    document.getElementById('priceStatistics').innerHTML = '<p style="color: var(--danger);">Failed to load statistics</p>';
                });
        }

        // Load chart data
        function loadChart(productId, type, dateRange) {
            let url = `get_history.php?product_id=${productId}`;
            
            if (dateRange !== 'all') {
                const days = parseInt(dateRange);
                const endDate = new Date();
                const startDate = new Date();
                startDate.setDate(endDate.getDate() - days);
                url += `&start_date=${startDate.toISOString().split('T')[0]}&end_date=${endDate.toISOString().split('T')[0]}`;
            }
            
            fetch(url)
                .then(response => response.json())
                .then(history => {
                    updateChart(history, type);
                })
                .catch(error => {
                    console.error('Error loading chart:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load chart data'
                    });
                });
        }

        // Update chart with data
        function updateChart(history, type) {
            const ctx = document.getElementById('priceChart').getContext('2d');
            
            let data;
            if (type === 'price') {
                data = history
                    .filter(entry => entry.price !== null && entry.price > 0)
                    .map(entry => ({
                        x: new Date(entry.timestamp),
                        y: entry.price
                    }));
            } else {
                data = history
                    .filter(entry => entry.stock !== null)
                    .map(entry => ({
                        x: new Date(entry.timestamp),
                        y: entry.stock
                    }));
            }

            if (chartInstance) {
                chartInstance.destroy();
            }

            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            if (type === 'price') {
                gradient.addColorStop(0, 'rgba(79, 70, 229, 0.2)');
                gradient.addColorStop(1, 'rgba(79, 70, 229, 0)');
            } else {
                gradient.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
                gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
            }

            chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    datasets: [{
                        label: type === 'price' ? 'Price (Rp)' : 'Stock',
                        data: data,
                        borderColor: type === 'price' ? 'rgb(79, 70, 229)' : 'rgb(16, 185, 129)',
                        backgroundColor: gradient,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: type === 'price' ? 'rgb(79, 70, 229)' : 'rgb(16, 185, 129)',
                        pointBorderWidth: 2,
                        pointHoverBackgroundColor: type === 'price' ? 'rgb(79, 70, 229)' : 'rgb(16, 185, 129)',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 14,
                                    weight: '600'
                                },
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (type === 'price') {
                                        label += 'Rp' + Number(context.parsed.y).toLocaleString('id-ID');
                                    } else {
                                        label += context.parsed.y + ' units';
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day',
                                displayFormats: {
                                    day: 'MMM dd'
                                }
                            },
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                }
                            }
                        },
                        y: {
                            beginAtZero: type === 'stock',
                            ticks: {
                                callback: function(value) {
                                    if (type === 'price') {
                                        return 'Rp' + Number(value).toLocaleString('id-ID', {maximumFractionDigits: 0});
                                    }
                                    return value;
                                },
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });
        }

        // Switch chart type
        function switchChartType(type) {
            currentType = type;
            document.getElementById('contentType').textContent = type === 'price' ? 'Price' : 'Stock';
            
            // Update button states
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-type') === type);
            });
            
            loadChart(currentProductId, type, currentDateRange);
        }

        // Set date range
        function setDateRange(range) {
            currentDateRange = range;
            
            // Update button states
            document.querySelectorAll('.date-range-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-range') == range);
            });
            
            loadChart(currentProductId, currentType, range);
        }

        // Reset chart zoom
        function resetChartZoom() {
            if (chartInstance) {
                chartInstance.resetZoom();
            }
        }

        // Download chart
        function downloadChart() {
            if (chartInstance) {
                const link = document.createElement('a');
                link.download = `${currentProductName}_${currentType}_history.png`;
                link.href = chartInstance.toBase64Image();
                link.click();
            }
        }

        // Make product cards clickable (deprecated, using button now)
        document.querySelectorAll('.product-card').forEach(card => {
            const viewBtn = card.querySelector('.view-chart-btn');
            if (viewBtn) {
                card.style.cursor = 'default'; // Remove pointer cursor from card
            }
        });
    </script>
    <script>
        const publicKey = '<?php echo VAPID_PUBLIC_KEY; ?>';

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding)
                .replace(/\-/g, '+')
                .replace(/_/g, '/');

            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);

            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        let isSubscribed = false;

        function updateSubscriptionButton() {
            const btn = document.getElementById('subscribeBtn');
            if (isSubscribed) {
                btn.textContent = '🔕 Unsubscribe';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-danger');
            } else {
                btn.textContent = '🔔 Enable Notifications';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-primary');
            }
            btn.disabled = false;
        }

        async function toggleSubscription() {
            const btn = document.getElementById('subscribeBtn');
            btn.disabled = true;
            
            if (isSubscribed) {
                await unsubscribeUser();
            } else {
                await subscribeUser();
            }
        }

        async function subscribeUser() {
            if (!('serviceWorker' in navigator)) {
                alert('Service Worker is not supported in this browser.');
                return;
            }

            if (!('PushManager' in window)) {
                alert('Push messaging is not supported in this browser.');
                return;
            }

            try {
                const registration = await navigator.serviceWorker.register('sw.js');
                console.log('Service Worker registered:', registration);

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey)
                });

                console.log('User is subscribed:', subscription);

                // Send subscription to server
                await fetch('subscribe.php', {
                    method: 'POST',
                    body: JSON.stringify(subscription),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                alert('Successfully subscribed to price alerts!');
                isSubscribed = true;
                updateSubscriptionButton();

            } catch (error) {
                console.error('Failed to subscribe the user: ', error);
                alert('Failed to subscribe: ' + error.message);
                updateSubscriptionButton();
            }
        }

        async function unsubscribeUser() {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                if (subscription) {
                    // Remove from server
                    await fetch('unsubscribe.php', {
                        method: 'POST',
                        body: JSON.stringify({ endpoint: subscription.endpoint }),
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });

                    // Unsubscribe locally
                    await subscription.unsubscribe();
                    
                    alert('Successfully unsubscribed.');
                    isSubscribed = false;
                    updateSubscriptionButton();
                }
            } catch (error) {
                console.error('Error unsubscribing', error);
                alert('Error unsubscribing: ' + error.message);
                updateSubscriptionButton();
            }
        }

        // Check if already subscribed
        if ('serviceWorker' in navigator && 'PushManager' in window) {
            navigator.serviceWorker.ready.then(function(registration) {
                registration.pushManager.getSubscription().then(function(subscription) {
                    isSubscribed = !!subscription;
                    updateSubscriptionButton();
                });
            });
        }
    </script>
</body>
</html>
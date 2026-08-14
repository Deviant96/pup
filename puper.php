<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/push_notifications.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;
use React\Promise\Promise;
use React\EventLoop\Factory;
use Nesk\Rialto\Exceptions\Node\FatalException;

// Start measuring time
$startTime = microtime(true);
$sessionId = date('Y-m-d_H-i-s');

// Create logs folder structure
$logsBaseDir = __DIR__ . '/logs';
$sessionLogsDir = $logsBaseDir . '/session_' . $sessionId;
if (!is_dir($sessionLogsDir)) {
    if (mkdir($sessionLogsDir, 0777, true)) {
        echo "📁 Created session logs folder: $sessionLogsDir\n";
    } else {
        die("❌ Failed to create session logs folder: $sessionLogsDir\n");
    }
}

// Create session log file
$sessionLogFile = $sessionLogsDir . '/session.log';
$productsLogDir = $sessionLogsDir . '/products';
if (!is_dir($productsLogDir)) {
    mkdir($productsLogDir, 0777, true);
}

// Create session folder for screenshots (use absolute path)
$sessionFolder = __DIR__ . '/screenshots/session_' . $sessionId;
if (!is_dir($sessionFolder)) {
    if (mkdir($sessionFolder, 0777, true)) {
        echo "📁 Created session folder: $sessionFolder\n";
    } else {
        echo "❌ Failed to create session folder: $sessionFolder\n";
        echo "Current working directory: " . getcwd() . "\n";
    }
} else {
    echo "📁 Session folder already exists: $sessionFolder\n";
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("❌ Database connection failed: missing PDO instance.\n");
}

echo "✅ Connected to database\n";

function writeLog($message, $level = 'INFO', $logFile = null, $productId = null) {
    // Set timezone if not already set
    date_default_timezone_set('UTC');

    // Create timestamp
    $timestamp = date('Y-m-d H:i:s');

    // Normalize level to uppercase
    $level = strtoupper($level);

    // Format the log message
    $logMessage = "[$timestamp] [$level] $message";

    // Print to output
    echo $logMessage . PHP_EOL;

    // Write to session log file if provided
    if ($logFile !== null) {
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
    }

    // Write to product-specific log if productId is provided
    if ($productId !== null && $logFile !== null) {
        $productLogFile = dirname($logFile) . '/products/product_' . $productId . '.log';
        file_put_contents($productLogFile, $logMessage . PHP_EOL, FILE_APPEND);
    }
}


function launchBrowser($loop) {
    $puppeteer = new Puppeteer([
        'js_extra' => /** @lang JavaScript */ "
            const puppeteer = require('puppeteer-extra');
            const StealthPlugin = require('puppeteer-extra-plugin-stealth');
            puppeteer.use(StealthPlugin());
            instruction.setDefaultResource(puppeteer);
        "
    ]);

    $browser = $puppeteer->launch([
        'headless' => true,
        'args' => [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--window-size=1366,768',
        ],
        'ignoreHTTPSErrors' => true,
        'stealth'=> true,    
        'timeout' => 60000,
        'protocolTimeout' => 60000,
    ], ['loop' => $loop]);
    
    return $browser;
}


function setupPage($browser) {
    $page = $browser->newPage();

    // Add stealth script
    // $stealthScriptPath = __DIR__ . '/node_modules/puppeteer-extra-plugin-stealth/index.js';
    // if (!file_exists($stealthScriptPath)) {
    //     throw new Exception("Stealth script not found at: $stealthScriptPath");
    // } else {
    //     echo "Stealth script found at: $stealthScriptPath\n";
    // }

    // $page->evaluateOnNewDocument(
    //     file_get_contents($stealthScriptPath)
    // );
    
    // Rotate user agents to avoid detection
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'
    ];
    $page->setUserAgent($userAgents[array_rand($userAgents)]);
    

    // Set screen size
	// $page->setViewport(['width' => 1280, 'height' => 720]);

    // Spoof common bot-detectable properties
    $page->evaluateOnNewDocument(JsFunction::createWithBody("
        Object.defineProperty(navigator, 'webdriver', { get: () => false });

        window.chrome = {
            runtime: {},
            // you can add more properties if needed
        };

        Object.defineProperty(navigator, 'plugins', {
            get: () => [1, 2, 3, 4, 5],
        });

        Object.defineProperty(navigator, 'languages', {
            get: () => ['en-US', 'en'],
        });

        Object.defineProperty(navigator, 'platform', {
            get: () => 'Win32',
        });

        // Fix for navigator.permissions query on notifications
        const originalQuery = window.navigator.permissions.query;
        window.navigator.permissions.query = (parameters) =>
            parameters.name === 'notifications'
                ? Promise.resolve({ state: Notification.permission })
                : originalQuery(parameters);
    "));

    // Set request interception to block unnecessary resources (images, media, fonts)
    $page->setRequestInterception(true);
    // $page->on('request', function($request) {
    //     $resourceType = $request->resourceType();
    //     if (in_array($resourceType, ['image', 'media', 'font'])) {
    //         $request->abort();  // Block unnecessary resources
    //     } else {
    //         $request->continue();  // Allow the rest of the requests
    //     }
    // });

    
    // $page->on('request', function($request) {
    //     $blocked = ['image', 'font', 'media'];
    //     $request->respond($blocked ? ['abort' => true] : ['continue' => true]);
    // });

    $page->on('request', JsFunction::createWithBody("
        const resourceType = request.resourceType();
        const url = request.url();

        if (['image', 'stylesheet', 'font', 'media', 'other'].includes(resourceType)) {
            request.abort();
        } else if (url.endsWith('.png') || url.endsWith('.jpg') || url.endsWith('.jpeg') || url.endsWith('.gif') || url.endsWith('.svg') || url.endsWith('.webp')) {
            request.abort();
        } else {
            request.continue();
        }
    ")->parameters(['request']));
    
    return $page;
}

// Function to load a page and wait for the required element to appear
function loadPage($page, $target_url, $sessionLogFile = null, $productId = null) {
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException("Invalid URL provided: $target_url");
    }

    try {
        // Set navigation timeout and handle page load
        // Use 'networkidle2' to wait for JavaScript execution
        $response = $page->goto($target_url, [
            'waitUntil' => 'networkidle2',
            'timeout' => 90000
        ]);

        // Random wait time (5-8 seconds) to let JavaScript render content
        $waitTime = rand(5000, 8000);
        $page->waitForTimeout($waitTime);

        $status = $response->status();
        $url = $response->url();
        echo "STATUS is: " . $status . "\n";

        if (!$response) {
            throw new Exception("Navigation failed - no response received");
        }

        if (!$response->ok()) {
            throw new Exception("HTTP error: " . $response->status());
        }

        if ($status === 429) {
            $err = "Rate limited on $url\n";
            echo $err . "\n";
            throw new Exception($err);
        } elseif ($status === 403 || $status === 503) {
            $err = "Possibly bot-blocked on $url\n";
            echo $err . "\n";
            throw new Exception($err);
        }

        // No selector validation - just give JavaScript time to render
        writeLog("Page loaded, giving additional time for rendering", "INFO");
        $page->waitForTimeout(5000);

    } catch (Exception $e) {
        throw new Exception("Page load failed for URL {$target_url}: " . $e->getMessage());
    }
}

// Function to extract text content from the page
function extractText($page, $sessionLogFile = null, $productId = null) {
    // Try multiple selector strategies
    $name = null;
    $nameSelectors = [
        'div.css-1nylpq2 > h1',
        'h1[data-testid="pdpProductName"]',
        'h1.css-1os9ouf',
        'h1'
    ];
    
    foreach ($nameSelectors as $selector) {
        try {
            $name = $page->evaluate("document.querySelector('$selector')?.textContent");
            if ($name) {
                writeLog("Found product name with selector: $selector", "INFO", $sessionLogFile, $productId);
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    if (!$name) {
        throw new Exception("Could not extract product name");
    }
    
    // Try multiple price selectors
    $amount_raw = null;
    $priceSelectors = [
        'div.price',
        '[data-testid="pdpPrice"]',
        '.css-o5uqvq',
        'div[class*="price"]'
    ];
    
    foreach ($priceSelectors as $selector) {
        try {
            $amount_raw = $page->evaluate("document.querySelector('$selector')?.textContent");
            if ($amount_raw) {
                writeLog("Found price with selector: $selector", "INFO", $sessionLogFile, $productId);
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    if (!$amount_raw) {
        throw new Exception("Could not extract price");
    }
    
    $amount_clean = preg_replace('/[^\d]/', '', $amount_raw);
    $amount_int = (int) $amount_clean;
    
    // Try to get stock, but make it optional
    $stock = 0;
    $stockSelectors = [
        '.css-1h8vbi4 input',
        'input[aria-valuemax]',
        'input[type="number"]'
    ];
    
    foreach ($stockSelectors as $selector) {
        try {
            $stockValue = $page->evaluate("document.querySelector('$selector')?.getAttribute('aria-valuemax')");
            if ($stockValue) {
                $stock = (int) $stockValue;
                writeLog("Found stock with selector: $selector", "INFO", $sessionLogFile, $productId);
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    if ($stock === 0) {
        writeLog("Could not extract stock, defaulting to 0", "WARNING", $sessionLogFile, $productId);
    }

    $product = [
        'name' => trim($name),
        'price' => $amount_int,
        'stock' => $stock
    ];

    return $product;
}

function scrapeProduct($pdo, $product_id, $target_url, $browser, $sessionFolder, $sessionLogFile) {
    $page = null;
    $product_name = null;
    $price = null;
    $stock = null;
    $status = 'failure';
    $error_message = null;

    try {
        // Random delay to avoid rate limiting (1-3 seconds)
        $delay = rand(1000000, 3000000);
        usleep($delay);
        writeLog("Starting scrape for product $product_id after " . ($delay/1000000) . "s delay", "INFO", $sessionLogFile, $product_id);
        
        // Set up a new page
        $page = setupPage($browser);

        // var_dump($page);
        
        // Load the page and extract data
        loadPage($page, $target_url, $sessionLogFile, $product_id);
        $product = extractText($page, $sessionLogFile, $product_id);
        
        // Validate extracted data
        if (empty($product['name']) || empty($product['price']) || !isset($product['stock'])) {
            throw new Exception("Invalid product data extracted");
        }

        // Assign values
        $product_name = $product['name'];
        $price = $product['price'];
        $stock = $product['stock'];

        // Check for price drop
        try {
            $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $old_price = $stmt->fetchColumn();

            if ($old_price && $price < $old_price) {
                $drop_percentage = ($old_price - $price) / $old_price;
                if ($drop_percentage > 0.10) {
                    writeLog("Price drop detected for $product_name! Old: $old_price, New: $price", "INFO", $sessionLogFile, $product_id);
                    sendPushNotification(
                        $pdo,
                        "Price Drop Alert: $product_name",
                        "Price dropped by " . round($drop_percentage * 100) . "%! Now: " . number_format($price),
                        $target_url,
                        function ($message, $level = 'INFO') {
                            writeLog($message, $level);
                        }
                    );
                }
            }
        } catch (Exception $e) {
            writeLog("Error checking price drop: " . $e->getMessage(), "ERROR", $sessionLogFile, $productId);
        }

        $status = 'success';

        // Update product info first
        if (!updateProductInfoById($pdo, $product_id, $product_name, $price, $stock)) {
            throw new Exception("Failed to update product information");
        } else {
            writeLog("Product $product_id: updated with name '$product_name' and price '$price'.", "INFO", $sessionLogFile, $product_id);
        }

        // Take screenshot on success
        try {
            $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($product_name, 0, 50));
            $screenshotPath = "$sessionFolder/product_{$product_id}_{$sanitizedName}.png";
            $page->screenshot([
                'path' => $screenshotPath,
                'fullPage' => true
            ]);
            writeLog("Screenshot saved: $screenshotPath", "INFO", $sessionLogFile, $product_id);
        } catch (Exception $e) {
            writeLog("Screenshot failed: " . $e->getMessage(), "WARNING", $sessionLogFile, $product_id);
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        writeLog("Error scraping product ID $product_id: " . $error_message, "ERROR", $sessionLogFile, $product_id);
        
        // Take screenshot on failure for debugging
        if ($page !== null) {
            try {
                $screenshotPath = "$sessionFolder/product_{$product_id}_ERROR_" . date('His') . ".png";
                $page->screenshot([
                    'path' => $screenshotPath,
                    'fullPage' => true,
                    'timeout' => 5000  // Short timeout for error screenshots
                ]);
                writeLog("Error screenshot saved: $screenshotPath", "INFO", $sessionLogFile, $product_id);
            } catch (Exception $screenshotEx) {
                writeLog("Error screenshot failed: " . $screenshotEx->getMessage(), "WARNING", $sessionLogFile, $product_id);
            }
        }
    } finally {
        // Log the scraping attempt
        try {
            // echo "UP UNTIL HERE, THE STATUS IS: " . $status . "\n";
            if (!insertScrapingLog($pdo, $product_id, $product_name, $price, $stock, $status, $error_message)) {
                writeLog("Failed to insert scraping log for product ID $product_id", "ERROR", $sessionLogFile, $product_id);
            }
        } catch (Exception $e) {
            writeLog("Error logging scrape attempt: " . $e->getMessage(), "ERROR", $sessionLogFile, $product_id);
        }

        // Clean up
        if ($page !== null) {
            $page->close();
        }
    }
}

// Function to scrape multiple product pages in parallel
function scrapeMultipleProducts($pdo, $products, $sessionFolder, $sessionLogFile) {  
    $loop = React\EventLoop\Factory::create();
    $concurrency = 1; // Sequential scraping for better reliability
    $browser = launchBrowser($loop); // Launch the browser
    
    writeLog("Starting scraping session with " . count($products) . " products", "INFO", $sessionLogFile);

    // Control concurrent pages
    // $queue = new React\Promise\Queue($concurrency);
    
    $promises = [];
    // foreach ($urls as $url) {
    //     $promises[] = $queue->add(function () use ($url, $browser) {
    //         return scrapeProduct($url, $browser);
    //     });
    // }
    foreach ($products as $product) {
        $promises[] = (new Promise(function ($resolve, $reject) use ($pdo, $product, $browser, $sessionFolder, $sessionLogFile) {
        // $promises[] = $queue->enqueue(function () use ($url, $browser) {
        //     return new React\Promise\Promise(function ($resolve) use ($url, $browser) {
        //         scrapeProduct($url, $browser);
        //         $resolve();
        //     });


            try {
                scrapeProduct($pdo, $product['id'], $product['url'], $browser, $sessionFolder, $sessionLogFile);
                $resolve(null);  // Fix: Call resolve() with a value (null in this case)
            } catch (Exception $e) {
                $reject($e);
            }
        }))->then(null, function ($error) use ($product, $sessionLogFile) {
            // Handle individual errors without breaking entire process
            writeLog("Failed product: " . json_encode($product) . " - " . $error->getMessage(), "ERROR", $sessionLogFile);
        });
    }
    
    // Wait for all promises to be resolved
    \React\Promise\all($promises)->then(function () use ($browser) {
        echo "All pages scraped.\n";
        $browser->close();
    }, function ($error) {
        echo "Error scraping pages: $error\n";
    });

    // \React\Promise\all($promises, $concurrency)->then(function () use ($browser) {
    //     echo "Completed with controlled concurrency\n";
    //     closeBrowser($browser);
    // }, function ($error) {
    //     echo "Error scraping pages: $error\n";
    // });
    
    // Run the event loop
    $loop->run();
}

/*
 * Function to update product information in the database
 * @param PDO $pdo
 * @param int $product_id
 * @param string|null $product_name
 * @param float|null $price
 * @param int|null $stock
 * @return bool
 */
function updateProductInfoById(PDO $pdo, $product_id, $product_name = null, $price = null, $stock = null) {
    try {
        // Build the update fields dynamically
        $fields = [];
        $params = [':product_id' => $product_id];

        if ($product_name !== null) {
            $fields[] = "product_name = :product_name";
            $params[':product_name'] = $product_name;
        }

        if ($price !== null) {
            $fields[] = "price = :price";
            $params[':price'] = $price;
        }

        if ($stock !== null) {
            $fields[] = "stock = :stock";
            $params[':stock'] = $stock;
        }

        if (empty($fields)) {
            throw new Exception("No data provided to update.");
        }

        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = :product_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            return true; // Values changed and row updated
        }

        // When rowCount is 0 the row either already had the same values
        // or the product id does not exist. Distinguish by checking existence.
        $checkStmt = $pdo->prepare('SELECT 1 FROM products WHERE id = :product_id LIMIT 1');
        $checkStmt->execute([':product_id' => $product_id]);

        return (bool) $checkStmt->fetchColumn();
    } catch (PDOException $e) {
        writeLog("Update Error: " . $e->getMessage(), "ERROR");
        return false;
    } catch (Exception $e) {
        writeLog("General Error: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function insertScrapingLog(PDO $pdo, $product_id, $title = null, $price = null, $stock = null, $status, $error_message = null) {
    try {
        $sql = "INSERT INTO scrape_log (
                    product_id, 
                    title, 
                    price, 
                    stock, 
                    status, 
                    error_message
                ) VALUES (
                    :product_id, 
                    :title, 
                    :price, 
                    :stock, 
                    :status, 
                    :error_message
                )";

        $stmt = $pdo->prepare($sql);

        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':title', $title, PDO::PARAM_STR);
        $stmt->bindParam(':price', $price, PDO::PARAM_INT);
        $stmt->bindParam(':stock', $stock, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);
        $stmt->bindParam(':error_message', $error_message, PDO::PARAM_STR);

        $stmt->execute();

        return true;
    } catch (PDOException $e) {
        $error_message_and_product_details = "Insert Error: " . $e->getMessage() . " - Product ID: " . $product_id . " - Status: " . $status . " - Error Message: " . $error_message . " - Title: " . $title . " - Price: " . $price . " - Stock: " . $stock . "\n";
        writeLog($error_message_and_product_details, "ERROR");
        echo $error_message_and_product_details;
        return false;
    }
}

$stmt = $pdo->query("SELECT id, url FROM products WHERE scrape_status = 'active'");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extract just the URLs into an array
// $urls = array_column($products, 'url');

if (empty($products)) {
    echo "No products to scrape.\n";
    writeLog("No products to scrape", "WARNING", $sessionLogFile);
    exit;
} else {
    writeLog("Found " . count($products) . " products to scrape", "INFO", $sessionLogFile);
    scrapeMultipleProducts($pdo, $products, $sessionFolder, $sessionLogFile);
}

// Total time taken for the entire process
$totalEnd = microtime(true);
$executionTime = number_format($totalEnd - $startTime, 6);
echo "Total execution time: $executionTime seconds\n";
writeLog("Scraping session completed in $executionTime seconds", "INFO", $sessionLogFile);
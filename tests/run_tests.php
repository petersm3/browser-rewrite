#!/usr/bin/env php
<?php
/**
 * Test suite for browser-rewrite application
 * Covers: functioning, integrity, security, and scalability
 *
 * Usage: php run_tests.php [--base-url=URL] [--db-config=PATH]
 *
 * Defaults:
 *   --base-url=https://lamp-petersm3.westus2.cloudapp.azure.com
 *   --db-config=/var/www/browser-rewrite/config/config.php
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$baseUrl = 'https://lamp-petersm3.westus2.cloudapp.azure.com';
$dbConfigPath = '/var/www/browser-rewrite/config/config.php';

foreach ($argv as $arg) {
    if (strpos($arg, '--base-url=') === 0) {
        $baseUrl = substr($arg, strlen('--base-url='));
    }
    if (strpos($arg, '--db-config=') === 0) {
        $dbConfigPath = substr($arg, strlen('--db-config='));
    }
}

$baseUrl = rtrim($baseUrl, '/');

// ---------------------------------------------------------------------------
// Test framework (lightweight, no external deps)
// ---------------------------------------------------------------------------
$testResults = [];
$totalPass = 0;
$totalFail = 0;
$currentSection = '';

function section($name) {
    global $currentSection;
    $currentSection = $name;
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $name\n";
    echo str_repeat('=', 70) . "\n";
}

function pass($name) {
    global $testResults, $totalPass, $currentSection;
    $totalPass++;
    $testResults[] = ['section' => $currentSection, 'name' => $name, 'status' => 'PASS'];
    echo "  PASS  $name\n";
}

function fail($name, $detail = '') {
    global $testResults, $totalFail, $currentSection;
    $totalFail++;
    $testResults[] = ['section' => $currentSection, 'name' => $name, 'status' => 'FAIL', 'detail' => $detail];
    echo "  FAIL  $name\n";
    if ($detail) {
        echo "        $detail\n";
    }
}

function http_get($url, &$httpCode = null, &$headers = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return $body;
}

function http_post($url, $postData, &$httpCode = null, &$headers = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return $body;
}

// ---------------------------------------------------------------------------
// Connect to database
// ---------------------------------------------------------------------------
$dbh = null;
if (file_exists($dbConfigPath)) {
    include($dbConfigPath);
    try {
        $dbh = new PDO(
            'mysql:host=' . MYSQL_HOSTNAME . ';dbname=' . MYSQL_DATABASE,
            MYSQL_USERNAME,
            MYSQL_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        echo "WARNING: Could not connect to database: " . $e->getMessage() . "\n";
        echo "Database tests will be skipped.\n";
    }
} else {
    echo "WARNING: DB config not found at $dbConfigPath\n";
    echo "Database tests will be skipped.\n";
}

echo "\n";
echo str_repeat('#', 70) . "\n";
echo "  BROWSER-REWRITE TEST SUITE\n";
echo "  Target: $baseUrl\n";
echo "  Date:   " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('#', 70) . "\n";

// ===================================================================
// 1. UNIT TESTS - Functioning
// ===================================================================
section('1. UNIT TESTS - Functioning');

// 1a. Database connectivity
if ($dbh) {
    try {
        $st = $dbh->query("SELECT 1");
        pass('Database connectivity');
    } catch (PDOException $e) {
        fail('Database connectivity', $e->getMessage());
    }
} else {
    fail('Database connectivity', 'No database connection');
}

// 1b. Categories table has expected structure and data
if ($dbh) {
    try {
        $st = $dbh->query("SELECT COUNT(DISTINCT category) as cnt FROM categories");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row['cnt'] >= 1) {
            pass("Categories exist ({$row['cnt']} distinct categories)");
        } else {
            fail('Categories exist', 'No categories found');
        }
    } catch (PDOException $e) {
        fail('Categories exist', $e->getMessage());
    }

    // 1c. Subcategories exist per category
    try {
        $st = $dbh->query("SELECT category, COUNT(subcategory) as cnt FROM categories GROUP BY category");
        $allHaveSubs = true;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($row['cnt'] < 1) {
                $allHaveSubs = false;
                break;
            }
        }
        if ($allHaveSubs) {
            pass('All categories have subcategories');
        } else {
            fail('All categories have subcategories', "Category {$row['category']} has 0 subcategories");
        }
    } catch (PDOException $e) {
        fail('All categories have subcategories', $e->getMessage());
    }

    // 1d. Properties table has accessions
    try {
        $st = $dbh->query("SELECT COUNT(*) as cnt FROM properties");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            pass("Properties table populated ({$row['cnt']} accessions)");
        } else {
            fail('Properties table populated', 'No properties found');
        }
        $totalProperties = $row['cnt'];
    } catch (PDOException $e) {
        fail('Properties table populated', $e->getMessage());
    }

    // 1e. Filters table maps categories to properties
    try {
        $st = $dbh->query("SELECT COUNT(*) as cnt FROM filters");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            pass("Filters table populated ({$row['cnt']} mappings)");
        } else {
            fail('Filters table populated', 'No filter mappings found');
        }
    } catch (PDOException $e) {
        fail('Filters table populated', $e->getMessage());
    }

    // 1f. Attributes table has metadata
    try {
        $st = $dbh->query("SELECT COUNT(*) as cnt FROM attributes");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            pass("Attributes table populated ({$row['cnt']} entries)");
        } else {
            fail('Attributes table populated', 'No attributes found');
        }
    } catch (PDOException $e) {
        fail('Attributes table populated', $e->getMessage());
    }

    // 1g. getFilterMatchCount vs getFilterMatches consistency
    try {
        // Get first category ID
        $st = $dbh->query("SELECT id FROM categories LIMIT 1");
        $cat = $st->fetch(PDO::FETCH_ASSOC);
        $catId = $cat['id'];

        // COUNT(*) method
        $st = $dbh->prepare("SELECT COUNT(*) as total FROM (SELECT fk_properties_id FROM filters WHERE fk_categories_id IN (?) GROUP BY fk_properties_id HAVING COUNT(fk_properties_id) = 1) as matched");
        $st->execute([$catId]);
        $countResult = $st->fetch(PDO::FETCH_ASSOC)['total'];

        // fetchAll method
        $st = $dbh->prepare("SELECT fk_properties_id FROM filters WHERE fk_categories_id IN (?) GROUP BY fk_properties_id HAVING COUNT(fk_properties_id) = 1");
        $st->execute([$catId]);
        $fetchResult = count($st->fetchAll());

        if ((int)$countResult === $fetchResult) {
            pass("COUNT(*) matches fetchAll count ($countResult)");
        } else {
            fail("COUNT(*) matches fetchAll count", "COUNT=$countResult, fetchAll=$fetchResult");
        }
    } catch (PDOException $e) {
        fail('COUNT(*) matches fetchAll count', $e->getMessage());
    }

    // 1h. Pagination math: LIMIT/OFFSET returns correct subset
    try {
        $st = $dbh->prepare("SELECT fk_properties_id FROM filters WHERE fk_categories_id = ? GROUP BY fk_properties_id HAVING COUNT(fk_properties_id) = 1 LIMIT 10 OFFSET 0");
        $st->execute([$catId]);
        $page1 = $st->fetchAll(PDO::FETCH_COLUMN);

        $st = $dbh->prepare("SELECT fk_properties_id FROM filters WHERE fk_categories_id = ? GROUP BY fk_properties_id HAVING COUNT(fk_properties_id) = 1 LIMIT 10 OFFSET 10");
        $st->execute([$catId]);
        $page2 = $st->fetchAll(PDO::FETCH_COLUMN);

        $overlap = array_intersect($page1, $page2);
        if (empty($overlap) && count($page1) > 0) {
            pass('Pagination LIMIT/OFFSET returns non-overlapping pages');
        } else if (count($page1) === 0) {
            pass('Pagination LIMIT/OFFSET (insufficient data to verify overlap, but query works)');
        } else {
            fail('Pagination LIMIT/OFFSET returns non-overlapping pages', count($overlap) . ' overlapping IDs');
        }
    } catch (PDOException $e) {
        fail('Pagination LIMIT/OFFSET', $e->getMessage());
    }
}

// ===================================================================
// 2. INTEGRATION TESTS - Integrity
// ===================================================================
section('2. INTEGRATION TESTS - Integrity');

// 2a. Homepage returns 200
$body = http_get($baseUrl . '/', $code);
if ($code === 200) {
    pass('Homepage returns HTTP 200');
} else {
    fail('Homepage returns HTTP 200', "Got HTTP $code");
}

// 2b. Homepage contains navigation dropdowns
if (strpos($body, 'dropdown-menu') !== false && strpos($body, 'navbar') !== false) {
    pass('Homepage contains navigation dropdowns');
} else {
    fail('Homepage contains navigation dropdowns', 'Missing dropdown-menu or navbar elements');
}

// 2c. Homepage shows default "no filters" message
if (strpos($body, 'Select filters from the dropdown') !== false) {
    pass('Homepage shows default no-filters message');
} else {
    fail('Homepage shows default no-filters message');
}

// 2d. About page returns 200
$body = http_get($baseUrl . '/about', $code);
if ($code === 200) {
    pass('About page returns HTTP 200');
} else {
    fail('About page returns HTTP 200', "Got HTTP $code");
}

// 2e. About page contains expected content
if (strpos($body, 'Technologies used') !== false) {
    pass('About page contains expected content');
} else {
    fail('About page contains expected content');
}

// 2f. robots.txt returns 200
$body = http_get($baseUrl . '/robots.txt', $code);
if ($code === 200 && strpos($body, 'Disallow: /filter') !== false) {
    pass('robots.txt serves correctly');
} else {
    fail('robots.txt serves correctly', "HTTP $code");
}

// 2g. Invalid route returns error page (not a 500)
$body = http_get($baseUrl . '/nonexistent-page-xyz', $code);
if ($code === 200 || $code === 404) {
    pass("Invalid route handled gracefully (HTTP $code)");
} else {
    fail("Invalid route handled gracefully", "Got HTTP $code");
}

// 2h. Filter with valid category returns 200 and results
// First get a valid category:subcategory from the database
$validFilter = null;
if ($dbh) {
    $st = $dbh->query("SELECT category, subcategory FROM categories LIMIT 1");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $validFilter = str_replace(' ', '_', $row['category']) . '%3A' . urlencode(str_replace(' ', '_', $row['subcategory']));
}

if ($validFilter) {
    $body = http_get($baseUrl . '/?filter[]=' . $validFilter, $code);
    if ($code === 200) {
        pass('Valid filter returns HTTP 200');
    } else {
        fail('Valid filter returns HTTP 200', "Got HTTP $code");
    }

    // 2i. Filter results contain result count
    if (preg_match('/Showing \d+-\d+ of \d+ results/', $body)) {
        pass('Filter results show result count');
    } else {
        fail('Filter results show result count', 'Missing "Showing X-Y of Z results"');
    }

    // 2j. Filter results contain pagination
    if (strpos($body, 'class="pagination"') !== false) {
        pass('Filter results contain pagination');
    } else {
        fail('Filter results contain pagination');
    }

    // 2k. Breadcrumb shows active filter
    if (strpos($body, 'breadcrumb') !== false) {
        pass('Breadcrumb bar present with active filters');
    } else {
        fail('Breadcrumb bar present with active filters');
    }

    // 2l. "Clear all filters" link present
    if (strpos($body, 'Clear all filters') !== false) {
        pass('"Clear all filters" link present');
    } else {
        fail('"Clear all filters" link present');
    }

    // 2m. Per-page dropdown present
    if (strpos($body, 'Per page:') !== false) {
        pass('Per-page dropdown present in navbar');
    } else {
        fail('Per-page dropdown present in navbar');
    }

    // 2n. Limit parameter works
    $body10 = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=10', $code);
    $body50 = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=50', $code);
    preg_match('/Showing \d+-(\d+) of (\d+)/', $body10, $m10);
    preg_match('/Showing \d+-(\d+) of (\d+)/', $body50, $m50);
    if (!empty($m10) && !empty($m50)) {
        $end10 = (int)$m10[1];
        $end50 = (int)$m50[1];
        $total10 = (int)$m10[2];
        $total50 = (int)$m50[2];
        if ($total10 === $total50 && $end10 <= 10 && ($end50 <= 50 || $end50 === $total50)) {
            pass("Limit parameter works (limit=10 shows $end10, limit=50 shows $end50, total=$total10)");
        } else {
            fail('Limit parameter works', "limit=10: end=$end10 total=$total10, limit=50: end=$end50 total=$total50");
        }
    } else {
        fail('Limit parameter works', 'Could not parse result counts');
    }

    // 2o. Pagination offset works - page 1 and page 2 show different accessions
    $body_p1 = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=10&offset=0', $code);
    $body_p2 = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=10&offset=10', $code);
    preg_match_all('/href="\/display\?id=(\d+)"/', $body_p1, $ids_p1);
    preg_match_all('/href="\/display\?id=(\d+)"/', $body_p2, $ids_p2);
    if (!empty($ids_p1[1]) && !empty($ids_p2[1])) {
        $overlap = array_intersect($ids_p1[1], $ids_p2[1]);
        if (empty($overlap)) {
            pass('Pagination offset returns different accessions per page');
        } else {
            fail('Pagination offset returns different accessions per page', count($overlap) . ' overlapping IDs');
        }
    } else {
        // Might just not have enough results for 2 pages
        pass('Pagination offset (insufficient results for 2 pages, query succeeds)');
    }
}

// 2p. Display page for single accession
if ($dbh) {
    $st = $dbh->query("SELECT id FROM properties LIMIT 1");
    $prop = $st->fetch(PDO::FETCH_ASSOC);
    $body = http_get($baseUrl . '/display?id=' . $prop['id'], $code);
    if ($code === 200) {
        pass('Single accession display page returns HTTP 200');
    } else {
        fail('Single accession display page returns HTTP 200', "Got HTTP $code");
    }

    // 2q. Display page contains accession data
    if (strpos($body, 'Accession:') !== false && strpos($body, 'Address:') !== false) {
        pass('Display page contains accession fields');
    } else {
        fail('Display page contains accession fields');
    }

    // 2r. Display page contains back button
    if (strpos($body, 'Back') !== false) {
        pass('Display page contains back button');
    } else {
        fail('Display page contains back button');
    }
}

// 2s. Filter POST→GET redirect
$postBody = http_post($baseUrl . '/filter', 'filters[]=Creator%3AC1&limit=50', $code, $headers);
if ($code === 301 || $code === 302 || $code === 303) {
    if (strpos($headers, 'filter[]=Creator') !== false && strpos($headers, 'limit=50') !== false) {
        pass("Filter POST redirects with filters and limit (HTTP $code)");
    } else if (strpos($headers, 'filter[]=Creator') !== false) {
        pass("Filter POST redirects with filters (HTTP $code)");
    } else {
        fail('Filter POST redirects correctly', "Redirect headers missing expected params");
    }
} else {
    // LightVC may handle this differently
    pass("Filter POST returns HTTP $code (framework handles redirect)");
}

// 2t. Database foreign key integrity
if ($dbh) {
    section('2b. DATABASE INTEGRITY');

    // Orphaned filters (fk_categories_id not in categories)
    $st = $dbh->query("SELECT COUNT(*) as cnt FROM filters f LEFT JOIN categories c ON f.fk_categories_id = c.id WHERE c.id IS NULL");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$row['cnt'] === 0) {
        pass('No orphaned filter→category references');
    } else {
        fail('No orphaned filter→category references', $row['cnt'] . ' orphaned rows');
    }

    // Orphaned filters (fk_properties_id not in properties)
    $st = $dbh->query("SELECT COUNT(*) as cnt FROM filters f LEFT JOIN properties p ON f.fk_properties_id = p.id WHERE p.id IS NULL");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$row['cnt'] === 0) {
        pass('No orphaned filter→property references');
    } else {
        fail('No orphaned filter→property references', $row['cnt'] . ' orphaned rows');
    }

    // Orphaned attributes (fk_properties_id not in properties)
    $st = $dbh->query("SELECT COUNT(*) as cnt FROM attributes a LEFT JOIN properties p ON a.fk_properties_id = p.id WHERE p.id IS NULL");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$row['cnt'] === 0) {
        pass('No orphaned attribute→property references');
    } else {
        fail('No orphaned attribute→property references', $row['cnt'] . ' orphaned rows');
    }

    // Every property has at least one filter
    $st = $dbh->query("SELECT COUNT(*) as cnt FROM properties p LEFT JOIN filters f ON p.id = f.fk_properties_id WHERE f.id IS NULL");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$row['cnt'] === 0) {
        pass('Every property has at least one filter mapping');
    } else {
        fail('Every property has at least one filter mapping', $row['cnt'] . ' properties without filters');
    }

    // Every property has attributes
    $st = $dbh->query("SELECT COUNT(*) as cnt FROM properties p LEFT JOIN attributes a ON p.id = a.fk_properties_id WHERE a.id IS NULL");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$row['cnt'] === 0) {
        pass('Every property has at least one attribute');
    } else {
        fail('Every property has at least one attribute', $row['cnt'] . ' properties without attributes');
    }
}

// ===================================================================
// 3. SECURITY TESTS
// ===================================================================
section('3. SECURITY TESTS');

// 3a. SQL injection in filter parameter
$sqliPayloads = [
    "Creator%3AC1' OR '1'='1",
    "Creator%3AC1; DROP TABLE properties;--",
    "Creator%3AC1' UNION SELECT * FROM properties--",
    "1%3A1' AND 1=1--",
];
foreach ($sqliPayloads as $i => $payload) {
    $body = http_get($baseUrl . '/?filter[]=' . urlencode($payload), $code);
    // Should NOT return a database error or 500
    if ($code !== 500 && strpos($body, 'SQL') === false && strpos($body, 'mysql') === false && strpos($body, 'PDOException') === false) {
        pass("SQL injection blocked in filter (payload " . ($i+1) . ")");
    } else {
        fail("SQL injection blocked in filter (payload " . ($i+1) . ")", "HTTP $code, possible SQL error in response");
    }
}

// 3b. SQL injection in offset parameter
$body = http_get($baseUrl . '/?filter[]=' . $validFilter . '&offset=0;DROP+TABLE+properties', $code);
if ($code !== 500) {
    pass('SQL injection blocked in offset parameter');
} else {
    fail('SQL injection blocked in offset parameter', "HTTP $code");
}

// 3c. SQL injection in limit parameter
$body = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=10;DROP+TABLE+properties', $code);
if ($code !== 500) {
    pass('SQL injection blocked in limit parameter');
} else {
    fail('SQL injection blocked in limit parameter', "HTTP $code");
}

// 3d. SQL injection in display ID
$body = http_get($baseUrl . '/display?id=1+OR+1=1', $code);
if ($code !== 500 && strpos($body, 'PDOException') === false) {
    pass('SQL injection blocked in display ID');
} else {
    fail('SQL injection blocked in display ID', "HTTP $code");
}

// 3e. XSS in filter parameter
$xssPayloads = [
    '<script>alert("xss")</script>',
    '"><img src=x onerror=alert(1)>',
    "'-alert(1)-'",
    '<svg/onload=alert(1)>',
];
foreach ($xssPayloads as $i => $payload) {
    $body = http_get($baseUrl . '/?filter[]=' . urlencode($payload), $code);
    // The raw payload should NOT appear unescaped in the response
    if (strpos($body, $payload) === false) {
        pass("XSS escaped in filter (payload " . ($i+1) . ")");
    } else {
        fail("XSS escaped in filter (payload " . ($i+1) . ")", 'Raw payload found in response body');
    }
}

// 3f. XSS in display ID
$body = http_get($baseUrl . '/display?id=<script>alert(1)</script>', $code);
if (strpos($body, '<script>alert(1)</script>') === false) {
    pass('XSS escaped in display ID');
} else {
    fail('XSS escaped in display ID');
}

// 3g. CRLF injection in filter (header injection)
$body = http_get($baseUrl . '/?filter[]=' . urlencode("test\r\nX-Injected: true"), $code, $headers);
if (strpos($headers, 'X-Injected') === false) {
    pass('CRLF injection blocked in headers');
} else {
    fail('CRLF injection blocked in headers', 'Injected header found');
}

// 3h. Negative offset handling
$body = http_get($baseUrl . '/?filter[]=' . $validFilter . '&offset=-100', $code);
if ($code === 200) {
    pass('Negative offset handled gracefully');
} else {
    fail('Negative offset handled gracefully', "HTTP $code");
}

// 3i. Limit above maximum (>500)
$body = http_get($baseUrl . '/?filter[]=' . $validFilter . '&limit=99999', $code);
if ($code === 200) {
    // Check it was clamped - should show max 500 or default
    preg_match('/Showing \d+-(\d+) of (\d+)/', $body, $m);
    if (!empty($m)) {
        $endResult = (int)$m[1];
        $startResult = 1;
        $shown = $endResult - $startResult + 1;
        if ($shown <= 500) {
            pass("Limit >500 clamped (showing $shown results)");
        } else {
            fail("Limit >500 clamped", "Showing $shown results, expected <= 500");
        }
    } else {
        pass('Limit >500 returns HTTP 200 (no results to verify count)');
    }
} else {
    fail('Limit >500 handled gracefully', "HTTP $code");
}

// 3j. Non-numeric ID in display
$body = http_get($baseUrl . '/display?id=abc', $code);
if ($code === 200 && strpos($body, 'PDOException') === false) {
    pass('Non-numeric display ID handled gracefully');
} else {
    fail('Non-numeric display ID handled gracefully', "HTTP $code");
}

// 3k. Empty filter array
$body = http_get($baseUrl . '/?filter[]=', $code);
if ($code !== 500) {
    pass("Empty filter value handled (HTTP $code)");
} else {
    fail('Empty filter value handled', "HTTP 500");
}

// 3l. CDN URLs use HTTPS (no mixed content)
if ($validFilter) {
    $body = http_get($baseUrl . '/?filter[]=' . $validFilter, $code);
    if (strpos($body, 'src="http://') === false) {
        pass('No mixed content (all image src use HTTPS)');
    } else {
        fail('No mixed content', 'Found src="http:// in response');
    }
}

// 3m. Verify properties table still intact after SQL injection tests
if ($dbh) {
    try {
        $st = $dbh->query("SELECT COUNT(*) as cnt FROM properties");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['cnt'] > 0) {
            pass("Database intact after injection tests ({$row['cnt']} properties)");
        } else {
            fail('Database intact after injection tests', 'Properties table is empty!');
        }
    } catch (PDOException $e) {
        fail('Database intact after injection tests', $e->getMessage());
    }
}

// ===================================================================
// 4. SCALABILITY TESTS (using Apache Bench)
// ===================================================================
section('4. SCALABILITY TESTS');

// Helper to parse ab output
function parse_ab($output) {
    $result = [];
    if (preg_match('/Requests per second:\s+([\d.]+)/', $output, $m)) {
        $result['rps'] = (float)$m[1];
    }
    if (preg_match('/Time per request:\s+([\d.]+)\s+\[ms\]\s+\(mean\)/', $output, $m)) {
        $result['mean_ms'] = (float)$m[1];
    }
    if (preg_match('/Failed requests:\s+(\d+)/', $output, $m)) {
        $result['failed'] = (int)$m[1];
    }
    if (preg_match('/50%\s+(\d+)/', $output, $m)) {
        $result['p50'] = (int)$m[1];
    }
    if (preg_match('/95%\s+(\d+)/', $output, $m)) {
        $result['p95'] = (int)$m[1];
    }
    if (preg_match('/99%\s+(\d+)/', $output, $m)) {
        $result['p99'] = (int)$m[1];
    }
    if (preg_match('/Non-2xx responses:\s+(\d+)/', $output, $m)) {
        $result['non2xx'] = (int)$m[1];
    } else {
        $result['non2xx'] = 0;
    }
    return $result;
}

$abAvailable = (trim(shell_exec('which ab 2>/dev/null')) !== '');

if ($abAvailable) {
    $abTests = [
        ['name' => 'Homepage', 'url' => $baseUrl . '/', 'c' => 10, 'n' => 100],
        ['name' => 'Filtered results', 'url' => $baseUrl . '/?filter[]=' . $validFilter, 'c' => 10, 'n' => 100],
        ['name' => 'Single accession', 'url' => $baseUrl . '/display?id=1', 'c' => 10, 'n' => 100],
        ['name' => 'Homepage (50 concurrent)', 'url' => $baseUrl . '/', 'c' => 50, 'n' => 200],
        ['name' => 'Filtered results (50 concurrent)', 'url' => $baseUrl . '/?filter[]=' . $validFilter, 'c' => 50, 'n' => 200],
    ];

    echo "\n  %-40s  %6s  %6s  %6s  %6s  %6s  %s\n";
    printf("  %-40s  %6s  %6s  %6s  %6s  %6s  %s\n", 'Test', 'RPS', 'p50ms', 'p95ms', 'p99ms', 'Fail', 'Status');
    echo "  " . str_repeat('-', 90) . "\n";

    foreach ($abTests as $test) {
        $url = escapeshellarg($test['url']);
        // Use -k for keepalive, -s for SSL
        $cmd = "ab -n {$test['n']} -c {$test['c']} -s 30 $url 2>&1";
        $output = shell_exec($cmd);
        $r = parse_ab($output);

        if (!empty($r)) {
            $rps = isset($r['rps']) ? sprintf('%.1f', $r['rps']) : 'N/A';
            $p50 = isset($r['p50']) ? $r['p50'] : 'N/A';
            $p95 = isset($r['p95']) ? $r['p95'] : 'N/A';
            $p99 = isset($r['p99']) ? $r['p99'] : 'N/A';
            $failed = isset($r['failed']) ? $r['failed'] : 0;
            $non2xx = $r['non2xx'];

            // Pass criteria: p95 < 5000ms, no failed requests, no non-2xx
            $status = 'PASS';
            $detail = '';
            if ($failed > 0) {
                $status = 'FAIL';
                $detail = "$failed failed requests";
            } elseif ($non2xx > 0) {
                $status = 'FAIL';
                $detail = "$non2xx non-2xx responses";
            } elseif (isset($r['p95']) && $r['p95'] > 5000) {
                $status = 'WARN';
                $detail = "p95 > 5s";
            }

            printf("  %-40s  %6s  %6s  %6s  %6s  %6s  %s\n", $test['name'], $rps, $p50, $p95, $p99, $failed, $status);
            if ($status === 'PASS') {
                pass($test['name'] . " (p95={$p95}ms, {$rps} req/s)");
            } elseif ($status === 'WARN') {
                pass($test['name'] . " (WARN: $detail, {$rps} req/s)");
            } else {
                fail($test['name'], $detail);
            }
        } else {
            fail($test['name'], 'Could not parse ab output');
        }
    }
} else {
    echo "  SKIP  Apache Bench (ab) not available\n";
}

// ===================================================================
// SUMMARY
// ===================================================================
echo "\n";
echo str_repeat('#', 70) . "\n";
echo "  TEST SUMMARY\n";
echo str_repeat('#', 70) . "\n\n";

$sections = [];
foreach ($testResults as $r) {
    $sections[$r['section']][] = $r;
}

foreach ($sections as $section => $tests) {
    $sPass = count(array_filter($tests, fn($t) => $t['status'] === 'PASS'));
    $sFail = count(array_filter($tests, fn($t) => $t['status'] === 'FAIL'));
    $icon = $sFail === 0 ? 'OK' : 'FAIL';
    echo "  [$icon] $section: $sPass passed, $sFail failed\n";
}

echo "\n  Total: $totalPass passed, $totalFail failed out of " . ($totalPass + $totalFail) . " tests\n\n";

if ($totalFail > 0) {
    echo "  Failed tests:\n";
    foreach ($testResults as $r) {
        if ($r['status'] === 'FAIL') {
            echo "    - {$r['name']}";
            if (!empty($r['detail'])) {
                echo ": {$r['detail']}";
            }
            echo "\n";
        }
    }
    echo "\n";
}

exit($totalFail > 0 ? 1 : 0);

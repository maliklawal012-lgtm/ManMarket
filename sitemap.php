<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');

$db = get_db();
$base = site_base_url();

function sitemap_url(string $loc, ?string $lastmod = null, string $changefreq = 'weekly', string $priority = '0.5'): string
{
    $xml = "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
    if ($lastmod !== null) {
        $xml .= '    <lastmod>' . date('Y-m-d', strtotime($lastmod)) . "</lastmod>\n";
    }
    $xml .= "    <changefreq>{$changefreq}</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";

    return $xml;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

echo sitemap_url("{$base}/index", null, 'daily', '1.0');
echo sitemap_url("{$base}/boutiques", null, 'daily', '0.9');
echo sitemap_url("{$base}/offres", null, 'daily', '0.8');
echo sitemap_url("{$base}/services", null, 'weekly', '0.6');
echo sitemap_url("{$base}/actualites", null, 'weekly', '0.5');
echo sitemap_url("{$base}/contact", null, 'monthly', '0.3');

$categories = $db->query('SELECT slug FROM categories')->fetchAll();
foreach ($categories as $cat) {
    echo sitemap_url("{$base}/categorie?slug=" . urlencode((string) $cat['slug']), null, 'daily', '0.7');
}

$activeShops = active_subscription_shops_subquery();

$shops = $db->query("SELECT slug FROM shops WHERE id IN {$activeShops}")->fetchAll();
foreach ($shops as $shop) {
    echo sitemap_url("{$base}/boutique?slug=" . urlencode((string) $shop['slug']), null, 'weekly', '0.7');
}

$products = $db->query("SELECT slug, created_at FROM products WHERE shop_id IN {$activeShops}")->fetchAll();
foreach ($products as $product) {
    echo sitemap_url("{$base}/produit?slug=" . urlencode((string) $product['slug']), $product['created_at'], 'weekly', '0.6');
}

echo '</urlset>' . "\n";

<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900');

$repo = new DomainRepository();
$entries = $repo->sitemapEntries(45000);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= htmlspecialchars(absolute_url('/'), ENT_XML1) ?></loc>
    <changefreq>hourly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc><?= htmlspecialchars(absolute_url('browse.php'), ENT_XML1) ?></loc>
    <changefreq>hourly</changefreq>
    <priority>0.8</priority>
  </url>
  <url>
    <loc><?= htmlspecialchars(absolute_url('how-it-works.php'), ENT_XML1) ?></loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>
  <url>
    <loc><?= htmlspecialchars(absolute_url('faq.php'), ENT_XML1) ?></loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>
<?php foreach ($entries as $row):
    $last = $row['last_checked'] ?: ($row['updated_at'] ?? null);
    $lastmod = $last ? date('Y-m-d', strtotime($last)) : date('Y-m-d');
?>
  <url>
    <loc><?= htmlspecialchars(domain_page_url($row['domain']), ENT_XML1) ?></loc>
    <lastmod><?= h($lastmod) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>
</urlset>

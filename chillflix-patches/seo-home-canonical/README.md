# Vuflix SEO fixes (home canonical)

Problems fixed on live `routes.php`:
1. `/` and `/home` both 200 with canonical pointing at `/home` while sitemap listed `/` → duplicate signal
2. `/home` now **301 → /** and home canonical is `https://vuflix.co`
3. Removed `/favorites` + `/request` from sitemap; favorites is `noindex, follow`

Next (manual): Google Search Console verify `vuflix.co`, submit `https://vuflix.co/sitemap.xml`, request indexing for `/`.

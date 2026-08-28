import Link from "next/link"

export function NewsFooter() {
  return (
    <footer className="n24-footer">
      <div className="n24-wrap">
        <div className="n24-footer-grid">
          <Link href="/news">News</Link>
          <Link href="/news?category=world">World</Link>
          <Link href="/news?category=sports">Sport</Link>
          <Link href="/news?category=technology">Sci/Tech</Link>
          <Link href="/">Chillflix</Link>
        </div>
        <small>
          © {new Date().getFullYear()} Daily24 — headlines aggregated from
          public news feeds. Credit always remains with the original publisher.
        </small>
      </div>
    </footer>
  )
}

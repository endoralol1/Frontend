#!/usr/bin/env python3
from pathlib import Path

p = Path("/var/www/chillflix.lol/middleware.ts")
text = p.read_text()
marker = "export async function middleware(request: NextRequest) {\n    const { pathname } = request.nextUrl\n"
insert = """export async function middleware(request: NextRequest) {
    const { pathname } = request.nextUrl

    // Soft bot defense: absurd pagination on similar/recommendations (nginx also enforces)
    if (
        /^\\/(movie|tv)\\/\\d+\\/(similar|recommendations)\\/?$/.test(pathname)
    ) {
        const page = Number(request.nextUrl.searchParams.get("page") || "1")
        if (Number.isFinite(page) && page >= 21) {
            return new NextResponse("Gone", { status: 410 })
        }
    }

"""
if "absurd pagination on similar" in text:
    print("middleware already patched")
elif marker not in text:
    raise SystemExit("middleware marker not found")
else:
    p.write_text(text.replace(marker, insert, 1))
    print("middleware source patched (needs next build to take effect)")

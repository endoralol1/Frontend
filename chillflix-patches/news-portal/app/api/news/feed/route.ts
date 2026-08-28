import { NextRequest, NextResponse } from "next/server"

import { fetchNewsFeed } from "@/lib/news/rss"

export const revalidate = 300

export async function GET(request: NextRequest) {
  const { searchParams } = request.nextUrl
  const country = searchParams.get("country")
  const category = searchParams.get("category")
  const limit = Number(searchParams.get("limit") || "36")

  try {
    const articles = await fetchNewsFeed({ country, category, limit })
    return NextResponse.json({ articles })
  } catch (error) {
    console.error("[api/news/feed]", error)
    return NextResponse.json(
      { articles: [], error: "Failed to load news feed" },
      { status: 502 }
    )
  }
}

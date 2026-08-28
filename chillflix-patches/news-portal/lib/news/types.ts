export type NewsCountry = {
  code: string
  label: string
  nativeLabel: string
  lang: string
  hl: string
  gl: string
  ceid: string
}

export type NewsArticle = {
  id: string
  title: string
  summary: string
  url: string
  image: string | null
  publishedAt: string | null
  sourceName: string
  sourceUrl: string | null
  category: string
  country: string
  lang: string
  isLocal?: boolean
}

import {
  CalendarIcon,
  ClapperboardIcon,
  GamepadIcon,
  HeartIcon,
  HomeIcon,
  Music2Icon,
  LucideIcon,
  PlayIcon,
  RadioIcon,
  RadioTowerIcon,
  SparklesIcon,
  StarIcon,
  TelescopeIcon,
  TrendingUpIcon,
  TvIcon,
  User,
} from "lucide-react"

import { pages } from "@/config/pages"

export type SiteConfig = typeof siteConfig

export const siteConfig = {
  name: "Chillflix",
  description:
    "Watch movies and TV shows online on Chillflix. Discover trending films, series, music, games, and IPTV.",
  share: {
    siteTitle: "Chillflix.lol – Online Movies & TV Shows",
    siteDescription:
      "Stream movies and TV shows free on Chillflix.lol. Watch trending films, binge-worthy series, music, games, and live IPTV.",
  },
  mainNav: [
    {
      title: "Home",
      href: "/",
    },
  ],
  links: {
    github: "https://github.com/oktay/movies",
    next: "https://nextjs.org",
    vercel: "https://vercel.com",
    tmdb: "https://www.themoviedb.org",
    shadcn: "https://ui.shadcn.com/",
    discord: "https://discord.gg/6r5KTZgqXV",
    telegram: "https://t.me/chillflixlol",
  },
  author: {
    name: "Oktay Colakoglu",
    web: "https://oktaycolakoglu.com",
  },
}

export type NavItem = {
  title: string
  href: string
  icon: LucideIcon
  description?: string
  items?: NavItem[]
}

const home = {
  title: "Home",
  href: pages.home.link,
  icon: HomeIcon,
}

const music = {
  title: "Music",
  href: pages.music.link,
  icon: Music2Icon,
  description: pages.music.description,
}

const games = {
  title: "Games",
  href: pages.games.link,
  icon: GamepadIcon,
  description: pages.games.description,
}

const iptv = {
  title: "IPTV",
  href: pages.iptv.link,
  icon: RadioIcon,
  description: pages.iptv.description,
}

const fourK = {
  title: "4K",
  href: pages.fourK.link,
  icon: SparklesIcon,
  description: pages.fourK.description,
}

const movies = {
  title: "Movies",
  href: pages.movie.discover.link,
  icon: ClapperboardIcon,
  description: pages.movie.discover.description,
}

const tvShows = {
  title: "TV Shows",
  href: pages.tv.discover.link,
  icon: TvIcon,
  description: pages.tv.discover.description,
}

const people = {
  title: "People",
  href: pages.people.popular.link,
  icon: User,
  description: pages.people.popular.description,
}

const trending = {
  title: "Trending",
  icon: TrendingUpIcon,
  href: pages.trending.root.link,
  description: pages.trending.root.description,
}

export const navigation = {
  items: [home, movies, tvShows, fourK, music, games, iptv, people, trending] as NavItem[],
}

export const availableParams = [
  "with_genres",
  "without_genres",
  "with_original_language",
  "with_watch_providers",
  "with_companies",
  "with_networks",
  "primary_release_date.gte",
  "primary_release_date.lte",
  "first_air_date.gte",
  "first_air_date.lte",
  "vote_average.gte",
  "vote_average.lte",
  "vote_count.gte",
  "vote_count.lte",
]

export const pageLimit = 500

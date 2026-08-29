"use client"

import { navigation, pages } from "@/config"
import { useActiveNav } from "@/hooks"

import { filterNavigationItems } from "@/lib/filter-navigation"
import { translateNavItems } from "@/lib/i18n/nav"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"
import { useSiteFeatures } from "@/components/site-features"
import { ListPageLink } from "@/components/list-page-link"
import {
  NavigationMenu,
  NavigationMenuContent,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  NavigationMenuTrigger,
} from "@/components/ui/navigation-menu"

const PRIMARY_HREFS = new Set([
  pages.home.link,
  pages.movie.discover.link,
  pages.tv.discover.link,
])

function NavTextLink({ href, title }: { href: string; title: string }) {
  const active = useActiveNav(href)

  return (
    <ListPageLink
      href={href}
      className={cn(
        "rounded-full px-2.5 py-1.5 text-[13px] font-medium transition-colors",
        active
          ? "bg-white/10 text-foreground"
          : "text-muted-foreground hover:text-foreground"
      )}
    >
      {title}
    </ListPageLink>
  )
}

const SiteNav = () => {
  const { t } = useTranslations()
  const features = useSiteFeatures()
  const items = translateNavItems(
    t,
    filterNavigationItems(navigation.items, features)
  )

  const primary = items.filter((item) => PRIMARY_HREFS.has(item.href))
  const more = items.filter((item) => !PRIMARY_HREFS.has(item.href))

  return (
    <div className="flex items-center gap-0.5">
      <nav aria-label="Primary" className="flex items-center gap-0.5">
        {primary.map((item) => (
          <NavTextLink key={item.href} href={item.href} title={item.title} />
        ))}
      </nav>

      {more.length > 0 ? (
        <NavigationMenu>
          <NavigationMenuList className="space-x-0">
            <NavigationMenuItem>
              <NavigationMenuTrigger className="h-8 rounded-full bg-transparent px-2.5 text-[13px] font-medium text-muted-foreground shadow-none hover:bg-white/10 hover:text-foreground data-[state=open]:bg-white/10 data-[state=open]:text-foreground data-[state=open]:shadow-none">
                {t("nav.more")}
              </NavigationMenuTrigger>
              <NavigationMenuContent>
                <ul className="grid w-52 gap-1 p-2">
                  {more.map((item) => {
                    const Icon = item.icon
                    return (
                      <li key={item.href}>
                        <NavigationMenuLink asChild>
                          <ListPageLink
                            href={item.href}
                            className="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm hover:bg-accent"
                          >
                            <Icon className="size-3.5 text-muted-foreground" />
                            {item.title}
                          </ListPageLink>
                        </NavigationMenuLink>
                      </li>
                    )
                  })}
                </ul>
              </NavigationMenuContent>
            </NavigationMenuItem>
          </NavigationMenuList>
        </NavigationMenu>
      ) : null}
    </div>
  )
}

export { SiteNav }

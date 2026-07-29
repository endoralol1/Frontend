"use client"

import { NavItem, navigation, pages } from "@/config"
import { useActiveNav } from "@/hooks"

import { filterNavigationItems } from "@/lib/filter-navigation"
import { translateNavItems } from "@/lib/i18n/nav"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"
import { useSiteFeatures } from "@/components/site-features"
import { ListPageLink } from "@/components/list-page-link"
import { Badge } from "@/components/ui/badge"
import {
  NavigationMenu,
  NavigationMenuContent,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  NavigationMenuTrigger,
  navigationMenuTriggerStyle,
} from "@/components/ui/navigation-menu"

const SiteNav = () => {
  const { t } = useTranslations()
  const features = useSiteFeatures()
  const items = translateNavItems(
    t,
    filterNavigationItems(navigation.items, features)
  )

  return (
    <div className="flex items-center">
      <NavigationMenu>
        <NavigationMenuList className="flex items-center gap-1">
          {items.map((item, index) =>
            item.items ? (
              <SiteNavItem key={item.href} index={index} {...item} />
            ) : (
              <SiteNavItemSingle key={item.href} index={index} {...item} />
            )
          )}
        </NavigationMenuList>
      </NavigationMenu>
    </div>
  )
}

const SiteNavItem = ({ title, icon, items, href, description, index }: NavItem & { index: number }) => {
  const isActive = useActiveNav(href)
  const Icon = icon

  return (
    <NavigationMenuItem className="animate-scale-in" style={{ animationDelay: `${index * 0.15}s` }}>
      <NavigationMenuTrigger className={cn(isActive && "bg-accent", "gap-1.5 h-9 px-3 text-sm transition-all duration-500 hover:scale-105 hover:bg-accent/80 group")}>
        <Icon className="size-3.5 transition-transform duration-500 group-hover:scale-110" /> {title}
      </NavigationMenuTrigger>
      <NavigationMenuContent className="animate-scale-in">
        <div className="p-4 pb-2 border-b border-border/30 bg-accent/5">
          <Icon className="mr-1 inline size-3.5 transition-transform" /> {title}
          <p className="mt-1 text-xs text-muted-foreground/80">{description}</p>
        </div>
        <div className="grid w-[500px] grid-cols-2 gap-1 p-3">
          {items?.map((item, itemIndex) => (
            <SiteNavListItem key={item.href} index={itemIndex} {...item} />
          ))}
        </div>
      </NavigationMenuContent>
    </NavigationMenuItem>
  )
}

const SiteNavItemSingle = ({ title, icon, href, index }: NavItem & { index: number }) => {
  const isActive = useActiveNav(href)
  const Icon = icon

  return (
    <NavigationMenuItem className="animate-scale-in" style={{ animationDelay: `${index * 0.15}s` }}>
      <NavigationMenuLink asChild>
        <ListPageLink
          href={href}
          className={cn(
            navigationMenuTriggerStyle(),
            isActive && "bg-accent",
            "gap-1.5 h-9 px-3 text-sm transition-all duration-500 hover:scale-105 hover:bg-accent/80"
          )}
        >
          <Icon className="size-3.5 transition-transform hover:scale-110" /> {title}
        </ListPageLink>
      </NavigationMenuLink>
    </NavigationMenuItem>
  )
}

const SiteNavListItem = ({ title, icon, description, href, index }: NavItem & { index: number }) => {
  const { t } = useTranslations()
  const Icon = icon
  const showNewBadge =
    href === pages.movie.discover.link || href === pages.tv.discover.link

  return (
    <NavigationMenuLink asChild>
      <ListPageLink
        href={href}
        className="select-none space-y-1 rounded-md p-2 hover:bg-accent transition-all duration-400 hover:scale-[1.02] hover:shadow-md hover:shadow-primary/5 group animate-scale-in relative overflow-hidden"
        style={{ animationDelay: `${index * 0.08}s` }}
      >
        <div className="text-xs font-medium leading-none">
          <Icon className="mr-1 inline size-3 transition-transform duration-400 group-hover:scale-110" /> {title}
          {showNewBadge ? (
            <Badge className="ml-1.5 px-1 py-0 text-[8px] leading-normal tracking-wide animate-pulse bg-primary">
              {t("nav.new")}
            </Badge>
          ) : null}
        </div>
        <p className="line-clamp-2 text-[11px] leading-snug text-muted-foreground group-hover:text-foreground transition-colors duration-400">
          {description}
        </p>
      </ListPageLink>
    </NavigationMenuLink>
  )
}

export { SiteNav }

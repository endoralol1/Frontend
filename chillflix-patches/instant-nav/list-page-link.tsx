"use client"

import Link from "next/link"
import { forwardRef, type ComponentProps, type MouseEvent } from "react"

import { requiresFullPageNavigation } from "@/lib/list-page-paths"

type ListPageLinkProps = ComponentProps<typeof Link>

function markNavigating() {
  try {
    document.documentElement.dataset.cfNav = "1"
  } catch {
    // ignore
  }
}

export const ListPageLink = forwardRef<HTMLAnchorElement, ListPageLinkProps>(
  function ListPageLink(
    { href, prefetch = true, replace, scroll, onClick, ...props },
    ref
  ) {
    const hrefString = typeof href === "string" ? href : href.pathname ?? ""

    if (requiresFullPageNavigation(hrefString)) {
      return (
        <a
          href={hrefString}
          ref={ref}
          {...(props as ComponentProps<"a">)}
          onClick={(event: MouseEvent<HTMLAnchorElement>) => {
            onClick?.(event as never)
            if (event.defaultPrevented) return
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
            if (event.button !== 0) return
            markNavigating()
          }}
        />
      )
    }

    return (
      <Link
        href={href}
        prefetch={prefetch}
        replace={replace}
        scroll={scroll}
        ref={ref}
        {...props}
        onClick={(event) => {
          onClick?.(event)
          if (event.defaultPrevented) return
          markNavigating()
        }}
      />
    )
  }
)

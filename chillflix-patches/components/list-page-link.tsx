"use client"

import Link from "next/link"
import { forwardRef, type ComponentProps } from "react"

import { requiresFullPageNavigation } from "@/lib/list-page-paths"

type ListPageLinkProps = ComponentProps<typeof Link>

export const ListPageLink = forwardRef<HTMLAnchorElement, ListPageLinkProps>(
  function ListPageLink(
    { href, prefetch, replace, scroll, ...props },
    ref
  ) {
    const hrefString = typeof href === "string" ? href : href.pathname ?? ""

    if (requiresFullPageNavigation(hrefString)) {
      return (
        <a
          href={hrefString}
          ref={ref}
          {...(props as ComponentProps<"a">)}
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
      />
    )
  }
)

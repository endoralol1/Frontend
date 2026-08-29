"use client"

import Link from "next/link"
import {
  forwardRef,
  startTransition,
  useCallback,
  type ComponentProps,
  type MouseEvent,
} from "react"
import { useRouter } from "next/navigation"

import { requiresFullPageNavigation } from "@/lib/list-page-paths"

type ListPageLinkProps = ComponentProps<typeof Link>

function pathOf(href: ListPageLinkProps["href"]) {
  if (typeof href === "string") return href
  if (href && typeof href === "object" && "pathname" in href) {
    return String(href.pathname ?? "")
  }
  return ""
}

export const ListPageLink = forwardRef<HTMLAnchorElement, ListPageLinkProps>(
  function ListPageLink(
    { href, prefetch = true, replace, scroll = true, onClick, onMouseEnter, onTouchStart, ...props },
    ref
  ) {
    const router = useRouter()
    const hrefString = pathOf(href)

    const warm = useCallback(() => {
      if (!hrefString || requiresFullPageNavigation(hrefString)) return
      try {
        router.prefetch(hrefString)
      } catch {
        // ignore
      }
    }, [hrefString, router])

    if (requiresFullPageNavigation(hrefString)) {
      return (
        <a
          href={hrefString}
          ref={ref}
          {...(props as ComponentProps<"a">)}
          onClick={onClick as never}
        />
      )
    }

    const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
      onClick?.(event)
      if (event.defaultPrevented) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
      if (event.button !== 0) return

      // Soft navigate with View Transitions when available for a smooth crossfade.
      const supportsVT =
        typeof document !== "undefined" &&
        "startViewTransition" in document &&
        !window.matchMedia("(prefers-reduced-motion: reduce)").matches

      if (!supportsVT) return // let Next <Link> handle it

      event.preventDefault()
      const navigate = () => {
        startTransition(() => {
          if (replace) router.replace(hrefString, { scroll })
          else router.push(hrefString, { scroll })
        })
      }

      try {
        // @ts-expect-error View Transitions API
        document.startViewTransition(navigate)
      } catch {
        navigate()
      }
    }

    return (
      <Link
        href={href}
        prefetch={prefetch}
        replace={replace}
        scroll={scroll}
        ref={ref}
        {...props}
        onMouseEnter={(e) => {
          warm()
          onMouseEnter?.(e)
        }}
        onTouchStart={(e) => {
          warm()
          onTouchStart?.(e)
        }}
        onClick={handleClick}
      />
    )
  }
)

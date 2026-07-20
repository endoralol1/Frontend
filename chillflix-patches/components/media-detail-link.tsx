"use client"

import Link from "next/link"
import type { ComponentProps, ReactNode } from "react"

import { markDetailModalOpen } from "@/lib/detail-modal-session"
import { requiresFullPageNavigation } from "@/lib/list-page-paths"
import { cn } from "@/lib/utils"

type MediaDetailLinkProps = ComponentProps<typeof Link>

/**
 * Soft-navigates into the Netflix-style detail modal intercept when possible.
 * List browse slugs still use a full document navigation.
 */
export function MediaDetailLink({
  href,
  onClick,
  prefetch = false,
  scroll = false,
  className,
  children,
  ...props
}: MediaDetailLinkProps) {
  const hrefStr = typeof href === "string" ? href : ""

  if (hrefStr && requiresFullPageNavigation(hrefStr)) {
    return (
      <a href={hrefStr} className={cn(className)} onClick={onClick as never}>
        {children as ReactNode}
      </a>
    )
  }

  return (
    <Link
      href={href}
      prefetch={prefetch}
      scroll={scroll}
      className={className}
      {...props}
      onClick={(event) => {
        onClick?.(event)
        if (event.defaultPrevented) return
        markDetailModalOpen()
      }}
    >
      {children}
    </Link>
  )
}

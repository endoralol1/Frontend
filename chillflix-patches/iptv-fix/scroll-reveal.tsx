"use client"

import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react"

import { isLowEndDevice, prefersReducedMotion } from "@/lib/device-profile"
import { cn } from "@/lib/utils"

type ScrollRevealProps = {
    children: ReactNode
    className?: string
    delay?: number
    rootMargin?: string
    threshold?: number
}

export function ScrollReveal({
    children,
    className,
    delay = 0,
    rootMargin = "420px 0px 80px 0px",
    threshold = 0.01,
}: ScrollRevealProps) {
    const containerRef = useRef<HTMLDivElement>(null)
    const [visible, setVisible] = useState(false)

    useEffect(() => {
        const element = containerRef.current
        if (!element || visible) return

        if (isLowEndDevice() || prefersReducedMotion()) {
            setVisible(true)
            return
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true)
                    observer.disconnect()
                }
            },
            { rootMargin, threshold }
        )

        observer.observe(element)
        return () => observer.disconnect()
    }, [rootMargin, threshold, visible])

    const style: CSSProperties | undefined =
        delay > 0 ? { transitionDelay: `${delay}ms` } : undefined

    return (
        <div
            ref={containerRef}
            className={cn("scroll-reveal", visible && "scroll-reveal-visible", className)}
            style={style}
        >
            {children}
        </div>
    )
}

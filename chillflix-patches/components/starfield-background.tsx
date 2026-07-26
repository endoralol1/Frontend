/**
 * Static site background — no canvas, rain, lightning, or CSS motion.
 */
export function StarfieldBackground() {
  return (
    <div
      className="site-ambient-sky fixed inset-0 overflow-hidden pointer-events-none"
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="ambient-void" />
      <div className="ambient-wash ambient-static" />
      <div className="ambient-vignette" />
    </div>
  )
}

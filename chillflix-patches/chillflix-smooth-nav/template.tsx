/** Shared page enter animation for soft navigations (keeps header/nav stable). */
export default function Template({ children }: { children: React.ReactNode }) {
  return <div className="cf-page-enter min-h-[50vh]">{children}</div>
}

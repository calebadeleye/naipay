import type { ReactNode } from 'react';

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    // Everywhere else dark text sits on the glassmorphism backdrop, it's
    // inside a `.glass-surface` panel — a title rendered straight onto
    // `<main>` has no such backing and its legibility depends entirely on
    // which part of the background image happens to be behind it. Giving
    // the header its own glass panel, like every Card below it, makes that
    // guaranteed rather than incidental.
    <div className="glass-surface flex flex-wrap items-start justify-between gap-4 rounded-2xl p-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
        {description ? <p className="mt-1 text-sm text-slate-600">{description}</p> : null}
      </div>

      {actions ? <div className="flex items-center gap-3">{actions}</div> : null}
    </div>
  );
}

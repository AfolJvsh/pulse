import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
  resolve: async (name) => (await import(`./Pages/${name}.tsx`)).default,
  setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
  progress: { color: '#111827' },
});

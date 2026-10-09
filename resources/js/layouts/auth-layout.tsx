import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Layers3 } from 'lucide-react';

export default function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-12 text-white">
            <div className="w-full max-w-md">
                <Link href="/login" className="mb-8 flex items-center justify-center gap-3 text-xl font-semibold">
                    <span className="rounded-xl bg-teal-500 p-2 text-slate-950">
                        <Layers3 size={22} />
                    </span>
                    Kautch CMS
                </Link>
                <section className="rounded-2xl border border-white/10 bg-white p-8 text-slate-900 shadow-2xl shadow-black/20">
                    {children}
                </section>
                <p className="mt-6 text-center text-sm text-slate-400">
                    Gestão de conteúdo com segurança e controle.
                </p>
            </div>
        </main>
    );
}

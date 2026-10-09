import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, LockKeyhole } from 'lucide-react';
import AuthLayout from '@/layouts/auth-layout';

export default function LoginPage() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/login');
    }

    return (
        <AuthLayout>
            <Head title="Entrar" />
            <div className="mb-7">
                <span className="mb-4 inline-flex rounded-xl bg-teal-50 p-3 text-teal-700"><LockKeyhole size={22} /></span>
                <h1 className="text-2xl font-semibold tracking-tight">Acesse sua conta</h1>
                <p className="mt-2 text-sm text-slate-500">Entre para gerenciar seus websites e conteúdos.</p>
            </div>
            <form onSubmit={submit} className="space-y-5">
                <label className="block text-sm font-medium">
                    E-mail
                    <input
                        autoComplete="username"
                        type="email"
                        required
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                    />
                    {errors.email && <span className="mt-1 block text-xs text-red-600">{errors.email}</span>}
                </label>
                <label className="block text-sm font-medium">
                    Senha
                    <input
                        autoComplete="current-password"
                        type="password"
                        required
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                    />
                    {errors.password && <span className="mt-1 block text-xs text-red-600">{errors.password}</span>}
                </label>
                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2 text-slate-600">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                        />
                        Manter conectado
                    </label>
                    <Link href="/forgot-password" className="font-medium text-teal-700 hover:text-teal-900">
                        Esqueci a senha
                    </Link>
                </div>
                <button
                    disabled={processing}
                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:opacity-60"
                >
                    {processing ? 'Entrando...' : 'Entrar'} <ArrowRight size={16} />
                </button>
            </form>
        </AuthLayout>
    );
}

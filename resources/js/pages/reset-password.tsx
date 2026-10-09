import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { api, apiError } from '@/lib/api';

type Props = { token?: string | null; email?: string | null };

export default function ResetPasswordPage({ token: initialToken, email: initialEmail }: Props) {
    const query = new URLSearchParams(window.location.search);
    const [token] = useState(initialToken || query.get('token') || '');
    const [email, setEmail] = useState(initialEmail || query.get('email') || '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setMessage('');
        setProcessing(true);

        try {
            await api('auth/reset-password', 'POST', {
                token,
                email,
                password,
                password_confirmation: confirmation,
            });
            setMessage('Senha redefinida. Você já pode entrar com a nova senha.');
        } catch (requestError) {
            setError(apiError(requestError));
        } finally {
            setProcessing(false);
        }
    }

    return (
        <AuthLayout>
            <Head title="Redefinir senha" />
            <h1 className="text-2xl font-semibold tracking-tight">Defina uma nova senha</h1>
            <p className="mt-2 text-sm text-slate-500">Escolha uma senha segura para sua conta.</p>
            {message && <p role="status" className="mt-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>}
            {error && <p role="alert" className="mt-5 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
            <form onSubmit={submit} className="mt-6 space-y-4">
                <input type="hidden" value={token} />
                <label className="block text-sm font-medium">
                    E-mail
                    <input type="email" required value={email} onChange={(event) => setEmail(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100" />
                </label>
                <label className="block text-sm font-medium">
                    Nova senha
                    <input type="password" required minLength={8} value={password} onChange={(event) => setPassword(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100" />
                </label>
                <label className="block text-sm font-medium">
                    Confirme a senha
                    <input type="password" required minLength={8} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100" />
                </label>
                <button disabled={processing || !token} className="w-full rounded-lg bg-teal-700 px-4 py-3 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60">
                    {processing ? 'Salvando...' : 'Redefinir senha'}
                </button>
            </form>
            <Link href="/login" className="mt-6 block text-center text-sm font-medium text-teal-700 hover:text-teal-900">
                Voltar para o login
            </Link>
        </AuthLayout>
    );
}

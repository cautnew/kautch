import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { api, apiError } from '@/lib/api';

export default function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setMessage('');
        setProcessing(true);

        try {
            await api('auth/forgot-password', 'POST', { email });
            setMessage('Se o e-mail estiver cadastrado, enviaremos um link para redefinir sua senha.');
        } catch (requestError) {
            setError(apiError(requestError));
        } finally {
            setProcessing(false);
        }
    }

    return (
        <AuthLayout>
            <Head title="Recuperar senha" />
            <h1 className="text-2xl font-semibold tracking-tight">Recuperar senha</h1>
            <p className="mt-2 text-sm text-slate-500">Informe seu e-mail para receber as instruções de redefinição.</p>
            {message && <p role="status" className="mt-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>}
            {error && <p role="alert" className="mt-5 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
            <form onSubmit={submit} className="mt-6 space-y-5">
                <label className="block text-sm font-medium">
                    E-mail
                    <input
                        type="email"
                        required
                        value={email}
                        onChange={(event) => setEmail(event.target.value)}
                        className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                    />
                </label>
                <button disabled={processing} className="w-full rounded-lg bg-teal-700 px-4 py-3 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60">
                    {processing ? 'Enviando...' : 'Enviar link de recuperação'}
                </button>
            </form>
            <Link href="/login" className="mt-6 block text-center text-sm font-medium text-teal-700 hover:text-teal-900">
                Voltar para o login
            </Link>
        </AuthLayout>
    );
}

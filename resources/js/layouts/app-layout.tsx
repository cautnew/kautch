import type { ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Boxes,
    FileText,
    Globe2,
    Image,
    LayoutTemplate,
    LogOut,
    Map,
    Settings2,
    Users,
} from 'lucide-react';

export type Section =
    | 'overview'
    | 'websites'
    | 'pages'
    | 'layouts'
    | 'assets'
    | 'routes'
    | 'team'
    | 'profile';

type User = { id: string; name: string; email: string };
type PageProps = { auth: { user: User | null } };

const navigation = [
    { id: 'overview', label: 'Visão geral', icon: Boxes, group: 'Workspace' },
    { id: 'websites', label: 'Websites', icon: Globe2, group: 'Workspace' },
    { id: 'pages', label: 'Páginas e versões', icon: FileText, group: 'Conteúdo' },
    { id: 'layouts', label: 'Layouts', icon: LayoutTemplate, group: 'Conteúdo' },
    { id: 'assets', label: 'Biblioteca de mídia', icon: Image, group: 'Conteúdo' },
    { id: 'routes', label: 'Rotas', icon: Map, group: 'Configuração' },
    { id: 'team', label: 'Equipe', icon: Users, group: 'Configuração' },
    { id: 'profile', label: 'Meu perfil', icon: Settings2, group: 'Conta' },
] as const;

export default function AppLayout({
    children,
    active,
    onNavigate,
    websiteName,
}: {
    children: ReactNode;
    active: Section;
    onNavigate: (section: Section) => void;
    websiteName?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
                <div className="flex h-20 items-center gap-3 border-b border-slate-100 px-6">
                    <span className="rounded-xl bg-teal-600 p-2 text-white"><Boxes size={21} /></span>
                    <div>
                        <p className="font-semibold tracking-tight">Kautch CMS</p>
                        <p className="text-xs text-slate-500">Painel administrativo</p>
                    </div>
                </div>
                <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-6">
                    {['Workspace', 'Conteúdo', 'Configuração', 'Conta'].map((group) => (
                        <div key={group}>
                            <p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">{group}</p>
                            <div className="space-y-1">
                                {navigation.filter((item) => item.group === group).map((item) => {
                                    const Icon = item.icon;

                                    return (
                                        <button
                                            key={item.id}
                                            onClick={() => onNavigate(item.id)}
                                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition ${
                                                active === item.id
                                                    ? 'bg-teal-50 font-semibold text-teal-800'
                                                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                                            }`}
                                        >
                                            <Icon size={17} />
                                            {item.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>
                <div className="border-t border-slate-100 p-4">
                    <div className="mb-3 truncate px-2">
                        <p className="truncate text-sm font-medium">{user?.name}</p>
                        <p className="truncate text-xs text-slate-500">{user?.email}</p>
                    </div>
                    <button
                        onClick={() => router.post('/logout')}
                        className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-900"
                    >
                        <LogOut size={16} /> Sair
                    </button>
                </div>
            </aside>
            <div className="lg:pl-64">
                <header className="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-8">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-800">{websiteName || 'Área administrativa'}</p>
                        <p className="text-xs text-slate-500">Gerencie conteúdo e publicação</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="hidden max-w-48 truncate text-sm text-slate-600 sm:block">{user?.name}</span>
                        <button
                            onClick={() => router.post('/logout')}
                            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                            aria-label="Sair"
                        >
                            <LogOut size={18} />
                        </button>
                    </div>
                </header>
                <main className="mx-auto w-full max-w-[1500px] p-4 sm:p-8">{children}</main>
            </div>
        </div>
    );
}

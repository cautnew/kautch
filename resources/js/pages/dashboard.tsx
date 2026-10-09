import { Head, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowDownToLine,
    ArrowRight,
    Check,
    ChevronDown,
    CirclePlus,
    ClipboardCheck,
    ExternalLink,
    FilePlus2,
    Globe2,
    ImagePlus,
    LoaderCircle,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    ShieldCheck,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout, { type Section } from '@/layouts/app-layout';
import { api, apiError, parseJson, records, type ApiRecord } from '@/lib/api';

type Field = {
    name: string;
    label: string;
    type?: 'text' | 'email' | 'number' | 'textarea' | 'json' | 'select' | 'file';
    required?: boolean;
    options?: Array<{ label: string; value: string }>;
    placeholder?: string;
    accept?: string;
};

type FormValues = Record<string, unknown>;
type FormSpec = {
    title: string;
    description?: string;
    fields: Field[];
    initial?: FormValues;
    save: (values: FormValues) => Promise<void>;
};

type Notice = { type: 'success' | 'error' | 'info'; message: string };
type User = { id: string; name: string; email: string };
type PageProps = { auth: { user: User | null } };

const sectionLabels: Record<Section, string> = {
    overview: 'Visão geral',
    websites: 'Websites',
    pages: 'Páginas e versões',
    layouts: 'Layouts',
    assets: 'Biblioteca de mídia',
    routes: 'Rotas',
    team: 'Equipe',
    profile: 'Meu perfil',
};

function text(value: unknown, fallback = ''): string {
    return value === null || value === undefined ? fallback : String(value);
}

function object(value: unknown): ApiRecord {
    return value !== null && typeof value === 'object' ? value as ApiRecord : {};
}

function displayName(row: ApiRecord): string {
    return text(row.name || row.path || row.filename || row.email || row.type, 'Item');
}

function endpointId(value: unknown): string {
    return encodeURIComponent(text(value));
}

function date(value: unknown): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(text(value));

    return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleDateString('pt-BR');
}

function FormDialog({
    spec,
    onClose,
    onSuccess,
}: {
    spec: FormSpec;
    onClose: () => void;
    onSuccess: () => Promise<void>;
}) {
    const [values, setValues] = useState<FormValues>(() => {
        const initial: FormValues = {};

        for (const field of spec.fields) {
            const value = spec.initial?.[field.name];
            initial[field.name] = field.type === 'json' ? parseJson(value) : value ?? '';
        }

        return initial;
    });
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    function update(name: string, value: unknown) {
        setValues((current) => ({ ...current, [name]: value }));
    }

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setSaving(true);
        const payload: FormValues = { ...values };

        try {
            for (const field of spec.fields) {
                if (field.type === 'json') {
                    const value = text(payload[field.name]).trim();
                    payload[field.name] = value ? JSON.parse(value) : null;
                }
            }

            await spec.save(payload);
            await onSuccess();
        } catch (saveError) {
            setError(apiError(saveError));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/45 p-4" role="presentation">
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="form-dialog-title"
                className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"
            >
                <div className="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h2 id="form-dialog-title" className="text-lg font-semibold">{spec.title}</h2>
                        {spec.description && <p className="mt-1 text-sm text-slate-500">{spec.description}</p>}
                    </div>
                    <button onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Fechar">
                        <X size={18} />
                    </button>
                </div>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {spec.fields.map((field) => (
                            <label key={field.name} className={`block text-sm font-medium text-slate-700 ${field.type === 'json' || field.type === 'textarea' ? 'sm:col-span-2' : ''}`}>
                                {field.label}
                                {field.type === 'textarea' || field.type === 'json' ? (
                                    <textarea
                                        required={field.required}
                                        rows={field.type === 'json' ? 7 : 3}
                                        value={text(values[field.name])}
                                        onChange={(event) => update(field.name, event.target.value)}
                                        placeholder={field.placeholder}
                                        className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                                    />
                                ) : field.type === 'select' ? (
                                    <select
                                        required={field.required}
                                        value={text(values[field.name])}
                                        onChange={(event) => update(field.name, event.target.value)}
                                        className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-normal outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                                    >
                                        {!field.required && <option value="">— Nenhum —</option>}
                                        {field.options?.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </select>
                                ) : field.type === 'file' ? (
                                    <input
                                        required={field.required}
                                        type="file"
                                        accept={field.accept}
                                        onChange={(event) => update(field.name, event.target.files?.[0] ?? null)}
                                        className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal file:mr-3 file:rounded-md file:border-0 file:bg-teal-50 file:px-3 file:py-1 file:text-sm file:font-medium file:text-teal-800"
                                    />
                                ) : (
                                    <input
                                        required={field.required}
                                        type={field.type ?? 'text'}
                                        value={text(values[field.name])}
                                        onChange={(event) => update(field.name, event.target.value)}
                                        placeholder={field.placeholder}
                                        className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                                    />
                                )}
                            </label>
                        ))}
                    </div>
                    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Cancelar
                        </button>
                        <button disabled={saving} className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60">
                            {saving ? 'Salvando...' : 'Salvar'}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    );
}

function StatusBadge({ value }: { value: unknown }) {
    const status = text(value, 'ativo');
    const colors: Record<string, string> = {
        published: 'bg-emerald-50 text-emerald-700',
        active: 'bg-emerald-50 text-emerald-700',
        approved: 'bg-sky-50 text-sky-700',
        under_review: 'bg-amber-50 text-amber-800',
        draft: 'bg-slate-100 text-slate-600',
        rejected: 'bg-rose-50 text-rose-700',
        archived: 'bg-slate-100 text-slate-500',
        suspended: 'bg-rose-50 text-rose-700',
    };

    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${colors[status] ?? 'bg-slate-100 text-slate-600'}`}>{status.replaceAll('_', ' ')}</span>;
}

export default function DashboardPage() {
    const { auth } = usePage<PageProps>().props;
    const [active, setActive] = useState<Section>('overview');
    const [websites, setWebsites] = useState<ApiRecord[]>([]);
    const [websiteId, setWebsiteId] = useState('');
    const [rows, setRows] = useState<ApiRecord[]>([]);
    const [pages, setPages] = useState<ApiRecord[]>([]);
    const [layouts, setLayouts] = useState<ApiRecord[]>([]);
    const [versions, setVersions] = useState<ApiRecord[]>([]);
    const [versionPage, setVersionPage] = useState('');
    const [activeVersion, setActiveVersion] = useState<ApiRecord | null>(null);
    const [pieces, setPieces] = useState<ApiRecord[]>([]);
    const [profile, setProfile] = useState<ApiRecord | null>(null);
    const [dialog, setDialog] = useState<FormSpec | null>(null);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [resolvePath, setResolvePath] = useState('/');
    const [resolveMethod, setResolveMethod] = useState('GET');
    const [resolveResult, setResolveResult] = useState<unknown>(null);

    const selectedWebsite = useMemo(
        () => websites.find((website) => text(website.id) === websiteId),
        [websiteId, websites],
    );

    const loadWebsites = useCallback(async () => {
        const result = records(await api<unknown>('websites'));
        setWebsites(result);
        const storedId = window.localStorage.getItem('kautch.website');
        const nextId = result.some((website) => text(website.id) === websiteId)
            ? websiteId
            : result.some((website) => text(website.id) === storedId)
                ? text(storedId)
                : text(result[0]?.id);

        setWebsiteId(nextId);
        if (nextId) {
            window.localStorage.setItem('kautch.website', nextId);
        }

        return result;
    }, [websiteId]);

    const loadActive = useCallback(async () => {
        if (active === 'overview' || active === 'websites') {
            await loadWebsites();
            return;
        }

        if (active === 'profile') {
            setProfile(object(await api<unknown>('auth/profile')));
            return;
        }

        if (!websiteId) {
            setRows([]);
            return;
        }

        const base = `websites/${endpointId(websiteId)}`;
        const endpoints: Partial<Record<Section, string>> = {
            pages: `${base}/pages`,
            layouts: `${base}/layouts`,
            assets: `${base}/assets`,
            routes: `${base}/routes`,
            team: `${base}/users`,
        };
        const endpoint = endpoints[active];

        if (endpoint) {
            const result = records(await api<unknown>(endpoint));
            setRows(result);

            if (active === 'pages') {
                setPages(result);
            } else if (active === 'layouts') {
                setLayouts(result);
            }
        }
    }, [active, loadWebsites, websiteId]);

    useEffect(() => {
        setLoading(true);
        void loadActive()
            .catch((error: unknown) => setNotice({ type: 'error', message: apiError(error) }))
            .finally(() => setLoading(false));
    }, [loadActive]);

    function notify(message: string, type: Notice['type'] = 'success') {
        setNotice({ type, message });
    }

    async function refreshAfterSave() {
        setDialog(null);
        await loadActive();
        notify('Alterações salvas com sucesso.');
    }

    async function act(message: string, operation: () => Promise<void>) {
        setBusy(true);
        setNotice(null);

        try {
            await operation();
            notify(message);
        } catch (error) {
            notify(apiError(error), 'error');
        } finally {
            setBusy(false);
        }
    }

    function openWebsiteForm(item?: ApiRecord) {
        const id = text(item?.id);
        setDialog({
            title: item ? 'Editar website' : 'Novo website',
            fields: [
                { name: 'name', label: 'Nome', required: true },
                { name: 'domain', label: 'Domínio', required: true, placeholder: 'exemplo.com.br' },
                { name: 'subdomain', label: 'Subdomínio', placeholder: 'www (opcional)' },
                { name: 'locale', label: 'Idioma', placeholder: 'pt-BR' },
                { name: 'timezone', label: 'Fuso horário', placeholder: 'America/Sao_Paulo' },
                ...(item ? [{ name: 'status', label: 'Status', type: 'select' as const, options: ['active', 'suspended', 'archived'].map((value) => ({ label: value, value })) }] : []),
            ],
            initial: item ? { ...item, subdomain: item.subdomain ?? '' } : { locale: 'pt-BR', timezone: 'America/Sao_Paulo' },
            save: async (values) => {
                const payload = { ...values, subdomain: text(values.subdomain).trim() || null };
                await api(id ? `websites/${endpointId(id)}` : 'websites', id ? 'PUT' : 'POST', payload);
            },
        });
    }

    function openLayoutForm(item?: ApiRecord) {
        const id = text(item?.id);
        setDialog({
            title: item ? 'Editar layout' : 'Novo layout',
            fields: [
                { name: 'name', label: 'Nome', required: true },
                { name: 'slug', label: 'Slug', required: true },
                { name: 'description', label: 'Descrição', type: 'textarea' },
                { name: 'status', label: 'Status', type: 'select', options: ['draft', 'active', 'archived'].map((value) => ({ label: value, value })) },
                { name: 'schema', label: 'Schema do layout (JSON)', type: 'json', placeholder: '{"regions":["main"]}' },
            ],
            initial: item ?? { status: 'draft', schema: { regions: ['main'] } },
            save: (values) => api(
                `websites/${endpointId(websiteId)}/layouts${id ? `/${endpointId(id)}` : ''}`,
                id ? 'PUT' : 'POST',
                values,
            ).then(() => undefined),
        });
    }

    function openPageForm(item?: ApiRecord) {
        const id = text(item?.id);
        setDialog({
            title: item ? 'Editar página' : 'Nova página',
            fields: [
                { name: 'name', label: 'Nome', required: true },
                { name: 'slug', label: 'Slug', required: true },
                { name: 'layout_id', label: 'Layout', type: 'select', required: true, options: layouts.map((layout) => ({ label: text(layout.name), value: text(layout.id) })) },
                ...(item ? [{ name: 'status', label: 'Status', type: 'select' as const, options: ['active', 'archived'].map((value) => ({ label: value, value })) }] : []),
            ],
            initial: item ?? { status: 'active' },
            save: (values) => api(
                `websites/${endpointId(websiteId)}/pages${id ? `/${endpointId(id)}` : ''}`,
                id ? 'PUT' : 'POST',
                values,
            ).then(() => undefined),
        });
    }

    function openRouteForm(item?: ApiRecord) {
        const id = text(item?.id);
        const creating = !item;
        setDialog({
            title: item ? 'Editar rota' : 'Nova rota',
            fields: [
                { name: 'path', label: 'Caminho', required: true, placeholder: '/sobre' },
                ...(creating ? [
                    { name: 'destination_type', label: 'Destino', type: 'select' as const, required: true, options: ['page', 'asset', 'redirect'].map((value) => ({ label: value, value })) },
                    { name: 'page_id', label: 'ID da página' },
                    { name: 'asset_id', label: 'ID do asset' },
                ] : []),
                ...(item && text(item.destination_type) === 'redirect' ? [{ name: 'redirect_to_route_id', label: 'ID da rota de destino' }] : []),
                { name: 'http_method', label: 'Método HTTP', type: 'select', options: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].map((value) => ({ label: value, value })) },
                { name: 'http_status', label: 'Código HTTP', type: 'number' },
            ],
            initial: item ?? { http_method: 'GET', http_status: 200, destination_type: 'page' },
            save: (values) => {
                const payload: FormValues = {
                    ...values,
                    page_id: text(values.page_id) || null,
                    asset_id: text(values.asset_id) || null,
                    redirect_to_route_id: text(values.redirect_to_route_id) || null,
                };

                return api(
                    `websites/${endpointId(websiteId)}/routes${id ? `/${endpointId(id)}` : ''}`,
                    id ? 'PUT' : 'POST',
                    payload,
                ).then(() => undefined);
            },
        });
    }

    function openMemberForm() {
        setDialog({
            title: 'Convidar pessoa para a equipe',
            fields: [
                { name: 'email', label: 'E-mail', type: 'email', required: true },
                { name: 'name', label: 'Nome (opcional)' },
                { name: 'role', label: 'Função', type: 'select', required: true, options: ['admin', 'editor', 'qa', 'viewer'].map((value) => ({ label: value, value })) },
            ],
            initial: { role: 'editor' },
            save: (values) => api(`websites/${endpointId(websiteId)}/users`, 'POST', values).then(() => undefined),
        });
    }

    function openVersionForm(page: ApiRecord) {
        const pageId = text(page.id);
        setDialog({
            title: `Nova versão: ${displayName(page)}`,
            fields: [
                { name: 'layout_id', label: 'Layout', type: 'select', required: true, options: layouts.map((layout) => ({ label: text(layout.name), value: text(layout.id) })) },
                { name: 'seo', label: 'Metadados SEO (JSON)', type: 'json', placeholder: '{"title":"Título da página"}' },
            ],
            initial: { layout_id: text(page.layout_id), seo: {} },
            save: (values) => api(`pages/${endpointId(pageId)}/versions`, 'POST', values).then(() => undefined),
        });
    }

    function openEditVersionForm(version: ApiRecord) {
        const versionId = text(version.id);
        setDialog({
            title: `Editar versão ${text(version.version_number)}`,
            description: 'O conteúdo é editado pelas peças. Uma versão publicada gera um novo rascunho ao ser alterada.',
            fields: [
                { name: 'layout_id', label: 'Layout', type: 'select', options: layouts.map((layout) => ({ label: text(layout.name), value: text(layout.id) })) },
                { name: 'seo', label: 'Metadados SEO (JSON)', type: 'json' },
            ],
            initial: { layout_id: version.layout_id, seo: version.seo_snapshot },
            save: async (values) => {
                const result = object(await api<unknown>(`versions/${endpointId(versionId)}`, 'PUT', values));
                setActiveVersion(result);
            },
        });
    }

    function openPieceForm(version: ApiRecord, piece?: ApiRecord) {
        const id = text(piece?.id);
        const versionId = text(version.id);
        const pieceTypes = ['heading', 'paragraph', 'image', 'list', 'two_columns_wrapper', 'text_image_wrapper'];
        setDialog({
            title: piece ? `Editar peça: ${displayName(piece)}` : 'Adicionar peça',
            fields: [
                ...(!piece ? [{ name: 'type', label: 'Tipo', type: 'select' as const, required: true, options: pieceTypes.map((value) => ({ label: value.replaceAll('_', ' '), value })) }] : []),
                { name: 'parent_piece_id', label: 'ID da peça pai (opcional)' },
                { name: 'slot', label: 'Slot da peça pai' },
                { name: 'region', label: 'Região do layout' },
                { name: 'position', label: 'Posição', type: 'number' },
                { name: 'asset_id', label: 'ID do asset (para imagem)' },
                { name: 'content', label: 'Conteúdo (JSON)', type: 'json', placeholder: '{"text":"Texto da peça","level":2}' },
                { name: 'settings', label: 'Configurações (JSON)', type: 'json' },
            ],
            initial: piece ?? { type: 'heading', position: 0, content: { text: '', level: 2 }, settings: {} },
            save: (values) => {
                const payload = {
                    ...values,
                    parent_piece_id: text(values.parent_piece_id) || null,
                    slot: text(values.slot) || null,
                    region: text(values.region) || null,
                    asset_id: text(values.asset_id) || null,
                };

                return api(
                    piece ? `pieces/${endpointId(id)}` : `versions/${endpointId(versionId)}/pieces`,
                    piece ? 'PUT' : 'POST',
                    payload,
                ).then(() => undefined);
            },
        });
    }

    async function loadVersions(page: ApiRecord) {
        const pageId = text(page.id);
        setVersionPage(pageId);
        setActiveVersion(null);
        setPieces([]);
        setVersions(records(await api<unknown>(`pages/${endpointId(pageId)}/versions`)));
    }

    async function selectVersion(version: ApiRecord) {
        setActiveVersion(version);
        setPieces(records(await api<unknown>(`versions/${endpointId(version.id)}/pieces`)));
    }

    async function deleteRecord(kind: Section, item: ApiRecord) {
        if (!window.confirm(`Excluir "${displayName(item)}"? Esta ação não pode ser desfeita.`)) {
            return;
        }

        const id = endpointId(item.id);
        const path: Partial<Record<Section, string>> = {
            websites: `websites/${id}`,
            layouts: `websites/${endpointId(websiteId)}/layouts/${id}`,
            pages: `websites/${endpointId(websiteId)}/pages/${id}`,
            assets: `websites/${endpointId(websiteId)}/assets/${id}`,
            routes: `websites/${endpointId(websiteId)}/routes/${id}`,
            team: `websites/${endpointId(websiteId)}/users/${id}`,
        };

        const endpoint = path[kind];
        if (!endpoint) {
            return;
        }

        await act('Item excluído.', async () => {
            await api(endpoint, 'DELETE');
            await loadActive();
        });
    }

    async function runVersionAction(action: 'submit-review' | 'approve' | 'reject' | 'publish' | 'delete', version: ApiRecord) {
        let body: FormValues = {};

        if (action === 'approve' || action === 'reject') {
            const notes = window.prompt(action === 'approve' ? 'Observações da aprovação (opcional):' : 'Motivo da rejeição:');
            if (notes === null || (action === 'reject' && !notes.trim())) {
                return;
            }
            body = { notes };
        }

        if (!window.confirm(`${action === 'publish' ? 'Publicar' : action === 'delete' ? 'Excluir' : 'Confirmar'} esta versão?`)) {
            return;
        }

        await act('Fluxo da versão atualizado.', async () => {
            const id = endpointId(version.id);
            const method = action === 'delete' ? 'DELETE' : 'POST';
            const path = action === 'delete' ? `versions/${id}` : `versions/${id}/${action}`;
            const updated = await api<unknown>(path, method, body);
            if (action === 'delete') {
                setActiveVersion(null);
                setPieces([]);
            } else {
                setActiveVersion(object(updated));
            }
            if (versionPage) {
                const result = records(await api<unknown>(`pages/${endpointId(versionPage)}/versions`));
                setVersions(result);
            }
        });
    }

    async function runPieceDelete(piece: ApiRecord) {
        if (!window.confirm('Excluir esta peça e todas as suas peças filhas?')) {
            return;
        }

        await act('Peça excluída.', async () => {
            await api(`pieces/${endpointId(piece.id)}`, 'DELETE');
            if (activeVersion) {
                setPieces(records(await api<unknown>(`versions/${endpointId(activeVersion.id)}/pieces`)));
            }
        });
    }

    async function uploadAsset(values: FormValues) {
        const data = new FormData();
        const file = values.file;
        if (!(file instanceof File)) {
            throw new Error('Selecione um arquivo para enviar.');
        }

        data.append('file', file);
        for (const key of ['layout_id', 'page_version_id', 'alt_text']) {
            const value = text(values[key]).trim();
            if (value) {
                data.append(key, value);
            }
        }
        if (values.metadata && typeof values.metadata === 'object') {
            data.append('metadata', JSON.stringify(values.metadata));
        }

        await api(`websites/${endpointId(websiteId)}/assets`, 'POST', data);
    }

    async function editAsset(item: ApiRecord) {
        const id = endpointId(item.id);
        setDialog({
            title: 'Editar informações do arquivo',
            fields: [
                { name: 'alt_text', label: 'Texto alternativo' },
                { name: 'metadata', label: 'Metadados (JSON)', type: 'json' },
            ],
            initial: item,
            save: (values) => api(`websites/${endpointId(websiteId)}/assets/${id}`, 'PUT', values).then(() => undefined),
        });
    }

    async function downloadAsset(asset: ApiRecord) {
        await act('Preparando download...', async () => {
            const result = await api<unknown>(`websites/${endpointId(websiteId)}/assets/${endpointId(asset.id)}/download`);
            const url = typeof result === 'string' ? result : text(object(result).url);
            if (!url) {
                throw new Error('A API não retornou uma URL de download.');
            }
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    }

    async function resolveRoute(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        await act('Rota resolvida.', async () => {
            const query = new URLSearchParams({ path: resolvePath, method: resolveMethod });
            setResolveResult(await api<unknown>(`websites/${endpointId(websiteId)}/routes/resolve?${query}`));
        });
    }

    async function saveProfile(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        await act('Perfil atualizado.', async () => {
            const result = object(await api<unknown>('auth/profile', 'PUT', {
                name: form.get('name'),
                email: form.get('email'),
            }));
            setProfile(result);
        });
    }

    async function changePassword(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        await act('Senha atualizada.', async () => {
            await api('auth/change-password', 'PUT', {
                current_password: form.get('current_password'),
                password: form.get('password'),
                password_confirmation: form.get('password_confirmation'),
            });
            event.currentTarget.reset();
        });
    }

    function headingAction() {
        if (active === 'websites') {
            openWebsiteForm();
        } else if (active === 'pages') {
            openPageForm();
        } else if (active === 'layouts') {
            openLayoutForm();
        } else if (active === 'routes') {
            openRouteForm();
        } else if (active === 'team') {
            openMemberForm();
        } else if (active === 'assets') {
            setDialog({
                title: 'Enviar arquivo',
                description: 'São aceitos JPG, PNG, GIF, SVG, WebP, PDF, MP4 e WebM, até 50 MB.',
                fields: [
                    { name: 'file', label: 'Arquivo', type: 'file', required: true, accept: '.jpg,.jpeg,.png,.gif,.svg,.webp,.pdf,.mp4,.webm' },
                    { name: 'layout_id', label: 'ID do layout (ou deixe vazio para usar versão)' },
                    { name: 'page_version_id', label: 'ID da versão de página (ou deixe vazio para usar layout)' },
                    { name: 'alt_text', label: 'Texto alternativo' },
                    { name: 'metadata', label: 'Metadados (JSON)', type: 'json' },
                ],
                save: uploadAsset,
            });
        }
    }

    const title = sectionLabels[active];
    const canCreate = ['websites', 'pages', 'layouts', 'assets', 'routes', 'team'].includes(active);
    const rowActions = active !== 'overview' && active !== 'profile' && active !== 'pages';

    function renderPiece(piece: ApiRecord, depth = 0) {
        const children = records(piece.children);

        return (
            <div key={text(piece.id)} className={`rounded-xl border border-slate-200 bg-white p-4 ${depth > 0 ? 'ml-5 border-l-2 border-l-teal-200' : ''}`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold">{text(piece.type)}</span>
                            {typeof piece.region === 'string' ? <StatusBadge value={piece.region} /> : null}
                            {typeof piece.slot === 'string' ? <span className="text-xs text-slate-500">slot: {text(piece.slot)}</span> : null}
                        </div>
                        <pre className="mt-2 max-w-full overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{JSON.stringify(piece.content ?? {}, null, 2)}</pre>
                    </div>
                    <div className="flex gap-1">
                        <button onClick={() => activeVersion && openPieceForm(activeVersion, piece)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar peça"><Pencil size={15} /></button>
                        <button onClick={() => void runPieceDelete(piece)} className="rounded-lg p-2 text-rose-600 hover:bg-rose-50" title="Excluir peça"><Trash2 size={15} /></button>
                    </div>
                </div>
                {children.length > 0 && <div className="mt-3 space-y-3">{children.map((child) => renderPiece(child, depth + 1))}</div>}
            </div>
        );
    }

    return (
        <AppLayout active={active} onNavigate={setActive} websiteName={text(selectedWebsite?.name, 'Selecione um website')}>
            <Head title={title} />
            <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="text-sm font-medium text-teal-700">Painel administrativo</p>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {active === 'overview' ? `Olá, ${auth.user?.name ?? 'bem-vindo'}. Aqui está seu espaço de trabalho.` : 'Gerencie os dados usando as mesmas regras e permissões da API.'}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {!['overview', 'websites', 'profile'].includes(active) && (
                        <label className="relative">
                            <Globe2 size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <select
                                aria-label="Website ativo"
                                value={websiteId}
                                onChange={(event) => {
                                    setWebsiteId(event.target.value);
                                    window.localStorage.setItem('kautch.website', event.target.value);
                                    setActiveVersion(null);
                                    setVersions([]);
                                }}
                                className="max-w-52 appearance-none rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-8 text-sm font-medium outline-none focus:border-teal-600"
                            >
                                {websites.map((website) => <option key={text(website.id)} value={text(website.id)}>{text(website.name)}</option>)}
                            </select>
                            <ChevronDown size={14} className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" />
                        </label>
                    )}
                    {canCreate && (
                        <button onClick={headingAction} className="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-800">
                            <Plus size={16} /> <span className="hidden sm:inline">Adicionar</span>
                        </button>
                    )}
                    <button onClick={() => void loadActive().catch((error: unknown) => notify(apiError(error), 'error'))} className="rounded-lg border border-slate-300 bg-white p-2.5 text-slate-600 hover:bg-slate-50" aria-label="Atualizar">
                        <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
                    </button>
                </div>
            </div>

            {notice && (
                <div role={notice.type === 'error' ? 'alert' : 'status'} className={`mb-5 flex items-start justify-between gap-3 rounded-xl border p-3 text-sm ${
                    notice.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : notice.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-sky-200 bg-sky-50 text-sky-800'
                }`}>
                    <span>{notice.message}</span>
                    <button onClick={() => setNotice(null)} aria-label="Fechar aviso"><X size={16} /></button>
                </div>
            )}

            {loading ? (
                <div className="flex min-h-56 items-center justify-center text-sm text-slate-500"><LoaderCircle className="mr-2 animate-spin" size={18} /> Carregando dados...</div>
            ) : active === 'overview' ? (
                <div className="space-y-6">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            { label: 'Websites disponíveis', value: websites.length, icon: Globe2 },
                            { label: 'Seções administrativas', value: 6, icon: Activity },
                            { label: 'Fluxo de publicação', value: 'Ativo', icon: ClipboardCheck },
                            { label: 'Sessão protegida', value: 'Sanctum', icon: ShieldCheck },
                        ].map((stat) => {
                            const Icon = stat.icon;
                            return (
                                <article key={stat.label} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-900/[0.02]">
                                    <div className="flex items-center justify-between text-sm text-slate-500">
                                        {stat.label}<span className="rounded-lg bg-teal-50 p-2 text-teal-700"><Icon size={17} /></span>
                                    </div>
                                    <p className="mt-3 text-2xl font-semibold text-slate-900">{stat.value}</p>
                                </article>
                            );
                        })}
                    </div>
                    <section className="rounded-xl border border-slate-200 bg-white p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">Seus websites</h2>
                                <p className="mt-1 text-sm text-slate-500">Selecione um projeto para gerenciar conteúdo, mídia e publicação.</p>
                            </div>
                            <button onClick={() => setActive('websites')} className="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:text-teal-900">Ver websites <ArrowRight size={15} /></button>
                        </div>
                        {websites.length === 0 ? (
                            <div className="mt-6 rounded-xl border border-dashed border-slate-300 px-5 py-10 text-center">
                                <Globe2 className="mx-auto text-slate-400" size={24} />
                                <p className="mt-3 font-medium">Ainda não há websites vinculados</p>
                                <p className="mt-1 text-sm text-slate-500">Crie um website para começar a organizar seu conteúdo.</p>
                                <button onClick={() => openWebsiteForm()} className="mt-4 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Criar website</button>
                            </div>
                        ) : (
                            <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {websites.map((website) => (
                                    <button
                                        key={text(website.id)}
                                        onClick={() => { setWebsiteId(text(website.id)); setActive('pages'); }}
                                        className="group rounded-xl border border-slate-200 p-4 text-left transition hover:border-teal-300 hover:bg-teal-50/40"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="rounded-lg bg-slate-100 p-2 text-slate-600 group-hover:bg-white group-hover:text-teal-700"><Globe2 size={18} /></span>
                                            <StatusBadge value={website.status} />
                                        </div>
                                        <p className="mt-4 font-semibold">{displayName(website)}</p>
                                        <p className="mt-1 truncate text-sm text-slate-500">{text(website.host || website.domain)}</p>
                                    </button>
                                ))}
                            </div>
                        )}
                    </section>
                    <div className="grid gap-4 md:grid-cols-3">
                        {[
                            { label: 'Páginas e versões', section: 'pages' as const, text: 'Crie páginas, edite versões e acompanhe aprovações.' },
                            { label: 'Biblioteca de mídia', section: 'assets' as const, text: 'Envie arquivos, edite metadados e baixe assets.' },
                            { label: 'Rotas e equipe', section: 'routes' as const, text: 'Configure destinos e gerencie os membros do website.' },
                        ].map((card) => (
                            <button key={card.section} onClick={() => setActive(card.section)} className="rounded-xl border border-slate-200 bg-white p-5 text-left hover:border-teal-300">
                                <p className="font-semibold">{card.label}</p><p className="mt-2 text-sm leading-6 text-slate-500">{card.text}</p>
                            </button>
                        ))}
                    </div>
                </div>
            ) : active === 'profile' ? (
                <div className="grid gap-5 xl:grid-cols-2">
                    <form onSubmit={(event) => void saveProfile(event)} className="rounded-xl border border-slate-200 bg-white p-6">
                        <h2 className="font-semibold">Informações pessoais</h2>
                        <p className="mt-1 text-sm text-slate-500">Atualize nome e e-mail da conta.</p>
                        <label className="mt-5 block text-sm font-medium">Nome
                            <input name="name" required defaultValue={text(profile?.name, auth.user?.name)} className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600" />
                        </label>
                        <label className="mt-4 block text-sm font-medium">E-mail
                            <input name="email" type="email" required defaultValue={text(profile?.email, auth.user?.email)} className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600" />
                        </label>
                        <button disabled={busy} className="mt-5 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">Salvar perfil</button>
                    </form>
                    <form onSubmit={(event) => void changePassword(event)} className="rounded-xl border border-slate-200 bg-white p-6">
                        <h2 className="font-semibold">Alterar senha</h2>
                        <p className="mt-1 text-sm text-slate-500">Confirme sua senha atual antes de definir uma nova.</p>
                        {[
                            { name: 'current_password', label: 'Senha atual' },
                            { name: 'password', label: 'Nova senha' },
                            { name: 'password_confirmation', label: 'Confirme a nova senha' },
                        ].map((field) => (
                            <label key={field.name} className="mt-4 block text-sm font-medium">{field.label}
                                <input name={field.name} type="password" required minLength={8} className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600" />
                            </label>
                        ))}
                        <button disabled={busy} className="mt-5 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Atualizar senha</button>
                    </form>
                </div>
            ) : active === 'pages' ? (
                <div className="space-y-5">
                    {pages.length === 0 && rows.length === 0 ? (
                        <EmptyState title="Nenhuma página encontrada" detail="Crie páginas para iniciar o fluxo editorial." action="Criar página" onAction={() => openPageForm()} />
                    ) : (
                        <div className="grid gap-4 xl:grid-cols-2">
                            {rows.map((page) => (
                                <article key={text(page.id)} className="rounded-xl border border-slate-200 bg-white p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h2 className="truncate font-semibold">{displayName(page)}</h2>
                                            <p className="mt-1 truncate text-sm text-slate-500">/{text(page.slug)} · {date(page.updated_at)}</p>
                                        </div>
                                        <StatusBadge value={page.status} />
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <ActionButton onClick={() => openPageForm(page)} icon={<Pencil size={14} />}>Editar</ActionButton>
                                        <ActionButton onClick={() => void loadVersions(page).catch((error: unknown) => notify(apiError(error), 'error'))} icon={<Activity size={14} />}>
                                            {versionPage === text(page.id) ? 'Atualizar versões' : 'Versões'}
                                        </ActionButton>
                                        <ActionButton onClick={() => openVersionForm(page)} icon={<FilePlus2 size={14} />}>Nova versão</ActionButton>
                                        <ActionButton onClick={() => void act('Pré-visualização carregada.', async () => {
                                            const content = await api<unknown>(`content/${endpointId(websiteId)}/${endpointId(page.slug)}`);
                                            setResolveResult(content);
                                        })} icon={<ExternalLink size={14} />}>Prévia pública</ActionButton>
                                        <ActionButton danger onClick={() => void deleteRecord('pages', page)} icon={<Trash2 size={14} />}>Excluir</ActionButton>
                                    </div>
                                    {versionPage === text(page.id) && (
                                        <div className="mt-5 border-t border-slate-100 pt-4">
                                            <h3 className="mb-3 text-sm font-semibold">Versões</h3>
                                            {versions.length === 0 ? <p className="text-sm text-slate-500">Ainda não há versões.</p> : (
                                                <div className="space-y-2">
                                                    {versions.map((version) => (
                                                        <div key={text(version.id)} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-3">
                                                            <button onClick={() => void selectVersion(version).catch((error: unknown) => notify(apiError(error), 'error'))} className="flex items-center gap-2 text-left">
                                                                <span className="text-sm font-medium">Versão {text(version.version_number)}</span>
                                                                <StatusBadge value={version.status} />
                                                                {version.is_published === true && <span className="text-xs text-emerald-700">Publicada</span>}
                                                            </button>
                                                            <div className="flex flex-wrap gap-1">
                                                                {text(version.status) === 'draft' || text(version.status) === 'rejected' ? (
                                                                    <ActionButton onClick={() => void runVersionAction('submit-review', version)} icon={<ArrowRight size={13} />}>Enviar para revisão</ActionButton>
                                                                ) : null}
                                                                {text(version.status) === 'under_review' && (
                                                                    <>
                                                                        <ActionButton onClick={() => void runVersionAction('approve', version)} icon={<Check size={13} />}>Aprovar</ActionButton>
                                                                        <ActionButton danger onClick={() => void runVersionAction('reject', version)} icon={<X size={13} />}>Rejeitar</ActionButton>
                                                                    </>
                                                                )}
                                                                {text(version.status) === 'approved' && <ActionButton onClick={() => void runVersionAction('publish', version)} icon={<ExternalLink size={13} />}>Publicar</ActionButton>}
                                                                <ActionButton onClick={() => openEditVersionForm(version)} icon={<Pencil size={13} />}>SEO</ActionButton>
                                                                <ActionButton danger onClick={() => void runVersionAction('delete', version)} icon={<Trash2 size={13} />}>Excluir</ActionButton>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                            {activeVersion && versions.some((version) => text(version.id) === text(activeVersion.id)) && (
                                                <section className="mt-4 rounded-xl border border-teal-100 bg-teal-50/40 p-4">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <p className="text-sm font-semibold">Conteúdo da versão {text(activeVersion.version_number)}</p>
                                                            <p className="mt-1 text-xs text-slate-500">Edite o conteúdo estruturado em peças; a API valida slots e regiões.</p>
                                                        </div>
                                                        <button onClick={() => openPieceForm(activeVersion)} className="inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-3 py-2 text-xs font-semibold text-white hover:bg-teal-800"><CirclePlus size={14} /> Adicionar peça</button>
                                                    </div>
                                                    <div className="mt-4 space-y-3">
                                                        {pieces.length === 0 ? <p className="text-sm text-slate-500">Esta versão ainda não possui peças.</p> : pieces.map((piece) => renderPiece(piece))}
                                                    </div>
                                                    {Array.isArray(object(activeVersion.reviews).data) && (
                                                        <div className="mt-4 border-t border-teal-100 pt-3">
                                                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Histórico de revisão</p>
                                                            <pre className="mt-2 max-h-40 overflow-auto text-xs text-slate-600">{JSON.stringify(object(activeVersion.reviews).data, null, 2)}</pre>
                                                        </div>
                                                    )}
                                                </section>
                                            )}
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </div>
            ) : active === 'routes' ? (
                <div className="space-y-5">
                    <section className="rounded-xl border border-slate-200 bg-white p-5">
                        <div className="flex items-start gap-3">
                            <span className="rounded-lg bg-teal-50 p-2 text-teal-700"><Search size={17} /></span>
                            <div><h2 className="font-semibold">Resolver rota pública</h2><p className="mt-1 text-sm text-slate-500">Teste para onde um caminho e método HTTP seriam direcionados.</p></div>
                        </div>
                        <form onSubmit={(event) => void resolveRoute(event)} className="mt-4 flex flex-wrap gap-2">
                            <input aria-label="Caminho da rota" value={resolvePath} onChange={(event) => setResolvePath(event.target.value)} required className="min-w-48 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-teal-600" />
                            <select aria-label="Método HTTP" value={resolveMethod} onChange={(event) => setResolveMethod(event.target.value)} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                {['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].map((method) => <option key={method}>{method}</option>)}
                            </select>
                            <button disabled={busy} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Resolver</button>
                        </form>
                        {resolveResult !== null && <pre className="mt-4 max-h-64 overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-emerald-200">{JSON.stringify(resolveResult, null, 2)}</pre>}
                    </section>
                    <ResourceList rows={rows} label="rota" emptyTitle="Nenhuma rota cadastrada" emptyDetail="Associe caminhos a páginas, arquivos ou redirecionamentos." onEdit={openRouteForm} onDelete={(item) => void deleteRecord('routes', item)} showActions={rowActions} />
                </div>
            ) : active === 'assets' ? (
                <ResourceList rows={rows} label="arquivo" emptyTitle="Biblioteca vazia" emptyDetail="Envie imagens, documentos ou vídeos para o website." onEdit={(item) => void editAsset(item)} onDelete={(item) => void deleteRecord('assets', item)} onDownload={(item) => void downloadAsset(item)} showActions={rowActions} />
            ) : active === 'team' ? (
                <div className="space-y-4">
                    {rows.length === 0 ? <EmptyState title="Equipe sem membros listados" detail="Convide colaboradores para trabalhar neste website." action="Convidar pessoa" onAction={openMemberForm} /> : rows.map((member) => (
                        <article key={text(member.id)} className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                            <div>
                                <p className="font-semibold">{text(object(member.user).name, 'Convite pendente')}</p>
                                <p className="mt-1 text-sm text-slate-500">{text(object(member.user).email)} · convite {date(member.invited_at)}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <select
                                    value={text(member.role)}
                                    onChange={(event) => void act('Função atualizada.', async () => {
                                        await api(`websites/${endpointId(websiteId)}/users/${endpointId(object(member.user).id)}`, 'PUT', { role: event.target.value });
                                        await loadActive();
                                    })}
                                    aria-label={`Função de ${text(object(member.user).name)}`}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                >
                                    {['owner', 'admin', 'editor', 'qa', 'viewer'].map((role) => <option key={role} value={role}>{role}</option>)}
                                </select>
                                <button onClick={() => void deleteRecord('team', { ...member, id: object(member.user).id })} className="rounded-lg p-2 text-rose-600 hover:bg-rose-50" title="Remover membro"><Trash2 size={16} /></button>
                            </div>
                        </article>
                    ))}
                </div>
            ) : active === 'layouts' ? (
                <ResourceList rows={rows} label="layout" emptyTitle="Nenhum layout criado" emptyDetail="Layouts definem as regiões e a estrutura das páginas." onEdit={openLayoutForm} onDelete={(item) => void deleteRecord('layouts', item)} showActions={rowActions} />
            ) : active === 'websites' ? (
                <ResourceList rows={websites} label="website" emptyTitle="Nenhum website disponível" emptyDetail="Crie um website para iniciar o gerenciamento de conteúdo." onEdit={openWebsiteForm} onDelete={(item) => void deleteRecord('websites', item)} showActions />
            ) : (
                <ResourceList rows={rows} label="item" emptyTitle="Nenhum dado encontrado" emptyDetail="Não há itens para exibir nesta seção." showActions={false} />
            )}

            {busy && <div className="fixed bottom-5 right-5 z-30 flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-3 text-sm text-white shadow-xl"><LoaderCircle size={16} className="animate-spin" /> Processando...</div>}
            {dialog && <FormDialog spec={dialog} onClose={() => setDialog(null)} onSuccess={refreshAfterSave} />}
        </AppLayout>
    );
}

function ActionButton({
    children,
    onClick,
    icon,
    danger = false,
}: {
    children: React.ReactNode;
    onClick: () => void;
    icon?: React.ReactNode;
    danger?: boolean;
}) {
    return (
        <button onClick={onClick} className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition ${
            danger ? 'border-rose-200 text-rose-700 hover:bg-rose-50' : 'border-slate-200 text-slate-600 hover:border-teal-200 hover:bg-teal-50 hover:text-teal-800'
        }`}>
            {icon}{children}
        </button>
    );
}

function EmptyState({
    title,
    detail,
    action,
    onAction,
}: {
    title: string;
    detail: string;
    action?: string;
    onAction?: () => void;
}) {
    return (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <ImagePlus className="mx-auto text-slate-400" size={24} />
            <h2 className="mt-3 font-semibold">{title}</h2>
            <p className="mt-1 text-sm text-slate-500">{detail}</p>
            {action && onAction && <button onClick={onAction} className="mt-4 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">{action}</button>}
        </div>
    );
}

function ResourceList({
    rows,
    label,
    emptyTitle,
    emptyDetail,
    onEdit,
    onDelete,
    onDownload,
    showActions,
}: {
    rows: ApiRecord[];
    label: string;
    emptyTitle: string;
    emptyDetail: string;
    onEdit?: (item: ApiRecord) => void;
    onDelete?: (item: ApiRecord) => void;
    onDownload?: (item: ApiRecord) => void;
    showActions: boolean;
}) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} detail={emptyDetail} />;
    }

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            {rows.map((item) => (
                <article key={text(item.id)} className="flex min-w-0 flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="truncate font-semibold">{displayName(item)}</h2>
                            {item.status !== undefined ? <StatusBadge value={item.status} /> : null}
                            {item.role !== undefined ? <StatusBadge value={item.role} /> : null}
                        </div>
                        <p className="mt-1 truncate text-sm text-slate-500">
                            {text(item.host || item.domain || item.slug || item.filename || item.mime_type || item.email || item.path || item.description, `Criado em ${date(item.created_at)}`)}
                        </p>
                        {item.schema !== undefined && <p className="mt-1 text-xs text-slate-400">Regiões: {text(records(object(item.schema).regions).map((entry) => text(entry)).join(', '), 'não definidas')}</p>}
                    </div>
                    {showActions && (
                        <div className="flex items-center gap-1">
                            {onDownload && <button onClick={() => onDownload(item)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title={`Baixar ${label}`}><ArrowDownToLine size={16} /></button>}
                            {onEdit && <button onClick={() => onEdit(item)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title={`Editar ${label}`}><Pencil size={16} /></button>}
                            {onDelete && <button onClick={() => onDelete(item)} className="rounded-lg p-2 text-rose-600 hover:bg-rose-50" title={`Excluir ${label}`}><Trash2 size={16} /></button>}
                        </div>
                    )}
                </article>
            ))}
        </div>
    );
}

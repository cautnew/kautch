import axios, { type AxiosError, type Method } from 'axios';

export type ApiRecord = Record<string, unknown>;

type ApiEnvelope<T> = {
    success: boolean;
    message?: string;
    data?: T;
    errors?: Record<string, string[]>;
};

axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

let csrfReady = false;

export async function api<T>(
    path: string,
    method: Method = 'GET',
    data?: unknown,
): Promise<T> {
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase()) && !csrfReady) {
        await axios.get('/sanctum/csrf-cookie');
        csrfReady = true;
    }

    try {
        const response = await axios.request<ApiEnvelope<T>>({
            baseURL: '/api/v1',
            url: path,
            method,
            data,
        });

        return response.data.data as T;
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
            window.location.assign('/login');
        }

        throw error;
    }
}

export function apiError(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const response = error as AxiosError<ApiEnvelope<unknown>>;
        const body = response.response?.data;

        if (body?.message) {
            return body.message;
        }

        const validationMessage = Object.values(body?.errors ?? {}).flat()[0];

        if (validationMessage) {
            return validationMessage;
        }

        return response.message || 'Não foi possível concluir a operação.';
    }

    return error instanceof Error ? error.message : 'Ocorreu um erro inesperado.';
}

export function records(value: unknown): ApiRecord[] {
    if (Array.isArray(value)) {
        return value as ApiRecord[];
    }

    if (value !== null && typeof value === 'object') {
        const data = (value as ApiRecord).data;

        if (Array.isArray(data)) {
            return data as ApiRecord[];
        }
    }

    return [];
}

export function parseJson(value: unknown): string {
    return JSON.stringify(value ?? {}, null, 2);
}

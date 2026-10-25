import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    Alert,
    Button,
    Card,
    Form,
    Input,
    InputNumber,
    message,
    Modal,
    Pagination,
    Popconfirm,
    Select,
    Skeleton,
    Space,
    Spin,
    Tag,
    Typography,
} from 'antd';
import { useCallback, useEffect, useRef, useState } from 'react';

import AppShell from '@/components/app-shell';
import type { components } from '@/generated/api';
import { apiClient, ensureCsrfCookie } from '@/lib/api';
import { login } from '@/routes';

type Deal = components['schemas']['Deal'];
type DealStatus = Deal['status'];
type PaginationMeta = components['schemas']['PaginationMeta'];

type DealUser = {
    id: number;
    first_name: string;
    last_name: string;
};

type DealFormValues = {
    external_id: string | null;
    title: string;
    status: DealStatus;
    amount: number;
    assigned_to_user_id: number | null;
};

type DealFilters = {
    search: string;
    status: DealStatus | undefined;
    page: number;
};

type DealRefreshStatusPayload = {
    deal_id: number;
    success: boolean;
    message: string;
    status: Exclude<Deal['external_refresh_status'], null>;
    deal: Partial<Deal> | null;
};

type PageProps = {
    auth: {
        user: {
            id: number;
            first_name: string;
            last_name: string;
            email: string;
        };
    };
    users: DealUser[];
};

const pageSize = 15;
const refreshPollingInterval = 2_000;
const refreshPollingTimeout = 60_000;

const refreshStatusOptions: Record<
    Exclude<Deal['external_refresh_status'], null>,
    { label: string; color: string }
> = {
    queued: { label: 'Обновление принято', color: 'processing' },
    processing: { label: 'Обновление выполняется', color: 'processing' },
    succeeded: { label: 'Успешно обновлено', color: 'success' },
    failed: { label: 'Ошибка обновления', color: 'error' },
};

const statusOptions: Array<{
    value: DealStatus;
    label: string;
    color: string;
}> = [
    { value: 'NEW', label: 'Новая', color: 'blue' },
    { value: 'IN_PROGRESS', label: 'В работе', color: 'gold' },
    { value: 'WON', label: 'Выиграна', color: 'green' },
    { value: 'LOST', label: 'Проиграна', color: 'default' },
];

function parseFilters(search: string): DealFilters {
    const params = new URLSearchParams(search);
    const rawStatus = params.get('status');
    const rawPage = Number(params.get('page'));
    const status = statusOptions.find(
        (option) => option.value === rawStatus,
    )?.value;

    return {
        search: params.get('search')?.trim() ?? '',
        status,
        page: Number.isInteger(rawPage) && rawPage > 0 ? rawPage : 1,
    };
}

function formatAmount(amount: string): string {
    return new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function formatDate(value: string | null): string | null {
    if (value === null) {
        return null;
    }

    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function getUserName(users: DealUser[], userId: number | null): string | null {
    const user = users.find((candidate) => candidate.id === userId);

    return user ? `${user.first_name} ${user.last_name}` : null;
}

function isRefreshInProgress(status: Deal['external_refresh_status']): boolean {
    return status === 'queued' || status === 'processing';
}

function DealCard({
    deal,
    assigneeName,
    onEdit,
    onDelete,
    onRefresh,
    deleting,
    refreshing,
}: {
    deal: Deal;
    assigneeName: string | null;
    onEdit: (deal: Deal) => void;
    onDelete: (deal: Deal) => void;
    onRefresh: (deal: Deal) => void;
    deleting: boolean;
    refreshing: boolean;
}) {
    const status = statusOptions.find((option) => option.value === deal.status);
    const refreshStatus = deal.external_refresh_status
        ? refreshStatusOptions[deal.external_refresh_status]
        : null;
    const updatedAt = formatDate(deal.updated_at);

    return (
        <Card className="deal-card" size="small">
            <div className="deal-card-heading">
                <Typography.Text strong className="deal-title">
                    {deal.title}
                </Typography.Text>
                {status && <Tag color={status.color}>{status.label}</Tag>}
            </div>

            <Typography.Title level={4} className="deal-amount">
                {formatAmount(deal.amount)}
            </Typography.Title>

            <div className="deal-details">
                {deal.external_id && (
                    <Typography.Text type="secondary">
                        Внешний ID: {deal.external_id}
                    </Typography.Text>
                )}
                {updatedAt && (
                    <Typography.Text type="secondary">
                        Обновлена: {updatedAt}
                    </Typography.Text>
                )}
                {deal.external_updated_at && (
                    <Typography.Text type="secondary">
                        Данные CRM: {formatDate(deal.external_updated_at)}
                    </Typography.Text>
                )}
                <Typography.Text type="secondary">
                    Ответственный: {assigneeName ?? 'Не назначен'}
                </Typography.Text>
                {refreshStatus && (
                    <Tag color={refreshStatus.color}>{refreshStatus.label}</Tag>
                )}
                {deal.external_refresh_error && (
                    <Typography.Text type="danger">
                        {deal.external_refresh_error}
                    </Typography.Text>
                )}
            </div>

            <Space className="deal-card-actions" size={8}>
                <Button
                    size="small"
                    onClick={() => onEdit(deal)}
                    disabled={deleting}
                >
                    Изменить
                </Button>
                <Button
                    size="small"
                    loading={refreshing}
                    disabled={deleting || !deal.external_id}
                    onClick={() => onRefresh(deal)}
                >
                    Обновить CRM
                </Button>
                <Popconfirm
                    title="Удалить сделку?"
                    description="Это действие нельзя отменить."
                    okText="Удалить"
                    cancelText="Отмена"
                    okButtonProps={{ danger: true, loading: deleting }}
                    onConfirm={() => onDelete(deal)}
                >
                    <Button size="small" danger disabled={deleting}>
                        Удалить
                    </Button>
                </Popconfirm>
            </Space>
        </Card>
    );
}

function LoadingCards() {
    return (
        <div className="deals-grid" aria-label="Загрузка сделок">
            {Array.from({ length: 3 }, (_, index) => (
                <Card className="deal-card" key={index} size="small">
                    <Skeleton
                        active
                        title={{ width: '55%' }}
                        paragraph={{ rows: 2 }}
                    />
                </Card>
            ))}
        </div>
    );
}

function DealsList({
    currentUserId,
    users,
}: {
    currentUserId: number;
    users: DealUser[];
}) {
    const [form] = Form.useForm<DealFormValues>();
    const [messageApi, messageContextHolder] = message.useMessage();
    const [filters, setFilters] = useState<DealFilters>(() =>
        parseFilters(
            typeof window === 'undefined' ? '' : window.location.search,
        ),
    );
    const [searchInput, setSearchInput] = useState(filters.search);
    const [deals, setDeals] = useState<Deal[] | null>(null);
    const [meta, setMeta] = useState<PaginationMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [requestError, setRequestError] = useState<string | null>(null);
    const [mutationError, setMutationError] = useState<string | null>(null);
    const [retryKey, setRetryKey] = useState(0);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingDeal, setEditingDeal] = useState<Deal | null>(null);
    const [saving, setSaving] = useState(false);
    const [deletingDealId, setDeletingDealId] = useState<number | null>(null);
    const [refreshingDealIds, setRefreshingDealIds] = useState<Set<number>>(
        () => new Set(),
    );
    const refreshPollingRef = useRef<
        Map<
            number,
            { intervalId: number; startedAt: number; inFlight: boolean }
        >
    >(new Map());
    const refreshRealtimeUpdatedAtRef = useRef<Map<number, number>>(new Map());
    const notifiedRefreshDealIdsRef = useRef<Set<number>>(new Set());
    const refreshRequestsPendingRef = useRef<Set<number>>(new Set());

    const stopRefreshPolling = useCallback((dealId: number): void => {
        const polling = refreshPollingRef.current.get(dealId);

        if (polling) {
            window.clearInterval(polling.intervalId);
            refreshPollingRef.current.delete(dealId);
        }

        setRefreshingDealIds((current) => {
            if (!current.has(dealId)) {
                return current;
            }

            const next = new Set(current);
            next.delete(dealId);

            return next;
        });
    }, []);

    const updateDealFromRefresh = useCallback(
        (
            refreshedDeal: Partial<Deal> & Pick<Deal, 'id'>,
            requestedAt?: number,
        ): void => {
            const realtimeUpdatedAt =
                refreshRealtimeUpdatedAtRef.current.get(refreshedDeal.id) ?? 0;

            if (requestedAt !== undefined && realtimeUpdatedAt > requestedAt) {
                return;
            }

            setDeals(
                (current) =>
                    current?.map((deal) =>
                        deal.id === refreshedDeal.id
                            ? { ...deal, ...refreshedDeal }
                            : deal,
                    ) ?? null,
            );
        },
        [],
    );

    const markRefreshStatusUnavailable = useCallback((dealId: number): void => {
        setDeals(
            (current) =>
                current?.map((deal) =>
                    deal.id === dealId
                        ? {
                              ...deal,
                              external_refresh_status: null,
                              external_refresh_error:
                                  'Не удалось проверить статус обновления. Задача может ещё выполняться.',
                          }
                        : deal,
                ) ?? null,
        );
    }, []);

    const notifyRefreshResult = useCallback(
        (dealId: number, success: boolean, text: string): void => {
            if (notifiedRefreshDealIdsRef.current.has(dealId)) {
                return;
            }

            notifiedRefreshDealIdsRef.current.add(dealId);

            if (success) {
                messageApi.success(text);

                return;
            }

            messageApi.error(text);
        },
        [messageApi],
    );

    const pollRefreshStatus = useCallback(
        async (dealId: number): Promise<void> => {
            const polling = refreshPollingRef.current.get(dealId);

            if (!polling || polling.inFlight) {
                return;
            }

            if (Date.now() - polling.startedAt >= refreshPollingTimeout) {
                stopRefreshPolling(dealId);
                markRefreshStatusUnavailable(dealId);
                messageApi.warning(
                    'Не удалось получить актуальное состояние. Обновите страницу или повторите запрос.',
                );

                return;
            }

            polling.inFlight = true;
            const requestedAt = Date.now();
            const requestController = new AbortController();
            const requestTimeoutId = window.setTimeout(
                () => requestController.abort(),
                refreshPollingTimeout - (requestedAt - polling.startedAt),
            );

            try {
                const { data, response } = await apiClient.GET(
                    '/deals/{deal}',
                    {
                        params: { path: { deal: dealId } },
                        signal: requestController.signal,
                    },
                );

                if (response.status === 401) {
                    stopRefreshPolling(dealId);
                    router.visit(login.url(), { replace: true });

                    return;
                }

                if (response.status === 404) {
                    stopRefreshPolling(dealId);
                    messageApi.error('Сделка больше не существует.');

                    return;
                }

                if (!response.ok || data === undefined) {
                    return;
                }

                const refreshedDeal = data.data;

                if (
                    (refreshRealtimeUpdatedAtRef.current.get(dealId) ?? 0) >
                    requestedAt
                ) {
                    return;
                }

                updateDealFromRefresh(refreshedDeal, requestedAt);

                if (
                    refreshedDeal.external_refresh_status === 'succeeded' ||
                    refreshedDeal.external_refresh_status === 'failed'
                ) {
                    stopRefreshPolling(dealId);
                    notifyRefreshResult(
                        dealId,
                        refreshedDeal.external_refresh_status === 'succeeded',
                        refreshedDeal.external_refresh_status === 'succeeded'
                            ? 'Сделка обновлена из внешней CRM.'
                            : (refreshedDeal.external_refresh_error ??
                                  'Не удалось обновить сделку из внешней CRM.'),
                    );
                }
            } catch {
                // Temporary API or network errors are retried by the next poll.
            } finally {
                window.clearTimeout(requestTimeoutId);

                if (refreshPollingRef.current.get(dealId) === polling) {
                    polling.inFlight = false;
                }
            }
        },
        [
            messageApi,
            markRefreshStatusUnavailable,
            notifyRefreshResult,
            stopRefreshPolling,
            updateDealFromRefresh,
        ],
    );

    const startRefreshPolling = useCallback(
        (dealId: number): void => {
            if (refreshPollingRef.current.has(dealId)) {
                return;
            }

            const polling = {
                intervalId: 0,
                startedAt: Date.now(),
                inFlight: false,
            };
            polling.intervalId = window.setInterval(() => {
                void pollRefreshStatus(dealId);
            }, refreshPollingInterval);
            refreshPollingRef.current.set(dealId, polling);
            setRefreshingDealIds((current) => new Set(current).add(dealId));
            void pollRefreshStatus(dealId);
        },
        [pollRefreshStatus],
    );

    useEffect(() => {
        deals?.forEach((deal) => {
            if (
                isRefreshInProgress(deal.external_refresh_status) &&
                !refreshRequestsPendingRef.current.has(deal.id)
            ) {
                startRefreshPolling(deal.id);
            }
        });
    }, [deals, startRefreshPolling]);

    useEffect(() => {
        const resumePollingWhenVisible = (): void => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            refreshPollingRef.current.forEach((_, dealId) => {
                void pollRefreshStatus(dealId);
            });
        };

        document.addEventListener('visibilitychange', resumePollingWhenVisible);

        return () => {
            document.removeEventListener(
                'visibilitychange',
                resumePollingWhenVisible,
            );
        };
    }, [pollRefreshStatus]);

    useEffect(
        () => () => {
            refreshPollingRef.current.forEach((polling) => {
                window.clearInterval(polling.intervalId);
            });
            refreshPollingRef.current.clear();
        },
        [],
    );

    useEcho<DealRefreshStatusPayload>(
        `App.Models.User.${currentUserId}`,
        '.deal.refresh.updated',
        (payload) => {
            refreshRealtimeUpdatedAtRef.current.set(
                payload.deal_id,
                Date.now(),
            );
            updateDealFromRefresh({
                ...payload.deal,
                id: payload.deal_id,
                external_refresh_status: payload.status,
            });

            if (payload.status === 'processing') {
                return;
            }

            stopRefreshPolling(payload.deal_id);
            notifyRefreshResult(
                payload.deal_id,
                payload.success,
                payload.message,
            );
        },
        [
            currentUserId,
            notifyRefreshResult,
            stopRefreshPolling,
            updateDealFromRefresh,
        ],
    );

    useEffect(() => {
        const timeout = window.setTimeout(() => {
            const search = searchInput.trim();

            setFilters((current) =>
                current.search === search
                    ? current
                    : { ...current, search, page: 1 },
            );
        }, 300);

        return () => window.clearTimeout(timeout);
    }, [searchInput]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);

        if (filters.search) {
            params.set('search', filters.search);
        } else {
            params.delete('search');
        }

        if (filters.status) {
            params.set('status', filters.status);
        } else {
            params.delete('status');
        }

        if (filters.page > 1) {
            params.set('page', String(filters.page));
        } else {
            params.delete('page');
        }

        const query = params.toString();
        const url = `${window.location.pathname}${query ? `?${query}` : ''}`;
        window.history.replaceState(window.history.state, '', url);
    }, [filters]);

    useEffect(() => {
        const handlePopState = () => {
            const nextFilters = parseFilters(window.location.search);
            setFilters(nextFilters);
            setSearchInput(nextFilters.search);
        };

        window.addEventListener('popstate', handlePopState);

        return () => window.removeEventListener('popstate', handlePopState);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setRequestError(null);

        void apiClient
            .GET('/deals', {
                params: {
                    query: {
                        search: filters.search || undefined,
                        status: filters.status,
                        page: filters.page,
                        per_page: pageSize,
                    },
                },
                signal: controller.signal,
            })
            .then(({ data, response }) => {
                if (controller.signal.aborted) {
                    return;
                }

                if (response.status === 401) {
                    router.visit(login.url(), { replace: true });

                    return;
                }

                if (!response.ok || data === undefined) {
                    setRequestError(
                        response.status >= 500
                            ? 'Не удалось загрузить сделки. Попробуйте ещё раз.'
                            : response.status === 429
                              ? 'Слишком много запросов. Подождите немного и повторите попытку.'
                              : 'Не удалось загрузить сделки. Проверьте фильтры и повторите попытку.',
                    );

                    return;
                }

                setDeals(data.data);
                setMeta(data.meta);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setRequestError(
                        'Нет связи с сервером. Проверьте подключение и повторите попытку.',
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [filters, retryKey]);

    function applySearch(): void {
        const search = searchInput.trim();
        setFilters((current) =>
            current.search === search
                ? current
                : { ...current, search, page: 1 },
        );
    }

    function resetFilters(): void {
        setSearchInput('');
        setFilters({ search: '', status: undefined, page: 1 });
    }

    function setDealRefreshState(
        dealId: number,
        status: Deal['external_refresh_status'],
        error: string | null = null,
    ): void {
        setDeals(
            (current) =>
                current?.map((deal) =>
                    deal.id === dealId
                        ? {
                              ...deal,
                              external_refresh_status: status,
                              external_refresh_error: error,
                          }
                        : deal,
                ) ?? null,
        );
    }

    async function refreshFromExternalCrm(deal: Deal): Promise<void> {
        if (!deal.external_id) {
            messageApi.error('Сделка не связана с внешней CRM.');

            return;
        }

        setDealRefreshState(deal.id, 'queued');
        setRefreshingDealIds((current) => new Set(current).add(deal.id));
        refreshRequestsPendingRef.current.add(deal.id);
        notifiedRefreshDealIdsRef.current.delete(deal.id);
        let queued = false;

        try {
            await ensureCsrfCookie();

            const { error, response } = await apiClient.POST(
                '/deals/{deal}/refresh',
                { params: { path: { deal: deal.id } } },
            );

            if (response.status === 401) {
                router.visit(login.url(), { replace: true });

                return;
            }

            if (response.status === 202) {
                queued = true;
                refreshRequestsPendingRef.current.delete(deal.id);
                startRefreshPolling(deal.id);
                messageApi.info('Обновление сделки запущено.');

                return;
            }

            setDealRefreshState(
                deal.id,
                'failed',
                error?.message ??
                    (response.status === 419
                        ? 'Защита сессии обновилась. Повторите запрос.'
                        : 'Не удалось запустить обновление сделки.'),
            );
            messageApi.error(
                error?.message ??
                    (response.status === 419
                        ? 'Защита сессии обновилась. Повторите запрос.'
                        : 'Не удалось запустить обновление сделки.'),
            );
        } catch {
            setDealRefreshState(
                deal.id,
                'failed',
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
            messageApi.error(
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
        } finally {
            if (!queued) {
                refreshRequestsPendingRef.current.delete(deal.id);
                stopRefreshPolling(deal.id);
            }
        }
    }

    function openDealDialog(deal?: Deal): void {
        setEditingDeal(deal ?? null);
        setMutationError(null);
        form.resetFields();
        form.setFieldsValue({
            external_id: deal?.external_id ?? null,
            title: deal?.title ?? '',
            status: deal?.status ?? 'NEW',
            amount: deal ? Number(deal.amount) : 0,
            assigned_to_user_id: deal
                ? deal.assigned_to_user_id
                : currentUserId,
        });
        setDialogOpen(true);
    }

    function closeDealDialog(): void {
        setDialogOpen(false);
        setEditingDeal(null);
        setMutationError(null);
        form.resetFields();
    }

    async function saveDeal(values: DealFormValues): Promise<void> {
        setSaving(true);
        setMutationError(null);

        try {
            await ensureCsrfCookie();

            const body = {
                ...values,
                external_id: values.external_id?.trim() || null,
                title: values.title.trim(),
                assigned_to_user_id: values.assigned_to_user_id ?? null,
            };
            const { error, response } = editingDeal
                ? await apiClient.PATCH('/deals/{deal}', {
                      params: { path: { deal: editingDeal.id } },
                      body,
                  })
                : await apiClient.POST('/deals', { body });

            if (response.status === 401) {
                router.visit(login.url(), { replace: true });

                return;
            }

            if (response.status === 422) {
                const validationErrors = error?.errors;
                const fields = (
                    [
                        'external_id',
                        'title',
                        'status',
                        'amount',
                        'assigned_to_user_id',
                    ] as const
                ).flatMap((name) =>
                    validationErrors?.[name]
                        ? [{ name, errors: validationErrors[name] }]
                        : [],
                );

                if (fields.length > 0) {
                    form.setFields(fields);
                }

                setMutationError(
                    error?.message ??
                        'Проверьте заполненные поля и повторите попытку.',
                );

                return;
            }

            if (!response.ok) {
                setMutationError(
                    response.status === 419
                        ? 'Защита сессии обновилась. Повторите отправку формы.'
                        : response.status === 429
                          ? 'Слишком много запросов. Подождите немного и попробуйте снова.'
                          : 'Не удалось сохранить сделку. Попробуйте ещё раз.',
                );

                return;
            }

            messageApi.success(
                editingDeal ? 'Сделка обновлена.' : 'Сделка создана.',
            );
            closeDealDialog();
            setRetryKey((value) => value + 1);
        } catch {
            setMutationError(
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
        } finally {
            setSaving(false);
        }
    }

    async function deleteDeal(deal: Deal): Promise<void> {
        setDeletingDealId(deal.id);
        setMutationError(null);

        try {
            await ensureCsrfCookie();
            const { response } = await apiClient.DELETE('/deals/{deal}', {
                params: { path: { deal: deal.id } },
            });

            if (response.status === 401) {
                router.visit(login.url(), { replace: true });

                return;
            }

            if (response.status !== 204) {
                setMutationError(
                    response.status === 419
                        ? 'Защита сессии обновилась. Повторите удаление.'
                        : response.status === 429
                          ? 'Слишком много запросов. Подождите немного и попробуйте снова.'
                          : 'Не удалось удалить сделку. Попробуйте ещё раз.',
                );

                return;
            }

            messageApi.success('Сделка удалена.');

            if (deals?.length === 1 && filters.page > 1) {
                setFilters((current) => ({
                    ...current,
                    page: current.page - 1,
                }));
            } else {
                setRetryKey((value) => value + 1);
            }
        } catch {
            setMutationError(
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
        } finally {
            setDeletingDealId(null);
        }
    }

    const hasActiveFilters =
        filters.search !== '' || filters.status !== undefined;

    return (
        <section className="deals-page">
            {messageContextHolder}
            <div className="deals-page-heading">
                <div>
                    <Typography.Title level={2}>Сделки</Typography.Title>
                    <Typography.Paragraph type="secondary">
                        {meta ? `Всего сделок: ${meta.total}` : 'Список сделок'}
                    </Typography.Paragraph>
                </div>
                <Button type="primary" onClick={() => openDealDialog()}>
                    Добавить сделку
                </Button>
            </div>

            <div className="deals-toolbar">
                <Input
                    allowClear
                    aria-label="Поиск по сделкам"
                    className="deal-search"
                    placeholder="Название или ID сделки"
                    value={searchInput}
                    onChange={(event) => setSearchInput(event.target.value)}
                    onPressEnter={applySearch}
                />
                <Select
                    aria-label="Фильтр по статусу"
                    className="deal-status-filter"
                    allowClear
                    placeholder="Все статусы"
                    value={filters.status}
                    options={statusOptions.map(({ value, label }) => ({
                        value,
                        label,
                    }))}
                    onChange={(status?: DealStatus) =>
                        setFilters((current) => ({
                            ...current,
                            status,
                            page: 1,
                        }))
                    }
                />
                {hasActiveFilters && (
                    <Button onClick={resetFilters}>Сбросить фильтры</Button>
                )}
                {loading && deals !== null && (
                    <Space
                        size={8}
                        className="deals-refreshing"
                        aria-live="polite"
                    >
                        <Spin size="small" />
                        <Typography.Text type="secondary">
                            Обновление
                        </Typography.Text>
                    </Space>
                )}
            </div>
            {requestError && (
                <Alert
                    className="crm-alert"
                    type="error"
                    showIcon
                    message={requestError}
                    action={
                        <Button
                            size="small"
                            onClick={() => setRetryKey((value) => value + 1)}
                        >
                            Повторить
                        </Button>
                    }
                />
            )}

            {mutationError && (
                <Alert
                    className="crm-alert"
                    type="error"
                    showIcon
                    closable
                    message={mutationError}
                    onClose={() => setMutationError(null)}
                />
            )}

            {loading && deals === null ? (
                <LoadingCards />
            ) : deals?.length ? (
                <div className="deals-grid">
                    {deals.map((deal) => (
                        <DealCard
                            key={deal.id}
                            deal={deal}
                            assigneeName={getUserName(
                                users,
                                deal.assigned_to_user_id,
                            )}
                            onEdit={openDealDialog}
                            onDelete={(selectedDeal) => {
                                void deleteDeal(selectedDeal);
                            }}
                            onRefresh={(selectedDeal) => {
                                void refreshFromExternalCrm(selectedDeal);
                            }}
                            deleting={deletingDealId !== null}
                            refreshing={
                                refreshingDealIds.has(deal.id) ||
                                isRefreshInProgress(
                                    deal.external_refresh_status,
                                )
                            }
                        />
                    ))}
                </div>
            ) : (
                !requestError && (
                    <Card className="deals-empty">
                        <Typography.Title level={4}>
                            {hasActiveFilters
                                ? 'По заданным условиям сделки не найдены'
                                : 'Сделок пока нет'}
                        </Typography.Title>
                        {hasActiveFilters && (
                            <Space>
                                <Button onClick={resetFilters}>
                                    Сбросить фильтры
                                </Button>
                                <Button
                                    type="primary"
                                    onClick={() => openDealDialog()}
                                >
                                    Добавить сделку
                                </Button>
                            </Space>
                        )}
                    </Card>
                )
            )}

            {meta && meta.total > 0 && (
                <div className="deals-pagination">
                    <Pagination
                        current={meta.current_page}
                        pageSize={meta.per_page}
                        total={meta.total}
                        showSizeChanger={false}
                        onChange={(page) =>
                            setFilters((current) => ({ ...current, page }))
                        }
                    />
                </div>
            )}

            <Modal
                title={editingDeal ? 'Редактировать сделку' : 'Новая сделка'}
                open={dialogOpen}
                onCancel={closeDealDialog}
                footer={null}
                destroyOnHidden
            >
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={(values) => {
                        void saveDeal(values);
                    }}
                >
                    <Form.Item
                        label="ID во внешней CRM"
                        name="external_id"
                        help="Укажите ID сделки во внешней CRM, чтобы включить обновление из CRM."
                        rules={[
                            {
                                max: 255,
                                message:
                                    'ID не должен быть длиннее 255 символов.',
                            },
                        ]}
                    >
                        <Input maxLength={255} />
                    </Form.Item>
                    <Form.Item
                        label="Название"
                        name="title"
                        rules={[
                            {
                                required: true,
                                message: 'Введите название сделки.',
                            },
                            {
                                max: 255,
                                message:
                                    'Название не должно быть длиннее 255 символов.',
                            },
                        ]}
                    >
                        <Input maxLength={255} autoFocus />
                    </Form.Item>
                    <Form.Item
                        label="Статус"
                        name="status"
                        rules={[
                            {
                                required: true,
                                message: 'Выберите статус сделки.',
                            },
                        ]}
                    >
                        <Select
                            options={statusOptions.map(({ value, label }) => ({
                                value,
                                label,
                            }))}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Сумма"
                        name="amount"
                        rules={[
                            {
                                required: true,
                                message: 'Укажите сумму сделки.',
                            },
                            {
                                type: 'number',
                                min: 0,
                                message: 'Сумма должна быть неотрицательной.',
                            },
                        ]}
                    >
                        <InputNumber
                            min={0}
                            precision={2}
                            step={0.01}
                            style={{ width: '100%' }}
                        />
                    </Form.Item>
                    <Form.Item label="Ответственный" name="assigned_to_user_id">
                        <Select
                            allowClear
                            placeholder="Не назначен"
                            options={users.map((user) => ({
                                value: user.id,
                                label: `${user.first_name} ${user.last_name}`,
                            }))}
                        />
                    </Form.Item>
                    <div className="deal-form-actions">
                        <Button onClick={closeDealDialog}>Отмена</Button>
                        <Button
                            type="primary"
                            htmlType="submit"
                            loading={saving}
                        >
                            {editingDeal ? 'Сохранить' : 'Создать'}
                        </Button>
                    </div>
                </Form>
            </Modal>
        </section>
    );
}

export default function DealsIndex({ auth, users }: PageProps) {
    return (
        <AppShell user={auth.user} activePage="deals">
            <DealsList currentUserId={auth.user.id} users={users} />
        </AppShell>
    );
}

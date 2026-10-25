import {
    Alert,
    Button,
    Card,
    Descriptions,
    Input,
    Modal,
    Pagination,
    Select,
    Space,
    Table,
    Tag,
    Typography,
} from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useEffect, useState } from 'react';

import AppShell from '@/components/app-shell';
import type { components } from '@/generated/api';
import { apiClient } from '@/lib/api';
import { login } from '@/routes';
import { router } from '@inertiajs/react';

type EventRecord = components['schemas']['Event'];
type PaginationMeta = components['schemas']['PaginationMeta'];
type EventStatus = 'CREATED' | 'PROCESSING' | 'SUCCESS' | 'SKIPPED' | 'FAILED';

type EventFilters = {
    search: string;
    eventType: string | undefined;
    status: EventStatus | undefined;
    page: number;
    perPage: number;
};

type PageProps = {
    auth: {
        user: {
            first_name: string;
            last_name: string;
            email: string;
        };
    };
    eventTypes: string[];
};

const eventStatuses = [
    { value: 'CREATED', label: 'Получено', color: 'default' },
    { value: 'PROCESSING', label: 'В обработке', color: 'processing' },
    { value: 'SUCCESS', label: 'Применено', color: 'success' },
    { value: 'SKIPPED', label: 'Версия не новее, пропущено', color: 'warning' },
    { value: 'FAILED', label: 'Ошибка', color: 'error' },
] as const;

function parseFilters(search: string): EventFilters {
    const params = new URLSearchParams(search);
    const page = Number(params.get('page'));
    const perPage = Number(params.get('per_page'));
    const rawStatus = params.get('status');

    return {
        search: params.get('search')?.trim() ?? '',
        eventType: params.get('event_type') || undefined,
        status: eventStatuses.find((option) => option.value === rawStatus)
            ?.value,
        page: Number.isInteger(page) && page > 0 ? page : 1,
        perPage: [15, 30, 50].includes(perPage) ? perPage : 15,
    };
}

function eventTypeLabel(eventType: string | null): string {
    if (eventType === 'webhook.deal.create') {
        return 'Webhook: сделка';
    }

    return eventType ?? 'Неизвестный тип';
}

function eventStatus(status: string | null) {
    return (
        eventStatuses.find((option) => option.value === status) ?? {
            label: status ?? 'Неизвестно',
            color: 'default',
        }
    );
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function EventsList({ eventTypes }: { eventTypes: string[] }) {
    const [filters, setFilters] = useState<EventFilters>(() =>
        parseFilters(
            typeof window === 'undefined' ? '' : window.location.search,
        ),
    );
    const [searchInput, setSearchInput] = useState(filters.search);
    const [events, setEvents] = useState<EventRecord[]>([]);
    const [meta, setMeta] = useState<PaginationMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [selectedEvent, setSelectedEvent] = useState<EventRecord | null>(
        null,
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
        const params = new URLSearchParams();

        if (filters.search) {
            params.set('search', filters.search);
        }
        if (filters.eventType) {
            params.set('event_type', filters.eventType);
        }
        if (filters.status) {
            params.set('status', filters.status);
        }
        if (filters.page > 1) {
            params.set('page', String(filters.page));
        }
        if (filters.perPage !== 15) {
            params.set('per_page', String(filters.perPage));
        }

        const query = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${query ? `?${query}` : ''}`,
        );
    }, [filters]);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        void apiClient
            .GET('/events', {
                params: {
                    query: {
                        search: filters.search || undefined,
                        event_type: filters.eventType,
                        status: filters.status,
                        page: filters.page,
                        per_page: filters.perPage,
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
                    setError(
                        'Не удалось загрузить журнал событий. Попробуйте ещё раз.',
                    );

                    return;
                }

                setEvents(data.data);
                setMeta(data.meta);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setError(
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
    }, [filters]);

    const columns: ColumnsType<EventRecord> = [
        {
            title: 'Время',
            dataIndex: 'event_time',
            key: 'event_time',
            render: (value: string | null) => formatDate(value),
        },
        {
            title: 'Тип события',
            dataIndex: 'event_type',
            key: 'event_type',
            render: (value: string | null) => eventTypeLabel(value),
        },
        {
            title: 'ID события',
            dataIndex: 'event_id',
            key: 'event_id',
            render: (value: string | null) => value ?? '—',
        },
        {
            title: 'Сделка',
            key: 'deal',
            render: (_, record) =>
                record.deal ? (
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>
                            {record.deal.title ?? 'Без названия'}
                        </Typography.Text>
                        <Typography.Text type="secondary">
                            ID: {record.deal.id ?? '—'}
                            {record.deal.version !== null &&
                                record.deal.version !== undefined &&
                                ` · версия ${record.deal.version}`}
                        </Typography.Text>
                    </Space>
                ) : (
                    '—'
                ),
        },
        {
            title: 'Статус',
            dataIndex: 'status',
            key: 'status',
            render: (status: string | null) => {
                const option = eventStatus(status);

                return <Tag color={option.color}>{option.label}</Tag>;
            },
        },
        {
            title: '',
            key: 'details',
            width: 130,
            render: (_, record) => (
                <Button size="small" onClick={() => setSelectedEvent(record)}>
                    Подробнее
                </Button>
            ),
        },
    ];

    const availableEventTypes = Array.from(
        new Set([...eventTypes, 'webhook.deal.create']),
    );

    return (
        <section className="deals-page">
            <div className="deals-page-heading">
                <div>
                    <Typography.Title level={2}>События</Typography.Title>
                    <Typography.Paragraph type="secondary">
                        {meta
                            ? `Всего событий: ${meta.total}`
                            : 'Журнал обработки внешних событий'}
                    </Typography.Paragraph>
                </div>
            </div>

            <Card className="crm-events-card">
                <Space className="deals-toolbar" wrap>
                    <Input
                        allowClear
                        aria-label="Поиск событий"
                        placeholder="ID события или сделки"
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                    />
                    <Select
                        aria-label="Фильтр по типу события"
                        allowClear
                        placeholder="Все типы событий"
                        value={filters.eventType}
                        options={availableEventTypes.map((eventType) => ({
                            value: eventType,
                            label: eventTypeLabel(eventType),
                        }))}
                        onChange={(eventType?: string) =>
                            setFilters((current) => ({
                                ...current,
                                eventType,
                                page: 1,
                            }))
                        }
                    />
                    <Select
                        aria-label="Фильтр по статусу события"
                        allowClear
                        placeholder="Все статусы"
                        value={filters.status}
                        options={eventStatuses.map(({ value, label }) => ({
                            value,
                            label,
                        }))}
                        onChange={(status?: EventStatus) =>
                            setFilters((current) => ({
                                ...current,
                                status,
                                page: 1,
                            }))
                        }
                    />
                    <Select
                        aria-label="Число событий на странице"
                        value={filters.perPage}
                        options={[15, 30, 50].map((value) => ({
                            value,
                            label: `${value} на странице`,
                        }))}
                        onChange={(perPage: number) =>
                            setFilters((current) => ({
                                ...current,
                                perPage,
                                page: 1,
                            }))
                        }
                    />
                    {(filters.search ||
                        filters.eventType ||
                        filters.status) && (
                        <Button
                            onClick={() => {
                                setSearchInput('');
                                setFilters((current) => ({
                                    ...current,
                                    search: '',
                                    eventType: undefined,
                                    status: undefined,
                                    page: 1,
                                }));
                            }}
                        >
                            Сбросить фильтры
                        </Button>
                    )}
                </Space>

                {error && (
                    <Alert
                        className="crm-alert"
                        type="error"
                        showIcon
                        message={error}
                        action={
                            <Button
                                size="small"
                                onClick={() =>
                                    setFilters((current) => ({ ...current }))
                                }
                            >
                                Повторить
                            </Button>
                        }
                    />
                )}

                <Table<EventRecord>
                    rowKey="id"
                    columns={columns}
                    dataSource={events}
                    loading={loading}
                    pagination={false}
                    locale={{ emptyText: 'События не найдены' }}
                    scroll={{ x: 850 }}
                />

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
            </Card>

            <Modal
                title="Сведения о событии"
                open={selectedEvent !== null}
                onCancel={() => setSelectedEvent(null)}
                footer={null}
                destroyOnHidden
            >
                {selectedEvent && (
                    <Space
                        direction="vertical"
                        size="middle"
                        className="w-full"
                    >
                        <Descriptions bordered column={1} size="small">
                            <Descriptions.Item label="ID события">
                                {selectedEvent.event_id ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Тип">
                                {eventTypeLabel(selectedEvent.event_type)}
                            </Descriptions.Item>
                            <Descriptions.Item label="Время">
                                {formatDate(selectedEvent.event_time)}
                            </Descriptions.Item>
                            <Descriptions.Item label="Статус">
                                {eventStatus(selectedEvent.status).label}
                            </Descriptions.Item>
                            {selectedEvent.deal && (
                                <>
                                    <Descriptions.Item label="Сделка">
                                        {selectedEvent.deal.title ??
                                            'Без названия'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Внешний ID">
                                        {selectedEvent.deal.id ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Версия источника">
                                        {selectedEvent.deal.version ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Статус сделки">
                                        {selectedEvent.deal.status ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Сумма">
                                        {selectedEvent.deal.amount ?? '—'}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Изменено во внешней CRM">
                                        {formatDate(
                                            selectedEvent.deal.updated_at,
                                        )}
                                    </Descriptions.Item>
                                </>
                            )}
                        </Descriptions>
                        {selectedEvent.error && (
                            <Alert
                                type="error"
                                showIcon
                                message="Ошибка обработки"
                                description={selectedEvent.error}
                            />
                        )}
                    </Space>
                )}
            </Modal>
        </section>
    );
}

export default function EventsIndex({ auth, eventTypes }: PageProps) {
    return (
        <AppShell user={auth.user} activePage="events">
            <EventsList eventTypes={eventTypes} />
        </AppShell>
    );
}

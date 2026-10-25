import { Link, router } from '@inertiajs/react';
import {
    Alert,
    Button,
    ConfigProvider,
    Dropdown,
    Layout,
    Menu,
    Typography,
} from 'antd';
import type { ReactNode } from 'react';
import { useState } from 'react';

import { apiClient, ensureCsrfCookie } from '@/lib/api';
import { crmTheme } from '@/lib/theme';
import { login } from '@/routes';
import { index as dealsIndex } from '@/routes/crm/deals';
import { index as eventsIndex } from '@/routes/crm/events';

type AppShellProps = {
    children: ReactNode;
    activePage: 'deals' | 'events';
    user: {
        first_name: string;
        last_name: string;
        email: string;
    };
};

export default function AppShell({
    children,
    user,
    activePage,
}: AppShellProps) {
    const [collapsed, setCollapsed] = useState(false);
    const [loggingOut, setLoggingOut] = useState(false);
    const [logoutError, setLogoutError] = useState<string | null>(null);

    async function logout(): Promise<void> {
        setLoggingOut(true);
        setLogoutError(null);

        try {
            await ensureCsrfCookie();
            const { response } = await apiClient.POST('/auth/logout');

            if (response.status === 204 || response.status === 401) {
                router.visit(login.url(), { replace: true });

                return;
            }

            setLogoutError(
                response.status === 419
                    ? 'Защита сессии обновилась. Повторите выход.'
                    : 'Не удалось завершить сеанс. Попробуйте ещё раз.',
            );
        } catch {
            setLogoutError(
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
        } finally {
            setLoggingOut(false);
        }
    }

    return (
        <ConfigProvider theme={crmTheme}>
            <Layout className="crm-layout">
                <Layout.Sider
                    breakpoint="lg"
                    collapsed={collapsed}
                    collapsedWidth={0}
                    onCollapse={setCollapsed}
                    width={236}
                    className="crm-sidebar"
                >
                    <div className="crm-brand" aria-label="Bid CRM">
                        <span className="crm-brand-mark" aria-hidden="true">
                            B
                        </span>
                        <Typography.Text strong>Bid CRM</Typography.Text>
                    </div>
                    <Menu
                        mode="inline"
                        selectedKeys={[activePage]}
                        items={[
                            {
                                key: 'deals',
                                label: (
                                    <Link
                                        href={dealsIndex.url()}
                                        className="crm-nav-link"
                                    >
                                        Сделки
                                    </Link>
                                ),
                            },
                            {
                                key: 'events',
                                label: (
                                    <Link
                                        href={eventsIndex.url()}
                                        className="crm-nav-link"
                                    >
                                        События
                                    </Link>
                                ),
                            },
                        ]}
                    />
                    <div className="crm-sidebar-profile">
                        <div className="crm-avatar" aria-hidden="true">
                            {user.first_name
                                .slice(0, 1)
                                .toLocaleUpperCase('ru-RU')}
                        </div>
                        <div className="crm-profile-copy">
                            <Typography.Text strong ellipsis>
                                {user.first_name} {user.last_name}
                            </Typography.Text>
                            <Typography.Text type="secondary" ellipsis>
                                {user.email}
                            </Typography.Text>
                        </div>
                    </div>
                </Layout.Sider>

                <Layout>
                    <Layout.Header className="crm-header">
                        <div className="crm-header-title">
                            <Button
                                className="crm-menu-toggle"
                                aria-label={
                                    collapsed
                                        ? 'Открыть навигацию'
                                        : 'Свернуть навигацию'
                                }
                                onClick={() =>
                                    setCollapsed((isCollapsed) => !isCollapsed)
                                }
                            >
                                ☰
                            </Button>
                            <Typography.Text strong>
                                Рабочее пространство
                            </Typography.Text>
                        </div>
                        <Dropdown
                            menu={{
                                items: [
                                    {
                                        key: 'logout',
                                        label: 'Выйти',
                                        onClick: logout,
                                        disabled: loggingOut,
                                    },
                                ],
                            }}
                            trigger={['click']}
                        >
                            <Button type="text" loading={loggingOut}>
                                {user.first_name} {user.last_name}
                            </Button>
                        </Dropdown>
                    </Layout.Header>
                    <Layout.Content className="crm-content">
                        {logoutError && (
                            <Alert
                                className="crm-alert"
                                type="error"
                                showIcon
                                message={logoutError}
                                closable
                                onClose={() => setLogoutError(null)}
                            />
                        )}
                        {children}
                    </Layout.Content>
                </Layout>
            </Layout>
        </ConfigProvider>
    );
}

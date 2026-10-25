import { router } from '@inertiajs/react';
import {
    Alert,
    Button,
    Card,
    ConfigProvider,
    Input,
    Space,
    Typography,
} from 'antd';
import { useState, type FormEvent } from 'react';

import { ensureCsrfCookie, apiClient } from '@/lib/api';
import { crmTheme } from '@/lib/theme';
import { index as dealsIndex } from '@/routes/crm/deals';

export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [emailError, setEmailError] = useState<string | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setProcessing(true);
        setEmailError(null);
        setErrorMessage(null);

        try {
            await ensureCsrfCookie();
            const { error, response } = await apiClient.POST('/auth/login', {
                body: { email, password },
            });

            if (response.status === 200) {
                setPassword('');
                router.visit(dealsIndex.url(), { replace: true });

                return;
            }

            if (response.status === 422) {
                setEmailError(
                    error?.errors?.email?.[0] ?? 'Проверьте email и пароль.',
                );
            } else if (response.status === 419) {
                setErrorMessage(
                    'Защита сессии обновилась. Повторите отправку формы.',
                );
            } else if (response.status === 429) {
                setErrorMessage(
                    'Слишком много попыток входа. Подождите и попробуйте снова.',
                );
            } else {
                setErrorMessage(
                    'Не удалось выполнить вход. Попробуйте ещё раз позже.',
                );
            }
        } catch {
            setErrorMessage(
                'Не удалось связаться с сервером. Проверьте подключение и повторите попытку.',
            );
        } finally {
            setPassword('');
            setProcessing(false);
        }
    }

    return (
        <ConfigProvider theme={crmTheme}>
            <main className="auth-page">
                <Card className="auth-card" bordered>
                    <Space
                        direction="vertical"
                        size={24}
                        className="auth-card-content"
                    >
                        <div className="auth-heading">
                            <Typography.Title level={2}>
                                Вход в Bid CRM
                            </Typography.Title>
                            <Typography.Paragraph type="secondary">
                                Войдите, чтобы продолжить работу со сделками.
                            </Typography.Paragraph>
                        </div>

                        {errorMessage && (
                            <Alert
                                type="error"
                                showIcon
                                message={errorMessage}
                                role="alert"
                            />
                        )}

                        <form onSubmit={submit} className="auth-form">
                            <label className="auth-field">
                                <Typography.Text strong>Email</Typography.Text>
                                <Input
                                    type="email"
                                    name="email"
                                    autoComplete="username"
                                    value={email}
                                    onChange={(event) =>
                                        setEmail(event.target.value)
                                    }
                                    status={emailError ? 'error' : undefined}
                                    aria-invalid={emailError !== null}
                                    aria-describedby={
                                        emailError
                                            ? 'login-email-error'
                                            : undefined
                                    }
                                    required
                                />
                                {emailError && (
                                    <Typography.Text
                                        id="login-email-error"
                                        type="danger"
                                        role="alert"
                                    >
                                        {emailError}
                                    </Typography.Text>
                                )}
                            </label>

                            <label className="auth-field">
                                <Typography.Text strong>Пароль</Typography.Text>
                                <Input.Password
                                    name="password"
                                    autoComplete="current-password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                    required
                                />
                            </label>

                            <Button
                                type="primary"
                                htmlType="submit"
                                loading={processing}
                                block
                            >
                                Войти
                            </Button>
                        </form>
                    </Space>
                </Card>
            </main>
        </ConfigProvider>
    );
}

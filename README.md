# Deals API

Приложение предоставляет REST API для управления сделками и аутентификации через cookie и сессии. Используются PHP 8.4, Laravel 13.38, React 19, TypeScript 5.7, PostgreSQL 17 и Redis 7.
Ответ на бинес задачи лежит в [Business.md](Business.md)
## Запуск проекта локально

Понадобятся Docker с Docker Compose v2. PHP и Composer запускаются в PHP-контейнере, frontend-команды — в отдельном контейнере Node.js 22 на той же Debian/glibc-базе, что и production-сборка.

1. Создайте локальный файл настроек:

    ```bash
    cp .env.example .env
    ```

2. Для PHP-контейнера необходимо указать группу Docker socket. Узнайте её номер:

    ```bash
    stat -c '%g' /var/run/docker.sock
    ```

    Откройте `.env` и укажите полученное значение в `DOCKER_GID`. Если после запуска файлы проекта принадлежат не вашему пользователю, укажите его UID и GID в `APP_UID` и `APP_GID` (`id -u` и `id -g`). Не используйте эти настройки на недоверенном сервере: доступ контейнера к Docker socket даёт широкие права на Docker-хосте.

3. Соберите PHP-образ, установите PHP-зависимости, создайте ключ Laravel и соберите интерфейс React:

    ```bash
    docker compose build
    docker compose run --rm --no-deps php composer install
    docker compose run --rm --no-deps php php artisan key:generate
    docker compose run --rm node npm ci
    docker compose run --rm node npm run build
    ```

4. Запустите приложение и подготовьте базу данных:

    ```bash
    docker compose up -d
    docker compose exec php php artisan migrate --force
    docker compose exec php php artisan db:seed
    ```

    Seeder создаёт демонстрационные данные и локальную учётную запись `test@example.com` с паролем `password`. Используйте эту тестовую учётную запись только при локальной разработке.

5. Откройте [http://localhost:8000](http://localhost:8000) и войдите в систему. PHP/Laravel обрабатывает запросы через Nginx; Compose отдельно запускает PostgreSQL, Redis, queue worker, планировщик и Reverb. WebSocket Reverb доступен на порту `8080`; браузер подключается к `REVERB_HOST`/`REVERB_PORT`, заданным в переменных Vite.

Для запуска Vite в режиме разработки выполните в отдельном терминале:

```bash
docker compose run --rm --service-ports node npm run dev -- --host=0.0.0.0
```

Контейнер Node использует Node.js 22, а зависимости сохраняются в отдельном Compose volume `node_modules`. Для запуска frontend-команд на хосте используйте Node.js 22 (`.nvmrc`).

Для остановки контейнеров выполните `docker compose down`. Данные PostgreSQL и Redis сохраняются в volumes Compose; команда `docker compose down -v` удалит эти данные.

## Production Docker Compose

Для production используйте отдельный `compose.production.yaml`; он не наследует локальный Compose. Образы содержат код и собранный frontend, исходники не монтируются. В production-сервисах нет доступа к Docker socket, все процессы запускаются без root и с read-only корневой файловой системой. PostgreSQL, Redis, PHP-FPM и Reverb не публикуют порты на хост; наружу через loopback публикуется только Nginx на `127.0.0.1:8088` (порт можно задать через `HTTP_PORT`). Настройте внешний TLS reverse proxy на этот порт и проксируйте WebSocket-путь `/app/` на тот же Nginx.

Создайте `.env.production` вне Git и заполните его значениями из secret manager. Обязательны `APP_KEY`, `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `WEBHOOK_TOKEN` и `VITE_REVERB_HOST`. Для сборки frontend задайте `VITE_REVERB_HOST` как публичное DNS-имя без схемы; `VITE_REVERB_PORT` и `VITE_REVERB_SCHEME` по умолчанию равны `443` и `https`. `APP_KEY`, токены и пароли должны быть уникальными и криптографически стойкими; не используйте локальные демонстрационные пароли из `.env.example`.

Соберите образы, примените миграции и запустите сервисы:

```bash
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml run --rm php php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Не запускайте production seeders с тестовыми пользователями. До открытия внешнего доступа создайте и проверьте production-учётную запись по принятой в окружении процедуре. При обновлении приложения сначала соберите новый образ, затем примените совместимые миграции и перезапустите сервисы. Не передавайте `.env.production` в build context: `.dockerignore` исключает `.env.*`.

## Webhook внешней CRM

Для `POST /api/webhooks/deals` требуется `X-Webhook-Token`. Укажите `WEBHOOK_TOKEN` в локальном `.env` или в окружении развертывания; это значение не хранится в репозитории. Если настройка на сервере отсутствует, возвращается `503`; если токен запроса отсутствует или неверен — `401`.

Перед отправкой `WebhookDeal` в настроенную очередь endpoint проверяет и сохраняет снимок события. Установите `QUEUE_CONNECTION=redis` и запустите queue worker. ID событий — строки, уникальные в пределах типа события. Повторное событие повторно не отправляется: пока оно ожидает обработки, ответ содержит сохранённый снимок сделки; после обработки — сохранённые поля сделки.

Webhook и успешные ответы mock CRM используют одинаковые поля данных `data`: `id`, `version`, `updated_at`, `title`, `status` и `amount`. Ответ webhook также содержит `meta.event_id` и `meta.processing_status` (`received`, `processing`, `applied`, `skipped` или `failed`). Если внешняя версия не новее уже сохранённой, событие завершается со статусом `skipped`: запись сделки не меняется, а ответ и журнал показывают текущую версию сделки. Например:

```json
{
    "data": {
        "id": "5678",
        "version": 18,
        "updated_at": "2026-10-15T15:29:50.000000Z",
        "title": "Продажа курса",
        "status": "NEW",
        "amount": "49000.00"
    },
    "meta": {
        "event_id": "evt_123",
        "processing_status": "received"
    }
}
```

События и их снимки сохраняются в таблице `events`. Чтобы повторно отправить сохранённые события со статусом `received` или `failed` после сбоя очереди, выполните:

```bash
php artisan webhooks:dispatch-pending
```

Планировщик запускает эту команду каждую минуту. Если worker аварийно остановился после перевода события в `PROCESSING`, запись с `processing_started_at`, более старым чем пять минут, повторно отправляется в очередь. Время старта обработки фиксируется отдельно от `event_time`; повторный запуск команды атомарно захватывает зависшую запись, а обработчик остаётся идемпотентным.

## OpenAPI и клиент React

В файле `openapi/openapi.yaml` описаны endpoints аутентификации, профиля пользователя и сделок. `openapi-typescript` генерирует типы путей TypeScript, используемые `openapi-fetch`:

```bash
npm run openapi:generate
npm run types:check
```

Сгенерированные типы сохраняются в `resources/js/generated/api.d.ts`. Используйте `apiClient` из `resources/js/lib/api.ts`: он передаёт session cookies и считывает CSRF cookie для запросов на изменение данных:

```ts
import { apiClient } from '@/lib/api';

await apiClient.GET('/auth/csrf-cookie');
const { data, error } = await apiClient.POST('/auth/login', {
    body: { email: 'ada@example.test', password: 'StrongPass123!' },
});
```

Повторно генерируйте типы клиента при каждом изменении схемы OpenAPI. Установлены версии генератора и клиента `openapi-typescript` 7.13 и `openapi-fetch` 0.17.

Документ OpenAPI — источник данных для коллекции Postman. После добавления или изменения endpoint обновите `openapi/openapi.yaml` и выполните `make postman-sync`, чтобы пересоздать локальную коллекцию и синхронизировать её с Postman. Синхронизация заменяет содержимое настроенной коллекции, поэтому не добавляйте запросы вручную в эту сгенерированную коллекцию.

Добавьте API key Postman и UID существующей коллекции в локальный файл `.env`:

```bash
POSTMAN_API_KEY=
POSTMAN_COLLECTION_UID=
```

Затем выполните:

```bash
make postman-sync
```

Создайте API key в настройках API keys Postman. UID коллекции можно найти в её описании или URL. Не сохраняйте эти значения в Git или истории команд shell. Команда `make postman` только пересоздаёт `postman/Bid CRM API.postman_collection.json`, а `make postman-sync` также обновляет существующую удалённую коллекцию через Postman API.

При первом создании коллекции импортируйте `postman/Bid CRM API.postman_collection.json` в Postman. Укажите `base_url` (только host и port, по умолчанию `localhost:8000`), `email` и `password`, затем один раз отправьте запросы **Initialize the session and receive the CSRF cookie** и **Authenticate with email and password**. Postman сохранит session cookie для последующих защищённых запросов, а коллекция добавит CSRF header из XSRF cookie к запросам на изменение данных. При успешном входе также обновится переменная коллекции `user_id`, используемая в запросах профиля. Чтобы отправлять webhook, задайте `webhook_token` такое же значение, как `WEBHOOK_TOKEN` на сервере. Поскольку синхронизация заменяет сгенерированную коллекцию, храните личные значения в окружении Postman, а не в переменных коллекции.

## Модель сделки

Таблица `deals` хранит текущие название, статус и сумму, а также необязательные значения `external_id`, `external_updated_at` и `external_version` для будущей синхронизации с CRM. У сделок, созданных вручную, внешний ID и версия могут отсутствовать. Поля `created_by_user_id` и `assigned_to_user_id` — nullable внешние ключи на `users`; при удалении пользователя сделка остаётся, а соответствующий внешний ключ получает значение `NULL`.

Уникальный индекс `external_id` предотвращает дублирование внешних записей. Составной индекс по статусу и времени создания ускоряет фильтрацию списка по статусу, а индекс по времени создания и ID поддерживает сортировку от новых к старым и стабильную пагинацию. Сортировка по другим разрешённым полям выполняется PostgreSQL без специальных индексов.

## Outbox уведомлений

Итоговый статус обновления сделки и запись соответствующего уведомления (`succeeded` или `failed`) сохраняются в одной транзакции в `deals` и `outbox_messages`. Статус `processing` сохраняется в сделке; интерфейс показывает его сразу после принятия запроса. Отдельная queue job синхронно публикует итоговое сообщение в Reverb; если публикация не удалась, outbox-запись остаётся `PENDING`, получает backoff и ошибку доставки, но уже завершённая синхронизация сделки не переводится в `failed`. Compose запускает `schedule:work`, который каждую минуту повторно ставит в очередь готовые сообщения и восстанавливает записи `SENDING`, оставшиеся без завершения более пяти минут. Вручную запустить восстановление можно командой `php artisan outbox:dispatch-pending`.

Статусы outbox: `PENDING`, `SENDING`, `SENT`. Доставка может быть повторена, если процесс завершился после публикации, но до сохранения `SENT`; поэтому потребителям следует считать уведомления доставляемыми как минимум один раз.

## API

| Метод          | Маршрут           | Описание                                         |
| -------------- | ----------------- | ------------------------------------------------ |
| `GET`          | `/api/events`     | Получить журнал событий с фильтрами и пагинацией |
| `GET`          | `/api/deals`      | Получить список сделок                           |
| `POST`         | `/api/deals`      | Создать сделку                                   |
| `GET`          | `/api/deals/{id}` | Получить сделку                                  |
| `PUT`, `PATCH` | `/api/deals/{id}` | Обновить сделку                                  |
| `DELETE`       | `/api/deals/{id}` | Удалить сделку                                   |

Для всех маршрутов сделок требуется аутентифицированная сессия. Запросы создания и обновления принимают `title`, `status`, `amount` и необязательные `assigned_to_user_id` и `external_id`, связывающий сделку с записью во внешней CRM. Внешний ID должен быть уникальным; его очистка отвязывает сделку и отключает обновление из CRM. Это поле доступно в форме сделки, поэтому менеджер может связать сделку и запустить обновление из клиента. Метаданные внешней версии и времени, а также `created_by_user_id` задаются на сервере. При создании сделки текущий пользователь записывается как её создатель.

Для `title`, `status` и `amount` действует правило «внешняя CRM побеждает только более новой версией»: webhook или GET-ответ применяются, только если `deal.version` больше сохранённого `external_version`. Ответ с меньшей или равной версией игнорируется. Поэтому ручная правка сохраняется до прихода строго более новой версии внешней CRM; если версия совпала, но значения отличаются, остаются локальные значения. Локальные правки обратно во внешнюю CRM не отправляются. В журнал конфликтов различия при одинаковой версии отдельно не записываются.

Маршрут списка принимает следующие параметры запроса:

| Параметр    | Описание                                                                 |
| ----------- | ------------------------------------------------------------------------ |
| `status`    | Фильтр по одному из доступных статусов                                   |
| `sort`      | Сортировка по `created_at`, `updated_at`, `title`, `status` или `amount` |
| `direction` | `asc` или `desc`                                                         |
| `page`      | Номер страницы, начиная с 1                                              |
| `per_page`  | Размер страницы от 1 до 100; по умолчанию — 15                           |

По умолчанию используется сортировка `created_at desc`, а `id desc` обеспечивает стабильный порядок при совпадении значений. Если указать недопустимые значения фильтра, сортировки, направления или пагинации, API вернёт ошибку валидации.

Пример:

```text
GET /api/deals?status=NEW&sort=amount&direction=desc&page=2&per_page=20
```

Ответ со списком — это JSON-ресурс Laravel с пагинацией, содержащий `data`, `links` и `meta`.

### Журнал событий

Раздел **«События»** в навигации показывает журнал входящих webhook-событий. Страница `/events` и `GET /api/events` доступны пользователям с активной сессией. События идут от новых к старым; доступны фильтры по точному типу (`event_type`) и статусу (`CREATED`, `PROCESSING`, `SUCCESS`, `SKIPPED`, `FAILED`), а поиск проверяет ID события, ID сделки и её название. `SKIPPED` означает, что событие обработано, но не применено, поскольку его версия не новее сохранённой. Параметры пагинации `page` и `per_page` работают так же, как в списке сделок; `per_page` ограничен диапазоном 1–100. В деталях события отображаются версия и поля сделки, а для завершившихся ошибкой событий — сохранённая причина.

Пример запроса:

```text
GET /api/events?event_type=webhook.deal.create&status=FAILED&search=evt_123&page=1&per_page=15
```

Индекс `event_id` обеспечивает уникальность и быстрый поиск точного ID события. Составные индексы по времени, типу и статусу поддерживают сортировку журнала и основные фильтры с предсказуемым порядком при одинаковом времени.

### Статус обновления сделки

Состояние последнего запроса обновления хранится в полях сделки `external_refresh_status`, `external_refresh_error` и `external_refresh_updated_at`. Возможные статусы: `queued` (запрос принят), `processing` (worker выполняет запрос), `succeeded` (обновление завершено) и `failed` (повторы исчерпаны или задачу не удалось поставить в очередь). Состояние возвращается вместе со сделкой через API и восстанавливается после перезагрузки страницы. Пока страница открыта, Reverb сразу передаёт изменения через приватный WebSocket-канал, а polling API раз в две секунды служит fallback и подхватывает уже активные операции после загрузки страницы. При возврате во вкладку выполняется немедленная проверка. Опрос прекращается по терминальному статусу или через 60 секунд с понятным предупреждением; поздний ответ polling не перезаписывает более свежее событие Reverb. При временных сбоях job выполняет до трёх попыток с задержками 1 и 5 секунд. Подключение Reverb можно проверить в инструментах разработчика браузера во вкладке Network → WS.

## Mock API внешней CRM

`GET /mock-api/deals/{id}` имитирует внешнюю CRM и не читает и не изменяет локальные сделки. Успешный JSON-ответ содержит те же поля `data`, что и webhook: `id`, `version`, `updated_at`, `title`, `status` и `amount`. Тестовые данные детерминированы для указанного ID.

Выберите режим с помощью query-параметра `mode` или задайте `MOCK_CRM_MODE` в `.env` (по умолчанию — `success`):

```bash
curl -i 'http://localhost:8000/mock-api/deals/CRM-42?mode=success'
curl -i 'http://localhost:8000/mock-api/deals/CRM-42?mode=permanent_failure'
```

Для показа обновления через интерфейс предусмотрена детерминированная фикстура `CRM-DEMO-UPDATE`: в режиме `success` она всегда возвращает версию `19`, название «Сделка обновлена из mock CRM», статус `IN_PROGRESS` и сумму `24500.00`. Остальные ID продолжают возвращать стандартную фикстуру версии `1`.

Чтобы увидеть обновление через UI, откройте список сделок, создайте новую сделку и укажите внешний ID `CRM-DEMO-UPDATE`. Сохраните сделку с любыми отличающимися названием, статусом или суммой и нажмите «Обновить CRM»: после выполнения фоновой задачи в карточке появятся поля фикстуры и статус «Успешно обновлено». Фикстура применяется, только если сохранённая внешняя версия меньше `19`; для чистого повторного показа создайте новую сделку. Фикстура настроена в `config/services.php`; при локальном запуске оставьте `MOCK_CRM_MODE=success`.

В режиме `rate_limited` первые два запроса для одного ID возвращают `429` с `Retry-After: 1`, а третий и последующие — `200`. Чтобы воспроизвести успешное восстановление, выполните три команды подряд с одним ID:

```bash
curl -i 'http://localhost:8000/mock-api/deals/CRM-RATE-LIMITED?mode=rate_limited'
curl -i 'http://localhost:8000/mock-api/deals/CRM-RATE-LIMITED?mode=rate_limited'
curl -i 'http://localhost:8000/mock-api/deals/CRM-RATE-LIMITED?mode=rate_limited'
```

Счётчик ограничений хранится в настроенном cache в течение 24 часов и ведётся отдельно для каждого ID. Чтобы сразу повторить сценарий, используйте новый ID. Режим `permanent_failure` возвращает `500` при каждом запросе.

Чтобы проверить сценарии повторов именно через кнопку обновления:

1. Свяжите сделку с внешней CRM: создайте или отредактируйте сделку и укажите уникальный внешний ID.
2. Установите в `.env` `MOCK_CRM_MODE=rate_limited`, затем перезапустите приложение и worker:

    ```bash
    docker compose restart php worker
    ```

3. Нажмите «Обновить CRM». Первые два обращения для этого внешнего ID вернут `429`; job повторит запросы с задержками 1 и 5 секунд и после третьего ответа `200` завершится успешно. Счётчик действует 24 часа, поэтому для повторного прогона используйте другой внешний ID.
4. Чтобы проверить окончательную ошибку, установите `MOCK_CRM_MODE=permanent_failure`, снова выполните `docker compose restart php worker` и запустите обновление. Все три попытки вернут `500`, после чего статус сделки станет `failed`, а в карточке отобразится сообщение об ошибке.
5. Для обычного сценария установите `MOCK_CRM_MODE=success` и перезапустите PHP и worker. После изменения режима дождитесь завершения старых задач или используйте для проверки отдельную сделку.

## Миграции базы данных

Для применения миграций выполните `php artisan migrate`.

## Тесты

Запустите feature- и unit-тесты из каталога проекта:

```bash
php artisan test --compact tests/Feature/AuthControllerTest.php
php artisan test --compact tests/Feature/UserControllerTest.php
php artisan test --compact tests/Feature/DealControllerTest.php
php artisan test --compact tests/Feature/DealWebhookControllerTest.php
php artisan test --compact tests/Feature/DealRefreshTest.php
php artisan test --compact tests/Feature/DeliverOutboxMessageTest.php
php artisan test --compact tests/Feature/MockApi/DealControllerTest.php
php artisan test --compact tests/Feature/EventControllerTest.php
php artisan test --compact tests/Unit
```

Набор integration-тестов запускает временный контейнер PostgreSQL 17 через Testcontainers и применяет реальные миграции. Refresh queue tests дополнительно запускают Redis 7 и выполняют задания настоящим Laravel worker; контейнер Redis используется только этими тестами. Тесты запускайте в PHP-сервисе Compose: сервису нужен доступ к Docker socket хоста. Укажите в `DOCKER_GID` в файле `.env` ID группы socket:

```bash
stat -c '%g' /var/run/docker.sock
```

Сервис `php` использует этот ID как дополнительную группу. Опубликованный Testcontainers port разрешается через `host.docker.internal`; Compose связывает это имя с gateway Docker-хоста. После изменения настроек пересоздайте сервис и запустите тест:

```bash
docker compose up -d --force-recreate php
docker compose exec php php artisan test --compact tests/Integration/WebhookEventTest.php
docker compose exec php php artisan test --compact tests/RedisIntegration/RefreshDealQueueTest.php
```

Redis queue tests проверяют реальные повторы через Laravel worker: режим `429, 429, 200` завершается успехом на третьей попытке, а три ответа `500` переводят сделку в конечный статус `failed` и удаляют job из очереди. Backoff в этих двух тестах обнулён, чтобы прогон был быстрым; значения backoff job отдельно остаются `1` и `5` секунд. Все тесты можно запустить командой `docker compose exec php php artisan test --compact`. Docker socket предоставляет широкие права на управление Docker daemon хоста, поэтому такая конфигурация предназначена только для локальной разработки и тестирования. Unit- и feature-тесты используют SQLite в памяти; integration-тесты используют PostgreSQL Testcontainer, а Redis Testcontainer поднимается только для `tests/RedisIntegration`.

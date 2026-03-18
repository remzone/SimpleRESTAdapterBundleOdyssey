# Odyssey Simple REST Adapter Bundle

Read this in: [English](#english) | [Русский](#russian)

<a id="english"></a>
## English

`odyssey/simple-rest-adapter-bundle` is a public Pimcore bundle that adds a configurable read-only REST API on top of Pimcore DataHub.

The bundle is designed for projects that need a lightweight integration layer for external services, storefronts, frontends, mobile applications, or middleware. Exposed data is indexed in Elasticsearch and served from there, which reduces direct database load and keeps API responses predictable for high-read scenarios.

### Highlights

- Compatible with `Pimcore 11`
- Built as a standalone reusable bundle for installation in other Pimcore projects
- Read-only REST endpoints for `DataObjects` and `Assets`
- DataHub-based schema configuration
- Elasticsearch-backed delivery layer
- Filtering, full-text search, sorting, pagination, and aggregations
- Swagger UI for endpoint discovery and testing
- Bearer-token protected API access

### Requirements

- PHP `>= 8.1`
- Pimcore `^11.0`
- Pimcore DataHub
- Elasticsearch
- Symfony Messenger

### Installation

Install the bundle via Composer:

```bash
composer require odyssey/simple-rest-adapter-bundle
```

If your project does not auto-register Pimcore bundles, register:

```php
CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterBundle::class => ['all' => true],
```

### What You Get

The bundle exposes a configurable REST layer with the following typical capabilities:

- `tree-items`: browse a tree level with pagination, filtering, sorting, search, and aggregations
- `search`: query indexed elements across configured data
- `get-element`: fetch a single element by type and ID
- Swagger documentation endpoint for inspection and manual testing

### Typical Use Cases

- Headless storefront integrations
- Frontend applications consuming Pimcore content
- Lightweight external system synchronization
- Search-oriented APIs backed by indexed Pimcore data

### Documentation

- [Installation and configuration](docs/00-installation-configuration.md)
- [Endpoint configuration](docs/01-endpoint-configuration.md)
- [Indexing details](docs/02-indexing.md)
- [Docker setup example](docs/03-docker-setup-example.md)

### Screenshots

![Schema Configuration](docs/images/schema.png "Schema Configuration")
![Swagger UI](docs/images/swagger_ui.png "Swagger UI")

### Package Notes

- Package name: `odyssey/simple-rest-adapter-bundle`
- Namespace: `CIHub\Bundle\SimpleRESTAdapterBundle`
- This repository contains the public Odyssey-maintained fork adapted for modern Pimcore usage
- The original bundle codebase was created by CI HUB
- Ongoing compatibility updates and fork maintenance are provided by Odyssey

### License

Licensed under `GPL-3.0-or-later`. See [LICENSE.md](LICENSE.md).

<a id="russian"></a>
## Русский

`odyssey/simple-rest-adapter-bundle` это публичный Pimcore bundle, который добавляет настраиваемый read-only REST API поверх Pimcore DataHub.

Bundle подходит для проектов, где нужен лёгкий интеграционный слой для внешних сервисов, витрин, frontend-приложений, мобильных клиентов или middleware. Данные индексируются в Elasticsearch и отдаются оттуда, что снижает нагрузку на базу данных и делает ответы API стабильнее в сценариях с большим числом запросов на чтение.

### Основные возможности

- Совместимость с `Pimcore 11`
- Самостоятельный переиспользуемый bundle для установки в другие Pimcore-проекты
- Read-only REST endpoints для `DataObjects` и `Assets`
- Конфигурирование схемы через DataHub
- Слой выдачи данных на базе Elasticsearch
- Фильтрация, полнотекстовый поиск, сортировка, пагинация и агрегации
- Swagger UI для просмотра и тестирования endpoint'ов
- Защита API через bearer token

### Требования

- PHP `>= 8.1`
- Pimcore `^11.0`
- Pimcore DataHub
- Elasticsearch
- Symfony Messenger

### Установка

Установите bundle через Composer:

```bash
composer require odyssey/simple-rest-adapter-bundle
```

Если в проекте bundle не регистрируются автоматически, добавьте:

```php
CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterBundle::class => ['all' => true],
```

### Что входит

Bundle предоставляет настраиваемый REST-слой со следующими типовыми возможностями:

- `tree-items`: загрузка элементов уровня дерева с пагинацией, фильтрацией, сортировкой, поиском и агрегациями
- `search`: поиск по индексированным элементам в настроенной схеме
- `get-element`: получение одного элемента по типу и ID
- Swagger endpoint для просмотра документации и ручной проверки API

### Типовые сценарии использования

- Интеграция headless storefront
- Frontend-приложения, работающие с данными Pimcore
- Лёгкая синхронизация с внешними системами
- Поисковые API поверх индексированных данных Pimcore

### Документация

- [Установка и конфигурация](docs/00-installation-configuration.md)
- [Настройка endpoint'ов](docs/01-endpoint-configuration.md)
- [Детали индексирования](docs/02-indexing.md)
- [Пример Docker setup](docs/03-docker-setup-example.md)

### Скриншоты

![Schema Configuration](docs/images/schema.png "Schema Configuration")
![Swagger UI](docs/images/swagger_ui.png "Swagger UI")

### Примечания по пакету

- Имя пакета: `odyssey/simple-rest-adapter-bundle`
- Namespace: `CIHub\Bundle\SimpleRESTAdapterBundle`
- Этот репозиторий содержит публичный fork Odyssey, адаптированный для современного Pimcore
- Исходная кодовая база bundle изначально создана CI HUB
- Дальнейшие обновления, адаптация и сопровождение форка выполняются Odyssey

### Лицензия

Лицензия `GPL-3.0-or-later`. Подробности в [LICENSE.md](LICENSE.md).

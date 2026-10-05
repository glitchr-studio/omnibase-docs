---
title: Installation
order: 10
---

# Installing the widget

```sh
composer require acme/widget
```

Then [configure the webhooks](20-guides/webhooks.md), or go back to the
[overview](../README.md). The bridge lives in [the source](../src/Widget.php).

> [!NOTE]
> The widget needs PHP 8.2.

> [!WARNING]
> Never commit the signing secret.

> An ordinary quote stays a quote.

## Requirements

PHP 8.2 or later.

## Configuration

```yaml
acme_widget:
    secret: '%env(WIDGET_SECRET)%'
```

```php
$widget = new Widget(secret: 'not-a-real-secret');
```

![The flow](img/flow.svg)

See also <https://example.org/widget> and [an anchor](#requirements).

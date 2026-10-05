# Webhooks

The widget calls back when a payment settles.

## Signature

Every webhook is signed: check the `X-Widget-Signature` header.

## Retries

A webhook is retried five times. Back to the [installation](../installation.md#configuration).

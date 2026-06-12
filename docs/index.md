# Laravel Event-Sourcing

An event sourcing laravel package, complete with all the essential features,
powered by the reliable Doctrine ecosystem and focused on developer experience.
This package is a [laravel](https://laravel.com/) integration
for [event-sourcing](https://github.com/patchlevel/event-sourcing) library.

## Features

* Everything is included in the package for event sourcing
* Facades for easy access to event sourcing services and aggregates
* Developer experience oriented and fully typed
* Automatic [snapshot](/docs/event-sourcing/latest/snapshots)-system to boost your performance
* [Split](/docs/event-sourcing/latest/split-stream) big aggregates into multiple streams
* Versioned and managed lifecycle of [subscriptions](/docs/event-sourcing/latest/subscription) like projections and processors
* Safe usage of [Sensitive Data](/docs/event-sourcing/latest/personal-data) with crypto-shredding
* Smooth [upcasting](/docs/event-sourcing/latest/upcasting) of old events
* Simple setup with [scheme management](/docs/event-sourcing/latest/store) and [doctrine migration](/docs/event-sourcing/latest/store)
* Built in [cli commands](/docs/event-sourcing/latest/cli)
* and much more...

## Installation

```bash
composer require patchlevel/laravel-event-sourcing
```

:::note
More about installation can be found in the [installation documentation](installation.md).
:::

:::tip
Start with the [quickstart](getting-started.md) to get a feeling for the package.
:::
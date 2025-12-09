# Laravel Event-Sourcing

An event sourcing laravel package, complete with all the essential features,
powered by the reliable Doctrine ecosystem and focused on developer experience.
This package is a [laravel](https://laravel.com/) integration
for [event-sourcing](https://github.com/patchlevel/event-sourcing) library.

## Features

* Everything is included in the package for event sourcing
* Facades for easy access to event sourcing services and aggregates
* Developer experience oriented and fully typed
* Automatic [snapshot](https://event-sourcing.patchlevel.io/latest/snapshots/)-system to boost your performance
* [Split](https://event-sourcing.patchlevel.io/latest/split_stream/) big aggregates into multiple streams
* Versioned and managed lifecycle of [subscriptions](https://event-sourcing.patchlevel.io/latest/subscription/) like projections and processors
* Safe usage of [Sensitive Data](https://event-sourcing.patchlevel.io/latest/personal_data/) with crypto-shredding
* Smooth [upcasting](https://event-sourcing.patchlevel.io/latest/upcasting/) of old events
* Simple setup with [scheme management](https://event-sourcing.patchlevel.io/latest/store/) and [doctrine migration](https://event-sourcing.patchlevel.io/latest/store/)
* Built in [cli commands](https://event-sourcing.patchlevel.io/latest/cli/)
* and much more...

## Installation

```bash
composer require patchlevel/laravel-event-sourcing
```
!!! info

    More about installation can be found in the [installation documentation](installation.md).
    
!!! tip

    Start with the [quickstart](./getting_started.md) to get a feeling for the package.
    
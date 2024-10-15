<?php

namespace Patchlevel\LaravelEventSourcing;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Patchlevel\EventSourcing\Snapshot\Adapter\Psr16SnapshotAdapter;
use Patchlevel\EventSourcing\Snapshot\Adapter\SnapshotAdapter;
use Patchlevel\EventSourcing\Snapshot\AdapterNotFound;
use Patchlevel\EventSourcing\Snapshot\AdapterRepository;

class LaravelSnapshotAdapterRepository implements AdapterRepository
{
    /**
     * @var array<string, SnapshotAdapter>
     */
    private array $adapterCache = [];

    public function get(string $name): SnapshotAdapter
    {
        if ($this->adapterCache[$name] ?? null) {
            return $this->adapterCache[$name];
        }

        try {
            $this->adapterCache[$name] = new Psr16SnapshotAdapter(
                Cache::store($name === 'default' ? null : $name)
            );

            return $this->adapterCache[$name];
        } catch (InvalidArgumentException) {
            throw new AdapterNotFound($name);
        }
    }
}

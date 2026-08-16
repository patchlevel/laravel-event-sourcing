<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Store;

use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use IteratorAggregate;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\Message\Serializer\HeadersSerializer;
use Patchlevel\EventSourcing\Serializer\EventSerializer;
use Patchlevel\EventSourcing\Serializer\SerializedEvent;
use Patchlevel\EventSourcing\Store\ArchivedHeader;
use Patchlevel\EventSourcing\Store\Header\EventIdHeader;
use Patchlevel\EventSourcing\Store\Header\IndexHeader;
use Patchlevel\EventSourcing\Store\Header\PlayheadHeader;
use Patchlevel\EventSourcing\Store\Header\RecordedOnHeader;
use Patchlevel\EventSourcing\Store\Header\StreamNameHeader;
use Patchlevel\EventSourcing\Store\Stream;
use Patchlevel\EventSourcing\Store\StreamClosed;
use stdClass;
use Traversable;

use function is_array;
use function is_string;

/** @implements IteratorAggregate<Message> */
final class StreamIlluminateStoreStream implements Stream, IteratorAggregate
{
    /** @var iterable<array<string, mixed>|stdClass>|null */
    private iterable|null $result;

    /** @var Generator<Message> */
    private Generator|null $generator;

    /** @var positive-int|0|null */
    private int|null $position;

    /** @var positive-int|null */
    private int|null $index;

    /** @param iterable<array<string, mixed>|stdClass> $result */
    public function __construct(
        iterable $result,
        EventSerializer $eventSerializer,
        HeadersSerializer $headersSerializer,
    ) {
        $this->result = $result;
        $this->generator = $this->buildGenerator($result, $eventSerializer, $headersSerializer);
        $this->position = null;
        $this->index = null;
    }

    public function close(): void
    {
        $this->result = null;
        $this->generator = null;
    }

    public function next(): void
    {
        $this->assertNotClosed();

        $this->generator->next();
    }

    public function end(): bool
    {
        $this->assertNotClosed();

        return !$this->generator->valid();
    }

    public function current(): Message|null
    {
        $this->assertNotClosed();

        /** @var Message|null $current */
        $current = $this->generator->current();

        return $current;
    }

    /** @return positive-int|0|null */
    public function position(): int|null
    {
        $this->assertNotClosed();

        if ($this->position === null) {
            $this->generator->key();
        }

        return $this->position;
    }

    /** @return positive-int|null */
    public function index(): int|null
    {
        $this->assertNotClosed();

        if ($this->index === null) {
            $this->generator->key();
        }

        return $this->index;
    }

    /** @return Traversable<Message> */
    public function getIterator(): Traversable
    {
        $this->assertNotClosed();

        return $this->generator;
    }

    /**
     * @param iterable<array<string, mixed>|stdClass> $result
     *
     * @return Generator<Message>
     */
    private function buildGenerator(
        iterable $result,
        EventSerializer $eventSerializer,
        HeadersSerializer $headersSerializer,
    ): Generator {
        foreach ($result as $data) {
            if ($this->position === null) {
                $this->position = 0;
            } else {
                ++$this->position;
            }

            /** @var positive-int $id */
            $id = (int)$this->extractValue($data, 'id');

            $this->index = $id;

            $payload = $this->extractValue($data, 'event_payload');
            $event = $eventSerializer->deserialize(new SerializedEvent(
                (string)$this->extractValue($data, 'event_name'),
                is_string($payload) ? $payload : (string)$payload,
            ));

            $recordedOn = $this->extractValue($data, 'recorded_on');
            $recordedOnDate = $recordedOn instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($recordedOn)
                : new DateTimeImmutable((string)$recordedOn);

            $message = Message::create($event)
                ->withHeader(new IndexHeader($id))
                ->withHeader(new StreamNameHeader((string)$this->extractValue($data, 'stream')))
                ->withHeader(new RecordedOnHeader($recordedOnDate))
                ->withHeader(new EventIdHeader((string)$this->extractValue($data, 'event_id')));

            $playhead = $this->extractValue($data, 'playhead');

            if ($playhead !== null) {
                $message = $message->withHeader(new PlayheadHeader((int)$playhead));
            }

            if ($this->extractValue($data, 'archived')) {
                $message = $message->withHeader(new ArchivedHeader());
            }

            $customHeaders = $this->extractValue($data, 'custom_headers');

            yield $message->withHeaders(
                $headersSerializer->deserialize(is_string($customHeaders) ? $customHeaders : (string)$customHeaders),
            );
        }
    }

    private function extractValue(mixed $data, string $field): mixed
    {
        if (is_array($data)) {
            return $data[$field] ?? null;
        }

        if ($data instanceof stdClass) {
            return $data->{$field} ?? null;
        }

        return null;
    }

    /**
     * @phpstan-assert !null $this->result
     * @phpstan-assert !null $this->generator
     */
    private function assertNotClosed(): void
    {
        if ($this->result === null || $this->generator === null) {
            throw new StreamClosed();
        }
    }
}

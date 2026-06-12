# Getting Started

In our little getting started example, we manage hotels.
We keep the example small, so we can only create hotels and let guests check in and check out.

:::note
First of all, the package has to be installed and configured.
If you haven't already done so, see the [installation introduction](installation.md).
:::

## Define some events

First we define the events that happen in our system.

A hotel can be created with a `name` and an `id`:

```php
namespace App\Events;

use Patchlevel\EventSourcing\Aggregate\Uuid;
use Patchlevel\EventSourcing\Attribute\Event;

#[Event('hotel.created')]
final class HotelCreated
{
    public function __construct(
        public readonly Uuid $id,
        public readonly string $hotelName,
    ) {
    }
}
```
A guest can check in by `name`:

```php
namespace App\Events;

use Patchlevel\EventSourcing\Attribute\Event;

#[Event('hotel.guest_is_checked_in')]
final class GuestIsCheckedIn
{
    public function __construct(
        public readonly string $guestName,
    ) {
    }
}
```
And also check out again:

```php
namespace App\Events;

use Patchlevel\EventSourcing\Attribute\Event;

#[Event('hotel.guest_is_checked_out')]
final class GuestIsCheckedOut
{
    public function __construct(
        public readonly string $guestName,
    ) {
    }
}
```
:::note
You can find out more about events in the [library](/docs/event-sourcing/latest/events).
:::

## Define aggregates

Next we need to define the hotel aggregate.
How you can interact with it, which events happen and what the business rules are.
For this we create the methods `create`, `checkIn` and `checkOut`.
In these methods the business checks are made and the events are recorded.
Last but not least, we need the associated apply methods to change the state.

```php
namespace App\Models;

use App\Events\GuestIsCheckedIn;
use App\Events\GuestIsCheckedOut;
use App\Events\HotelCreated;
use Patchlevel\EventSourcing\Aggregate\Uuid;
use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Apply;
use Patchlevel\EventSourcing\Attribute\Id;
use Patchlevel\LaravelEventSourcing\AggregateRoot;

use function array_filter;
use function array_values;
use function in_array;
use function sprintf;

#[Aggregate(name: 'hotel')]
final class Hotel extends AggregateRoot
{
    #[Id]
    private Uuid $id;
    private string $name;

    /** @var list<string> */
    private array $guests;

    public function name(): string
    {
        return $this->name;
    }

    public function guests(): array
    {
        return $this->guests;
    }

    public static function create(Uuid $id, string $hotelName): self
    {
        $self = new self();
        $self->recordThat(new HotelCreated($id, $hotelName));

        return $self;
    }

    public function checkIn(string $guestName): void
    {
        if (in_array($guestName, $this->guests, true)) {
            throw new RuntimeException(sprintf('Guest %s is already checked in', $guestName));
        }

        $this->recordThat(new GuestIsCheckedIn($guestName));
    }

    public function checkOut(string $guestName): void
    {
        if (!in_array($guestName, $this->guests, true)) {
            throw new RuntimeException(sprintf('Guest %s is not checked in', $guestName));
        }

        $this->recordThat(new GuestIsCheckedOut($guestName));
    }

    #[Apply]
    protected function applyHotelCreated(HotelCreated $event): void
    {
        $this->id = $event->id;
        $this->name = $event->hotelName;
        $this->guests = [];
    }

    #[Apply]
    protected function applyGuestIsCheckedIn(GuestIsCheckedIn $event): void
    {
        $this->guests[] = $event->guestName;
    }

    #[Apply]
    protected function applyGuestIsCheckedOut(GuestIsCheckedOut $event): void
    {
        $this->guests = array_values(
            array_filter(
                $this->guests,
                static fn ($name) => $name !== $event->guestName,
            ),
        );
    }
}
```
:::note
You can find out more about aggregates in the [library](/docs/event-sourcing/latest/aggregate).
:::

## Define projections

So that we can see all the hotels on our website and also see how many guests are currently visiting the hotels,
we need a projection for it. To create a projection we need a projector.
Each projector is then responsible for a specific projection.

```php
namespace App\Subscribers;

use App\Events\GuestIsCheckedIn;
use App\Events\GuestIsCheckedOut;
use App\Events\HotelCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Patchlevel\EventSourcing\Aggregate\Uuid;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberUtil;

#[Projector('hotel')]
final class HotelProjection
{
    use SubscriberUtil;

    /** @return Collection<array{id: string, name: string, guests: int}> */
    public function getHotels(): Collection
    {
        return DB::table($this->table())->get();
    }

    #[Subscribe(HotelCreated::class)]
    public function handleHotelCreated(HotelCreated $event): void
    {
        DB::table($this->table())->insert([
            'id' => $event->id->toString(),
            'name' => $event->hotelName,
            'guests' => 0,
        ]);
    }

    #[Subscribe(GuestIsCheckedIn::class)]
    public function handleGuestIsCheckedIn(Uuid $hotelId): void
    {
        DB::table($this->table())
            ->where('id', $hotelId->toString())
            ->increment('guests');
    }

    #[Subscribe(GuestIsCheckedOut::class)]
    public function handleGuestIsCheckedOut(Uuid $hotelId): void
    {
        DB::table($this->table())
            ->where('id', $hotelId->toString())
            ->decrement('guests');
    }

    #[Setup]
    public function create(): void
    {
        Schema::create($this->table(), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->integer('guests');
        });
    }

    #[Teardown]
    public function drop(): void
    {
        Schema::dropIfExists('hotels');
    }

    private function table(): string
    {
        return 'projection_' . $this->subscriberId();
    }
}
```
You need to register the projector in the `event-sourcing.php` configuration file.

```php
use App\Subscribers\HotelProjection;

return [
    'subscribers' => [
        HotelProjection::class,
    ],
];
```
:::note
You can find out more about projections in the [library](/docs/event-sourcing/latest/subscription).
:::

## Processor

In our example we also want to send an email to the head office as soon as a guest is checked in.

```php
namespace App\Subscribers;

use App\Events\GuestIsCheckedIn;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Patchlevel\EventSourcing\Attribute\Processor;
use Patchlevel\EventSourcing\Attribute\Subscribe;

use function sprintf;

#[Processor('admin_emails')]
final class SendCheckInEmailProcessor
{
    #[Subscribe(GuestIsCheckedIn::class)]
    public function onGuestIsCheckedIn(GuestIsCheckedIn $event): void
    {
        Mail::raw('Event Sourcing is amazing!', static function (Message $message) use ($event): void {
            $message
                ->subject(sprintf('Guest %s checked in', $event->guestName))
                ->to('info@patchlevel.de');
        });
    }
}
```
You need to register the processor in the `event-sourcing.php` configuration file.

```php
use App\Subscribers\SendCheckInEmailProcessor;

return [
    'subscribers' => [
        SendCheckInEmailProcessor::class,
    ],
];
```
:::note
You can find out more about processor in the [library](/docs/event-sourcing/latest/subscription)
:::

## Usage

We are now ready to use the Event Sourcing System.
To demonstrate this, we create a controller that allows us to create hotels and check in and out guests.

```php
namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Subscribers\HotelProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Patchlevel\EventSourcing\Aggregate\Uuid;

use function response;

final class HotelController
{
    public function __construct(
        private readonly HotelProjection $hotelProjection,
    ) {
    }

    public function list(): JsonResponse
    {
        return response()->json(
            $this->hotelProjection->getHotels(),
        );
    }

    public function create(Request $request): JsonResponse
    {
        $hotelName = $request->json('name'); // need validation!

        $id = Uuid::generate();

        $hotel = Hotel::create($id, $hotelName);
        $hotel->save();

        return response()->json(['id' => $id->toString()]);
    }

    public function checkIn(string $id, Request $request): JsonResponse
    {
        $guestName = $request->request->get('name'); // need validation!

        $hotel = Hotel::load(Uuid::fromString($id));
        $hotel->checkIn($guestName);
        $hotel->save();

        return response()->json();
    }

    public function checkOut(string $id, Request $request): JsonResponse
    {
        $guestName = $request->request->get('name'); // need validation!

        $hotel = Hotel::load(Uuid::fromString($id));
        $hotel->checkOut($guestName);
        $hotel->save();

        return response()->json();
    }
}
```
The last step is to define the routes in the `routes/api.php` file.

```php
use App\Http\Controllers\HotelController;
use Illuminate\Support\Facades\Route;

Route::get('/hotel', [HotelController::class, 'list']);
Route::post('/hotel/create', [HotelController::class, 'create']);
Route::post('/hotel/{id}/check-in', [HotelController::class, 'checkIn']);
Route::post('/hotel/{id}/check-out', [HotelController::class, 'checkOut']);
```
:::warning
Don't forget to define the path to the [api routes](https://laravel.com/docs/11.x/routing#api-routes) in the `bootstrap/app.php` configuration file.
:::

## Result

:::success
We have successfully implemented and used event sourcing.

Feel free to browse further in the documentation for more detailed information.
If there are still open questions, create a ticket on Github and we will try to help you.
:::

:::note
This documentation is limited to the package integration.
You should also read the [library documentation](/docs/event-sourcing/latest).
:::

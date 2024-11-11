# Getting Started

In our little getting started example, we manage hotels.
We keep the example small, so we can only create hotels and let guests check in and check out.

!!! info

    First of all, the package has to be installed and configured.
    If you haven't already done so, see the [installation introduction](installation.md).
    
## Define some events

First we define the events that happen in our system.

A hotel can be created with a `name` and an `id`:

```php
namespace App\Event;

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
namespace App\Event;

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
namespace App\Event;

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
!!! note

    You can find out more about events in the [library](https://event-sourcing.patchlevel.io/latest/events/).
    
## Define aggregates

Next we need to define the hotel aggregate.
How you can interact with it, which events happen and what the business rules are.
For this we create the methods `create`, `checkIn` and `checkOut`.
In these methods the business checks are made and the events are recorded.
Last but not least, we need the associated apply methods to change the state.

```php
namespace App\Model;

use App\Event\GuestIsCheckedIn;
use App\Event\GuestIsCheckedOut;
use App\Event\HotelCreated;
use Patchlevel\EventSourcing\Aggregate\Uuid;
use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Apply;
use Patchlevel\EventSourcing\Attribute\Id;
use Patchlevel\LaravelEventSourcing\AggregateRoot;

use function array_filter;
use function array_values;
use function in_array;

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
            throw new GuestHasAlreadyCheckedIn($guestName);
        }

        $this->recordThat(new GuestIsCheckedIn($guestName));
    }

    public function checkOut(string $guestName): void
    {
        if (!in_array($guestName, $this->guests, true)) {
            throw new IsNotAGuest($guestName);
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
!!! note

    You can find out more about aggregates in the [library](https://event-sourcing.patchlevel.io/latest/aggregate/).
    
## Define projections

So that we can see all the hotels on our website and also see how many guests are currently visiting the hotels,
we need a projection for it. To create a projection we need a projector.
Each projector is then responsible for a specific projection.

```php
namespace App\Subscribers;

use App\Event\GuestIsCheckedIn;
use App\Event\GuestIsCheckedOut;
use App\Event\HotelCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberUtil;

#[Projector('hotel')]
final class HotelProjection
{
    use SubscriberUtil;

    /** @return list<array{id: string, name: string, guests: int}> */
    public function getHotels(): array
    {
        DB::select('select id, name, guests from ' . $this->table());
    }

    #[Subscribe(HotelCreated::class)]
    public function handleHotelCreated(HotelCreated $event): void
    {
        DB::insert(
            "insert into {$this->table()} (id, name, guests) values (?, ?, ?)",
            [
                $event->id->toString(),
                $event->name,
                0,
            ],
        );
    }

    #[Subscribe(GuestIsCheckedIn::class)]
    public function handleGuestIsCheckedIn(Uuid $hotelId): void
    {
        DB::update(
            "update {$this->table()} set guests = guests + 1 where id = ?",
            [$hotelId->toString()],
        );
    }

    #[Subscribe(GuestIsCheckedOut::class)]
    public function handleGuestIsCheckedOut(Uuid $hotelId): void
    {
        DB::update(
            "update {$this->table()} set guests = guests - 1 where id = ?",
            [$hotelId->toString()],
        );
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
return [
    'subscribers' => [
        App\Subscribers\HotelProjector::class,
    ],
];

```
    
!!! note

    You can find out more about projections in the [library](https://event-sourcing.patchlevel.io/latest/subscription/).
    
## Processor

In our example we also want to send an email to the head office as soon as a guest is checked in.

```php
namespace App\Subscribers;

use App\Event\GuestIsCheckedIn;
use Illuminate\Support\Facades\Mail;
use Patchlevel\EventSourcing\Attribute\Processor;

#[Processor('admin_emails')]
final class SendCheckInEmailProcessor
{
    #[Subscribe(GuestIsCheckedIn::class)]
    public function onGuestIsCheckedIn(GuestIsCheckedIn $event): void
    {
        Mail::to('hq@patchlevel.de')->send(new GuestCheckedIn($event->guestName));
    }
}
```

You need to register the processor in the `event-sourcing.php` configuration file.

```php
return [
    'subscribers' => [
        App\Subscribers\SendCheckInEmailProcessor::class,
    ],
];

```

!!! note

    You can find out more about processor in the [library](https://event-sourcing.patchlevel.io/latest/subscription/)
    
## Usage

We are now ready to use the Event Sourcing System. We can load, change and save aggregates.

```php
namespace App\Hotel\Infrastructure\Controller;

use App\Model\Hotel;
use App\Subscribers\HotelProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Patchlevel\EventSourcing\Aggregate\Uuid;

use function response;

final class HotelController
{
    public function __construct(
        private readonly HotelProjection $hotelProjection,
    ) {
    }

    public function listAction(): Response
    {
        return response()->json(
            $this->hotelProjection->getHotels(),
        );
    }

    public function createAction(Request $request): Response
    {
        $hotelName = $request->request->get('name'); // need validation!
        $id = Uuid::v7();

        $hotel = Hotel::create($id, $hotelName);
        $hotel->save();

        return response()->json(['id' => $id->toString()]);
    }

    public function checkInAction(Uuid $hotelId, Request $request): Response
    {
        $guestName = $request->request->get('name'); // need validation!

        $hotel = Hotel::load($hotelId);
        $hotel->checkIn($guestName);
        $hotel->save();

        return response()->json();
    }

    public function checkOutAction(Uuid $hotelId, Request $request): Response
    {
        $guestName = $request->request->get('name'); // need validation!

        $hotel = Hotel::load($hotelId);
        $hotel->checkOut($guestName);
        $hotel->save();

        return response()->json();
    }
}
```
## Result

!!! success

    We have successfully implemented and used event sourcing.
    
    Feel free to browse further in the documentation for more detailed information. 
    If there are still open questions, create a ticket on Github and we will try to help you.
    
!!! note

    This documentation is limited to the package integration.
    You should also read the [library documentation](https://event-sourcing.patchlevel.io/latest/).
    
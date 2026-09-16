<?php

namespace Blemli\Swissstreets\Models;

use Blemli\Swissstreets\Events\AddressAdded;
use Blemli\Swissstreets\Facades\Swissstreets;
use Blemli\Swissstreets\Geo\Distance;
use Blemli\Swissstreets\Geo\Lv95;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

/**
 * One row of the official Swiss building address register.
 *
 * @property int $egaid
 * @property int|null $egid
 * @property string $street
 * @property string|null $number
 * @property int|null $number_int
 * @property string $zip
 * @property string $locality
 * @property string $commune
 * @property string $street_search
 * @property string $locality_search
 * @property string $commune_search
 * @property string|null $canton
 * @property string $country
 * @property string $source
 * @property string $category
 * @property float|null $lat
 * @property float|null $lng
 * @property float|null $easting
 * @property float|null $northing
 * @property Carbon|null $modified_at
 * @property Carbon|null $imported_at
 * @property Carbon|null $deleted_at
 * @property-read string $line
 * @property-read string $street_line
 * @property-read string $city_line
 */
class Address extends Model
{
    use SoftDeletes;

    public const SOURCE_REGISTER = 'register';

    public const SOURCE_MANUAL = 'manual';

    public const CATEGORY_MANUAL = 'manual';

    /** Manual rows get ids far above the official EGAID range. */
    public const MANUAL_EGAID_START = 9_000_000_000;

    protected $primaryKey = 'egaid';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'egaid' => 'integer',
        'egid' => 'integer',
        'number_int' => 'integer',
        'zip' => 'string',
        'lat' => 'float',
        'lng' => 'float',
        'easting' => 'float',
        'northing' => 'float',
        'modified_at' => 'date',
        'imported_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('swissstreets-for-filament.table_name', 'swissstreets_addresses');
    }

    // ---- presentation ------------------------------------------------------

    public function getStreetLineAttribute(): string
    {
        return trim($this->street . ' ' . $this->number);
    }

    /** Swiss convention for foreign places: "DE-12345 Berlin". */
    public function getCityLineAttribute(): string
    {
        $prefix = $this->isForeign() ? "{$this->country}-" : '';

        return trim("{$prefix}{$this->zip} {$this->locality}");
    }

    public function isForeign(): bool
    {
        return filled($this->country) && strtoupper($this->country) !== 'CH';
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * Add an address the register does not know — foreign, or simply missing.
     *
     * @param  array{street: string, number?: string|null, zip: string, locality: string, commune?: string|null, country?: string|null, lat?: float|null, lng?: float|null}  $data
     */
    public static function createManual(array $data): static
    {
        $number = trim((string) ($data['number'] ?? ''));
        $country = strtoupper(trim((string) ($data['country'] ?? 'CH'))) ?: 'CH';
        $locality = trim($data['locality']);
        $street = trim($data['street']);
        $commune = trim((string) ($data['commune'] ?? '')) ?: $locality;

        $egaid = max(
            self::MANUAL_EGAID_START,
            ((int) static::withTrashed()->where('egaid', '>=', self::MANUAL_EGAID_START)->max('egaid')) + 1,
        );

        $address = static::query()->create([
            'egaid' => $egaid,
            'egid' => null,
            'street' => $street,
            'number' => $number === '' ? null : $number,
            'number_int' => preg_match('/^(\d+)/', $number, $m) ? (int) $m[1] : null,
            'zip' => trim((string) $data['zip']),
            'locality' => $locality,
            'commune' => $commune,
            'street_search' => self::searchKey($street),
            'locality_search' => self::searchKey($locality),
            'commune_search' => self::searchKey($commune),
            'canton' => null,
            'country' => $country,
            'category' => self::CATEGORY_MANUAL,
            'source' => self::SOURCE_MANUAL,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'imported_at' => null,
        ]);

        AddressAdded::dispatch($address);

        return $address;
    }

    public function getLineAttribute(): string
    {
        return "{$this->street_line}, {$this->city_line}";
    }

    public function toStringable(): Stringable
    {
        return str($this->line);
    }

    public function __toString(): string
    {
        return $this->line;
    }

    public function mapUrl(): ?string
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }

        if ($this->isForeign()) {
            return sprintf('https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=17/%F/%F', $this->lat, $this->lng, $this->lat, $this->lng);
        }

        [$e, $n] = [$this->easting, $this->northing];

        if ($e === null || $n === null) {
            [$e, $n] = Lv95::fromWgs84($this->lat, $this->lng);
        }

        return sprintf('https://map.geo.admin.ch/?E=%d&N=%d&zoom=10&crosshair=marker', $e, $n);
    }

    public function distanceTo(float $lat, float $lng): ?float
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }

        return Distance::kilometres($this->lat, $this->lng, $lat, $lng);
    }

    public function isResidential(): bool
    {
        return $this->isManual()
            || in_array($this->category, (array) config('swissstreets-for-filament.residential_categories', []), true);
    }

    // ---- scopes ------------------------------------------------------------

    /**
     * @param  Builder<static>  $query
     */
    public function scopeResidential(Builder $query): void
    {
        // Manual rows carry no building category and are always offered.
        $query->where(fn (Builder $q) => $q
            ->whereIn('category', (array) config('swissstreets-for-filament.residential_categories', []))
            ->orWhere('source', self::SOURCE_MANUAL));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeRegister(Builder $query): void
    {
        $query->where('source', self::SOURCE_REGISTER);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeManual(Builder $query): void
    {
        $query->where('source', self::SOURCE_MANUAL);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNear(Builder $query, float $lat, float $lng, ?float $withinKm = null): void
    {
        Distance::apply($query, $lat, $lng, $withinKm);
    }

    /**
     * Addresses referenced by at least one model using the HasAddress trait.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUsed(Builder $query): void
    {
        $usages = Swissstreets::usages();

        if ($usages === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $this->getTable();

        $query->where(function (Builder $query) use ($usages, $table): void {
            foreach ($usages as ['model' => $model, 'column' => $column]) {
                /** @var Model $instance */
                $instance = new $model;

                $query->orWhereExists(function (QueryBuilder $sub) use ($instance, $column, $table): void {
                    $sub->selectRaw('1')
                        ->from($instance->getTable())
                        ->whereColumn("{$instance->getTable()}.{$column}", "{$table}.egaid");
                });
            }
        });
    }

    /**
     * Multi-word search across street, number, ZIP and town: "spalen 11 basel".
     *
     * Runs on the normalised *_search columns, so it is case- and accent-
     * insensitive ("zurich" finds Zürich) without any custom SQL function.
     * The first word is matched as a word start with an index-friendly range
     * comparison; pass `contains: true` to match it anywhere instead —
     * slower, but finds "langen" in "Im langen Loh".
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $search, bool $contains = false): void
    {
        $tokens = preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isWord = fn (string $token): bool => ! preg_match('/^\d{1,5}[a-z]{0,3}$/i', $token);

        // The first word drives the query through the indexes; later words only
        // filter the rows it narrowed down, so a contains match there is cheap.
        $driver = $contains ? null : collect($tokens)->first($isWord);

        foreach ($tokens as $token) {
            // Four digits are a Swiss ZIP: an indexed equality instead of
            // scanning two million rows. Five digits may be a German ZIP or a
            // house number, so both are tried below.
            if (preg_match('/^\d{4}$/', $token)) {
                $query->where('zip', $token);

                continue;
            }

            $key = self::searchKey($token);

            $query->where(function (Builder $query) use ($token, $key, $driver): void {
                foreach (['street_search', 'locality_search', 'commune_search'] as $column) {
                    if ($token === $driver || $column !== 'street_search') {
                        $query->orWhere(fn (Builder $q) => $q->where($column, '>=', $key)->where($column, '<', $key . "\u{10FFFF}"));
                    } else {
                        self::whereContains($query, $column, $key);
                    }
                }

                if (preg_match('/^\d{1,3}$/', $token) || preg_match('/^\d{5}$/', $token)) {
                    $query->orWhere('zip', 'like', "{$token}%");
                }

                if (preg_match('/^\d{1,5}[a-z]{0,3}$/i', $token)) {
                    $query->orWhere('number', 'like', "{$token}%");
                }
            });
        }
    }

    /**
     * Lowercase, accent-folded form used for matching: "Écublens" → "ecublens".
     */
    public static function searchKey(string $value): string
    {
        return trim(Str::ascii(mb_strtolower(trim($value))));
    }

    /**
     * Native substring match on a normalised column — no LIKE, so an app that
     * overrides like() in PHP does not turn the search into a PHP loop.
     *
     * @param  Builder<static>  $query
     */
    protected static function whereContains(Builder $query, string $column, string $key): void
    {
        $function = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'strpos' : 'instr';

        $query->orWhereRaw("{$function}({$column}, ?) > 0", [$key]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('street')->orderBy('zip')->orderBy('number_int')->orderBy('number');
    }
}

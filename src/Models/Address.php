<?php

namespace Blemli\Swissstreets\Models;

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
 * @property int $egid
 * @property string $street
 * @property string|null $number
 * @property int|null $number_int
 * @property int $zip
 * @property string $locality
 * @property string $commune
 * @property string $street_search
 * @property string $locality_search
 * @property string $commune_search
 * @property string $canton
 * @property string $category
 * @property float $lat
 * @property float $lng
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

    protected $primaryKey = 'egaid';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'egaid' => 'integer',
        'egid' => 'integer',
        'number_int' => 'integer',
        'zip' => 'integer',
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

    public function getCityLineAttribute(): string
    {
        return "{$this->zip} {$this->locality}";
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

    public function mapUrl(): string
    {
        [$e, $n] = [$this->easting, $this->northing];

        if ($e === null || $n === null) {
            [$e, $n] = Lv95::fromWgs84($this->lat, $this->lng);
        }

        return sprintf('https://map.geo.admin.ch/?E=%d&N=%d&zoom=10&crosshair=marker', $e, $n);
    }

    public function distanceTo(float $lat, float $lng): float
    {
        return Distance::kilometres($this->lat, $this->lng, $lat, $lng);
    }

    public function isResidential(): bool
    {
        return in_array($this->category, (array) config('swissstreets-for-filament.residential_categories', []), true);
    }

    // ---- scopes ------------------------------------------------------------

    /**
     * @param  Builder<static>  $query
     */
    public function scopeResidential(Builder $query): void
    {
        $query->whereIn('category', (array) config('swissstreets-for-filament.residential_categories', []));
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
            // Four digits are a ZIP: an indexed equality instead of scanning
            // two million rows.
            if (preg_match('/^\d{4}$/', $token)) {
                $query->where('zip', (int) $token);

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

                if (preg_match('/^\d{1,3}$/', $token)) {
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

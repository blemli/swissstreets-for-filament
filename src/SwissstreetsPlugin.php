<?php

namespace Blemli\Swissstreets;

use Blemli\Swissstreets\Resources\AddressResource;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

class SwissstreetsPlugin implements Plugin
{
    /** @var array<string, mixed> */
    protected array $config = [];

    protected ?Closure $notifyUsing = null;

    public function getId(): string
    {
        return 'swissstreets-for-filament';
    }

    public function register(Panel $panel): void
    {
        foreach ($this->config as $key => $value) {
            config()->set("swissstreets-for-filament.{$key}", $value);
        }

        if ($this->notifyUsing instanceof Closure) {
            app(Swissstreets::class)->notifyUsing($this->notifyUsing);
        }

        if (config('swissstreets-for-filament.table', false)) {
            $panel->resources([AddressResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    // ---- import scope ------------------------------------------------------

    /**
     * @param  array<int, string>  $cantons  e.g. ['BS', 'BL']
     */
    public function cantons(array $cantons): static
    {
        $this->config['cantons'] = $cantons;

        return $this;
    }

    /** Keep the LV95 easting/northing columns next to lat/lng. */
    public function swissgrid(bool $condition = true): static
    {
        $this->config['swissgrid'] = $condition;

        return $this;
    }

    public function unofficial(bool $condition = true): static
    {
        $this->config['unofficial'] = $condition;

        return $this;
    }

    public function planned(bool $condition = true): static
    {
        $this->config['planned'] = $condition;

        return $this;
    }

    // ---- logging & notifications ------------------------------------------

    public function logChannel(?string $channel): static
    {
        $this->config['log_channel'] = $channel;

        return $this;
    }

    /**
     * Who gets the import summary as a Filament database notification: a user
     * model class (everyone) or a closure returning users.
     *
     * @param  class-string<Model>|Closure|null  $recipients
     */
    public function notify(string | Closure | null $recipients): static
    {
        if ($recipients instanceof Closure) {
            $this->notifyUsing = $recipients;
        } else {
            $this->config['notify'] = $recipients;
        }

        return $this;
    }

    // ---- panel -------------------------------------------------------------

    /** Show the read-only, searchable "Addresses" table in the panel (off by default). */
    public function table(bool $condition = true): static
    {
        $this->config['table'] = $condition;

        return $this;
    }

    public function navigationGroup(?string $group): static
    {
        $this->config['navigation_group'] = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->config['navigation_sort'] = $sort;

        return $this;
    }
}

<?php

namespace Blemli\Swissstreets;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class Swissstreets
{
    /** @var array<string, array{model: class-string<Model>, column: string}> */
    protected array $usages = [];

    protected ?Closure $notifyUsing = null;

    /**
     * @param  class-string<Model>  $model
     */
    public function registerUsage(string $model, string $column = 'address_id'): void
    {
        $this->usages["{$model}.{$column}"] = ['model' => $model, 'column' => $column];
    }

    /**
     * @return array<string, array{model: class-string<Model>, column: string}>
     */
    public function usages(): array
    {
        return $this->usages;
    }

    public function notifyUsing(?Closure $callback): void
    {
        $this->notifyUsing = $callback;
    }

    /**
     * Users that receive the import summary notification.
     *
     * @return Collection<int, Model>
     */
    public function notificationRecipients(): Collection
    {
        if ($this->notifyUsing instanceof Closure) {
            return Collection::wrap(($this->notifyUsing)())->filter();
        }

        $notify = config('swissstreets-for-filament.notify');

        if (is_string($notify) && class_exists($notify)) {
            /** @var class-string<Model> $notify */
            return $notify::query()->get();
        }

        if (is_callable($notify)) {
            return Collection::wrap($notify())->filter();
        }

        return new Collection;
    }

    public function activitylogAvailable(): bool
    {
        return (bool) config('swissstreets-for-filament.activitylog', true)
            && function_exists('activity')
            && class_exists(Activity::class);
    }
}

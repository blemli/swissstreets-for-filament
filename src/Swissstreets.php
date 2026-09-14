<?php

namespace Blemli\Swissstreets;

use Blemli\Swissstreets\Concerns\HasAddress;
use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use Spatie\Activitylog\Models\Activity;
use Throwable;

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
     * Every model + column referencing an address: registered explicitly,
     * registered by the HasAddress trait at boot, or discovered through the
     * current panel's resources (their models may not have booted yet).
     *
     * @return array<string, array{model: class-string<Model>, column: string}>
     */
    public function usages(): array
    {
        foreach ($this->discoverPanelModels() as $model) {
            if (in_array(HasAddress::class, class_uses_recursive($model), true)) {
                $defaults = (new ReflectionClass($model))->getDefaultProperties();
                $this->registerUsage($model, $defaults['addressColumn'] ?? 'address_id');
            }
        }

        return $this->usages;
    }

    /**
     * @return array<int, class-string<Model>>
     */
    protected function discoverPanelModels(): array
    {
        try {
            $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
        } catch (Throwable) {
            return [];
        }

        $models = [];

        /** @var class-string<resource> $resource */
        foreach ($panel->getResources() as $resource) {
            $model = $resource::getModel();

            if (class_exists($model)) {
                $models[] = $model;
            }
        }

        return $models;
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

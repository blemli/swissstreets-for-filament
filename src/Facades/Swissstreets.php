<?php

namespace Blemli\Swissstreets\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void registerUsage(string $model, string $column = 'address_id')
 * @method static array<string, array{model: class-string<\Illuminate\Database\Eloquent\Model>, column: string}> usages()
 * @method static void notifyUsing(?\Closure $callback)
 * @method static \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> notificationRecipients()
 * @method static bool activitylogAvailable()
 *
 * @see \Blemli\Swissstreets\Swissstreets
 */
class Swissstreets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Blemli\Swissstreets\Swissstreets::class;
    }
}

<?php

namespace Nextpointer\Bridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array run(string $source, string $entity, array $rows)
 * * @see \Nextpointer\Bridge\SyncEngine
 */
class Bridge extends Facade
{
    /**
     * Λήψη του εγγεγραμμένου ονόματος του component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'bridge-engine';
    }
}
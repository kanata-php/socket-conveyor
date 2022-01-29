<?php

namespace Conveyor\Actions\Traits;

use Exception;

/**
 * Action's Trait for Controller similar procedures.
 */

trait ProcedureActionTrait
{
    /**
     * @param array $data
     * @param int|null $fd
     * @param mixed|null $server
     * @return mixed
     * @throws Exception
     */
    public function __invoke(array $data, ?int $fd = null, mixed $server = null): mixed
    {
        $this->validateData($data);
        return $this->execute($data, $fd, $server);
    }
}
